<?php

declare(strict_types=1);

namespace Chunlian\Tests\Domain;

use Chunlian\Domain\OrderApiCompatibility;
use Chunlian\Domain\OrderStatus;
use PHPUnit\Framework\TestCase;

final class OrderApiCompatibilityTest extends TestCase
{
    public function testLegacyFulfillmentAliasesMatchCanonicalOrderStatus(): void
    {
        $expected = [
            OrderStatus::PENDING->value => ['PENDING', '待配送'],
            OrderStatus::DELIVERING->value => ['DELIVERING', '配送中'],
            OrderStatus::DELIVERED->value => ['DELIVERED', '已送达'],
            OrderStatus::FINISHED->value => ['FINISHED', '已完成'],
            OrderStatus::CANCELLED->value => ['CANCELLED', '已取消'],
        ];

        foreach (OrderStatus::cases() as $status) {
            $fields = OrderApiCompatibility::legacyFieldsFor($status, false);

            self::assertSame($expected[$status->value][0], $fields['fulfillment']);
            self::assertSame($expected[$status->value][1], $fields['fulfillment_text']);
        }
    }

    public function testLegacyReceiptStateTracksReceivedTimestamp(): void
    {
        self::assertSame(
            'UNCONFIRMED',
            OrderApiCompatibility::legacyFieldsFor(OrderStatus::FINISHED, false)['receipt_state'],
        );
        self::assertSame(
            'CONFIRMED',
            OrderApiCompatibility::legacyFieldsFor(OrderStatus::DELIVERED, true)['receipt_state'],
        );
        self::assertSame(
            'UNCONFIRMED',
            OrderApiCompatibility::legacyFieldsFor(OrderStatus::DELIVERED, false)['receipt_state'],
        );
    }
}
