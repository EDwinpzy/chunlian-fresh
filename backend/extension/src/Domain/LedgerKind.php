<?php

declare(strict_types=1);

namespace Chunlian\Domain;

/** 资金流水类型。 */
enum LedgerKind: string
{
    /** 主支付（在线）。 */
    case WX_PAY = 'WX_PAY';

    /** 修订补款。 */
    case TOPUP = 'TOPUP';

    /** 微信退款（金额为负）。 */
    case WX_REFUND = 'WX_REFUND';

    /** 货到付款线下实收。 */
    case COD_RECEIPT = 'COD_RECEIPT';

    case OFFLINE_RECEIPT = 'OFFLINE_RECEIPT';
    case OFFLINE_REFUND = 'OFFLINE_REFUND';

    /** 是否属于"收到的钱"（用于今日实收统计）。 */
    public function isInflow(): bool
    {
        return !in_array($this, [self::WX_REFUND, self::OFFLINE_REFUND], true);
    }

    /** COD 收款是线下收款事实记录，不是微信支付平台回调。 */
    public function isOffline(): bool
    {
        return in_array($this, [self::COD_RECEIPT, self::OFFLINE_RECEIPT, self::OFFLINE_REFUND], true);
    }

    /** 统计"今日实收"时计入的类型。 */
    public static function receivedKinds(): array
    {
        return [self::WX_PAY, self::TOPUP, self::COD_RECEIPT, self::WX_REFUND, self::OFFLINE_RECEIPT, self::OFFLINE_REFUND];
    }
}
