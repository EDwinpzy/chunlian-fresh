<?php

declare(strict_types=1);

namespace Chunlian\Service;

use Chunlian\Domain\Order;
use Chunlian\Domain\OrderStatus;
use Chunlian\Domain\OrderLine;
use Chunlian\Domain\OrderRevisionState;
use Chunlian\Domain\PaymentMethod;
use Chunlian\Domain\RejectionCode;
use Chunlian\Repository\PdoIdempotencyStore;
use Chunlian\Repository\OrderRowMapper;
use Chunlian\Repository\PdoOrderRepository;

/**
 * 订单修订服务（ADR-004 的落地）。
 *
 * 【核心规则 —— 三条，缺一不可】
 *
 * 1. **补款成功后才提交新版本**
 *    差价为正（顾客加了东西）时，只创建一条 `PENDING_TOPUP` 修订单并
 *    把 `revision_state` 置为待补款；**订单明细保持旧版本不变**。
 *    原因：若先把新明细落库、再等顾客补款，顾客不付就变成「已按新单备货
 *    但没收到钱」；而且商家会看到新明细误以为可以配送。
 *    补款成功后由 [commitTopup] 原子地切换明细。
 *
 * 2. **已有 SKU 维持原订单单价，新增 SKU 与增量数量按现价**
 *    由 `RevisionCalculator` 保证。这条规则的理由是：顾客下单时看到的
 *    是当时的价，商家事后调价不应影响已成交的部分。
 *
 * 3. **待补款期间禁止配送**
 *    `revision_state = PENDING_TOPUP / APPLYING` 时
 *    `OrderStateMachine::startDelivery()` 会拒绝。这条已有测试覆盖。
 *
 * 【与库存的关系】
 * 修订会改变占用库存。差价为正时先**预占**新增部分（避免顾客付款期间
 * 被别人买走），补款失败或修订取消时对称释放。预占用
 * `shopxo_goods_spec_base.inventory` 的减法实现，并记流水以便对账。
 */
final class RevisionService
{
    private readonly IdempotencyService $idempotency;

    public function __construct(
        private readonly \PDO $pdo,
        private readonly PdoOrderRepository $orders,
        private readonly RevisionCalculator $calculator,
        private readonly string $tablePrefix = 'shopxo_',
        ?IdempotencyService $idempotency = null,
    ) {
        $this->idempotency = $idempotency ?? new IdempotencyService(new PdoIdempotencyStore($pdo));
        FreshCategoryPolicy::ensureLegacyRoots($this->pdo, $this->tablePrefix);
    }

    // =========================================================
    // 预览
    // =========================================================

    /**
     * 差价预览（不写任何数据）。
     *
     * @param array<int,array{sku_id:string,qty:int}> $items
     */
    public function preview(string $orderId, array $items): array
    {
        $order = $this->orders->find($orderId);
        if ($order === null) {
            return $this->fail(RejectionCode::NOT_FOUND, '订单不存在');
        }

        $catalog = $this->buildSkuCatalog($order);

        $result = $this->calculator->preview($order, $items, $catalog);
        if (!$result->success) {
            return $this->fail(
                $result->code ?? RejectionCode::VALIDATION_FAILED,
                $result->message ?? '无法预览差价',
                $result->data
            );
        }

        // 去掉内部字段（OrderLine 对象不适合直接出网）
        $payload = $result->data;
        unset($payload['_new_lines']);

        return $this->ok($payload);
    }

    // =========================================================
    // 提交修订
    // =========================================================

    /**
     * 提交修订。
     *
     * @param array<int,array{sku_id:string,qty:int}> $items
     */
    public function submit(
        string $orderId,
        array $items,
        string $actorId,
        string $actorType = 'CUSTOMER',
        ?int $clientVersion = null,
        ?string $idempotencyKey = null,
    ): array
    {
        if ($clientVersion === null || $clientVersion < 1) {
            return $this->fail(RejectionCode::VALIDATION_FAILED, '缺少有效的 client_version');
        }
        if ($idempotencyKey === null || !IdempotencyService::isValidKey($idempotencyKey)) {
            return $this->fail(RejectionCode::VALIDATION_FAILED, '改单必须携带有效的 Idempotency-Key（UUID v4）');
        }

        $payload = [
            'order_id' => $orderId,
            'client_version' => $clientVersion,
            'items' => $this->normalizeItemsForIdempotency($items),
        ];

        $outer = $this->idempotency->run(
            $actorId,
            'customer.order.revision',
            $idempotencyKey,
            $payload,
            fn (): array => $this->submitTransactional($orderId, $items, $actorId, $actorType, $clientVersion)
        );

        if (($outer['code'] ?? '') !== 'OK') {
            return [
                'code' => (string) $outer['code'],
                'message' => (string) ($outer['data']['message'] ?? RejectionCode::IDEMPOTENCY_CONFLICT->defaultMessage()),
                'data' => null,
            ];
        }

        $result = $outer['data'] ?? null;
        if (($outer['replayed'] ?? false) === true && $result === []) {
            return $this->fail(
                RejectionCode::ORDER_VERSION_CONFLICT,
                '改单正在处理中，请稍后刷新订单'
            );
        }

        return is_array($result) && isset($result['code'])
            ? $result
            : $this->ok(is_array($result) ? $result : []);
    }

    /** @param array<int,array{sku_id:string,qty:int}> $items */
    private function submitTransactional(
        string $orderId,
        array $items,
        string $actorId,
        string $actorType,
        int $clientVersion,
    ): array {
        $this->pdo->beginTransaction();
        try {
            $order = $this->orders->findForUpdate($orderId, $actorId);
            if ($order === null) {
                $this->pdo->rollBack();

                return $this->fail(RejectionCode::NOT_FOUND, '订单不存在');
            }

            if ($order->version !== $clientVersion) {
                $this->pdo->rollBack();

                return $this->fail(
                    RejectionCode::ORDER_VERSION_CONFLICT,
                    '订单已更新，请刷新后重试',
                    ['current_version' => $order->version]
                );
            }

            // 只有未出发的订单可改（与服务端 canCustomerEdit 同口径）
            if ($order->orderStatus !== OrderStatus::PENDING) {
                $this->pdo->rollBack();

                return $this->fail(
                    RejectionCode::ORDER_NOT_EDITABLE,
                    '商家已开始配送，不能再修改订单'
                );
            }

            // 已有未结清的修订时不允许再改（避免多笔差价叠加）
            if (!$order->revisionState->allowsNewRevision()) {
                $this->pdo->rollBack();

                return $this->fail(
                    RejectionCode::TOPUP_REQUIRED,
                    '该订单还有未完成的改单，请先完成补款或等待退款完成'
                );
            }

            $catalog = $this->buildSkuCatalog($order);

            $preview = $this->calculator->preview($order, $items, $catalog);
            if (!$preview->success) {
                $this->pdo->rollBack();

                return $this->fail(
                    $preview->code ?? RejectionCode::VALIDATION_FAILED,
                    $preview->message ?? '无法提交修订',
                    $preview->data
                );
            }

            $deltaFen = (int) $preview->get('delta_fen', 0);
            $oldLines = $order->lines;
            /** @var OrderLine[] $newLines */
            $newLines = $preview->get('_new_lines', []);

            // 没有任何变化 —— 直接返回，不产生无意义的修订记录
            if ($this->stockDeltaIsEmpty($oldLines, $newLines) && $deltaFen === 0) {
                $this->pdo->rollBack();

                return $this->ok([
                    'changed' => false,
                    'message' => '订单内容没有变化',
                ]);
            }

            $toVersion = $order->version + 1;

            $newState = $this->calculator->stateFor($order, $deltaFen);
            if ($this->calculator->requiresRefund($order, $deltaFen)
                && abs($deltaFen) > $order->refundable()->fen()) {
                $this->pdo->rollBack();

                return $this->fail(RejectionCode::VALIDATION_FAILED, '退款差额超过当前可退金额');
            }

            $revisionId = $this->insertRevision(
                orderId: $orderId,
                fromVersion: $order->version,
                toVersion: $toVersion,
                status: $newState->value,
                before: $oldLines,
                after: $newLines,
                deltaFen: $deltaFen,
                actorType: $actorType,
                actorId: $actorId
            );

            // 已付订单加价进入 PENDING_TOPUP：订单明细保持旧版直到收到补款，
            // 因此此时只预占新增库存。减少/移除部分仍由旧明细占用，只有补款成功、
            // 新明细原子切换时才释放；取消或补款失败只需归还新增预占。
            $stockDelta = $this->calculator->stockDelta($oldLines, $newLines);
            $stockToApply = $newState === OrderRevisionState::PENDING_TOPUP
                ? array_filter($stockDelta, static fn (int $qty): bool => $qty > 0)
                : $stockDelta;
            if (!$this->applyStockDelta($stockToApply)) {
                $this->pdo->rollBack();

                return $this->fail(
                    RejectionCode::OUT_OF_STOCK,
                    '库存刚刚发生变化，请调整数量后重试'
                );
            }

            $applyLinesNow = $newState !== OrderRevisionState::PENDING_TOPUP;

            if ($applyLinesNow) {
                $this->replaceOrderLines($orderId, $newLines);
                $this->refreshOrderTotals($orderId, $newLines, $order->deliveryFeeFen);
            }

            // 更新四维状态中的修订维度 + 版本（乐观锁 CAS）
            // 行数为 0 说明期间有别的操作改了这张订单 —— 必须让上层重新决策，
            // 不能继续往下走，否则会用过期判断覆盖别人的结果。
            $upd = $this->pdo->prepare(
                'UPDATE `veg_order_delivery` SET `revision_state` = :rs, `version` = `version` + 1, '
                . '`updated_at` = NOW() WHERE `order_id` = :oid AND `version` = :expected'
            );
            $upd->execute([
                'rs' => $newState->value,
                'oid' => $orderId,
                'expected' => $order->version,
            ]);

            if ($upd->rowCount() === 0) {
                $this->pdo->rollBack();

                return $this->fail(
                    RejectionCode::ORDER_VERSION_CONFLICT,
                    '订单已被其他操作修改，请刷新后重试'
                );
            }

            // 审计 + outbox
            $this->orders->writeAuditLog($actorId, $orderId, 'REVISION_SUBMIT', [
                'actor_type' => $actorType,
                'revision_id' => $revisionId,
                'delta_fen' => $deltaFen,
                'revision_state' => $newState->value,
                'applied_immediately' => $applyLinesNow,
            ]);

            $this->orders->enqueueEvent('ORDER_REVISED', $orderId, [
                'order_id' => $orderId,
                'revision_id' => $revisionId,
                'delta_fen' => $deltaFen,
                'revision_state' => $newState->value,
            ]);

            if ($this->calculator->requiresRefund($order, $deltaFen)) {
                if ($order->paymentMethod === PaymentMethod::OFFLINE_QR) {
                    // 线下扫码不调用微信退款接口，先占用可退款金额并等待商家
                    // 实际转账/扫码退款后手工确认。
                    $this->createOfflineRefund($orderId, $revisionId, abs($deltaFen));
                } else {
                    $this->orders->enqueueEvent('REFUND_QUERY', $orderId, [
                        'refund_fen' => abs($deltaFen),
                        'revision_id' => $revisionId,
                    ]);
                }
            }

            $this->pdo->commit();

            $fresh = $this->orders->find($orderId);
            $payload = [
                'revision_id' => $revisionId,
                'changed' => true,
                'delta_fen' => $deltaFen,
                'revision_state' => $newState->value,
                'applied_immediately' => $applyLinesNow,
                'requires_topup' => $newState === OrderRevisionState::PENDING_TOPUP,
                'order' => $fresh === null ? null : $this->serializeOrder($fresh),
            ];

            return $this->ok($payload, (string) $preview->get('customer_message', '订单已更新'));
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @param array<int,array{sku_id:string,qty:int}> $items @return array<int,array{sku_id:string,qty:int}> */
    private function normalizeItemsForIdempotency(array $items): array
    {
        $merged = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $skuId = (string) ($item['sku_id'] ?? '');
            $merged[$skuId] = ($merged[$skuId] ?? 0) + (int) ($item['qty'] ?? 0);
        }
        ksort($merged, SORT_STRING);
        $normalized = [];
        foreach ($merged as $skuId => $qty) {
            $normalized[] = ['sku_id' => (string) $skuId, 'qty' => (int) $qty];
        }

        return $normalized;
    }

    /**
     * 补款成功后提交修订（把待补款的明细切换为生效）。
     *
     * 由支付回调或商家确认线下扫码收款后调用。
     * 幂等：只有当前状态是 PENDING_TOPUP 时才切换，重复调用无副作用。
     * 若调用方已在事务中（线下扫码收款需与流水、订单切换原子提交），本方法
     * 复用外层事务；否则自行管理事务。
     */
    public function commitTopup(string $orderId, string $actorId): array
    {
        $p = $this->tablePrefix;

        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            $order = $this->orders->findForUpdate($orderId, $actorId);
            if ($order === null) {
                if ($ownsTransaction) {
                    $this->pdo->rollBack();
                }

                return $this->fail(RejectionCode::NOT_FOUND, '订单不存在');
            }

            if ($order->revisionState !== OrderRevisionState::PENDING_TOPUP) {
                if ($ownsTransaction) {
                    $this->pdo->rollBack();
                }

                return $this->ok([
                    'changed' => false,
                    'revision_state' => $order->revisionState->value,
                    'message' => '当前没有待补款的改单',
                ]);
            }

            // 取最近一条待补款修订
            $stmt = $this->pdo->prepare(
                'SELECT `id`, `before_json`, `after_json`, `delta_fen` FROM `veg_order_revision` '
                . 'WHERE `order_id` = :oid AND `status` = :st ORDER BY `id` DESC LIMIT 1 FOR UPDATE'
            );
            $stmt->execute(['oid' => $orderId, 'st' => OrderRevisionState::PENDING_TOPUP->value]);
            $rev = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($rev === false) {
                if ($ownsTransaction) {
                    $this->pdo->rollBack();
                }

                return $this->fail(RejectionCode::VALIDATION_FAILED, '找不到待补款的改单记录');
            }

            $deltaFen = (int) $rev['delta_fen'];
            $ledgerKind = match ($order->paymentMethod) {
                \Chunlian\Domain\PaymentMethod::WECHAT => 'TOPUP',
                \Chunlian\Domain\PaymentMethod::OFFLINE_QR => 'OFFLINE_RECEIPT',
                default => null,
            };
            if ($ledgerKind === null || $deltaFen <= 0) {
                if ($ownsTransaction) {
                    $this->pdo->rollBack();
                }

                return $this->fail(RejectionCode::VALIDATION_FAILED, '该修订不符合补款提交条件');
            }

            // 只有关联到这条修订、金额相等且成功的资金流水才能提交。
            $payment = $this->pdo->prepare(
                'SELECT `amount_fen`, `status`, `provider_trade_no`, `collection_method` FROM `veg_payment_ledger` '
                . 'WHERE `order_id` = :oid AND `revision_id` = :rid AND `kind` = :kind '
                . 'ORDER BY `id` DESC LIMIT 1 FOR UPDATE'
            );
            $payment->execute(['oid' => $orderId, 'rid' => (int) $rev['id'], 'kind' => $ledgerKind]);
            $topup = $payment->fetch(\PDO::FETCH_ASSOC);
            if ($topup === false
                || (string) $topup['status'] !== 'SUCCESS'
                || (int) $topup['amount_fen'] !== $deltaFen
                || ($ledgerKind === 'TOPUP' && trim((string) ($topup['provider_trade_no'] ?? '')) === '')
                || ($ledgerKind === 'OFFLINE_RECEIPT' && (string) ($topup['collection_method'] ?? '') !== 'OFFLINE_QR')) {
                if ($ownsTransaction) {
                    $this->pdo->rollBack();
                }

                return $this->fail(
                    RejectionCode::PAYMENT_CONFIRMING,
                    '尚未查到该修订金额一致的补款成功记录'
                );
            }

            $beforeLines = json_decode((string) $rev['before_json'], true);
            $afterLines = json_decode((string) $rev['after_json'], true);
            if (!is_array($beforeLines) || !is_array($afterLines)) {
                if ($ownsTransaction) {
                    $this->pdo->rollBack();
                }

                return $this->fail(RejectionCode::VALIDATION_FAILED, '改单快照损坏，无法提交');
            }

            $oldLines = array_map(static fn (array $a): OrderLine => OrderLine::fromArray($a), $beforeLines);
            $lines = array_map(static fn (array $a): OrderLine => OrderLine::fromArray($a), $afterLines);

            // 正 delta 已在等待补款时预占；只在切换版本时释放减少/移除的库存。
            $releaseDelta = array_filter(
                $this->calculator->stockDelta($oldLines, $lines),
                static fn (int $qty): bool => $qty < 0
            );
            if (!$this->applyStockDelta($releaseDelta)) {
                if ($ownsTransaction) {
                    $this->pdo->rollBack();
                }

                return $this->fail(RejectionCode::VALIDATION_FAILED, '改单库存释放失败，请核对库存后重试');
            }

            $this->replaceOrderLines($orderId, $lines);
            $this->refreshOrderTotals($orderId, $lines, $order->deliveryFeeFen);

            $newPaidFen = $order->paidFen + $deltaFen;
            $this->pdo->prepare(
                sprintf('UPDATE `%sorder` SET `pay_status` = 1, `pay_price` = :pay_price, `upd_time` = UNIX_TIMESTAMP() WHERE `id` = :id', $p)
            )->execute([
                'pay_price' => OrderRowMapper::fenToDecimal($newPaidFen),
                'id' => $orderId,
            ]);

            $this->pdo->prepare(
                'UPDATE `veg_order_revision` SET `status` = :st, `updated_at` = NOW() WHERE `id` = :id'
            )->execute(['st' => OrderRevisionState::COMMITTED->value, 'id' => (int) $rev['id']]);

            $update = $this->pdo->prepare(
                'UPDATE `veg_order_delivery` SET `revision_state` = :rs, `version` = `version` + 1, '
                . '`updated_at` = NOW() WHERE `order_id` = :oid AND `version` = :expected'
            );
            $update->execute([
                'rs' => OrderRevisionState::COMMITTED->value,
                'oid' => $orderId,
                'expected' => $order->version,
            ]);
            if ($update->rowCount() !== 1) {
                if ($ownsTransaction) {
                    $this->pdo->rollBack();
                }

                return $this->fail(
                    RejectionCode::ORDER_VERSION_CONFLICT,
                    '订单已被其他操作修改，请刷新后重试'
                );
            }

            $this->orders->writeAuditLog($actorId, $orderId, 'REVISION_COMMIT_TOPUP', [
                'actor_type' => 'MERCHANT',
                'revision_id' => (int) $rev['id'],
                'delta_fen' => $deltaFen,
            ]);

            if ($ownsTransaction) {
                $this->pdo->commit();
            }

            return $this->ok([
                'changed' => true,
                'revision_state' => OrderRevisionState::COMMITTED->value,
            ], '改单已生效');
        } catch (\Throwable $e) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /** 修订历史（商家端核对用，只读）。 */
    public function history(string $orderId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT `id`, `from_version`, `to_version`, `status`, `delta_fen`, `actor_type`, '
            . '`reason`, `before_json`, `after_json`, `created_at` '
            . 'FROM `veg_order_revision` WHERE `order_id` = :oid ORDER BY `id` ASC'
        );
        $stmt->execute(['oid' => $orderId]);

        $list = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $before = json_decode((string) $r['before_json'], true);
            $after = json_decode((string) $r['after_json'], true);

            $list[] = [
                'id' => (string) $r['id'],
                'from_version' => (int) $r['from_version'],
                'to_version' => (int) $r['to_version'],
                'state' => (string) $r['status'],
                'delta_fen' => (int) $r['delta_fen'],
                'actor_type' => (string) $r['actor_type'],
                'reason' => $r['reason'] === null ? null : (string) $r['reason'],
                'created_at' => (string) $r['created_at'],
                // 差异摘要：让商家一眼看出改了什么，不必自己比对 JSON
                'summary' => $this->diffSummary(is_array($before) ? $before : [], is_array($after) ? $after : []),
                'before_lines' => $this->lineBrief(is_array($before) ? $before : []),
                'after_lines' => $this->lineBrief(is_array($after) ? $after : []),
            ];
        }

        return $this->ok(['list' => $list, 'total' => count($list)]);
    }

    // =========================================================
    // 内部
    // =========================================================

    /**
     * 构造 SKU 目录（键为 sku_id）。
     *
     * 目录包括当前顾客可见的鲜食 SKU，支持在改单时添加原订单之外的商品。
     * 已下架或失去鲜食分类的 SKU 只为旧订单保留，并限制数量不得高于原占用量。
     * 新增 SKU 与新增数量按当前价；原有数量价格由订单行快照保留。
     *
     * @return array<string,array{unit_fen:int,stock:int,product_id:string,name:string,spec:string,image:string,sellable:bool}>
     */
    private function buildSkuCatalog(Order $order): array
    {
        $skuIds = array_map(static fn (OrderLine $l): string => $l->skuId, $order->lines);

        $p = $this->tablePrefix;
        $freshPredicate = FreshCategoryPolicy::goodsPredicate($p, 'g');
        $where = "(g.`is_delete_time` = 0 AND g.`is_shelves` = 1 AND {$freshPredicate})";
        $params = [];
        if ($skuIds !== []) {
            $where .= ' OR b.`id` IN (' . implode(',', array_fill(0, count($skuIds), '?')) . ')';
            $params = $skuIds;
        }

        $stmt = $this->pdo->prepare(
            "SELECT b.`id`, b.`goods_id`, b.`price`, b.`inventory`, g.`title`, g.`images`, "
            . "g.`is_shelves`, g.`is_delete_time`, "
            . "CASE WHEN g.`is_delete_time` = 0 AND g.`is_shelves` = 1 AND {$freshPredicate} THEN 1 ELSE 0 END AS `is_sellable`, "
            . "COALESCE(specs.`spec_text`, '') AS `spec_text` "
            . "FROM `{$p}goods_spec_base` b "
            . "INNER JOIN `{$p}goods` g ON g.`id` = b.`goods_id` "
            . "LEFT JOIN (SELECT `goods_spec_base_id`, GROUP_CONCAT(`value` ORDER BY `id` SEPARATOR '，') AS `spec_text` "
            . "FROM `{$p}goods_spec_value` GROUP BY `goods_spec_base_id`) specs ON specs.`goods_spec_base_id` = b.`id` "
            . "WHERE {$where}"
        );
        $stmt->execute($params);

        $catalog = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $skuId = (string) $row['id'];
            $orderedQty = $this->orderedQty($order, $skuId);
            $sellable = ((int) $row['is_sellable']) === 1;
            $catalog[$skuId] = [
                'unit_fen' => OrderRowMapper::decimalToFen((string) $row['price']),
                // 加上订单已占用的数量：顾客改单时这部分库存本就属于他
                'stock' => $sellable ? (int) $row['inventory'] + $orderedQty : $orderedQty,
                'product_id' => (string) $row['goods_id'],
                'name' => (string) $row['title'],
                'spec' => (string) ($row['spec_text'] ?: '默认规格'),
                'image' => $this->imageUrl((string) $row['images']),
                'sellable' => $sellable,
            ];
        }

        // 若 ShopXO 已删除历史 SKU 主数据，仍允许顾客减少/移除该快照商品。
        foreach ($order->lines as $line) {
            if (isset($catalog[$line->skuId])) {
                continue;
            }
            $catalog[$line->skuId] = [
                'unit_fen' => $line->unitFen,
                'stock' => $this->orderedQty($order, $line->skuId),
                'product_id' => $line->productId,
                'name' => $line->nameSnapshot,
                'spec' => $line->specSnapshot,
                'image' => $line->imageSnapshot,
                'sellable' => false,
            ];
        }

        return $catalog;
    }

    private function orderedQty(Order $order, string $skuId): int
    {
        $qty = 0;
        foreach ($order->lines as $line) {
            if ($line->skuId === $skuId) {
                $qty += $line->qty;
            }
        }

        return $qty;
    }

    private function imageUrl(string $images): string
    {
        foreach (explode(',', $images) as $image) {
            $image = trim($image);
            if ($image === '') {
                continue;
            }
            if (preg_match('#^https?://#i', $image) === 1) {
                return $image;
            }
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $base = (string) (getenv('APP_URL') ?: ($scheme . '://' . (string) ($_SERVER['HTTP_HOST'] ?? '')));

            return rtrim($base, '/') . '/' . ltrim($image, '/');
        }

        return '';
    }

    /** @param OrderLine[] $before @param OrderLine[] $after */
    private function insertRevision(
        string $orderId,
        int $fromVersion,
        int $toVersion,
        string $status,
        array $before,
        array $after,
        int $deltaFen,
        string $actorType,
        string $actorId,
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO `veg_order_revision` '
            . '(`order_id`, `from_version`, `to_version`, `status`, `before_json`, `after_json`, '
            . '`delta_fen`, `actor_type`, `actor_id`, `created_at`, `updated_at`) '
            . 'VALUES (:oid, :fv, :tv, :st, :before, :after, :delta, :atype, :aid, NOW(), NOW())'
        );

        $stmt->execute([
            'oid' => $orderId,
            'fv' => $fromVersion,
            'tv' => $toVersion,
            'st' => $status,
            'before' => json_encode(array_map(static fn (OrderLine $l) => $l->toArray(), $before), JSON_UNESCAPED_UNICODE),
            'after' => json_encode(array_map(static fn (OrderLine $l) => $l->toArray(), $after), JSON_UNESCAPED_UNICODE),
            'delta' => $deltaFen,
            'atype' => $actorType,
            'aid' => $actorId === '' ? null : $actorId,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @param array<string,int> $delta sku_id => 数量差额（正=新增预占，负=释放） */
    private function applyStockDelta(array $delta): bool
    {
        if ($delta === []) {
            return true;
        }

        $p = $this->tablePrefix;
        $reserve = $this->pdo->prepare(
            "UPDATE `{$p}goods_spec_base` SET `inventory` = `inventory` - :qty "
            . 'WHERE `id` = :sid AND `inventory` >= :available'
        );
        $release = $this->pdo->prepare(
            "UPDATE `{$p}goods_spec_base` SET `inventory` = `inventory` + :qty WHERE `id` = :sid"
        );
        $exists = $this->pdo->prepare("SELECT 1 FROM `{$p}goods_spec_base` WHERE `id` = :sid LIMIT 1");

        foreach ($delta as $skuId => $qty) {
            if ($qty === 0) {
                continue;
            }

            if ($qty > 0) {
                // 条件扣减负责并发防超卖；不能用 GREATEST 把库存不足静默截成 0。
                $reserve->execute(['qty' => $qty, 'sid' => $skuId, 'available' => $qty]);
                if ($reserve->rowCount() === 1) {
                    continue;
                }

                $exists->execute(['sid' => $skuId]);
                if ($exists->fetchColumn() === false) {
                    throw new \RuntimeException('修订引用的 SKU 库存记录不存在：' . $skuId);
                }

                return false;
            }

            $release->execute(['qty' => abs($qty), 'sid' => $skuId]);
            if ($release->rowCount() !== 1) {
                throw new \RuntimeException('无法释放修订 SKU 库存：' . $skuId);
            }
        }

        return true;
    }

    private function createOfflineRefund(string $orderId, int $revisionId, int $refundFen): void
    {
        if ($refundFen <= 0) {
            throw new \InvalidArgumentException('线下退款金额必须大于 0');
        }

        $now = date('Y-m-d H:i:s');
        $this->pdo->prepare(
            'INSERT INTO `veg_payment_ledger` '
            . '(`order_id`, `revision_id`, `kind`, `amount_fen`, `status`, `business_no`, `collection_method`, '
            . '`collected_fen`, `idempotency_key`, `operator_id`, `occurred_at`, `created_at`, `updated_at`) '
            . 'VALUES (:oid, :rid, \'OFFLINE_REFUND\', :amount, \'PROCESSING\', :business_no, NULL, NULL, '
            . ':idem, NULL, :occurred, :created, :updated)'
        )->execute([
            'oid' => $orderId,
            'rid' => $revisionId,
            'amount' => -$refundFen,
            'business_no' => sprintf('OFR%s%s', date('YmdHis'), bin2hex(random_bytes(5))),
            'idem' => 'offline-refund-revision-' . $revisionId,
            'occurred' => $now,
            'created' => $now,
            'updated' => $now,
        ]);
    }

    /** @param OrderLine[] $lines */
    private function replaceOrderLines(string $orderId, array $lines): void
    {
        $p = $this->tablePrefix;

        $this->pdo->prepare("DELETE FROM `{$p}order_detail` WHERE `order_id` = :oid")
            ->execute(['oid' => $orderId]);

        $insert = $this->pdo->prepare(
            "INSERT INTO `{$p}order_detail` (`order_id`, `user_id`, `goods_id`, `title`, `images`, `price`, "
            . "`total_price`, `spec`, `buy_number`, `spec_desc`, `add_time`) "
            . "VALUES (:oid, :uid, :gid, :title, :images, :price, :total, :spec, :qty, :spec_desc, UNIX_TIMESTAMP())"
        );

        $userId = (int) $this->pdo->query(
            sprintf("SELECT `user_id` FROM `%sorder` WHERE `id` = %d", $p, (int) $orderId)
        )->fetchColumn();

        foreach ($lines as $line) {
            $insert->execute([
                'oid' => $orderId,
                'uid' => $userId,
                'gid' => $line->productId,
                'title' => $line->nameSnapshot,
                'images' => $line->imageSnapshot,
                'price' => OrderRowMapper::fenToDecimal($line->unitFen),
                'total' => OrderRowMapper::fenToDecimal($line->subtotal()->fen()),
                'spec' => json_encode([['value' => $line->specSnapshot]], JSON_UNESCAPED_UNICODE),
                'qty' => $line->qty,
                'spec_desc' => $line->specSnapshot,
            ]);
        }

        // 订单行快照（我们自己的 SKU 身份表）同步重建 ——
        // 否则改单后 sku_id 与明细对不上，下一次改单会算错
        $this->pdo->prepare('DELETE FROM `veg_order_line` WHERE `order_id` = :oid')
            ->execute(['oid' => $orderId]);

        $detailIds = $this->pdo->query(
            sprintf("SELECT `id` FROM `%sorder_detail` WHERE `order_id` = %d ORDER BY `id` ASC", $p, (int) $orderId)
        )->fetchAll(\PDO::FETCH_COLUMN);

        $lineInsert = $this->pdo->prepare(
            'INSERT INTO `veg_order_line` (`order_id`, `order_detail_id`, `sku_id`, `goods_id`, '
            . '`name_snapshot`, `spec_snapshot`, `image_snapshot`, `unit_fen`, `qty`, `created_at`) '
            . 'VALUES (:oid, :did, :sku, :gid, :name, :spec, :image, :fen, :qty, NOW())'
        );

        foreach ($lines as $i => $line) {
            if (!isset($detailIds[$i])) {
                break;
            }
            $lineInsert->execute([
                'oid' => $orderId,
                'did' => (int) $detailIds[$i],
                'sku' => $line->skuId,
                'gid' => $line->productId,
                'name' => $line->nameSnapshot,
                'spec' => $line->specSnapshot,
                'image' => $line->imageSnapshot,
                'fen' => $line->unitFen,
                'qty' => $line->qty,
            ]);
        }
    }

    /** @param OrderLine[] $lines */
    private function refreshOrderTotals(string $orderId, array $lines, int $deliveryFeeFen): void
    {
        $p = $this->tablePrefix;

        $goodsFen = 0;
        $count = 0;
        foreach ($lines as $line) {
            $goodsFen += $line->subtotal()->fen();
            $count += $line->qty;
        }

        $this->pdo->prepare(
            "UPDATE `{$p}order` SET `price` = :price, `total_price` = :total, "
            . "`buy_number_count` = :cnt, `upd_time` = UNIX_TIMESTAMP() WHERE `id` = :id"
        )->execute([
            'price' => OrderRowMapper::fenToDecimal($goodsFen),
            'total' => OrderRowMapper::fenToDecimal($goodsFen + $deliveryFeeFen),
            'cnt' => $count,
            'id' => $orderId,
        ]);
    }

    /** @param OrderLine[] $old @param OrderLine[] $new */
    private function stockDeltaIsEmpty(array $old, array $new): bool
    {
        return $this->calculator->stockDelta($old, $new) === [];
    }

    /** 人类可读的差异摘要。 */
    private function diffSummary(array $before, array $after): string
    {
        $b = [];
        foreach ($before as $l) {
            $sku = (string) $l['sku_id'];
            $b[$sku] = ($b[$sku] ?? 0) + (int) $l['qty'];
        }
        $a = [];
        foreach ($after as $l) {
            $sku = (string) $l['sku_id'];
            $a[$sku] = ($a[$sku] ?? 0) + (int) $l['qty'];
        }

        $parts = [];
        foreach (array_unique(array_merge(array_keys($b), array_keys($a))) as $sku) {
            $ob = $b[$sku] ?? 0;
            $oa = $a[$sku] ?? 0;
            if ($ob === $oa) {
                continue;
            }
            $parts[] = $ob === 0
                ? sprintf('新增 %s ×%d', $sku, $oa)
                : ($oa === 0
                    ? sprintf('移除 %s', $sku)
                    : sprintf('%s ×%d → ×%d', $sku, $ob, $oa));
        }

        return $parts === [] ? '无明细变化' : implode('；', $parts);
    }

    /** @param array<int,array<string,mixed>> $lines */
    private function lineBrief(array $lines): array
    {
        return array_map(static fn (array $l): array => [
            'sku_id' => (string) ($l['sku_id'] ?? ''),
            'name' => (string) ($l['name'] ?? ''),
            'spec' => (string) ($l['spec'] ?? ''),
            'unit_fen' => (int) ($l['unit_fen'] ?? 0),
            'qty' => (int) ($l['qty'] ?? 0),
            'subtotal_fen' => (int) ($l['subtotal_fen'] ?? 0),
        ], $lines);
    }

    private function serializeOrder(Order $order): array
    {
        return [
            'id' => $order->id,
            'order_no' => $order->orderNo,
            'version' => $order->version,
            'order_status' => $order->orderStatus->value,
            'payment_state' => $order->paymentState->value,
            'revision_state' => $order->revisionState->value,
            'payment_method' => $order->paymentMethod->value,
            'delivery_mode' => $order->deliveryMode->value,
            'expected_at' => $order->expectedAt?->format(DATE_ATOM),
            'lines' => array_map(static fn (OrderLine $l): array => $l->toArray(), $order->lines),
            'contact' => [
                'name' => $order->contact->name,
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
        ];
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
