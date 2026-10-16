<?php

declare(strict_types=1);

namespace Chunlian\Domain;

use InvalidArgumentException;

/**
 * 金额值对象。内部以「分」的整数保存，禁止浮点。
 *
 * 不可变；所有运算返回新实例。
 */
final class Money
{
    private function __construct(private readonly int $fen)
    {
    }

    /** 从「分」构造。 */
    public static function fromFen(int $fen): self
    {
        return new self($fen);
    }

    public static function zero(): self
    {
        return new self(0);
    }

    /**
     * 从「元」字符串构造（如 "3.50"）。
     *
     * 拒绝超过两位小数，避免四舍五入歧义。
     */
    public static function fromYuanString(string $yuan): self
    {
        $normalized = trim($yuan);
        if (!preg_match('/^-?\d+(\.\d{1,2})?$/', $normalized)) {
            throw new InvalidArgumentException("金额格式非法：{$yuan}");
        }

        $negative = str_starts_with($normalized, '-');
        $normalized = ltrim($normalized, '-');
        [$intPart, $decPart] = array_pad(explode('.', $normalized, 2), 2, '');
        $decPart = str_pad($decPart, 2, '0');

        $fen = ((int) $intPart) * 100 + (int) $decPart;

        return new self($negative ? -$fen : $fen);
    }

    public function fen(): int
    {
        return $this->fen;
    }

    public function add(self $other): self
    {
        return new self($this->fen + $other->fen);
    }

    public function subtract(self $other): self
    {
        return new self($this->fen - $other->fen);
    }

    public function multiply(int $qty): self
    {
        if ($qty < 0) {
            throw new InvalidArgumentException('数量不能为负');
        }

        return new self($this->fen * $qty);
    }

    public function isZero(): bool
    {
        return $this->fen === 0;
    }

    public function isPositive(): bool
    {
        return $this->fen > 0;
    }

    public function isNegative(): bool
    {
        return $this->fen < 0;
    }

    public function equals(self $other): bool
    {
        return $this->fen === $other->fen;
    }

    public function greaterThan(self $other): bool
    {
        return $this->fen > $other->fen;
    }

    public function lessThan(self $other): bool
    {
        return $this->fen < $other->fen;
    }

    /** 人类可读金额，用于日志与文案（如 "¥35.80"）。界面展示由前端负责格式化。 */
    public function toYuanString(): string
    {
        $negative = $this->fen < 0;
        $abs = abs($this->fen);
        $yuan = intdiv($abs, 100);
        $cents = $abs % 100;

        return sprintf('%s%d.%02d', $negative ? '-' : '', $yuan, $cents);
    }
}
