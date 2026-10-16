<?php

declare(strict_types=1);

namespace Chunlian\Service;

/** Builds the optional search condition for a customer's paged order list. */
final class CustomerOrderListFilter
{
    /**
     * @return array{where_sql:string,params:array<string,string>}
     */
    public static function build(?string $keyword, string $tablePrefix): array
    {
        $keyword = trim($keyword ?? '');
        if ($keyword === '') {
            return ['where_sql' => '', 'params' => []];
        }

        $like = '%' . $keyword . '%';

        return [
            'where_sql' => '(o.`order_no` LIKE :keyword_order_no OR EXISTS ('
                . 'SELECT 1 FROM `' . $tablePrefix . 'order_detail` od '
                . 'WHERE od.`order_id` = o.`id` AND od.`title` LIKE :keyword_item_title))',
            'params' => [
                'keyword_order_no' => $like,
                'keyword_item_title' => $like,
            ],
        ];
    }
}
