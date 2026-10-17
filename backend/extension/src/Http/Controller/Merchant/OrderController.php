<?php

declare(strict_types=1);

namespace Chunlian\Http\Controller\Merchant;

use Chunlian\Domain\CollectionMethod;
use Chunlian\Domain\Order;
use Chunlian\Domain\OrderApiCompatibility;
use Chunlian\Domain\RejectionCode;
use Chunlian\Repository\OrderRepository;
use Chunlian\Service\LedgerCalculator;
use Chunlian\Service\OrderStateMachine;
use Chunlian\Service\TransitionResult;

/**
 * 商家订单履约控制器 —— 参考实现。
 *
 * ⚠️  本类展示**业务规则如何被服务端强制执行**，不包含 ShopXO 实际持久化代码。
 *     接入时需要：
 *       1. 用审计后的 Repository 替换下面的 `$this->orders()` 桩实现
 *       2. 在事务内加行锁 / 乐观锁 `WHERE version=? AND order_status='PENDING'`
 *       3. 写入 `veg_operation_log` 审计
 *       4. 写 `veg_outbox_event` 触发 App 同步
 *
 * 参见 docs/ROADMAP.md §6.2「未完成」清单。
 */
final class OrderController
{
    public function __construct(
        private readonly OrderStateMachine $stateMachine,
        private readonly LedgerCalculator $ledger,
        private readonly OrderRepository $repository,
    ) {
    }

    /**
     * 开始配送。
     *
     * 关键：即使前端绕过了按钮禁用，也必须在这里拦截。
     */
    public function dispatch(string $orderId, int $orderVersion, string $actorId): array
    {
        $order = $this->orders()->findForUpdate($orderId, $actorId);

        if ($order === null) {
            return $this->error(RejectionCode::NOT_FOUND);
        }

        // 服务端裁决：待补款时禁止出发
        $result = $this->stateMachine->startDelivery($order, $orderVersion);

        if (!$result->success) {
            return $this->error($result->code, $result->data, $result->message);
        }

        $this->orders()->applyTransition($order, $result);
        $this->audit($actorId, $orderId, 'DISPATCH', $result->data);

        return [
            'code' => 'OK',
            'message' => 'success',
            'data' => [
                'order_id' => $orderId,
                'order_version' => $result->get('version'),
                'order_status' => $result->get('order_status')->value,
                ...OrderApiCompatibility::legacyFieldsFor($result->get('order_status'), false),
                'locked_customer_editing' => true,
            ],
        ];
    }

    /**
     * 确认送达（合并 COD 收款）。
     *
     * 三选一语义在此强制：
     *   - received_money=true  → 同事务写 DELIVERED + COD_RECEIPT 流水
     *   - received_money=false → 只写 DELIVERED，保持应收
     */
    public function deliver(
        string $orderId,
        int $orderVersion,
        bool $receivedMoney,
        ?string $collectionMethod,
        ?int $collectedFen,
        string $actorId,
    ): array {
        $order = $this->orders()->findForUpdate($orderId, $actorId);

        if ($order === null) {
            return $this->error(RejectionCode::NOT_FOUND);
        }

        $result = $this->stateMachine->confirmDelivery(
            $order,
            $orderVersion,
            $receivedMoney,
            $collectedFen,
        );

        if (!$result->success) {
            return $this->error($result->code, $result->data, $result->message);
        }

        // 事务内：更新订单阶段 + （条件性）写 COD 收款流水
        $this->orders()->applyTransition($order, $result);

        $ledgerId = null;
        if ($result->get('write_cod_receipt') === true) {
            $method = CollectionMethod::tryFrom((string) $collectionMethod);
            if ($method === null) {
                return $this->error(RejectionCode::VALIDATION_FAILED, [
                    'reason' => '已收款时必须提供合法到账渠道',
                ]);
            }

            $ledgerId = $this->orders()->writeCodReceipt(
                orderId: $orderId,
                amountFen: (int) $collectedFen,
                method: $method,
                operatorId: $actorId,
                idempotencyKey: $this->idempotencyKey(),
            );
        }

        $this->audit($actorId, $orderId, 'DELIVER', [
            'received_money' => $receivedMoney,
            'collection_method' => $collectionMethod,
            'collected_fen' => $collectedFen,
            'ledger_id' => $ledgerId,
        ]);

        return [
            'code' => 'OK',
            'message' => 'success',
            'data' => [
                'order_id' => $orderId,
                'order_version' => $result->get('version'),
                'order_status' => $result->get('order_status')->value,
                ...OrderApiCompatibility::legacyFieldsFor($result->get('order_status'), false),
                'payment_state' => $result->get('payment_state')->value,
                'receivable_fen' => $result->get('receivable_fen'),
                'ledger_id' => $ledgerId,
                'delivered_at' => date(DATE_ATOM),
            ],
        ];
    }

    /**
     * 仅补记 COD 收款（纠错入口，非日常主操作）。
     */
    public function codReceipt(
        string $orderId,
        int $orderVersion,
        string $collectionMethod,
        int $collectedFen,
        string $actorId,
    ): array {
        $order = $this->orders()->findForUpdate($orderId, $actorId);

        if ($order === null) {
            return $this->error(RejectionCode::NOT_FOUND);
        }

        $method = CollectionMethod::tryFrom($collectionMethod);
        if ($method === null) {
            return $this->error(RejectionCode::VALIDATION_FAILED, [
                'reason' => '到账渠道非法',
            ]);
        }

        $result = $this->stateMachine->recordCodReceipt($order, $orderVersion, $collectedFen);

        if (!$result->success) {
            return $this->error($result->code, $result->data, $result->message);
        }

        $ledgerId = $this->orders()->writeCodReceipt(
            orderId: $orderId,
            amountFen: $collectedFen,
            method: $method,
            operatorId: $actorId,
            idempotencyKey: $this->idempotencyKey(),
        );

        // 补记收款属敏感动作，必须留痕
        $this->audit($actorId, $orderId, 'COD_RECEIPT', [
            'collection_method' => $collectionMethod,
            'collected_fen' => $collectedFen,
            'ledger_id' => $ledgerId,
        ]);

        return [
            'code' => 'OK',
            'message' => 'success',
            'data' => [
                'order_id' => $orderId,
                'order_version' => $result->get('version'),
                'payment_state' => $result->get('payment_state')->value,
                'receivable_fen' => 0,
                'ledger_id' => $ledgerId,
            ],
        ];
    }

    /**
     * 待收款清单（COD 已送达未核销）。
     */
    public function receivables(): array
    {
        $rows = $this->orders()->findCodReceivables();
        $totalFen = 0;

        foreach ($rows as $row) {
            $entries = $this->orders()->ledgerEntries((string) $row['order_id']);
            $row['receivable_fen'] = $this->ledger->receivableFen($entries, (int) $row['total_fen']);
            $totalFen += (int) $row['receivable_fen'];
        }

        return [
            'code' => 'OK',
            'message' => 'success',
            'data' => [
                'list' => $rows,
                'total_receivable_fen' => $totalFen,
                'count' => count($rows),
            ],
        ];
    }

    private function error(RejectionCode $code, array $data = [], ?string $message = null): array
    {
        return [
            'code' => $code->value,
            'message' => $message ?? $code->defaultMessage(),
            'data' => $data,
            'http_status' => $code->httpStatus(),
        ];
    }

    private function audit(string $actorId, string $orderId, string $action, array $detail): void
    {
        $this->orders()->writeAuditLog($actorId, $orderId, $action, $detail);
    }

    private function idempotencyKey(): string
    {
        return $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '';
    }

    /** 由注入的 Repository 提供。 */
    private function orders(): OrderRepository
    {
        return $this->repository;
    }
}
