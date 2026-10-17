<?php

declare(strict_types=1);

namespace Chunlian\Service;

use Chunlian\Domain\Order;
use Chunlian\Domain\OrderLine;
use Chunlian\Domain\OrderRevisionState;
use Chunlian\Domain\PaymentMethod;
use Chunlian\Domain\RejectionCode;
use Chunlian\Domain\RevisionDeltaType;

/**
 * 订单修订计算器。
 *
 * 职责：给定「原订单快照」+「顾客提交的新明细 + 最新 SKU 售价/库存」，
 * 计算出新的订单行、总价与差价，并给出补款/退款方向。
 *
 * 定价规则（默认，可配置但**必须统一**）：
 *   - 原订单已有的数量：维持订单快照中的各自单价（不受调价影响）
 *   - 新增 SKU：按**现价**
 *   - 已有 SKU 的**增量数量**：按现价
 *
 * 本类为纯函数，不做持久化；库存预占由 Repository 在事务内完成。
 */
final class RevisionCalculator
{
    /**
     * @param array<string,array{unit_fen:int,stock:int,product_id:string,name:string,spec:string,image:string,sellable?:bool}> $skuCatalog
     *        最新 SKU 目录（键为 sku_id）
     * @param array<int,array{sku_id:string,qty:int}> $submittedItems 顾客提交的明细
     */
    public function preview(Order $order, array $submittedItems, array $skuCatalog): TransitionResult
    {
        if ($order->orderStatus->value !== 'PENDING') {
            return TransitionResult::reject(RejectionCode::ORDER_NOT_EDITABLE);
        }

        /** @var array<string,OrderLine[]> $originalLines */
        $originalLines = [];
        foreach ($order->lines as $line) {
            $originalLines[$line->skuId][] = $line;
        }

        // 合并重复 SKU，避免同一 SKU 分两行提交时分别通过库存检查而超卖。
        $mergedItems = [];
        foreach ($submittedItems as $item) {
            $skuId = (string) ($item['sku_id'] ?? '');
            $qty = (int) ($item['qty'] ?? 0);
            if ($skuId === '' || $qty <= 0) {
                return TransitionResult::reject(RejectionCode::VALIDATION_FAILED, [
                    'reason' => '商品明细非法',
                    'sku_id' => $skuId,
                ]);
            }
            $mergedItems[$skuId] = ($mergedItems[$skuId] ?? 0) + $qty;
        }
        if (count($mergedItems) > 50) {
            return TransitionResult::reject(RejectionCode::VALIDATION_FAILED, [
                'reason' => '单次改单商品种类过多',
            ]);
        }

        $newLines = [];
        $pricedLines = [];
        $warnings = [];

        foreach ($mergedItems as $skuKey => $qty) {
            // PHP 会把纯数字字符串数组键自动转换成 int；领域对象仍要求 SKU ID 为 string。
            $skuId = (string) $skuKey;
            if (!isset($skuCatalog[$skuId])) {
                return TransitionResult::reject(RejectionCode::VALIDATION_FAILED, [
                    'reason' => '商品不存在或已下架',
                    'sku_id' => $skuId,
                ]);
            }

            $sku = $skuCatalog[$skuId];
            $original = $originalLines[$skuId] ?? [];
            $originalQty = array_sum(array_map(static fn (OrderLine $l): int => $l->qty, $original));

            // 已下架/移出鲜食分类的旧商品可以保留或减少，但不能继续加购。
            if ($originalQty > 0 && $qty > $originalQty && !($sku['sellable'] ?? true)) {
                return TransitionResult::reject(RejectionCode::VALIDATION_FAILED, [
                    'reason' => '商品已下架，不能增加购买数量',
                    'sku_id' => $skuId,
                ]);
            }

            if ($sku['stock'] < $qty) {
                $warnings[] = [
                    'sku_id' => $skuId,
                    'name' => $sku['name'],
                    'available' => $sku['stock'],
                    'requested' => $qty,
                    'message' => '部分商品库存不足，请调整数量',
                ];

                return TransitionResult::reject(RejectionCode::OUT_OF_STOCK, [
                    'warnings' => $warnings,
                ]);
            }

            if ($original !== []) {
                // 留存原订单的每个价格段；重复改单后已有多个历史单价时也不能丢价。
                $remaining = min($qty, $originalQty);
                $lineTotalFen = 0;
                $segments = 0;
                $firstRetainedUnit = null;
                foreach ($original as $oldLine) {
                    if ($remaining <= 0) {
                        break;
                    }
                    $retainedQty = min($remaining, $oldLine->qty);
                    if ($retainedQty <= 0) {
                        continue;
                    }
                    $newLines[] = new OrderLine(
                        skuId: $skuId,
                        productId: $oldLine->productId !== '' ? $oldLine->productId : $sku['product_id'],
                        nameSnapshot: $oldLine->nameSnapshot !== '' ? $oldLine->nameSnapshot : $sku['name'],
                        specSnapshot: $oldLine->specSnapshot !== '' ? $oldLine->specSnapshot : $sku['spec'],
                        imageSnapshot: $oldLine->imageSnapshot !== '' ? $oldLine->imageSnapshot : $sku['image'],
                        unitFen: $oldLine->unitFen,
                        qty: $retainedQty,
                    );
                    $lineTotalFen += $oldLine->unitFen * $retainedQty;
                    $firstRetainedUnit ??= $oldLine->unitFen;
                    $segments++;
                    $remaining -= $retainedQty;
                }

                $incrementQty = $qty - $originalQty;
                $currentUnit = (int) $sku['unit_fen'];
                if ($incrementQty > 0) {
                    $newLines[] = new OrderLine(
                        skuId: $skuId,
                        productId: $sku['product_id'],
                        nameSnapshot: $sku['name'],
                        specSnapshot: $sku['spec'],
                        imageSnapshot: $sku['image'],
                        unitFen: $currentUnit,
                        qty: $incrementQty,
                    );
                    $lineTotalFen += $currentUnit * $incrementQty;
                    $segments++;
                }

                $pricedBy = $incrementQty > 0
                    ? 'MIXED'
                    : (count($original) > 1 ? 'HISTORIC_ORDER' : 'ORIGINAL_ORDER');
                $displayUnit = $lineTotalFen % $qty === 0
                    ? intdiv($lineTotalFen, $qty)
                    : ($incrementQty > 0 ? $currentUnit : (int) $firstRetainedUnit);
                $pricedLines[] = [
                    'sku_id' => $skuId,
                    'qty' => $qty,
                    'unit_fen' => $displayUnit,
                    'line_total_fen' => $lineTotalFen,
                    'priced_by' => $pricedBy,
                    ...($segments > 1 ? ['split_lines' => true] : []),
                ];

                continue;
            }

            // 新增 SKU：按现价
            $currentUnit = (int) $sku['unit_fen'];
            $lineTotalFen = $currentUnit * $qty;

            $newLines[] = new OrderLine(
                skuId: $skuId,
                productId: $sku['product_id'],
                nameSnapshot: $sku['name'],
                specSnapshot: $sku['spec'],
                imageSnapshot: $sku['image'],
                unitFen: $currentUnit,
                qty: $qty,
            );

            $pricedLines[] = [
                'sku_id' => $skuId,
                'qty' => $qty,
                'unit_fen' => $currentUnit,
                'line_total_fen' => $lineTotalFen,
                'priced_by' => 'CURRENT_PRICE',
            ];
        }

        if ($newLines === []) {
            return TransitionResult::reject(RejectionCode::VALIDATION_FAILED, [
                'reason' => '订单至少需要一件商品',
            ]);
        }

        $oldGoods = $order->goodsTotal()->fen();
        $newGoods = 0;
        foreach ($newLines as $line) {
            $newGoods += $line->subtotal()->fen();
        }

        $oldTotal = $order->total()->fen();
        $newTotal = $newGoods + $order->deliveryFeeFen;
        $deltaFen = $newTotal - $oldTotal;

        $deltaType = RevisionDeltaType::fromFen($deltaFen);

        return TransitionResult::ok([
            'order_version' => $order->version,
            'old_total_fen' => $oldTotal,
            'new_total_fen' => $newTotal,
            'old_goods_fen' => $oldGoods,
            'new_goods_fen' => $newGoods,
            'delta_fen' => $deltaFen,
            'delta_type' => $deltaType->value,
            'goods_changed' => $oldGoods !== $newGoods,
            'delivery_fee_fen' => $order->deliveryFeeFen,
            'priced_lines' => $pricedLines,
            'lines' => array_map(static fn (OrderLine $l) => $l->toArray(), $newLines),
            'warnings' => $warnings,
            'requires_topup_before_commit' => $deltaType->requiresTopupBeforeCommit(),
            'customer_message' => $deltaType->customerMessage($deltaFen),
            '_new_lines' => $newLines,
        ]);
    }

    /**
     * 库存差额：正数表示需要新增预占，负数表示需要释放。
     *
     * @param OrderLine[] $oldLines
     * @param OrderLine[] $newLines
     * @return array<string,int> sku_id => 数量差额
     */
    public function stockDelta(array $oldLines, array $newLines): array
    {
        $old = [];
        foreach ($oldLines as $line) {
            $old[$line->skuId] = ($old[$line->skuId] ?? 0) + $line->qty;
        }

        $new = [];
        foreach ($newLines as $line) {
            $new[$line->skuId] = ($new[$line->skuId] ?? 0) + $line->qty;
        }

        $delta = [];
        foreach (array_unique(array_merge(array_keys($old), array_keys($new))) as $skuId) {
            $diff = ($new[$skuId] ?? 0) - ($old[$skuId] ?? 0);
            if ($diff !== 0) {
                $delta[$skuId] = $diff;
            }
        }

        return $delta;
    }

    /**
     * 判断修订是否需要走资金流程。
     *
     * - NONE：只更新地址/备注/配送时间或等价商品调整，不触发资金操作
     * - TOPUP：需补款成功后才提交新版本
     * - REFUND：提交修订 + 差额退款任务，并保持退款 PROCESSING
     */
    public function fundingAction(
        int $deltaFen,
        bool $isPaid,
        PaymentMethod $paymentMethod = PaymentMethod::WECHAT,
    ): string
    {
        if ($deltaFen === 0) {
            return 'NONE';
        }

        if ($paymentMethod === PaymentMethod::COD) {
            return 'NONE';
        }

        if (!$isPaid) {
            // 未付款：只更新应付金额，不产生退款。
            return 'REISSUE_PAYMENT';
        }

        return $deltaFen > 0 ? 'TOPUP' : 'REFUND';
    }

    /**
     * 修订状态只要求在线支付的已收款金额走补款/退款；COD 与未付微信订单
     * 修改金额后直接生效，不创建虚假的收退款任务。
     */
    public function stateFor(Order $order, int $deltaFen): OrderRevisionState
    {
        $isSettledCollectable = in_array(
            $order->paymentMethod,
            [PaymentMethod::WECHAT, PaymentMethod::OFFLINE_QR],
            true
        ) && $order->paidFen > 0;

        if ($deltaFen > 0 && $isSettledCollectable) {
            return OrderRevisionState::PENDING_TOPUP;
        }
        if ($deltaFen < 0 && $isSettledCollectable) {
            return OrderRevisionState::APPLYING;
        }

        return OrderRevisionState::COMMITTED;
    }

    public function requiresRefund(Order $order, int $deltaFen): bool
    {
        return $deltaFen < 0
            && in_array($order->paymentMethod, [PaymentMethod::WECHAT, PaymentMethod::OFFLINE_QR], true)
            && $order->paidFen > 0;
    }
}
