<?php

declare(strict_types=1);

namespace Chunlian\Service;

/** Builds the SQL conditions and bindings used by the merchant order list. */
final class MerchantOrderListFilter
{
    /**
     * @return array{where_sql:string,params:array<string,string>}
     */
    public static function build(?string $status, ?string $keyword): array
    {
        $where = ['1 = 1'];
        $params = [];

        if ($status !== null && $status !== '') {
            if ($status === 'DELIVERED') {
                $where[] = "d.`order_status` IN ('DELIVERED','FINISHED')";
            } else {
                $where[] = 'd.`order_status` = :status';
                $params['status'] = $status;
            }
        }

        if ($keyword !== null && trim($keyword) !== '') {
            // ShopXO's address table calls the phone column `tel`.
            $where[] = '(o.`order_no` LIKE :kw_no OR a.`name` LIKE :kw_name OR a.`tel` LIKE :kw_tel)';
            $params['kw_no'] = $params['kw_name'] = $params['kw_tel'] = '%' . trim($keyword) . '%';
        }

        return [
            'where_sql' => implode(' AND ', $where),
            'params' => $params,
        ];
    }
}
