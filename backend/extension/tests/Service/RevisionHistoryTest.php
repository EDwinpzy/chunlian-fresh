<?php

declare(strict_types=1);

namespace Chunlian\Tests\Service;

use Chunlian\Service\RevisionService;
use PHPUnit\Framework\TestCase;

final class RevisionHistoryTest extends TestCase
{
    private function summary(array $before, array $after): string
    {
        $type = new \ReflectionClass(RevisionService::class);
        $service = $type->newInstanceWithoutConstructor();
        return $type->getMethod('diffSummary')->invoke($service, $before, $after);
    }

    public function testSplitPriceIncrementCountsAllLines(): void
    {
        self::assertSame('sku-a ×1 → ×2', $this->summary(
            [['sku_id' => 'sku-a', 'qty' => 1]],
            [['sku_id' => 'sku-a', 'qty' => 1], ['sku_id' => 'sku-a', 'qty' => 1]],
        ));
    }

    public function testSplitPriceReductionCountsAllOriginalLines(): void
    {
        self::assertSame('sku-a ×4 → ×1', $this->summary(
            [['sku_id' => 'sku-a', 'qty' => 2], ['sku_id' => 'sku-a', 'qty' => 2]],
            [['sku_id' => 'sku-a', 'qty' => 1]],
        ));
    }

    public function testEquivalentLineSplitsDoNotInventQuantityChange(): void
    {
        self::assertSame('无明细变化', $this->summary(
            [['sku_id' => 'sku-a', 'qty' => 2]],
            [['sku_id' => 'sku-a', 'qty' => 1], ['sku_id' => 'sku-a', 'qty' => 1]],
        ));
    }
}
