<?php

declare(strict_types=1);

namespace Chunlian\Tests\Service;

use Chunlian\Service\MerchantOrderListFilter;
use PHPUnit\Framework\TestCase;

final class MerchantOrderListFilterTest extends TestCase
{
    public function testDeliveredFilterIncludesDeliveredAndFinishedOrders(): void
    {
        self::assertSame([
            'where_sql' => "1 = 1 AND d.`order_status` IN ('DELIVERED','FINISHED')",
            'params' => [],
        ], MerchantOrderListFilter::build('DELIVERED', null));
    }

    public function testOtherStatusesRemainExactAndKeywordCanBeCombined(): void
    {
        self::assertSame([
            'where_sql' => '1 = 1 AND d.`order_status` = :status AND (o.`order_no` LIKE :kw_no OR a.`name` LIKE :kw_name OR a.`tel` LIKE :kw_tel)',
            'params' => [
                'status' => 'DELIVERING',
                'kw_no' => '%林女士%',
                'kw_name' => '%林女士%',
                'kw_tel' => '%林女士%',
            ],
        ], MerchantOrderListFilter::build('DELIVERING', ' 林女士 '));
    }
}
