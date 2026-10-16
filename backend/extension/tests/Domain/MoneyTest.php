<?php

declare(strict_types=1);

namespace Chunlian\Tests\Domain;

use Chunlian\Domain\Money;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    public function testFromFenStoresInteger(): void
    {
        self::assertSame(3580, Money::fromFen(3580)->fen());
    }

    public function testFromYuanStringParsesTwoDecimals(): void
    {
        self::assertSame(350, Money::fromYuanString('3.50')->fen());
        self::assertSame(280, Money::fromYuanString('2.8')->fen());
        self::assertSame(300, Money::fromYuanString('3')->fen());
        self::assertSame(0, Money::fromYuanString('0.00')->fen());
    }

    public function testFromYuanStringParsesNegative(): void
    {
        self::assertSame(-1250, Money::fromYuanString('-12.50')->fen());
    }

    public function testFromYuanStringRejectsMoreThanTwoDecimals(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::fromYuanString('3.505');
    }

    public function testFromYuanStringRejectsGarbage(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::fromYuanString('abc');
    }

    public function testAddAndSubtract(): void
    {
        $a = Money::fromFen(3580);
        $b = Money::fromFen(940);

        self::assertSame(4520, $a->add($b)->fen());
        self::assertSame(2640, $a->subtract($b)->fen());
    }

    public function testMultiply(): void
    {
        self::assertSame(1050, Money::fromFen(350)->multiply(3)->fen());
    }

    public function testMultiplyRejectsNegativeQty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::fromFen(350)->multiply(-1);
    }

    public function testSignHelpers(): void
    {
        self::assertTrue(Money::zero()->isZero());
        self::assertTrue(Money::fromFen(1)->isPositive());
        self::assertTrue(Money::fromFen(-1)->isNegative());
        self::assertFalse(Money::fromFen(0)->isPositive());
    }

    public function testComparisons(): void
    {
        self::assertTrue(Money::fromFen(100)->greaterThan(Money::fromFen(99)));
        self::assertTrue(Money::fromFen(99)->lessThan(Money::fromFen(100)));
        self::assertTrue(Money::fromFen(100)->equals(Money::fromFen(100)));
    }

    public function testToYuanStringFormatsCents(): void
    {
        self::assertSame('35.80', Money::fromFen(3580)->toYuanString());
        self::assertSame('3.05', Money::fromFen(305)->toYuanString());
        self::assertSame('0.00', Money::zero()->toYuanString());
        self::assertSame('-9.40', Money::fromFen(-940)->toYuanString());
    }

    public function testNoFloatingPointDrift(): void
    {
        // 0.1 + 0.2 在浮点下是 0.30000000000000004；整数分不会漂移
        $sum = Money::fromYuanString('0.10')->add(Money::fromYuanString('0.20'));
        self::assertSame(30, $sum->fen());
        self::assertSame('0.30', $sum->toYuanString());
    }
}
