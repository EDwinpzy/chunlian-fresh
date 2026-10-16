<?php

declare(strict_types=1);

namespace Chunlian\Domain;

/**
 * 订单行（商品快照）。
 *
 * 下单时固化：名称、规格、图片地址、单价、税费/配送费快照。
 * **之后调价不影响已提交订单。**
 */
final class OrderLine
{
    public function __construct(
        public readonly string $skuId,
        public readonly string $productId,
        public readonly string $nameSnapshot,
        public readonly string $specSnapshot,
        public readonly string $imageSnapshot,
        public readonly int $unitFen,
        public readonly int $qty,
    ) {
        if ($qty <= 0) {
            throw new \InvalidArgumentException('订单行数量必须大于 0');
        }
    }

    public function subtotal(): Money
    {
        return Money::fromFen($this->unitFen)->multiply($this->qty);
    }

    public static function fromArray(array $row): self
    {
        return new self(
            skuId: (string) $row['sku_id'],
            productId: (string) ($row['product_id'] ?? ''),
            nameSnapshot: (string) ($row['name'] ?? ''),
            specSnapshot: (string) ($row['spec'] ?? ''),
            imageSnapshot: (string) ($row['image'] ?? ''),
            unitFen: (int) $row['unit_fen'],
            qty: (int) $row['qty'],
        );
    }

    public function toArray(): array
    {
        return [
            'sku_id' => $this->skuId,
            'product_id' => $this->productId,
            'name' => $this->nameSnapshot,
            'spec' => $this->specSnapshot,
            'image' => $this->imageSnapshot,
            'unit_fen' => $this->unitFen,
            'qty' => $this->qty,
            'subtotal_fen' => $this->subtotal()->fen(),
        ];
    }

    public function withQty(int $qty): self
    {
        return new self(
            skuId: $this->skuId,
            productId: $this->productId,
            nameSnapshot: $this->nameSnapshot,
            specSnapshot: $this->specSnapshot,
            imageSnapshot: $this->imageSnapshot,
            unitFen: $this->unitFen,
            qty: $qty,
        );
    }
}
