<?php

declare(strict_types=1);

namespace Chunlian\Domain;

/** 配送时间模式。 */
enum DeliveryMode: string
{
    /** 尽快安排，不承诺固定分钟数。 */
    case ASAP = 'ASAP';

    /** 顾客希望送达的具体日期时间。 */
    case SCHEDULED = 'SCHEDULED';

    /**
     * 校验预约时间。
     *
     * 规则：SCHEDULED 必须传 expectedAt；必须晚于当前时刻 + 最小缓冲。
     * 时区取服务器经营时区（默认 Asia/Shanghai）。
     */
    public static function validateExpectedAt(
        self $mode,
        ?\DateTimeImmutable $expectedAt,
        \DateTimeImmutable $now,
        int $minLeadMinutes = 30
    ): bool {
        if ($mode === self::ASAP) {
            // ASAP 不传时间；传了也不作为承诺
            return $expectedAt === null || $expectedAt > $now;
        }

        if ($expectedAt === null) {
            return false;
        }

        return $expectedAt >= $now->modify(sprintf('+%d minutes', $minLeadMinutes));
    }

    public function displayLabel(): string
    {
        return $this === self::ASAP ? '尽快配送' : '预约送达';
    }
}
