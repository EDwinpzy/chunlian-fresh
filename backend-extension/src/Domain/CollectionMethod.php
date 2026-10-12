<?php

declare(strict_types=1);

namespace Chunlian\Domain;

/** COD 收款渠道。 */
enum CollectionMethod: string
{
    case CASH = 'CASH';
    case OFFLINE_QR = 'OFFLINE_QR';
    case OTHER = 'OTHER';

    public function displayLabel(): string
    {
        return match ($this) {
            self::CASH => '现金',
            self::OFFLINE_QR => '线下扫码',
            self::OTHER => '其他',
        };
    }
}
