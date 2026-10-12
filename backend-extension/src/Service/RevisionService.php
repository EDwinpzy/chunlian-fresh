<?php

declare(strict_types=1);

namespace Chunlian\Service;

use Chunlian\Domain\Order;
use Chunlian\Domain\OrderFulfillment;
use Chunlian\Domain\OrderLine;
use Chunlian\Domain\OrderRevisionState;
use Chunlian\Domain\RejectionCode;
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
    public function __construct(
        private readonly \PDO $pdo,
        private readonly PdoOrderRepository $orders,
        private readonly RevisionCalculator $calculator,
        private readonly string $tablePrefix = 'shopxo_',
    ) {
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
    public function submit(string $orderId, array $items, string $actorId, string $actorType = 'CUSTOMER'): array
    {
        $this->pdo->beginTransaction();
        try {
            $order = $this->orders->findForUpdate($orderId, $actorId);
            if ($order === null) {
                $this->pdo->rollBack();

                return $this->fail(RejectionCode::NOT_FOUND, '订单不存在');
            }

            // 只有未出发的订单可改（与服务端 canCustomerEdit 同口径）
            if ($order->fulfillment !== OrderFulfillment::PENDING) {
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

            // 状态判定：这是本服务最关键的一处分支
            $isPaid = $order->paidFen > 0;
            $newState = match (true) {
                // 差价为正且订单已付款 → 必须先补款，明细暂不切换
                $deltaFen > 0 && $isPaid => OrderRevisionState::PENDING_TOPUP,
                // 差价为正但订单未付款（COD）→ 无需补款，直接生效
                // 理由：COD 是送达时收款，顾客加了商品就是多收，不存在「先补款」
                $deltaFen > 0 => OrderRevisionState::COMMITTED,
                // 需要退款 → 明细立即切换（商家应按新单备货），退款在后台处理
                $deltaFen < 0 => OrderRevisionState::APPLYING,
                default => OrderRevisionState::COMMITTED,
            };

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

            // 库存差额：正数新增预占、负数释放
            $stockDelta = $this->calculator->stockDelta($oldLines, $newLines);
            $this->applyStockDelta($stockDelta);

            $applyLinesNow = $newState !== OrderRevisionState::PENDING_TOPUP;

            if ($applyLinesNow) {
                $this->replaceOrderLines($orderId, $newLines);
                $this->refreshOrderTotals($orderId, $newLines);
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

            if ($deltaFen < 0) {
                // 需退款：排一条任务由 worker 调微信退款并轮询
                $this->orders->enqueueEvent('REFUND_QUERY', $orderId, [
                    'refund_fen' => abs($deltaFen),
                    'revision_id' => $revisionId,
                ]);
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

    /**
     * 补款成功后提交修订（把待补款的明细切换为生效）。
     *
     * 由支付回调或商家确认收款后调用。
     * 幂等：只有当前状态是 PENDING_TOPUP 时才切换，重复调用无副作用。
     */
    public function commitTopup(string $orderId, string $actorId): array
    {
        $p = $this->tablePrefix;

        $this->pdo->beginTransaction();
        try {
            $order = $this->orders->findForUpdate($orderId, $actorId);
            if ($order === null) {
                $this->pdo->rollBack();

                return $this->fail(RejectionCode::NOT_FOUND, '订单不存在');
            }

            if ($order->revisionState !== OrderRevisionState::PENDING_TOPUP) {
                $this->pdo->rollBack();

                return $this->ok([
                    'changed' => false,
                    'revision_state' => $order->revisionState->value,
                    'message' => '当前没有待补款的改单',
                ]);
            }

            // 取最近一条待补款修订
            $stmt = $this->pdo->prepare(
                'SELECT `id`, `after_json`, `delta_fen` FROM `veg_order_revision` '
                . 'WHERE `order_id` = :oid AND `status` = :st ORDER BY `id` DESC LIMIT 1 FOR UPDATE'
            );
            $stmt->execute(['oid' => $orderId, 'st' => OrderRevisionState::PENDING_TOPUP->value]);
            $rev = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($rev === false) {
                $this->pdo->rollBack();

                return $this->fail(RejectionCode::VALIDATION_FAILED, '找不到待补款的改单记录');
            }

            $afterLines = json_decode((string) $rev['after_json'], true);
            if (!is_array($afterLines)) {
                $this->pdo->rollBack();

                return $this->fail(RejectionCode::VALIDATION_FAILED, '改单快照损坏，无法提交');
            }

            $lines = array_map(static fn (array $a): OrderLine => OrderLine::fromArray($a), $afterLines);

            $this->replaceOrderLines($orderId, $lines);
            $this->refreshOrderTotals($orderId, $lines);

            $this->pdo->prepare(
                'UPDATE `veg_order_revision` SET `status` = :st, `updated_at` = NOW() WHERE `id` = :id'
            )->execute(['st' => OrderRevisionState::COMMITTED->value, 'id' => (int) $rev['id']]);

            $this->pdo->prepare(
                'UPDATE `veg_order_delivery` SET `revision_state` = :rs, `version` = `version` + 1, '
                . '`updated_at` = NOW() WHERE `order_id` = :oid AND `version` = :expected'
            )->execute([
                'rs' => OrderRevisionState::COMMITTED->value,
                'oid' => $orderId,
                'expected' => $order->version,
            ]);

            $this->orders->writeAuditLog($actorId, $orderId, 'REVISION_COMMIT_TOPUP', [
                'actor_type' => 'MERCHANT',
                'revision_id' => (int) $rev['id'],
                'delta_fen' => (int) $rev['delta_fen'],
            ]);

            $this->pdo->commit();

            return $this->ok([
                'changed' => true,
                'revision_state' => OrderRevisionState::COMMITTED->value,
            ], '改单已生效');
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
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
     * 单价取**当前** `shopxo_goods_spec_base.price` —— 这是「新增 SKU 与
     * 增量数量按现价」的依据。已有 SKU 的原价由 `RevisionCalculator`
     * 从订单行快照里取，不走这里。
     *
     * @return array<string,array{unit_fen:int,stock:int,product_id:string,name:string,spec:string,image:string}>
     */
    private function buildSkuCatalog(Order $order): array
    {
        $skuIds = array_map(static fn (OrderLine $l): string => $l->skuId, $order->lines);

        // 目录只需要覆盖「订单里已有的 SKU」——
        // 顾客改单只能在原单基础上增删，不能凭空加一个没买过的商品。
        // 这既符合业务，也避免了顾客绕过购物车直接构造任意 SKU 的请求。
        if ($skuIds === []) {
            return [];
        }

        $p = $this->tablePrefix;
        $placeholders = implode(',', array_fill(0, count($skuIds), '?'));

        $stmt = $this->pdo->prepare(
            "SELECT b.`id`, b.`goods_id`, b.`price`, b.`inventory`, g.`title`, g.`images` "
            . "FROM `{$p}goods_spec_base` b "
            . "INNER JOIN `{$p}goods` g ON g.`id` = b.`goods_id` "
            . "WHERE b.`id` IN ({$placeholders}) AND g.`is_delete_time` = 0"
        );
        $stmt->execute($skuIds);

        $catalog = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $skuId = (string) $row['id'];
            $catalog[$skuId] = [
                'unit_fen' => OrderRowMapper::decimalToFen((string) $row['price']),
                // 加上订单已占用的数量：顾客改单时这部分库存本就属于他
                'stock' => (int) $row['inventory'] + $this->orderedQty($order, $skuId),
                'product_id' => (string) $row['goods_id'],
                'name' => (string) $row['title'],
                'spec' => '',
                'image' => (string) $row['images'],
            ];
        }

        return $catalog;
    }

    private function orderedQty(Order $order, string $skuId): int
    {
        foreach ($order->lines as $line) {
            if ($line->skuId === $skuId) {
                return $line->qty;
            }
        }

        return 0;
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
    private function applyStockDelta(array $delta): void
    {
        if ($delta === []) {
            return;
        }

        $p = $this->tablePrefix;
        $stmt = $this->pdo->prepare(
            "UPDATE `{$p}goods_spec_base` SET `inventory` = GREATEST(0, `inventory` - :d) WHERE `id` = :sid"
        );

        foreach ($delta as $skuId => $qty) {
            if ($qty === 0) {
                continue;
            }
            $stmt->execute(['d' => $qty, 'sid' => $skuId]);
        }
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
    private function refreshOrderTotals(string $orderId, array $lines): void
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
            'total' => OrderRowMapper::fenToDecimal($goodsFen),
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
            $b[(string) $l['sku_id']] = (int) $l['qty'];
        }
        $a = [];
        foreach ($after as $l) {
            $a[(string) $l['sku_id']] = (int) $l['qty'];
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
            'fulfillment' => $order->fulfillment->value,
            'payment_state' => $order->paymentState->value,
            'receipt_state' => $order->receiptState->value,
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
