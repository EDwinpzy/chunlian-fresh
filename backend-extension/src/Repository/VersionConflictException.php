<?php

declare(strict_types=1);

namespace Chunlian\Repository;

/**
 * 乐观锁冲突。
 *
 * 表示「读取时的 version 与写回时不一致」—— 期间有别的操作改动了这张订单。
 *
 * 【为什么必须抛出，而不是自动重试】
 * 自动重试会用**已经过期的业务判断**去覆盖别人的结果。
 * 例：A 读到「待配送」决定发货；B 同时把订单改单为「待补款」；
 * 若 A 的写回自动重试，就会在 B 的改动之上强行发货 —— 少收钱。
 *
 * 正确做法：抛给上层 → 重新读取 → 重新判断 → 重新决策，
 * 并向客户端返回 409 让其刷新。
 */
final class VersionConflictException extends \RuntimeException
{
    public function __construct(
        public readonly string $orderId,
        public readonly int $expectedVersion,
        public readonly int $actualVersion,
    ) {
        parent::__construct(sprintf(
            '订单 %s 版本冲突：期望 %d，实际 %d。请重新读取后重试。',
            $orderId,
            $expectedVersion,
            $actualVersion
        ));
    }
}
