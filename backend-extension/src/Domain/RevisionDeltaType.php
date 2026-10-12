<?php

declare(strict_types=1);

namespace Chunlian\Domain;

/**
 * 修订的差价方向。
 *
 * 对应 `veg_order_revision.delta_fen` 的符号：正=补款、负=退款、零=无资金动作。
 */
enum RevisionDeltaType: string
{
    case NONE = 'NONE';
    case TOPUP = 'TOPUP';
    case REFUND = 'REFUND';

    public static function fromFen(int $deltaFen): self
    {
        return match (true) {
            $deltaFen > 0 => self::TOPUP,
            $deltaFen < 0 => self::REFUND,
            default => self::NONE,
        };
    }

    /** 客户可见文案。 */
    public function customerMessage(int $deltaFen): string
    {
        $amount = Money::fromFen(abs($deltaFen))->toYuanString();

        return match ($this) {
            self::TOPUP => "本次修改需补付 ¥{$amount}，支付成功后才生效。",
            self::REFUND => "将为你退还差价 ¥{$amount}，退款结果以支付平台为准。",
            self::NONE => '本次修改不涉及金额变化。',
        };
    }

    /** 该差价方向是否需要补款成功后才提交新版本。 */
    public function requiresTopupBeforeCommit(): bool
    {
        return $this === self::TOPUP;
    }
}
