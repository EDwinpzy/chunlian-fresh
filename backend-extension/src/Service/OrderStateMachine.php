<?php

declare(strict_types=1);

namespace Chunlian\Service;

use Chunlian\Domain\DeliveryMode;
use Chunlian\Domain\Order;
use Chunlian\Domain\OrderFulfillment;
use Chunlian\Domain\OrderPaymentState;
use Chunlian\Domain\PaymentMethod;
use Chunlian\Domain\RejectionCode;
use Chunlian\Domain\RevisionDeltaType;

/**
 * 订单状态机 —— 所有履约/支付迁移的唯一裁决点。
 *
 * 设计要点：
 * - 每个动作返回 `TransitionResult`：成功则带新的状态，失败则带 `RejectionCode`。
 * - 纯函数，无副作用；持久化与锁由调用方（Service/Repository）负责。
 * - **不使用前端"按钮不可点"代替这里的服务端保护。**
 */
final class OrderStateMachine
{
    /** 商家点击「开始配送」。 */
    public function startDelivery(Order $order, int $clientVersion): TransitionResult
    {
        if ($order->version !== $clientVersion) {
            return TransitionResult::reject(RejectionCode::ORDER_VERSION_CONFLICT, [
                'current_version' => $order->version,
            ]);
        }

        if ($order->fulfillment !== OrderFulfillment::PENDING) {
            return TransitionResult::reject(RejectionCode::ORDER_NOT_EDITABLE, [
                'fulfillment' => $order->fulfillment->value,
            ]);
        }

        if ($order->revisionState->blocksDispatch()) {
            return TransitionResult::reject(RejectionCode::TOPUP_REQUIRED, [
                'revision_state' => $order->revisionState->value,
            ]);
        }

        if (!$order->fulfillment->canTransitionTo(OrderFulfillment::DELIVERING)) {
            return TransitionResult::reject(RejectionCode::ORDER_NOT_EDITABLE);
        }

        return TransitionResult::ok([
            'fulfillment' => OrderFulfillment::DELIVERING,
            'version' => $order->version + 1,
            'locked_customer_editing' => true,
        ]);
    }

    /**
     * 商家「确认送达」（合并收款）。
     *
     * @param bool $receivedMoney COD 是否已收款；在线支付单必须为 false
     * @param int|null $collectedFen COD 实收金额，须等于应收
     */
    public function confirmDelivery(
        Order $order,
        int $clientVersion,
        bool $receivedMoney,
        ?int $collectedFen = null,
    ): TransitionResult {
        if ($order->version !== $clientVersion) {
            return TransitionResult::reject(RejectionCode::ORDER_VERSION_CONFLICT, [
                'current_version' => $order->version,
            ]);
        }

        if ($order->fulfillment !== OrderFulfillment::DELIVERING) {
            return TransitionResult::reject(RejectionCode::ORDER_NOT_EDITABLE, [
                'fulfillment' => $order->fulfillment->value,
            ]);
        }

        // 在线已付款订单不得在此重复收款
        if ($order->paymentMethod === PaymentMethod::WECHAT && $receivedMoney) {
            return TransitionResult::reject(RejectionCode::VALIDATION_FAILED, [
                'reason' => '在线已付款订单不得重复登记收款',
            ]);
        }

        // COD 已收款：金额必须与应收完全一致
        if ($receivedMoney) {
            if ($collectedFen === null) {
                return TransitionResult::reject(RejectionCode::VALIDATION_FAILED, [
                    'reason' => '已收款时必须提供实收金额',
                ]);
            }
            $receivable = $order->receivable()->fen();
            if ($collectedFen !== $receivable) {
                return TransitionResult::reject(RejectionCode::VALIDATION_FAILED, [
                    'reason' => '实收金额必须与应收金额一致',
                    'receivable_fen' => $receivable,
                ]);
            }
        }

        $newPaidFen = $receivedMoney
            ? $order->paidFen + (int) $collectedFen
            : $order->paidFen;

        $isCod = $order->paymentMethod === PaymentMethod::COD;

        return TransitionResult::ok([
            'fulfillment' => OrderFulfillment::DELIVERED,
            'version' => $order->version + 1,
            'paid_fen' => $newPaidFen,
            'payment_state' => $this->derivePaymentState($order->paymentMethod, $newPaidFen, $order->total()->fen(), $order->refundedFen),
            'receivable_fen' => $order->total()->fen() - $newPaidFen,
            'write_cod_receipt' => $receivedMoney && $isCod,
            'delivered_at' => true,
        ]);
    }

    /**
     * 仅补记 COD 收款（纠错入口，非日常主操作）。
     */
    public function recordCodReceipt(Order $order, int $clientVersion, int $collectedFen): TransitionResult
    {
        if ($order->version !== $clientVersion) {
            return TransitionResult::reject(RejectionCode::ORDER_VERSION_CONFLICT, [
                'current_version' => $order->version,
            ]);
        }

        if ($order->paymentMethod !== PaymentMethod::COD) {
            return TransitionResult::reject(RejectionCode::VALIDATION_FAILED, [
                'reason' => '仅 COD 订单支持补记收款',
            ]);
        }

        if ($order->fulfillment !== OrderFulfillment::DELIVERED) {
            return TransitionResult::reject(RejectionCode::ORDER_NOT_EDITABLE, [
                'fulfillment' => $order->fulfillment->value,
            ]);
        }

        $receivable = $order->receivable()->fen();
        if ($receivable <= 0) {
            return TransitionResult::reject(RejectionCode::COD_ALREADY_COLLECTED);
        }

        if ($collectedFen !== $receivable) {
            return TransitionResult::reject(RejectionCode::VALIDATION_FAILED, [
                'reason' => '实收金额必须与应收金额一致',
                'receivable_fen' => $receivable,
            ]);
        }

        $newPaidFen = $order->paidFen + $collectedFen;

        return TransitionResult::ok([
            'version' => $order->version + 1,
            'paid_fen' => $newPaidFen,
            'payment_state' => OrderPaymentState::PAID,
            'receivable_fen' => 0,
            'write_cod_receipt' => true,
        ]);
    }

    /** 顾客确认收货。仅已送达可确认。 */
    public function confirmReceipt(Order $order, int $clientVersion): TransitionResult
    {
        if ($order->version !== $clientVersion) {
            return TransitionResult::reject(RejectionCode::ORDER_VERSION_CONFLICT, [
                'current_version' => $order->version,
            ]);
        }

        if (!$order->receiptState->canConfirm($order->fulfillment)) {
            return TransitionResult::reject(RejectionCode::ORDER_NOT_EDITABLE, [
                'fulfillment' => $order->fulfillment->value,
                'receipt_state' => $order->receiptState->value,
            ]);
        }

        return TransitionResult::ok([
            'fulfillment' => OrderFulfillment::FINISHED,
            'receipt_state' => \Chunlian\Domain\OrderReceiptState::CONFIRMED,
            'version' => $order->version + 1,
        ]);
    }

    /**
     * 顾客取消订单。
     *
     * 已付款的订单会同时转入退款流程（`payment_state = REFUNDING`）。
     * 应退金额取 `Order::refundable()` —— 该式已扣除**处理中**的退款，
     * 防止在退款窗口期内重复计算可退额度。
     */
    public function cancel(Order $order, int $clientVersion): TransitionResult
    {
        if ($order->version !== $clientVersion) {
            return TransitionResult::reject(RejectionCode::ORDER_VERSION_CONFLICT, [
                'current_version' => $order->version,
            ]);
        }

        if (!$order->canCustomerCancel()) {
            // 已出发：不允许自助取消，进入售后
            return TransitionResult::reject(RejectionCode::ORDER_NOT_EDITABLE, [
                'fulfillment' => $order->fulfillment->value,
                'next_step' => 'AFTER_SALES',
            ]);
        }

        // 应退 = 已实付 − 已成功退款 − 处理中退款（refundable() 内部已做下限 0 保护）
        $refundFen = $order->refundable()->fen();

        $paymentState = match (true) {
            $refundFen > 0 => OrderPaymentState::REFUNDING,
            $order->paymentState === OrderPaymentState::PAID => OrderPaymentState::REFUNDING,
            default => $order->paymentState,
        };

        return TransitionResult::ok([
            'fulfillment' => OrderFulfillment::CANCELLED,
            'payment_state' => $paymentState,
            'version' => $order->version + 1,
            'refund_fen' => $refundFen,
            'release_stock' => true,
            'cancel_revision' => true,
        ]);
    }

    /**
     * 校验顾客是否有权发起修订。
     */
    public function canStartRevision(Order $order, int $clientVersion): TransitionResult
    {
        if ($order->version !== $clientVersion) {
            return TransitionResult::reject(RejectionCode::ORDER_VERSION_CONFLICT, [
                'current_version' => $order->version,
            ]);
        }

        if (!$order->canCustomerEdit()) {
            return TransitionResult::reject(RejectionCode::ORDER_NOT_EDITABLE, [
                'fulfillment' => $order->fulfillment->value,
            ]);
        }

        if (!$order->revisionState->allowsNewRevision()) {
            return TransitionResult::reject(RejectionCode::TOPUP_REQUIRED, [
                'revision_state' => $order->revisionState->value,
            ]);
        }

        return TransitionResult::ok(['allowed' => true]);
    }

    /** 由金额推导支付状态。 */
    private function derivePaymentState(
        PaymentMethod $method,
        int $paidFen,
        int $totalFen,
        int $refundedFen,
    ): OrderPaymentState {
        if ($refundedFen > 0) {
            return $refundedFen >= $paidFen
                ? OrderPaymentState::REFUNDED
                : OrderPaymentState::PARTIAL_REFUNDED;
        }

        if ($paidFen >= $totalFen && $totalFen > 0) {
            return OrderPaymentState::PAID;
        }

        return OrderPaymentState::UNPAID;
    }

    /**
     * 订单能否被商家开始配送的完整判定（供列表批量标记）。
     */
    public function isDispatchable(Order $order): bool
    {
        return $order->fulfillment === OrderFulfillment::PENDING
            && !$order->revisionState->blocksDispatch();
    }

    /** 校验配送时间合法性。 */
    public function validateDeliveryTime(
        DeliveryMode $mode,
        ?\DateTimeImmutable $expectedAt,
        \DateTimeImmutable $now,
    ): TransitionResult {
        if (!DeliveryMode::validateExpectedAt($mode, $expectedAt, $now)) {
            return TransitionResult::reject(RejectionCode::INVALID_EXPECTED_TIME, [
                'delivery_mode' => $mode->value,
            ]);
        }

        return TransitionResult::ok(['valid' => true]);
    }

    /** 修订方向 → 是否需补款后才提交。 */
    public function requiresTopupBeforeCommit(int $deltaFen): bool
    {
        return RevisionDeltaType::fromFen($deltaFen)->requiresTopupBeforeCommit();
    }
}
