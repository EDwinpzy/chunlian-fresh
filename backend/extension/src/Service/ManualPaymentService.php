<?php

declare(strict_types=1);

namespace Chunlian\Service;

use Chunlian\Domain\OrderPaymentState;
use Chunlian\Domain\OrderStatus;
use Chunlian\Domain\OrderRevisionState;
use Chunlian\Domain\PaymentMethod;
use Chunlian\Domain\RejectionCode;
use Chunlian\Repository\OrderRowMapper;
use Chunlian\Repository\PdoOrderRepository;

/**
 * 商家手工核实顾客扫码收款、确认线下退款。
 *
 * 二维码收款事实只能由商家核账后写入；客户端展示二维码/提交截图不构成支付成功。
 * 本服务同时支持下单预付款和 PENDING_TOPUP 修订补款。
 */
final class ManualPaymentService
{
    public function __construct(
        private readonly \PDO $pdo,
        private readonly PdoOrderRepository $orders,
        private readonly RevisionService $revisions,
        private readonly IdempotencyService $idempotency,
        private readonly string $tablePrefix = 'shopxo_',
    ) {
    }

    public function confirmQrReceipt(
        string $orderId,
        string $actorId,
        int $clientVersion,
        int $amountFen,
        string $idempotencyKey,
    ): array {
        if ($clientVersion < 1 || $amountFen <= 0) {
            return $this->fail(RejectionCode::VALIDATION_FAILED, '请提供有效的订单版本和收款金额');
        }
        if (!IdempotencyService::isValidKey($idempotencyKey)) {
            return $this->fail(RejectionCode::VALIDATION_FAILED, '收款确认必须携带有效的 Idempotency-Key（UUID v4）');
        }

        $outer = $this->idempotency->run(
            $actorId,
            'merchant.order.qr-receipt',
            $idempotencyKey,
            ['order_id' => $orderId, 'version' => $clientVersion, 'amount_fen' => $amountFen],
            fn (): array => $this->confirmQrReceiptTransactional(
                $orderId,
                $actorId,
                $clientVersion,
                $amountFen,
                $idempotencyKey
            )
        );

        if (($outer['code'] ?? '') !== 'OK') {
            $code = RejectionCode::tryFrom((string) ($outer['code'] ?? ''))
                ?? RejectionCode::IDEMPOTENCY_CONFLICT;

            return $this->fail(
                $code,
                (string) ($outer['data']['message'] ?? RejectionCode::IDEMPOTENCY_CONFLICT->defaultMessage())
            );
        }

        $result = $outer['data'] ?? [];

        return is_array($result) && isset($result['code']) ? $result : $this->ok($result);
    }

    public function confirmManualRefund(
        string $orderId,
        string $actorId,
        int $clientVersion,
        int $amountFen,
        string $idempotencyKey,
    ): array {
        if ($clientVersion < 1 || $amountFen <= 0) {
            return $this->fail(RejectionCode::VALIDATION_FAILED, '请提供有效的订单版本和退款金额');
        }
        if (!IdempotencyService::isValidKey($idempotencyKey)) {
            return $this->fail(RejectionCode::VALIDATION_FAILED, '退款确认必须携带有效的 Idempotency-Key（UUID v4）');
        }

        $outer = $this->idempotency->run(
            $actorId,
            'merchant.order.manual-refund',
            $idempotencyKey,
            ['order_id' => $orderId, 'version' => $clientVersion, 'amount_fen' => $amountFen],
            fn (): array => $this->confirmManualRefundTransactional(
                $orderId,
                $actorId,
                $clientVersion,
                $amountFen
            )
        );

        if (($outer['code'] ?? '') !== 'OK') {
            $code = RejectionCode::tryFrom((string) ($outer['code'] ?? ''))
                ?? RejectionCode::IDEMPOTENCY_CONFLICT;

            return $this->fail(
                $code,
                (string) ($outer['data']['message'] ?? RejectionCode::IDEMPOTENCY_CONFLICT->defaultMessage())
            );
        }

        $result = $outer['data'] ?? [];

        return is_array($result) && isset($result['code']) ? $result : $this->ok($result);
    }

    private function confirmQrReceiptTransactional(
        string $orderId,
        string $actorId,
        int $clientVersion,
        int $amountFen,
        string $idempotencyKey,
    ): array {
        $this->pdo->beginTransaction();
        try {
            $order = $this->orders->findForUpdate($orderId, $actorId);
            if ($order === null) {
                return $this->finishRejected(RejectionCode::NOT_FOUND, '订单不存在');
            }
            if ($order->version !== $clientVersion) {
                return $this->finishRejected(RejectionCode::ORDER_VERSION_CONFLICT, '订单已更新，请刷新后重试');
            }
            if ($order->paymentMethod !== PaymentMethod::OFFLINE_QR) {
                return $this->finishRejected(RejectionCode::VALIDATION_FAILED, '该订单未选择线下扫码付款');
            }
            if ($order->orderStatus !== OrderStatus::PENDING) {
                return $this->finishRejected(RejectionCode::VALIDATION_FAILED, '只有待配送订单可以登记扫码预付款或补款');
            }

            if ($order->revisionState === OrderRevisionState::PENDING_TOPUP) {
                $pending = $this->pendingTopup($orderId);
                if ($pending === null || (int) $pending['delta_fen'] !== $amountFen || $amountFen <= 0) {
                    return $this->finishRejected(RejectionCode::VALIDATION_FAILED, '补款金额必须与待处理改单差额一致');
                }

                $this->insertOfflineReceipt(
                    $orderId,
                    (int) $pending['id'],
                    $amountFen,
                    $actorId,
                    $idempotencyKey
                );

                $committed = $this->revisions->commitTopup($orderId, $actorId);
                if (($committed['code'] ?? '') !== 'OK' || (($committed['data']['changed'] ?? false) !== true)) {
                    $this->pdo->rollBack();

                    return $committed;
                }

                $this->pdo->commit();

                return $this->ok([
                    'kind' => 'OFFLINE_RECEIPT',
                    'amount_fen' => $amountFen,
                    'revision_state' => OrderRevisionState::COMMITTED->value,
                ], '补款已核实，改单已生效');
            }

            if ($order->paymentState !== OrderPaymentState::UNPAID || $order->paidFen !== 0) {
                return $this->finishRejected(RejectionCode::VALIDATION_FAILED, '该订单已收款或当前状态不能确认预付款');
            }
            $receivableFen = max(0, $order->receivable()->fen());
            if ($amountFen !== $receivableFen) {
                return $this->finishRejected(
                    RejectionCode::VALIDATION_FAILED,
                    '收款金额必须等于订单应收金额',
                    ['receivable_fen' => $receivableFen]
                );
            }

            $this->insertOfflineReceipt($orderId, null, $amountFen, $actorId, $idempotencyKey);
            $update = $this->pdo->prepare(
                "UPDATE `veg_order_delivery` SET `payment_state` = 'PAID', `version` = `version` + 1, `updated_at` = NOW() "
                . "WHERE `order_id` = :oid AND `version` = :version AND `payment_state` = 'UNPAID'"
            );
            $update->execute(['oid' => $orderId, 'version' => $clientVersion]);
            if ($update->rowCount() !== 1) {
                return $this->finishRejected(RejectionCode::ORDER_VERSION_CONFLICT, '订单已更新，请刷新后重试');
            }

            $this->pdo->prepare(sprintf(
                'UPDATE `%sorder` SET `pay_status` = 1, `status` = 2, `pay_price` = :paid, `upd_time` = UNIX_TIMESTAMP() WHERE `id` = :id',
                $this->tablePrefix
            ))->execute([
                'paid' => OrderRowMapper::fenToDecimal($amountFen),
                'id' => $orderId,
            ]);

            $this->orders->writeAuditLog($actorId, $orderId, 'OFFLINE_QR_RECEIPT', [
                'actor_type' => 'MERCHANT',
                'amount_fen' => $amountFen,
                'collection_method' => 'OFFLINE_QR',
                'idempotency_key' => $idempotencyKey,
            ]);
            $this->orders->enqueueEvent('ORDER_PAYMENT_CONFIRMED', $orderId, [
                'order_id' => $orderId,
                'amount_fen' => $amountFen,
                'payment_method' => PaymentMethod::OFFLINE_QR->value,
            ]);
            $this->pdo->commit();

            return $this->ok([
                'kind' => 'OFFLINE_RECEIPT',
                'amount_fen' => $amountFen,
                'payment_state' => OrderPaymentState::PAID->value,
                'version' => $clientVersion + 1,
            ], '扫码收款已核实');
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    private function confirmManualRefundTransactional(
        string $orderId,
        string $actorId,
        int $clientVersion,
        int $amountFen,
    ): array {
        $this->pdo->beginTransaction();
        try {
            $order = $this->orders->findForUpdate($orderId, $actorId);
            if ($order === null) {
                return $this->finishRejected(RejectionCode::NOT_FOUND, '订单不存在');
            }
            if ($order->version !== $clientVersion) {
                return $this->finishRejected(RejectionCode::ORDER_VERSION_CONFLICT, '订单已更新，请刷新后重试');
            }
            if ($order->paymentMethod !== PaymentMethod::OFFLINE_QR) {
                return $this->finishRejected(RejectionCode::VALIDATION_FAILED, '没有待确认的线下退款');
            }

            // 订单取消可能同时保留一笔改单退款（revision_id 非空）并产生
            // 一笔订单级剩余退款（revision_id 为 NULL）。按实际金额选择最早的
            // PROCESSING 流水；同额时先核销修订退款，再核销取消退款，顺序稳定。
            $ledger = $this->pdo->prepare(
                "SELECT `id`, `amount_fen`, `status`, `revision_id` FROM `veg_payment_ledger` "
                . "WHERE `order_id` = :oid AND `kind` = 'OFFLINE_REFUND' AND `status` = 'PROCESSING' "
                . 'AND `amount_fen` = :amount ORDER BY (`revision_id` IS NULL) ASC, `id` ASC LIMIT 1 FOR UPDATE'
            );
            $ledger->execute(['oid' => $orderId, 'amount' => -$amountFen]);
            $refund = $ledger->fetch(\PDO::FETCH_ASSOC);
            if ($refund === false || (int) $refund['amount_fen'] !== -$amountFen) {
                return $this->finishRejected(RejectionCode::VALIDATION_FAILED, '找不到金额一致的处理中线下退款记录');
            }

            $revisionId = $refund['revision_id'] === null ? null : (int) $refund['revision_id'];
            $revision = null;
            if ($revisionId !== null) {
                $revisionQuery = $this->pdo->prepare(
                    'SELECT `id`, `delta_fen`, `status` FROM `veg_order_revision` '
                    . 'WHERE `id` = :rid AND `order_id` = :oid LIMIT 1 FOR UPDATE'
                );
                $revisionQuery->execute(['rid' => $revisionId, 'oid' => $orderId]);
                $revision = $revisionQuery->fetch(\PDO::FETCH_ASSOC);
                if ($revision === false
                    || (string) $revision['status'] !== OrderRevisionState::APPLYING->value
                    || (int) $revision['delta_fen'] >= 0
                    || abs((int) $revision['delta_fen']) !== $amountFen) {
                    return $this->finishRejected(RejectionCode::VALIDATION_FAILED, '退款金额必须与待确认修订差额一致');
                }
            } elseif ($order->orderStatus !== OrderStatus::CANCELLED
                || $order->paymentState !== OrderPaymentState::REFUNDING) {
                return $this->finishRejected(RejectionCode::VALIDATION_FAILED, '订单级线下退款只允许在订单取消后确认');
            }

            $confirm = $this->pdo->prepare(
                "UPDATE `veg_payment_ledger` SET `status` = 'SUCCESS', `occurred_at` = NOW(), `updated_at` = NOW(), `operator_id` = :actor "
                . "WHERE `id` = :id AND `status` = 'PROCESSING'"
            );
            $confirm->execute(['actor' => $actorId, 'id' => (int) $refund['id']]);
            if ($confirm->rowCount() !== 1) {
                return $this->finishRejected(RejectionCode::ORDER_VERSION_CONFLICT, '退款状态已变化，请刷新后重试');
            }

            $newRefundedFen = $order->refundedFen + $amountFen;
            // 订单取消时，改单退款和剩余订单退款可能同时挂起；核销其中一笔时
            // 仍需把其他处理中金额算入上限，避免累计退款超过实收。
            $otherRefundingFen = max(0, $order->refundingFen - $amountFen);
            if ($newRefundedFen + $otherRefundingFen > $order->paidFen) {
                return $this->finishRejected(RejectionCode::VALIDATION_FAILED, '累计退款不能超过已收款金额');
            }
            $newPaymentState = match (true) {
                $otherRefundingFen > 0 => OrderPaymentState::REFUNDING,
                $newRefundedFen >= $order->paidFen => OrderPaymentState::REFUNDED,
                default => OrderPaymentState::PARTIAL_REFUNDED,
            };
            $this->pdo->prepare(sprintf(
                'UPDATE `%sorder` SET `refund_price` = :refund_price, `upd_time` = UNIX_TIMESTAMP() WHERE `id` = :id',
                $this->tablePrefix
            ))->execute([
                'refund_price' => OrderRowMapper::fenToDecimal($newRefundedFen),
                'id' => $orderId,
            ]);

            $revisionState = $order->revisionState;
            if ($revisionId !== null) {
                $revisionUpdate = $this->pdo->prepare(
                    "UPDATE `veg_order_revision` SET `status` = 'COMMITTED', `updated_at` = NOW() "
                    . "WHERE `id` = :id AND `status` = 'APPLYING'"
                );
                $revisionUpdate->execute(['id' => $revisionId]);
                if ($revisionUpdate->rowCount() !== 1) {
                    return $this->finishRejected(RejectionCode::ORDER_VERSION_CONFLICT, '改单状态已变化，请刷新后重试');
                }
                if ($order->revisionState === OrderRevisionState::APPLYING) {
                    $revisionState = OrderRevisionState::COMMITTED;
                }
            }

            $orderUpdate = $this->pdo->prepare(
                'UPDATE `veg_order_delivery` SET `payment_state` = :payment_state, `revision_state` = :revision_state, '
                . '`version` = `version` + 1, `updated_at` = NOW() WHERE `order_id` = :oid AND `version` = :version'
            );
            $orderUpdate->execute([
                'payment_state' => $newPaymentState->value,
                'revision_state' => $revisionState->value,
                'oid' => $orderId,
                'version' => $clientVersion,
            ]);
            if ($orderUpdate->rowCount() !== 1) {
                return $this->finishRejected(RejectionCode::ORDER_VERSION_CONFLICT, '订单已更新，请刷新后重试');
            }

            $this->orders->writeAuditLog($actorId, $orderId, 'OFFLINE_REFUND_CONFIRMED', [
                'actor_type' => 'MERCHANT',
                'refund_scope' => $revisionId !== null ? 'REVISION' : 'CANCELLATION',
                'revision_id' => $revisionId,
                'amount_fen' => $amountFen,
                'payment_state' => $newPaymentState->value,
            ]);
            $this->pdo->commit();

            return $this->ok([
                'revision_state' => $revisionState->value,
                'payment_state' => $newPaymentState->value,
                'refunded_fen' => $newRefundedFen,
                'refund_scope' => $revisionId !== null ? 'REVISION' : 'CANCELLATION',
                'version' => $clientVersion + 1,
            ], '线下退款已确认');
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @return array<string,mixed>|null */
    private function pendingTopup(string $orderId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT `id`, `delta_fen` FROM `veg_order_revision` WHERE `order_id` = :oid AND `status` = 'PENDING_TOPUP' "
            . 'ORDER BY `id` DESC LIMIT 1 FOR UPDATE'
        );
        $stmt->execute(['oid' => $orderId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    private function insertOfflineReceipt(
        string $orderId,
        ?int $revisionId,
        int $amountFen,
        string $actorId,
        string $idempotencyKey,
    ): void {
        $now = date('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'INSERT INTO `veg_payment_ledger` '
            . '(`order_id`, `revision_id`, `kind`, `amount_fen`, `status`, `business_no`, `collection_method`, '
            . '`collected_fen`, `idempotency_key`, `operator_id`, `occurred_at`, `created_at`, `updated_at`) '
            . 'VALUES (:oid, :rid, \'OFFLINE_RECEIPT\', :amount, \'SUCCESS\', :business_no, \'OFFLINE_QR\', '
            . ':collected, :idem, :actor, :occurred, :created, :updated)'
        );
        $stmt->execute([
            'oid' => $orderId,
            'rid' => $revisionId,
            'amount' => $amountFen,
            'business_no' => sprintf('OQR%s%s', date('YmdHis'), bin2hex(random_bytes(5))),
            'collected' => $amountFen,
            // veg_payment_ledger 的幂等键是全局唯一；哈希中包含请求作用域，
            // 避免两个管理员恰好使用同一个 UUID 时发生跨账号冲突。
            'idem' => hash('sha256', $actorId . ':merchant.order.qr-receipt:' . $idempotencyKey),
            'actor' => $actorId === '' ? null : (int) $actorId,
            'occurred' => $now,
            'created' => $now,
            'updated' => $now,
        ]);
    }

    private function finishRejected(RejectionCode $code, string $message, mixed $data = null): array
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }

        return $this->fail($code, $message, $data);
    }

    private function ok(mixed $data, ?string $message = null): array
    {
        return ['code' => 'OK', 'message' => $message, 'data' => $data];
    }

    private function fail(RejectionCode $code, string $message, mixed $data = null): array
    {
        return ['code' => $code->value, 'message' => $message, 'data' => $data];
    }
}
