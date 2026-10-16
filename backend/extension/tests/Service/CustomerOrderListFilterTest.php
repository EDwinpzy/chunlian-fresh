<?php

declare(strict_types=1);

namespace Chunlian\Tests\Service;

use Chunlian\Service\CustomerOrderListFilter;
use PHPUnit\Framework\TestCase;

final class CustomerOrderListFilterTest extends TestCase
{
    public function testBlankSearchDoesNotAddACondition(): void
    {
        self::assertSame(
            ['where_sql' => '', 'params' => []],
            CustomerOrderListFilter::build('   ', 'shopxo_')
        );
    }

    public function testSearchMatchesOrderNumberOrHistoricalProductTitle(): void
    {
        self::assertSame([
            'where_sql' => '(o.`order_no` LIKE :keyword_order_no OR EXISTS (SELECT 1 FROM `shopxo_order_detail` od WHERE od.`order_id` = o.`id` AND od.`title` LIKE :keyword_item_title))',
            'params' => [
                'keyword_order_no' => '%小青菜%',
                'keyword_item_title' => '%小青菜%',
            ],
        ], CustomerOrderListFilter::build(' 小青菜 ', 'shopxo_'));
    }
}
