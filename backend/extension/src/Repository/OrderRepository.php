<?php

declare(strict_types=1);

namespace Chunlian\Repository;

use Chunlian\Domain\CollectionMethod;
use Chunlian\Domain\Order;
use Chunlian\Domain\PaymentMethod;
use Chunlian\Service\TransitionResult;

/**
 * 订单仓储接口。
 *
 * ⚠️  实现必须满足以下不变量（方案 §9.3）：
 *
 *  1. **行锁 / 乐观锁**：`findForUpdate()` 必须以
 *     `SELECT ... FOR UPDATE` 或 `WHERE version=? AND order_status='PENDING'` 方式读取，
 *     否则并发下会出现"错版本发货"。
 *  2. **事务边界**：`applyTransition()` 与资金流水写入必须在**同一事务**内完成，
 *     保证状态变更与收款记账原子性。
 *  3. **幂等**：`writeCodReceipt()` 依赖 `veg_payment_ledger.uk_idem_key` /
 *     `uk_business_no` 唯一约束兜底，重复调用不得产生第二条流水。
 *  4. **库存对称**：修订预占的增减必须可逆，撤销时对称归还。
 *  5. **审计只增**：`writeAuditLog()` 只 INSERT，禁止前台删除。
 */
interface OrderRepository
{
    /** 悲观/乐观锁读取订单；返回 null 表示不存在或无权访问。 */
    public function findForUpdate(string $orderId, string $actorId): ?Order;

    /** 应用状态迁移（同一事务内）。 */
    public function applyTransition(Order $order, TransitionResult $result): void;

    /**
     * 写入 COD 收款流水。
     *
     * @return int 流水 ID
     */
    public function writeCodReceipt(
        string $orderId,
        int $amountFen,
        CollectionMethod $method,
        string $operatorId,
        string $idempotencyKey,
    ): int;

    /** 写入顾客确认收货时自动结清的资金流水。 */
    public function writeCustomerReceipt(
        string $orderId,
        int $amountFen,
        PaymentMethod $paymentMethod,
        string $operatorId,
        string $idempotencyKey,
    ): int;

    /** 写审计日志。 */
    public function writeAuditLog(string $actorId, string $orderId, string $action, array $detail): void;

    /** @return array<int,array{kind:string,amount_fen:int,status:string,occurred_at:string}> */
    public function ledgerEntries(string $orderId): array;

    /** 写入 outbox 事件（同事务）。 */
    public function enqueueEvent(string $eventType, ?string $entityId, array $payload): void;

    /** COD 已送达未核销的订单。 */
    public function findCodReceivables(): array;
}
