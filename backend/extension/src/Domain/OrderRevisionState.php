<?php

declare(strict_types=1);

namespace Chunlian\Domain;

/** 修订维度状态：订单商品与金额修改流程。 */
enum OrderRevisionState: string
{
    case NONE = 'NONE';
    /** 已生成待补款修订单，但尚未覆盖已生效明细。 */
    case PENDING_TOPUP = 'PENDING_TOPUP';
    case APPLYING = 'APPLYING';
    case COMMITTED = 'COMMITTED';
    case FAILED = 'FAILED';

    /**
     * 是否存在未结清的补款。
     *
     * 为 true 时商家**不能开始配送**，同时顾客不能再次发起修订。
     */
    public function blocksDispatch(): bool
    {
        return in_array($this, [self::PENDING_TOPUP, self::APPLYING], true);
    }

    /** 是否允许顾客再次修改订单。 */
    public function allowsNewRevision(): bool
    {
        return in_array($this, [self::NONE, self::COMMITTED, self::FAILED], true);
    }
}
