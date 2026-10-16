<?php

declare(strict_types=1);

namespace Chunlian\Domain;

/** 支付方式。 */
enum PaymentMethod: string
{
    /** 微信支付（在线）。 */
    case WECHAT = 'WECHAT';

    /** 货到付款：下单不收款，送达时核销。 */
    case COD = 'COD';

    /** 顾客扫码预付，店主核实实际到账。 */
    case OFFLINE_QR = 'OFFLINE_QR';

    /** 下单后是否立即处于"待配送"。 */
    public function dispatchableImmediately(): bool
    {
        return $this === self::COD;
    }

    /** 是否需要在送达时弹收款确认。 */
    public function requiresCollectionOnDelivery(): bool
    {
        return $this === self::COD;
    }
}
