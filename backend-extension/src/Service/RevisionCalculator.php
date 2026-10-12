<?php

declare(strict_types=1);

namespace Chunlian\Service;

use Chunlian\Domain\Order;
use Chunlian\Domain\OrderLine;
use Chunlian\Domain\RejectionCode;
use Chunlian\Domain\RevisionDeltaType;

/**
 * 订单修订计算器。
 *
 * 职责：给定「原订单快照」+「顾客提交的新明细 + 最新 SKU 售价/库存」，
 * 计算出新的订单行、总价与差价，并给出补款/退款方向。
 *
 * 定价规则（默认，可配置但**必须统一**）：
 *   - 原订单已有的 SKU：**维持原下单单价**（不受调价影响）
 *   - 新增 SKU：按**现价**
 *   - 已有 SKU 的**增量数量**：按现价
 *
 * 本类为纯函数，不做持久化；库存预占由 Repository 在事务内完成。
 */
final class RevisionCalculator
{
    /**
     * @param array<string,array{unit_fen:int,stock:int,product_id:string,name:string,spec:string,image:string}> $skuCatalog
     *        最新 SKU 目录（键为 sku_id）
     * @param array<int,array{sku_id:string,qty:int}> $submittedItems 顾客提交的明细
     */
    public function preview(Order $order, array $submittedItems, array $skuCatalog): TransitionResult
    {
        if ($order->fulfillment->value !== 'PENDING') {
            return TransitionResult::reject(RejectionCode::ORDER_NOT_EDITABLE);
        }

        $originalLines = [];
        foreach ($order->lines as $line) {
            $originalLines[$line->skuId] = $line;
        }

        $newLines = [];
        $pricedLines = [];
        $warnings = [];

        foreach ($submittedItems as $item) {
            $skuId = (string) ($item['sku_id'] ?? '');
            $qty = (int) ($item['qty'] ?? 0);

            if ($skuId === '' || $qty <= 0) {
                return TransitionResult::reject(RejectionCode::VALIDATION_FAILED, [
                    'reason' => '商品明细非法',
                    'sku_id' => $skuId,
                ]);
            }

            if (!isset($skuCatalog[$skuId])) {
                return TransitionResult::reject(RejectionCode::VALIDATION_FAILED, [
                    'reason' => '商品不存在或已下架',
                    'sku_id' => $skuId,
                ]);
            }

            $sku = $skuCatalog[$skuId];

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

            $original = $originalLines[$skuId] ?? null;

            if ($original !== null) {
                // 旧有 SKU：维持原下单单价；增量数量按现价
                $originalQty = $original->qty;
                $originalUnit = $original->unitFen;
                $currentUnit = (int) $sku['unit_fen'];

                if ($qty <= $originalQty) {
                    // 不增量或减量：全部按原价
                    $lineTotalFen = $originalUnit * $qty;
                    $pricedBy = 'ORIGINAL_ORDER';
                    $displayUnit = $originalUnit;
                } else {
                    // 增量部分按现价，形成混合计价行
                    $lineTotalFen = $originalUnit * $originalQty
                        + $currentUnit * ($qty - $originalQty);
                    $pricedBy = 'MIXED';
                    $displayUnit = $currentUnit;
                }

                // 关键：行内用 lineTotalFen / qty 反推单元价是错的（会破坏混合段的加权语义）。
                // OrderLine 的 subtotal() = unitFen × qty，因此 unitFen 必须严格等于
                // lineTotalFen ÷ qty。混合计价时余数会落在最后一个单位上，可能导致
                // 尾差 > 1 分；因此只有当 lineTotalFen 能被 qty 整除时才走混合行，
                // 否则把混合段拆成两条独立订单行（原价行 + 现价行），
                // 保证 unitFen × qty === lineTotalFen 恒成立。
                if ($lineTotalFen % $qty === 0) {
                    $newLines[] = new OrderLine(
                        skuId: $skuId,
                        productId: $original->productId !== '' ? $original->productId : $sku['product_id'],
                        nameSnapshot: $sku['name'] !== '' ? $sku['name'] : $original->nameSnapshot,
                        specSnapshot: $sku['spec'] !== '' ? $sku['spec'] : $original->specSnapshot,
                        imageSnapshot: $sku['image'] !== '' ? $sku['image'] : $original->imageSnapshot,
                        unitFen: intdiv($lineTotalFen, $qty),
                        qty: $qty,
                    );

                    $pricedLines[] = [
                        'sku_id' => $skuId,
                        'qty' => $qty,
                        'unit_fen' => intdiv($lineTotalFen, $qty),
                        'line_total_fen' => $lineTotalFen,
                        'priced_by' => $pricedBy,
                    ];
                } else {
                    // 拆分为两条订单行：原价段 + 现价段
                    $newLines[] = new OrderLine(
                        skuId: $skuId,
                        productId: $original->productId,
                        nameSnapshot: $original->nameSnapshot,
                        specSnapshot: $original->specSnapshot,
                        imageSnapshot: $original->imageSnapshot,
                        unitFen: $originalUnit,
                        qty: $originalQty,
                    );

                    if ($qty > $originalQty) {
                        $newLines[] = new OrderLine(
                            skuId: $skuId,
                            productId: $original->productId !== '' ? $original->productId : $sku['product_id'],
                            nameSnapshot: $sku['name'] !== '' ? $sku['name'] : $original->nameSnapshot,
                            specSnapshot: $sku['spec'] !== '' ? $sku['spec'] : $original->specSnapshot,
                            imageSnapshot: $sku['image'] !== '' ? $sku['image'] : $original->imageSnapshot,
                            unitFen: $currentUnit,
                            qty: $qty - $originalQty,
                        );
                    }

                    $pricedLines[] = [
                        'sku_id' => $skuId,
                        'qty' => $qty,
                        'unit_fen' => $displayUnit,
                        'line_total_fen' => $lineTotalFen,
                        'priced_by' => $pricedBy,
                        'split_lines' => true,
                    ];
                }

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
    public function fundingAction(int $deltaFen, bool $isPaid): string
    {
        if ($deltaFen === 0) {
            return 'NONE';
        }

        if (!$isPaid) {
            // 未付款：旧支付单失效/安全关闭，重新生成应付金额
            return 'REISSUE_PAYMENT';
        }

        return $deltaFen > 0 ? 'TOPUP' : 'REFUND';
    }
}
