<?php

declare(strict_types=1);

namespace Chunlian\Domain;

/**
 * 支付状态。与统一订单状态分别记录，以支持支付、退款和对账。
 *
 * 注意：微信支付页面返回成功 ≠ 资金到账；必须服务端查单/验签后才可进入 PAID。
 * 退款受理 ≠ 退款成功；PROCESSING 期间不得计入成功退款。
 */
enum OrderPaymentState: string
{
    case UNPAID = 'UNPAID';
    case PAYING = 'PAYING';
    case PAID = 'PAID';
    case PARTIAL_REFUNDED = 'PARTIAL_REFUNDED';
    case REFUNDING = 'REFUNDING';
    case REFUNDED = 'REFUNDED';

    /** 是否视为"已收到足额款项"。 */
    public function isSettled(): bool
    {
        return $this === self::PAID;
    }

    /** 是否允许发起新的在线收款动作。 */
    public function allowsNewPayment(): bool
    {
        return in_array($this, [self::UNPAID, self::PARTIAL_REFUNDED], true);
    }

    /** 退款是否仍在进行中（不得计为成功退款）。 */
    public function isRefundInFlight(): bool
    {
        return $this === self::REFUNDING;
    }

    public function displayLabel(): string
    {
        return match ($this) {
            self::UNPAID => '未付款',
            self::PAYING => '支付确认中',
            self::PAID => '已付款',
            self::PARTIAL_REFUNDED => '部分退款',
            self::REFUNDING => '退款处理中',
            self::REFUNDED => '已退款',
        };
    }
}
