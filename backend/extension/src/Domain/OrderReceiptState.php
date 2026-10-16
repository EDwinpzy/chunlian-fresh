<?php

declare(strict_types=1);

namespace Chunlian\Domain;

/** 收货维度状态：顾客是否确认收货。与履约/支付独立。 */
enum OrderReceiptState: string
{
    case UNCONFIRMED = 'UNCONFIRMED';
    case CONFIRMED = 'CONFIRMED';

    /** 仅已送达订单可确认收货。 */
    public function canConfirm(OrderFulfillment $fulfillment): bool
    {
        return $this === self::UNCONFIRMED && $fulfillment === OrderFulfillment::DELIVERED;
    }
}
