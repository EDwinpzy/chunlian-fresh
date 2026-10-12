<?php

declare(strict_types=1);

namespace Chunlian\Service;

use Chunlian\Domain\LedgerKind;

/**
 * 资金账本计算器。
 *
 * 统计口径（docs/DATA-MODEL.md §5.7）：
 *   今日实收 = 当天在线确认入账 + 当天 COD 实收 − 当天已成功退款
 *
 * 跨日支付/退款按**资金发生日**归集，另提供"按订单日销售额"指标，不混算。
 */
final class LedgerCalculator
{
    /**
     * @param array<int,array{kind:string,amount_fen:int,status:string,occurred_at:string}> $entries
     *        amount_fen：入账为正，退款为负（或正数但 kind=WX_REFUND，本方法统一按符号处理）
     * @return array{received_fen:int,online_fen:int,cod_fen:int,refund_fen:int,order_sales_fen:int}
     */
    public function dailySummary(array $entries, ?string $businessDate = null): array
    {
        $receivedFen = 0;
        $onlineFen = 0;
        $codFen = 0;
        $refundFen = 0;
        $orderSalesFen = 0;

        foreach ($entries as $entry) {
            $kind = LedgerKind::from($entry['kind']);
            $status = $entry['status'];

            if ($businessDate !== null && !str_starts_with((string) $entry['occurred_at'], $businessDate)) {
                continue;
            }

            // 只有成功的流水计入实收
            if ($status !== 'SUCCESS') {
                continue;
            }

            $amount = (int) $entry['amount_fen'];
            // 退款统一为负数
            if ($kind === LedgerKind::WX_REFUND && $amount > 0) {
                $amount = -$amount;
            }

            $receivedFen += $amount;

            if ($kind === LedgerKind::WX_REFUND) {
                $refundFen += $amount;
            } elseif ($kind === LedgerKind::COD_RECEIPT) {
                $codFen += $amount;
            } else {
                $onlineFen += $amount;
            }

            $orderSalesFen += $amount;
        }

        return [
            'received_fen' => $receivedFen,
            'online_fen' => $onlineFen,
            'cod_fen' => $codFen,
            'refund_fen' => $refundFen,
            'order_sales_fen' => $orderSalesFen,
        ];
    }

    /**
     * 可退余额。防超退：处理中的退款也要占用额度。
     *
     * @param array<int,array{kind:string,amount_fen:int,status:string}> $entries
     */
    public function refundableFen(array $entries): int
    {
        $paid = 0;
        $occupied = 0;

        foreach ($entries as $entry) {
            $kind = LedgerKind::from($entry['kind']);
            $status = $entry['status'];
            $amount = (int) $entry['amount_fen'];

            if ($status === 'SUCCESS' && $kind->isInflow()) {
                $paid += $amount;
            }

            // 已成功 + 处理中的退款都占用额度
            if ($kind === LedgerKind::WX_REFUND && in_array($status, ['SUCCESS', 'PROCESSING', 'PENDING'], true)) {
                $occupied += abs($amount);
            }
        }

        return max(0, $paid - $occupied);
    }

    /**
     * 应收余额（COD 已送达未收款场景）。
     *
     * @param array<int,array{kind:string,amount_fen:int,status:string}> $entries
     */
    public function receivableFen(array $entries, int $orderTotalFen): int
    {
        $paid = 0;

        foreach ($entries as $entry) {
            $kind = LedgerKind::from($entry['kind']);
            $status = $entry['status'];
            $amount = (int) $entry['amount_fen'];

            if ($status !== 'SUCCESS') {
                continue;
            }

            if ($kind->isInflow()) {
                $paid += $amount;
            } elseif ($kind === LedgerKind::WX_REFUND) {
                $paid -= abs($amount);
            }
        }

        return max(0, $orderTotalFen - $paid);
    }

    /** 生成唯一业务单号（我方单号，用于防重复记账）。 */
    public static function businessNo(string $prefix, \DateTimeImmutable $at, int $sequence): string
    {
        return sprintf('%s%s%06d', $prefix, $at->format('YmdHis'), $sequence % 1000000);
    }
}
