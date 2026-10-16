<?php

declare(strict_types=1);

namespace Chunlian\Service;

use Chunlian\Domain\CollectionMethod;
use Chunlian\Domain\LedgerKind;
use Chunlian\Domain\Order;
use Chunlian\Domain\PaymentMethod;
use Chunlian\Domain\RejectionCode;
use Chunlian\Repository\OrderRowMapper;
use Chunlian\Repository\PdoOrderRepository;
use Chunlian\Repository\VersionConflictException;

/**
 * 商家端订单应用服务。
 *
 * 【职责边界】
 * 本类只做**编排**：事务边界、调用状态机、写流水/审计/outbox、序列化响应。
 * 业务规则在 `OrderStateMachine`（纯函数），持久化在 `PdoOrderRepository`。
 * 规则因此可以脱离数据库单测，事务语义则集中在一处便于审查。
 *
 * 【事务边界为什么必须在这里】
 * 「加行锁读取 → 状态机判断 → 写回」三者必须在**同一事务**内。
 * 若由 Repository 各自开事务，行锁会在方法返回时释放，
 * 乐观锁就退化成「先读后写」的竞态 —— 并发下会错版本发货。
 *
 * 【返回信封】
 * 所有方法统一返回 `['code'=>string, 'message'=>?string, 'data'=>mixed]`。
 * `code === 'OK'` 表示成功，其余为 `RejectionCode` 取值。
 * 与客户端 `ApiEnvelope` 契约一致。
 */
final class MerchantOrderService
{
    public function __construct(
        private readonly \PDO $pdo,
        private readonly PdoOrderRepository $orders,
        private readonly OrderStateMachine $stateMachine,
        private readonly IdempotencyService $idempotency,
        private readonly LedgerCalculator $ledger,
        /**
         * ShopXO 表前缀（如 `shopxo_`）。
         *
         * 【为什么由外部注入而不是自己去读 ShopXO 配置】
         * 领域层必须保持**零框架依赖** —— 不 import `app\`、不读 ShopXO 的
         * `config/database.php`。否则领域层就无法脱离 ShopXO 单独测试，
         * 也就失去了分层的意义。
         */
        private readonly string $tablePrefix = 'shopxo_',
    ) {
    }

    // =========================================================
    // 写操作
    // =========================================================

    /** 商家点击「开始配送」。 */
    public function startDelivery(string $orderId, string $actorId, int $clientVersion, string $idemKey): array
    {
        return $this->write(
            $orderId,
            $actorId,
            'startDelivery',
            $idemKey,
            ['version' => $clientVersion],
            function (Order $order) use ($clientVersion): array {
                $result = $this->stateMachine->startDelivery($order, $clientVersion);
                if (!$result->success) {
                    return $this->rejected($result);
                }

                return $this->applied($result, 'DISPATCH', 'ORDER_DISPATCHED', '已开始配送');
            }
        );
    }

    /**
     * 商家「确认送达」（合并 COD 收款）。
     *
     * 【不变量】在线已付款订单在送达时绝不能产生「收款」记录。
     * 状态机已拒绝该组合（WECHAT + receivedMoney），这里再断言一次 ——
     * 重复入账是真实的资金差错，值得一道冗余防线。
     */
    public function confirmDelivery(
        string $orderId,
        string $actorId,
        int $clientVersion,
        bool $collectedOnDelivery,
        ?string $collectionMethod,
        ?int $collectedFen,
        string $idemKey,
    ): array {
        return $this->write(
            $orderId,
            $actorId,
            'confirmDelivery',
            $idemKey,
            [
                'version' => $clientVersion,
                'collected_on_delivery' => $collectedOnDelivery,
                'collection_method' => $collectionMethod,
                'collected_fen' => $collectedFen,
            ],
            function (Order $order) use (
                $clientVersion,
                $collectedOnDelivery,
                $collectionMethod,
                $collectedFen,
                $actorId,
                $idemKey
            ): array {
                if ($collectedOnDelivery && ($collectionMethod === null || $collectionMethod === '')) {
                    return $this->rejectedWith(RejectionCode::VALIDATION_FAILED, '已选择收款时必须指定收款方式');
                }

                // 金额以服务端计算为准：客户端没传就用应收金额。
                // 不让客户端决定收多少，否则本地数据稍旧就会少收/多收却显示成功。
                $amount = $collectedOnDelivery
                    ? ($collectedFen ?? $order->receivable()->fen())
                    : null;

                $result = $this->stateMachine->confirmDelivery(
                    $order,
                    $clientVersion,
                    $collectedOnDelivery,
                    $amount
                );
                if (!$result->success) {
                    return $this->rejected($result);
                }

                if ($collectedOnDelivery && $order->paymentMethod !== PaymentMethod::COD) {
                    throw new \LogicException('状态机放行了在线订单的收款，违反不变量');
                }

                $shouldWriteLedger = $collectedOnDelivery && $order->paymentMethod === PaymentMethod::COD;

                $envelope = $this->applied(
                    $result,
                    'DELIVER',
                    'ORDER_DELIVERED',
                    $collectedOnDelivery
                        ? '已送达并登记收款'
                        : ($order->paymentMethod === PaymentMethod::COD && $order->receivable()->fen() > 0
                            ? '已送达（货款未收，已记入应收）'
                            : '已送达（货款已结清）')
                );

                if ($shouldWriteLedger) {
                    $envelope['ledger'] = [
                        'amount_fen' => (int) $amount,
                        'method' => CollectionMethod::from((string) $collectionMethod),
                        'operator_id' => $actorId,
                        'idem_key' => $idemKey,
                    ];
                }

                return $envelope;
            }
        );
    }

    /** 送达后补记 COD 收款（纠错入口，必写审计）。 */
    public function recordCodReceipt(
        string $orderId,
        string $actorId,
        int $clientVersion,
        int $collectedFen,
        string $collectionMethod,
        string $idemKey,
    ): array {
        return $this->write(
            $orderId,
            $actorId,
            'recordCodReceipt',
            $idemKey,
            [
                'version' => $clientVersion,
                'collected_fen' => $collectedFen,
                'collection_method' => $collectionMethod,
            ],
            function (Order $order) use (
                $clientVersion,
                $collectedFen,
                $collectionMethod,
                $actorId,
                $idemKey
            ): array {
                $result = $this->stateMachine->recordCodReceipt($order, $clientVersion, $collectedFen);
                if (!$result->success) {
                    return $this->rejected($result);
                }

                $envelope = $this->applied($result, 'COD_RECEIPT', 'COD_RECEIPT_RECORDED', '已补记收款');
                $envelope['ledger'] = [
                    'amount_fen' => $collectedFen,
                    'method' => CollectionMethod::from($collectionMethod),
                    'operator_id' => $actorId,
                    'idem_key' => $idemKey,
                ];

                return $envelope;
            }
        );
    }

    /** 取消订单。 */
    public function cancel(
        string $orderId,
        string $actorId,
        int $clientVersion,
        ?string $reason,
        string $idemKey,
    ): array {
        return $this->write(
            $orderId,
            $actorId,
            'cancel',
            $idemKey,
            ['version' => $clientVersion, 'reason' => $reason],
            function (Order $order) use ($clientVersion, $reason): array {
                $result = $this->stateMachine->cancel($order, $clientVersion);
                if (!$result->success) {
                    return $this->rejected($result);
                }

                $refundFen = (int) $result->get('refund_fen', 0);

                $envelope = $this->applied(
                    $result,
                    'CANCEL',
                    'ORDER_CANCELLED',
                    $refundFen > 0
                        ? sprintf('订单已取消，将退还 %s 元', number_format($refundFen / 100, 2, '.', ''))
                        : '订单已取消'
                );

                if ($refundFen > 0) {
                    // 排一条退款查询任务，由 cron worker 调微信退款接口并轮询结果
                    $envelope['extra_outbox'] = ['REFUND_QUERY', [
                        'refund_fen' => $refundFen,
                        'reason' => $reason,
                    ]];
                }

                return $envelope;
            }
        );
    }

    // =========================================================
    // 读操作
    // =========================================================

    public function orderDetail(string $orderId, string $actorId): array
    {
        $order = $this->orders->find($orderId);
        if ($order === null) {
            return $this->fail(RejectionCode::NOT_FOUND, '订单不存在');
        }

        $detail = $this->serializeOrder($order);
        // This endpoint is reachable only after merchant authentication. A
        // delivery operator needs the real number to dial/copy contact details;
        // list, dashboard and notification payloads remain masked.
        $detail['contact']['phone'] = $order->contact->phone;

        return $this->ok($detail);
    }

    /**
     * 订单列表。
     *
     * @param string|null $status 履约筛选；DELIVERED 同时包含已送达和已完成
     */
    public function listOrders(
        ?string $status,
        ?string $keyword,
        int $page,
        int $pageSize,
        string $sort = 'DEFAULT',
    ): array {
        $page = max(1, $page);
        $pageSize = min(100, max(1, $pageSize));
        $sort = strtoupper(trim($sort));
        if (!in_array($sort, ['DEFAULT', 'EXPECTED_AT'], true)) {
            $sort = 'DEFAULT';
        }

        ['where_sql' => $whereSql, 'params' => $params] = MerchantOrderListFilter::build($status, $keyword);

        $p = $this->tablePrefix;

        $countStmt = $this->pdo->prepare(
            "SELECT COUNT(*) AS c FROM `veg_order_delivery` d "
            . "INNER JOIN `{$p}order` o ON o.`id` = d.`order_id` "
            . "LEFT JOIN `{$p}order_address` a ON a.`order_id` = d.`order_id` "
            . "WHERE {$whereSql}"
        );
        $countStmt->execute($params);
        $total = (int) ($countStmt->fetch(\PDO::FETCH_ASSOC)['c'] ?? 0);

        $offset = ($page - 1) * $pageSize;
        $orderBy = $sort === 'EXPECTED_AT'
            // ASAP orders have expected_at = NULL and are shown before scheduled orders.
            ? "(d.`expected_at` IS NOT NULL) ASC, d.`expected_at` ASC, FIELD(d.`fulfillment`, 'PENDING','DELIVERING','DELIVERED','FINISHED','CANCELLED') ASC, d.`order_id` DESC"
            : "FIELD(d.`fulfillment`, 'PENDING','DELIVERING','DELIVERED','FINISHED','CANCELLED') ASC, d.`order_id` DESC";
        $stmt = $this->pdo->prepare(
            "SELECT d.`order_id` FROM `veg_order_delivery` d "
            . "INNER JOIN `{$p}order` o ON o.`id` = d.`order_id` "
            . "LEFT JOIN `{$p}order_address` a ON a.`order_id` = d.`order_id` "
            . "WHERE {$whereSql} "
            // Default keeps the fulfillment priority; delivery-time sorting is global and page-safe.
            . "ORDER BY {$orderBy} "
            . "LIMIT {$pageSize} OFFSET {$offset}"
        );
        $stmt->execute($params);

        $list = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $order = $this->orders->find((string) $row['order_id']);
            if ($order !== null) {
                $list[] = $this->serializeOrder($order);
            }
        }

        return $this->ok([
            'list' => $list,
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
        ]);
    }

    /** 首页统计。 */
    public function dashboard(): array
    {
        $p = $this->tablePrefix;

        $counts = $this->pdo->query(
            "SELECT "
            . "SUM(d.`fulfillment` = 'PENDING') AS pending_count, "
            . "SUM(d.`fulfillment` = 'DELIVERING') AS delivering_count, "
            . "SUM(d.`fulfillment` IN ('DELIVERED','FINISHED') AND d.`payment_method` = 'COD' AND d.`payment_state` = 'UNPAID') AS uncollected_count, "
            . "SUM(d.`revision_state` = 'PENDING_TOPUP') AS topup_count, "
            . "SUM(d.`revision_state` = 'APPLYING' OR d.`payment_state` = 'REFUNDING') AS refund_count "
            . "FROM `veg_order_delivery` d"
        )->fetch(\PDO::FETCH_ASSOC) ?: [];

        // 今日实收：按**资金发生日**归集，只统计 SUCCESS
        $today = date('Y-m-d');
        $stmt = $this->pdo->prepare(
            "SELECT `kind`, `amount_fen`, `status`, `occurred_at` FROM `veg_payment_ledger` "
            . "WHERE `status` = 'SUCCESS' AND DATE(`occurred_at`) = :d"
        );
        $stmt->execute(['d' => $today]);
        $summary = $this->ledger->dailySummary(
            $this->normalizeLedgerRows($stmt->fetchAll(\PDO::FETCH_ASSOC))
        );

        // 今日订单数：按**下单日**统计，与「今日实收」的资金发生日口径不同，
        // 两者不能互相推导，因此分别提供（见 docs/API.md §4 统计口径）
        $orderCountStmt = $this->pdo->prepare(
            "SELECT COUNT(*) AS c FROM `{$p}order` WHERE DATE(FROM_UNIXTIME(`add_time`)) = :d "
            . 'AND `is_delete_time` = 0'
        );
        $orderCountStmt->execute(['d' => $today]);
        $todayOrderCount = (int) ($orderCountStmt->fetch(\PDO::FETCH_ASSOC)['c'] ?? 0);

        $orderGroups = $this->recentDashboardOrderGroups($today, 5);
        $recentActionable = [];
        $seenOrderIds = [];
        foreach (['pending_orders', 'delivering_orders', 'uncollected_orders'] as $groupName) {
            foreach ($orderGroups[$groupName] as $order) {
                $orderId = (string) $order['order_id'];
                if (isset($seenOrderIds[$orderId])) {
                    continue;
                }
                $seenOrderIds[$orderId] = true;
                $recentActionable[] = $order;
            }
        }

        return $this->ok([
            'pending_count' => (int) ($counts['pending_count'] ?? 0),
            'delivering_count' => (int) ($counts['delivering_count'] ?? 0),
            'delivered_uncollected_count' => (int) ($counts['uncollected_count'] ?? 0),
            'pending_topup_count' => (int) ($counts['topup_count'] ?? 0),
            'pending_refund_count' => (int) ($counts['refund_count'] ?? 0),
            'today_received_fen' => (int) $summary['received_fen'],
            // 在线与 COD 分列：方案 §4 要求「实收」必须能拆开看，
            // 否则店主无法判断「今天到底收了多少现金」
            'today_wechat_received_fen' => (int) $summary['online_fen'],
            'today_cod_received_fen' => (int) $summary['cod_fen'],
            'today_offline_qr_received_fen' => (int) $summary['offline_qr_fen'],
            'today_refund_fen' => (int) $summary['refund_fen'],
            'today_order_count' => $todayOrderCount,
            'today_receivable_fen' => $this->receivableTotalFen(),
            'recent_orders' => array_slice($recentActionable, 0, 5),
            ...$orderGroups,
        ]);
    }

    /**
     * 工作台各项指标对应的最近订单。
     *
     * 今日订单按下单日；待配送、配送中和未收款分别与工作台计数采用相同口径。
     *
     * @return array<string,array<int,array<string,mixed>>>
     */
    private function recentDashboardOrderGroups(string $today, int $limit): array
    {
        $p = $this->tablePrefix;
        $limit = max(1, min(20, $limit));
        $querySpecs = [
            'today_orders' => [
                'join' => "INNER JOIN `{$p}order` o ON o.`id` = d.`order_id`",
                'where' => "DATE(FROM_UNIXTIME(o.`add_time`)) = :today AND o.`is_delete_time` = 0",
                'order' => 'o.`add_time` DESC, d.`order_id` DESC',
                'params' => ['today' => $today],
            ],
            'pending_orders' => [
                'join' => '',
                'where' => "d.`fulfillment` = 'PENDING'",
                'order' => 'd.`order_id` DESC',
                'params' => [],
            ],
            'delivering_orders' => [
                'join' => '',
                'where' => "d.`fulfillment` = 'DELIVERING'",
                'order' => 'd.`order_id` DESC',
                'params' => [],
            ],
            'uncollected_orders' => [
                'join' => '',
                'where' => "d.`fulfillment` IN ('DELIVERED','FINISHED') AND d.`payment_method` = 'COD' AND d.`payment_state` = 'UNPAID'",
                'order' => 'd.`order_id` DESC',
                'params' => [],
            ],
        ];

        $idsByGroup = [];
        $uniqueOrderIds = [];
        foreach ($querySpecs as $groupName => $spec) {
            $stmt = $this->pdo->prepare(
                "SELECT d.`order_id` FROM `veg_order_delivery` d {$spec['join']} "
                . "WHERE {$spec['where']} ORDER BY {$spec['order']} LIMIT {$limit}"
            );
            $stmt->execute($spec['params']);
            $ids = array_map(
                static fn (array $row): string => (string) $row['order_id'],
                $stmt->fetchAll(\PDO::FETCH_ASSOC),
            );
            $idsByGroup[$groupName] = $ids;
            foreach ($ids as $id) {
                $uniqueOrderIds[$id] = true;
            }
        }

        $summariesById = [];
        foreach (array_keys($uniqueOrderIds) as $id) {
            $order = $this->orders->find((string) $id);
            if ($order !== null) {
                $summariesById[(string) $id] = $this->serializeDashboardOrder($order);
            }
        }

        $groups = [];
        foreach ($idsByGroup as $groupName => $ids) {
            $groups[$groupName] = array_values(array_filter(array_map(
                static fn (string $id): ?array => $summariesById[$id] ?? null,
                $ids,
            )));
        }

        return $groups;
    }

    /** 最近订单使用精简 DTO，列表和详情仍使用完整订单 DTO。 */
    private function serializeDashboardOrder(Order $order): array
    {
        $serialized = $this->serializeOrder($order);

        return [
            'order_id' => $serialized['id'],
            'order_no' => $serialized['order_no'],
            'summary' => $this->lineSummary($order),
            'fulfillment' => $serialized['fulfillment'],
            'payment_state' => $serialized['payment_state'],
            'payment_method' => $serialized['payment_method'],
            'total_fen' => $serialized['total_fen'],
            'receivable_fen' => $serialized['receivable_fen'],
            'delivery_mode' => $serialized['delivery_mode'],
            'expected_at' => $serialized['expected_at'],
            'contact_name' => $serialized['contact']['name'],
            'masked_phone' => $serialized['contact']['masked_phone'],
            'address' => $serialized['contact']['address'],
            'revision_state' => $serialized['revision_state'],
        ];
    }

    /** 「青菜×2 / 土豆×1」这类一行摘要，供列表卡片显示。 */
    private function lineSummary(Order $order): string
    {
        $parts = [];
        foreach (array_slice($order->lines, 0, 3) as $line) {
            $parts[] = sprintf('%s×%d', $line->nameSnapshot, $line->qty);
        }

        $text = implode(' / ', $parts);
        if (count($order->lines) > 3) {
            $text .= sprintf(' 等 %d 种', count($order->lines));
        }

        return mb_substr($text, 0, 80);
    }

    /** 应收款清单。 */
    public function receivables(): array
    {
        return $this->ok($this->orders->findCodReceivables());
    }

    /** 当日资金汇总。 */
    public function dailySettlement(?string $date): array
    {
        $businessDate = ($date === null || $date === '') ? date('Y-m-d') : $date;

        $stmt = $this->pdo->prepare(
            "SELECT `kind`, `amount_fen`, `status`, `occurred_at` FROM `veg_payment_ledger` "
            . "WHERE DATE(`occurred_at`) = :d"
        );
        $stmt->execute(['d' => $businessDate]);
        $summary = $this->ledger->dailySummary(
            $this->normalizeLedgerRows($stmt->fetchAll(\PDO::FETCH_ASSOC)),
            $businessDate
        );

        $p = $this->tablePrefix;
        $stmt = $this->pdo->prepare(
            "SELECT COALESCE(SUM(o.`total_price` - o.`pay_price`), 0) AS receivable, COUNT(*) AS c "
            . "FROM `veg_order_delivery` d "
            . "INNER JOIN `{$p}order` o ON o.`id` = d.`order_id` "
            . "WHERE d.`payment_method` = 'COD' AND d.`fulfillment` IN ('DELIVERED','FINISHED') AND d.`payment_state` = 'UNPAID'"
        );
        $stmt->execute();
        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];

        return $this->ok([
            'date' => $businessDate,
            'received_wechat_fen' => (int) $summary['online_fen'],
            'received_cod_fen' => (int) $summary['cod_fen'],
            'received_offline_qr_fen' => (int) $summary['offline_qr_fen'],
            'refunded_fen' => (int) $summary['refund_fen'],
            // 今日实收 = 在线入账 + COD 实收 − 退款
            'received_total_fen' => (int) $summary['received_fen'],
            // 应收是**独立口径**，绝不与实收相加成一个「今日金额」
            'receivable_fen' => OrderRowMapper::decimalToFen($row['receivable'] ?? 0),
            'order_count' => (int) ($row['c'] ?? 0),
        ]);
    }

    // =========================================================
    // 事务编排
    // =========================================================

    /**
     * 写操作通用流程：幂等包装 + 事务 + 落库。
     *
     * @param callable(Order):array $handler 返回由 [applied]/[rejected] 构造的信封
     */
    private function write(
        string $orderId,
        string $actorId,
        string $endpoint,
        string $idemKey,
        array $requestPayload,
        callable $handler,
    ): array {
        try {
            $outer = $this->idempotency->run(
                $actorId,
                $endpoint,
                $idemKey,
                $requestPayload,
                fn (): array => $this->transactional($orderId, $actorId, $endpoint, $handler)
            );
        } catch (VersionConflictException $e) {
            return $this->fail(
                RejectionCode::ORDER_VERSION_CONFLICT,
                $e->getMessage(),
                ['current_version' => $e->actualVersion]
            );
        }

        // run() 自身判定的冲突（同 key 不同请求体）
        if ($outer['code'] !== 'OK') {
            return [
                'code' => $outer['code'],
                'message' => $outer['data']['message'] ?? RejectionCode::IDEMPOTENCY_CONFLICT->defaultMessage(),
                'data' => null,
            ];
        }

        // 内层信封由 transactional() 产出
        $inner = $outer['data'];
        if (is_array($inner) && isset($inner['code'])) {
            return $inner;
        }

        return $this->ok($inner);
    }

    /**
     * 真正落库的部分，全部在一个事务内。
     *
     * 【拒绝也要提交事务】
     * 业务拒绝（如「待补款不能配送」）是一个**确定结果**。
     * 提交它，幂等键才能记住「这个请求已被处理并拒绝」——
     * 客户端重试时会拿到同样的拒绝，而不是重新执行一次。
     */
    private function transactional(string $orderId, string $actorId, string $endpoint, callable $handler): array
    {
        $this->pdo->beginTransaction();

        try {
            $order = $this->orders->findForUpdate($orderId, $actorId);
            if ($order === null) {
                $this->pdo->commit();

                return $this->fail(RejectionCode::NOT_FOUND, '订单不存在');
            }

            $outcome = $handler($order);

            // ---- 拒绝路径 ----
            if (($outcome['rejected'] ?? false) === true) {
                $this->pdo->commit();

                return $outcome['envelope'];
            }

            /** @var TransitionResult $transition */
            $transition = $outcome['transition'];

            // 1. 四维状态 + version（乐观锁 CAS）
            $this->orders->applyTransition($order, $transition);

            // 2. 资金流水（幂等由唯一约束兜底，不靠先查后写）
            if (!empty($outcome['ledger'])) {
                $l = $outcome['ledger'];
                $this->orders->writeCodReceipt(
                    $orderId,
                    (int) $l['amount_fen'],
                    $l['method'],
                    (string) $l['operator_id'],
                    (string) $l['idem_key']
                );
            }

            // 3. 审计（只增，禁止前台删除）
            $this->orders->writeAuditLog($actorId, $orderId, (string) $outcome['audit_action'], [
                'actor_type' => 'MERCHANT',
                'endpoint' => $endpoint,
                'message' => $outcome['message'],
                'from_version' => $order->version,
                'to_version' => $order->version + 1,
            ]);

            // 4. outbox
            $this->orders->enqueueEvent((string) $outcome['outbox'], $orderId, [
                'order_id' => $orderId,
                'message' => $outcome['message'],
            ]);
            if (!empty($outcome['extra_outbox'])) {
                [$type, $payload] = $outcome['extra_outbox'];
                $this->orders->enqueueEvent((string) $type, $orderId, $payload);
            }

            // 5. 回读最新状态返回（让客户端拿到权威的 version 与金额）
            $fresh = $this->orders->find($orderId);

            $this->pdo->commit();

            return $this->ok(
                $this->serializeOrder($fresh ?? $order),
                $outcome['message']
            );
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    // =========================================================
    // 信封构造
    // =========================================================

    private function ok(mixed $data, ?string $message = null): array
    {
        return ['code' => 'OK', 'message' => $message, 'data' => $data];
    }

    private function fail(RejectionCode $code, string $message, mixed $data = null): array
    {
        return ['code' => $code->value, 'message' => $message, 'data' => $data];
    }

    /** 构造成功载荷（供 transactional 使用）。 */
    private function applied(TransitionResult $result, string $auditAction, string $outbox, string $message): array
    {
        return [
            'rejected' => false,
            'transition' => $result,
            'audit_action' => $auditAction,
            'outbox' => $outbox,
            'message' => $message,
            'ledger' => null,
            'extra_outbox' => null,
        ];
    }

    /** 构造拒绝载荷（供 transactional 使用）。 */
    private function rejected(TransitionResult $result): array
    {
        return [
            'rejected' => true,
            'envelope' => [
                'code' => $result->code?->value ?? RejectionCode::VALIDATION_FAILED->value,
                'message' => $result->message ?? '操作被拒绝',
                'data' => $result->data,
            ],
        ];
    }

    private function rejectedWith(RejectionCode $code, string $message): array
    {
        return [
            'rejected' => true,
            'envelope' => ['code' => $code->value, 'message' => $message, 'data' => null],
        ];
    }

    // =========================================================
    // 序列化
    // =========================================================

    /**
     * 领域对象 → 客户端 DTO。
     *
     * 【契约】金额字段一律以 `_fen` 结尾且为整数。
     * 不在此处格式化成「元」—— 格式化是客户端的职责；
     * 服务端输出原始整数分才能避免二次换算误差。
     */
    private function serializeOrder(Order $order): array
    {
        $pending = $this->pdo->prepare("SELECT `delta_fen` FROM `veg_order_revision` WHERE `order_id` = :oid AND `status` = 'PENDING_TOPUP' ORDER BY `id` DESC LIMIT 1");
        $pending->execute(['oid' => $order->id]);
        $refunds = $this->pdo->prepare("SELECT `id`, `revision_id`, `amount_fen`, `business_no` FROM `veg_payment_ledger` WHERE `order_id` = :oid AND `kind` = 'OFFLINE_REFUND' AND `status` = 'PROCESSING' ORDER BY (`revision_id` IS NULL) ASC, `id` ASC");
        $refunds->execute(['oid' => $order->id]);
        return [
            'id' => $order->id,
            'order_no' => $order->orderNo,
            'version' => $order->version,
            'fulfillment' => $order->fulfillment->value,
            'payment_state' => $order->paymentState->value,
            'receipt_state' => $order->receiptState->value,
            'revision_state' => $order->revisionState->value,
            'can_start_delivery' => $this->stateMachine->startDelivery($order, $order->version)->success,
            'pending_topup_fen' => (int) ($pending->fetchColumn() ?: 0),
            'pending_manual_refunds' => array_map(static fn (array $r): array => [
                'id' => (string) $r['id'], 'revision_id' => $r['revision_id'] === null ? null : (string) $r['revision_id'],
                'amount_fen' => abs((int) $r['amount_fen']), 'business_no' => (string) $r['business_no'],
            ], $refunds->fetchAll(\PDO::FETCH_ASSOC)),
            'payment_method' => $order->paymentMethod->value,
            'delivery_mode' => $order->deliveryMode->value,
            'expected_at' => $order->expectedAt?->format(DATE_ATOM),
            'lines' => array_map(static fn ($line): array => [
                'sku_id' => $line->skuId,
                'product_id' => $line->productId,
                'name_snapshot' => $line->nameSnapshot,
                'spec_snapshot' => $line->specSnapshot,
                'image_snapshot' => $line->imageSnapshot,
                'unit_fen' => $line->unitFen,
                'qty' => $line->qty,
                'subtotal_fen' => $line->subtotal()->fen(),
            ], $order->lines),
            'contact' => [
                'name' => $order->contact->name,
                // 默认脱敏；只有已鉴权的订单详情单独补充完整履约电话。
                'masked_phone' => $order->contact->maskedPhone(),
                'address' => $order->contact->address,
            ],
            'goods_total_fen' => $order->goodsTotal()->fen(),
            'delivery_fee_fen' => $order->deliveryFeeFen,
            'total_fen' => $order->total()->fen(),
            'paid_fen' => $order->paidFen,
            'refunded_fen' => $order->refundedFen,
            'refunding_fen' => $order->refundingFen,
            'receivable_fen' => max(0, $order->receivable()->fen()),
            'refundable_fen' => $order->refundable()->fen(),
            'revision_delta_fen' => 0,
            'created_at' => $order->deliveredAt?->format(DATE_ATOM) ?? '',
            'remark' => $order->remark,
        ];
    }

    // =========================================================
    // 内部工具
    // =========================================================

    /** @param array<int,array<string,mixed>> $rows */
    private function normalizeLedgerRows(array $rows): array
    {
        return array_map(static fn (array $r): array => [
            'kind' => (string) $r['kind'],
            'amount_fen' => (int) $r['amount_fen'],
            'status' => (string) $r['status'],
            'occurred_at' => (string) $r['occurred_at'],
        ], $rows);
    }

    /** 应收总额（已送达未收款）。 */
    private function receivableTotalFen(): int
    {
        $p = $this->tablePrefix;
        $stmt = $this->pdo->prepare(
            "SELECT COALESCE(SUM(o.`total_price` - o.`pay_price`), 0) AS receivable "
            . "FROM `veg_order_delivery` d "
            . "INNER JOIN `{$p}order` o ON o.`id` = d.`order_id` "
            . "WHERE d.`payment_method` = 'COD' AND d.`fulfillment` IN ('DELIVERED','FINISHED') AND d.`payment_state` = 'UNPAID'"
        );
        $stmt->execute();

        return OrderRowMapper::decimalToFen($stmt->fetch(\PDO::FETCH_ASSOC)['receivable'] ?? 0);
    }
}
