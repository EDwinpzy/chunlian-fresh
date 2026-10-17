<?php

declare(strict_types=1);

namespace Chunlian\Domain;

/**
 * 统一订单状态。
 *
 * 订单从待配送到已完成的完整阶段都由此状态表示。顾客确认收货后
 * 从 DELIVERED 迁移到 FINISHED；付款信息由支付状态和资金流水记录。
 */
enum OrderStatus: string
{
    /** 已下单，未出发；顾客可自助修改/取消。 */
    case PENDING = 'PENDING';

    /** 已出发；锁定顾客自助改/取消。 */
    case DELIVERING = 'DELIVERING';

    /** 已送达，等待顾客确认收货。 */
    case DELIVERED = 'DELIVERED';

    /** 顾客已确认收货，或订单流程按平台规则终结。 */
    case FINISHED = 'FINISHED';

    /** 已取消。 */
    case CANCELLED = 'CANCELLED';

    /** 顾客是否可自助修改、取消 —— 仅未出发且未取消时允许。 */
    public function allowsCustomerSelfService(): bool
    {
        return $this === self::PENDING;
    }

    /** 商家主操作按钮应显示的动作。 */
    public function merchantPrimaryAction(): string
    {
        return match ($this) {
            self::PENDING => 'START_DELIVERY',
            self::DELIVERING => 'CONFIRM_DELIVERY',
            default => 'NONE',
        };
    }

    /** 允许的迁移目标，用于状态机校验。 */
    public function allowedTargets(): array
    {
        return match ($this) {
            self::PENDING => [self::DELIVERING, self::CANCELLED],
            self::DELIVERING => [self::DELIVERED, self::CANCELLED],
            self::DELIVERED => [self::FINISHED],
            self::FINISHED, self::CANCELLED => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTargets(), true);
    }
}
