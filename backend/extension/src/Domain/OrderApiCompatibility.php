<?php

declare(strict_types=1);

namespace Chunlian\Domain;

/**
 * Legacy order fields retained for already-published 1.0.5 Android clients.
 *
 * `order_status` is canonical. The aliases intentionally preserve the older
 * API contract until those clients are retired.
 */
final class OrderApiCompatibility
{
    /** @return array{fulfillment:string,fulfillment_text:string,receipt_state:string} */
    public static function legacyFields(Order $order): array
    {
        return self::legacyFieldsFor($order->orderStatus, $order->receivedAt !== null);
    }

    /** @return array{fulfillment:string,fulfillment_text:string,receipt_state:string} */
    public static function legacyFieldsFor(OrderStatus $status, bool $received): array
    {
        return [
            'fulfillment' => $status->value,
            'fulfillment_text' => match ($status) {
                OrderStatus::PENDING => '待配送',
                OrderStatus::DELIVERING => '配送中',
                OrderStatus::DELIVERED => '已送达',
                OrderStatus::FINISHED => '已完成',
                OrderStatus::CANCELLED => '已取消',
            },
            'receipt_state' => self::receiptState($received),
        ];
    }

    public static function receiptState(bool $received): string
    {
        return $received ? 'CONFIRMED' : 'UNCONFIRMED';
    }
}
