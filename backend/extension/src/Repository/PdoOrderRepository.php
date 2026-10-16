<?php

declare(strict_types=1);

namespace Chunlian\Repository;

use Chunlian\Domain\CollectionMethod;
use Chunlian\Domain\Contact;
use Chunlian\Domain\LedgerKind;
use Chunlian\Domain\Order;
use Chunlian\Service\TransitionResult;

/**
 * 订单仓储的 MySQL/PDO 实现。
 *
 * 【表归属与真源】
 *   `veg_*`（我方扩展表，固定名，不加前缀）
 *     veg_order_delivery  四维状态 + 乐观锁 version  ← 履约真源
 *     veg_order_line      商品行（含 SKU 身份）      ← 商品真源
 *     veg_payment_ledger  资金流水                   ← 资金真源
 *     veg_operation_log   操作审计（只增）
 *     veg_outbox_event    异步任务
 *
 *   `{prefix}*`（ShopXO 原生表，前缀可配置）
 *     order / order_address  ← 订单号、金额、收货地址真源
 *
 * 【为什么状态与金额分属两张表】
 * ShopXO 的 `order.status` 是单一维度且 `4=已完成` 合并了「已送达」与
 * 「已完成」，不能作为履约真源；但金额与订单号仍以 ShopXO 为准。
 * 因此本实现**跨两张表读、写时同事务更新两张表**。
 *
 * 【事务约定】
 * 调用方负责开启/提交事务。本类内部不 beginTransaction ——
 * 因为「读取 → 状态机判断 → 写回」必须处于同一事务，
 * 事务边界属于调用方（Service 层）的职责。
 * 若由本类各自开事务，行锁会在方法返回时释放，乐观锁就失效了。
 */
final class PdoOrderRepository implements OrderRepository
{
    private readonly OrderRowMapper $mapper;

    public function __construct(
        private readonly \PDO $pdo,
        private readonly string $tablePrefix = 'shopxo_',
    ) {
        $this->mapper = new OrderRowMapper();
    }

    /** 取出并清空映射过程中的降级告警，供上层写日志。 */
    public function flushWarnings(): array
    {
        return $this->mapper->flushWarnings();
    }

    // =========================================================
    // 读取
    // =========================================================

    /**
     * 加行锁读取订单。
     *
     * 【关于 $actorId】
     * 本项目是**单店模型**：ShopXO 里所有商家共享同一个订单池，
     * 数据模型中没有「订单属于哪个商家」这个维度。
     * 因此本方法**不按 actorId 过滤**，$actorId 仅用于调用方做审计串联。
     *
     * 鉴权（确认调用者是已登录商家）由插件层的认证中间件负责，
     * 不能依赖本方法 —— 这里静默忽略一个安全参数会给人错误的安全感，
     * 所以显式说明。
     *
     * @throws OrderNotFoundException
     */
    public function findForUpdate(string $orderId, string $actorId): ?Order
    {
        $orderRow = $this->queryOne(
            sprintf('SELECT * FROM `%sorder` WHERE `id` = :id LIMIT 1 FOR UPDATE', $this->tablePrefix),
            ['id' => $orderId]
        );
        if ($orderRow === null) {
            return null;
        }

        $addressRow = $this->queryOne(
            sprintf('SELECT * FROM `%sorder_address` WHERE `order_id` = :id LIMIT 1', $this->tablePrefix),
            ['id' => $orderId]
        );

        $deliveryRow = $this->queryOne(
            'SELECT * FROM `veg_order_delivery` WHERE `order_id` = :id LIMIT 1',
            ['id' => $orderId]
        );

        $lineRows = $this->queryAll(
            'SELECT * FROM `veg_order_line` WHERE `order_id` = :id ORDER BY `id` ASC',
            ['id' => $orderId]
        );

        $order = $this->mapper->toOrder($orderRow, $addressRow, $deliveryRow, $lineRows);

        // 处理中退款需从资金流水汇总 —— 它不落在订单主表上，
        // 但必须参与可退余额计算（防超退），所以这里补齐。
        return $this->withRefundingFen($order);
    }

    /**
     * 不带锁读取（列表场景）。
     * 列表不需要行锁：它只用于展示，任何写操作都必须先走 findForUpdate。
     */
    public function find(string $orderId): ?Order
    {
        $orderRow = $this->queryOne(
            sprintf('SELECT * FROM `%sorder` WHERE `id` = :id LIMIT 1', $this->tablePrefix),
            ['id' => $orderId]
        );
        if ($orderRow === null) {
            return null;
        }

        return $this->withRefundingFen($this->mapper->toOrder(
            $orderRow,
            $this->queryOne(
                sprintf('SELECT * FROM `%sorder_address` WHERE `order_id` = :id LIMIT 1', $this->tablePrefix),
                ['id' => $orderId]
            ),
            $this->queryOne('SELECT * FROM `veg_order_delivery` WHERE `order_id` = :id LIMIT 1', ['id' => $orderId]),
            $this->queryAll('SELECT * FROM `veg_order_line` WHERE `order_id` = :id ORDER BY `id` ASC', ['id' => $orderId])
        ));
    }

    // =========================================================
    // 写入
    // =========================================================

    /**
     * 应用状态迁移（乐观锁 CAS）。
     *
     * 两处写入，同一事务：
     *   1. `veg_order_delivery` —— 四维状态 + version 自增
     *   2. `{prefix}order`     —— 金额（若迁移结果带 `paid_fen`）
     *
     * 【version 的 CAS 语义】
     * UPDATE 的 WHERE 同时带 `version = :expected`。
     * 受影响行数为 0 有两种可能：
     *   a) 版本已被别人改掉 → 冲突，必须让上层重新决策
     *   b) 扩展行根本不存在 → 订单未纳入扩展管理
     * 两者都会抛异常，但异常信息不同，便于排查。
     *
     * @throws VersionConflictException
     */
    public function applyTransition(Order $order, TransitionResult $result): void
    {
        if (!$result->success) {
            throw new \LogicException('不能把失败的迁移结果写入数据库');
        }

        $expectedVersion = $order->version;
        $now = date('Y-m-d H:i:s');

        $sets = ['`version` = `version` + 1', '`updated_at` = :updated_at'];
        $params = ['order_id' => $order->id, 'expected_version' => $expectedVersion, 'updated_at' => $now];

        // ---- 四维状态：只写迁移结果里显式给出的字段 ----
        // 不写「结果里没提到的维度」，避免把无关维度意外重置。
        if (($v = $result->get('fulfillment')) !== null) {
            $sets[] = '`fulfillment` = :fulfillment';
            $params['fulfillment'] = $v instanceof \BackedEnum ? $v->value : (string) $v;
        }
        if (($v = $result->get('payment_state')) !== null) {
            $sets[] = '`payment_state` = :payment_state';
            $params['payment_state'] = $v instanceof \BackedEnum ? $v->value : (string) $v;
        }
        if (($v = $result->get('receipt_state')) !== null) {
            $sets[] = '`receipt_state` = :receipt_state';
            $params['receipt_state'] = $v instanceof \BackedEnum ? $v->value : (string) $v;
        }
        if (($v = $result->get('revision_state')) !== null) {
            $sets[] = '`revision_state` = :revision_state';
            $params['revision_state'] = $v instanceof \BackedEnum ? $v->value : (string) $v;
        }
        if ($result->get('delivered_at') === true) {
            $sets[] = '`delivered_at` = :delivered_at';
            $params['delivered_at'] = $now;
        }
        if ($result->get('received_at') === true) {
            $sets[] = '`received_at` = :received_at';
            $params['received_at'] = $now;
        }

        $sql = sprintf(
            'UPDATE `veg_order_delivery` SET %s WHERE `order_id` = :order_id AND `version` = :expected_version',
            implode(', ', $sets)
        );
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        if ($stmt->rowCount() === 0) {
            // 区分「版本冲突」与「扩展行缺失」—— 两者的处置方式不同
            $exists = $this->queryOne(
                'SELECT `version` FROM `veg_order_delivery` WHERE `order_id` = :id LIMIT 1',
                ['id' => $order->id]
            );

            if ($exists === null) {
                throw new \RuntimeException(sprintf(
                    '订单 %s 缺少 veg_order_delivery 扩展行，无法应用迁移。'
                    . '该订单可能创建于插件安装之前，需先回填扩展行。',
                    $order->id
                ));
            }

            throw new VersionConflictException(
                $order->id,
                $expectedVersion,
                (int) $exists['version']
            );
        }

        // ---- 金额：写回 ShopXO 原生表（decimal），需做 fen → decimal 转换 ----
        $paidFen = $result->get('paid_fen');
        if ($paidFen !== null) {
            $stmt = $this->pdo->prepare(sprintf(
                'UPDATE `%sorder` SET `pay_price` = :pay_price, `upd_time` = :upd_time WHERE `id` = :id',
                $this->tablePrefix
            ));
            $stmt->execute([
                'pay_price' => OrderRowMapper::fenToDecimal((int) $paidFen),
                'upd_time' => time(),
                'id' => $order->id,
            ]);
        }
    }

    /**
     * 写入 COD 收款流水。
     *
     * 【幂等靠数据库唯一约束兜底，不靠先查后写】
     * `veg_payment_ledger` 上有 `uk_business_no` 与 `uk_idem_key` 两个唯一索引。
     * 并发下「先 SELECT 再 INSERT」会两个请求都通过检查然后都插入；
     * 直接 INSERT 并捕获唯一键冲突才是可靠的。
     *
     * 已存在时返回既有流水 ID（视作成功重放），不抛异常 ——
     * 这正是幂等的语义：同一请求重复到达，结果应当相同。
     */
    public function writeCodReceipt(
        string $orderId,
        int $amountFen,
        CollectionMethod $method,
        string $operatorId,
        string $idempotencyKey,
    ): int {
        $businessNo = sprintf('COD%s%06d', date('YmdHis'), random_int(0, 999999));
        $now = date('Y-m-d H:i:s');

        $sql = 'INSERT INTO `veg_payment_ledger` '
            . '(`order_id`, `kind`, `amount_fen`, `status`, `business_no`, `collection_method`, '
            . '`collected_fen`, `idempotency_key`, `operator_id`, `occurred_at`, `created_at`, `updated_at`) '
            . 'VALUES (:order_id, :kind, :amount_fen, :status, :business_no, :collection_method, '
            . ':collected_fen, :idempotency_key, :operator_id, :occurred_at, :created_at, :updated_at)';

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                'order_id' => $orderId,
                'kind' => LedgerKind::COD_RECEIPT->value,
                'amount_fen' => $amountFen,
                'status' => 'SUCCESS',
                'business_no' => $businessNo,
                'collection_method' => $method->value,
                'collected_fen' => $amountFen,
                'idempotency_key' => $idempotencyKey,
                'operator_id' => $operatorId === '' ? null : $operatorId,
                'occurred_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return (int) $this->pdo->lastInsertId();
        } catch (\PDOException $e) {
            // 23000 = 违反完整性约束（唯一键冲突）
            if ($e->getCode() !== '23000') {
                throw $e;
            }

            $existing = $this->queryOne(
                'SELECT `id` FROM `veg_payment_ledger` WHERE `idempotency_key` = :key LIMIT 1',
                ['key' => $idempotencyKey]
            );
            if ($existing !== null) {
                // 同一幂等键重放 —— 返回既有流水，不重复记账
                return (int) $existing['id'];
            }

            $existing = $this->queryOne(
                'SELECT `id` FROM `veg_payment_ledger` WHERE `business_no` = :no LIMIT 1',
                ['no' => $businessNo]
            );
            if ($existing !== null) {
                return (int) $existing['id'];
            }

            // 其他唯一键（如 provider_trade_no）冲突 —— 不是幂等重放，应当暴露
            throw $e;
        }
    }

    /** 写审计日志。只增不改，禁止前台删除。 */
    public function writeAuditLog(string $actorId, string $orderId, string $action, array $detail): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO `veg_operation_log` '
            . '(`actor_type`, `actor_id`, `order_id`, `action`, `detail_json`, `request_id`, `ip`, `created_at`) '
            . 'VALUES (:actor_type, :actor_id, :order_id, :action, :detail_json, :request_id, :ip, :created_at)'
        );

        $stmt->execute([
            'actor_type' => (string) ($detail['actor_type'] ?? 'MERCHANT'),
            'actor_id' => $actorId === '' ? null : $actorId,
            'order_id' => $orderId === '' ? null : $orderId,
            'action' => $action,
            'detail_json' => json_encode(
                array_diff_key($detail, ['actor_type' => null]),
                JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            ),
            'request_id' => $detail['request_id'] ?? null,
            'ip' => $detail['ip'] ?? null,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** 写入 outbox 事件（须与业务写入同事务）。 */
    public function enqueueEvent(string $eventType, ?string $entityId, array $payload): void
    {
        $now = date('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'INSERT INTO `veg_outbox_event` '
            . '(`event_type`, `entity_id`, `payload`, `status`, `retry_count`, `next_retry_at`, `created_at`, `updated_at`) '
            . 'VALUES (:event_type, :entity_id, :payload, :status, 0, :next_retry_at, :created_at, :updated_at)'
        );

        $stmt->execute([
            'event_type' => $eventType,
            'entity_id' => $entityId === null || $entityId === '' ? null : $entityId,
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'status' => 'PENDING',
            'next_retry_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    // =========================================================
    // 查询
    // =========================================================

    /** @return array<int,array{kind:string,amount_fen:int,status:string,occurred_at:string}> */
    public function ledgerEntries(string $orderId): array
    {
        $rows = $this->queryAll(
            'SELECT `kind`, `business_no`, `amount_fen`, `status`, `occurred_at` FROM `veg_payment_ledger` '
            . 'WHERE `order_id` = :id ORDER BY `id` ASC',
            ['id' => $orderId]
        );

        return array_map(static fn (array $r): array => [
            'kind' => (string) $r['kind'],
            'business_no' => (string) $r['business_no'],
            'amount_fen' => (int) $r['amount_fen'],
            'status' => (string) $r['status'],
            'occurred_at' => (string) $r['occurred_at'],
        ], $rows);
    }

    /**
     * COD 已送达未核销的订单。
     *
     * 这是商家每天必须清零的清单。
     *
     * 【为什么不用 ShopXO 的 status】
     * ShopXO 的 `status=4` 同时涵盖「已送达」与「已完成」，
     * 用它筛不出「已送达但未收款」。必须用我们自己的四维状态。
     */
    public function findCodReceivables(): array
    {
        $sql = sprintf(
            'SELECT o.`id`, o.`order_no`, o.`total_price`, o.`pay_price`, o.`add_time`, '
            // 地址表用 `tel` 而非 `phone`（见 OrderRowMapper 的说明）
            . 'd.`delivered_at`, a.`name`, a.`tel` '
            . 'FROM `veg_order_delivery` d '
            . 'INNER JOIN `%sorder` o ON o.`id` = d.`order_id` '
            . 'LEFT JOIN `%sorder_address` a ON a.`order_id` = d.`order_id` '
            . 'WHERE d.`payment_method` = :method '
            . "AND d.`fulfillment` IN ('DELIVERED', 'FINISHED') "
            . 'AND d.`payment_state` = :payment_state '
            . 'ORDER BY d.`delivered_at` ASC, o.`id` ASC',
            $this->tablePrefix,
            $this->tablePrefix
        );

        $rows = $this->queryAll($sql, [
            'method' => 'COD',
            'payment_state' => 'UNPAID',
        ]);

        return array_map(function (array $r): array {
            $totalFen = OrderRowMapper::decimalToFen($r['total_price'] ?? 0);
            $paidFen = OrderRowMapper::decimalToFen($r['pay_price'] ?? 0);
            $receivable = max(0, $totalFen - $paidFen);

            $phone = Contact::normalizePhone((string) ($r['tel'] ?? ''));

            return [
                'order_id' => (string) $r['id'],
                'order_no' => (string) $r['order_no'],
                'name' => (string) ($r['name'] ?? ''),
                // 列表一律脱敏：这是给商家看对账清单的，不需要完整号码
                'masked_phone' => strlen($phone) >= 7
                    ? substr($phone, 0, 3) . '****' . substr($phone, -4)
                    : '****',
                'receivable_fen' => $receivable,
                'delivered_at' => $r['delivered_at'] === null ? null : (string) $r['delivered_at'],
                'created_at' => OrderRowMapper::toDateTime($r['add_time'] ?? null)?->format(DATE_ATOM) ?? '',
            ];
        }, $rows);
    }

    /**
     * 按订单汇总「处理中」的退款金额。
     *
     * 单独查询的理由：`veg_payment_ledger` 是资金真源，
     * 而处理中的退款并不体现在订单主表的任何列上。
     * 不补这一项，可退余额会偏大 → 存在超退风险（ADR-005）。
     */
    public function refundingFen(string $orderId): int
    {
        $row = $this->queryOne(
            "SELECT COALESCE(SUM(ABS(`amount_fen`)), 0) AS `total` FROM `veg_payment_ledger` "
            . "WHERE `order_id` = :id AND `kind` IN ('WX_REFUND', 'OFFLINE_REFUND') AND `status` IN ('PROCESSING', 'PENDING')",
            ['id' => $orderId]
        );

        return (int) ($row['total'] ?? 0);
    }

    // =========================================================
    // 内部
    // =========================================================

    private function withRefundingFen(Order $order): Order
    {
        $refunding = $this->refundingFen($order->id);
        if ($refunding > 0) {
            $order->refundingFen = $refunding;
        }

        return $order;
    }

    /** @param array<string,mixed> $params */
    private function queryOne(string $sql, array $params): ?array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * @param array<string,mixed> $params
     * @return array<int,array<string,mixed>>
     */
    private function queryAll(string $sql, array $params): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
}
