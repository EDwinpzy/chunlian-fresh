<?php

declare(strict_types=1);

namespace Chunlian\Repository;

/** 订单不存在（或已被删除）。 */
final class OrderNotFoundException extends \RuntimeException
{
    public function __construct(public readonly string $orderId)
    {
        parent::__construct(sprintf('订单 %s 不存在', $orderId));
    }
}
