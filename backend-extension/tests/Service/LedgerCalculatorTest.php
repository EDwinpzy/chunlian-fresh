<?php

declare(strict_types=1);

namespace Chunlian\Tests\Service;

use Chunlian\Service\LedgerCalculator;
use PHPUnit\Framework\TestCase;

/**
 * 资金账本测试 —— 对应 T25（COD 未收款不计入实收）、T12、T17。
 *
 * 统计口径：今日实收 = 当天在线确认入账 + 当天 COD 实收 − 当天已成功退款
 */
final class LedgerCalculatorTest extends TestCase
{
    private LedgerCalculator $calc;

    protected function setUp(): void
    {
        $this->calc = new LedgerCalculator();
    }

    public function testDailySummarySeparatesOnlineAndCod(): void
    {
        $entries = [
            ['kind' => 'WX_PAY', 'amount_fen' => 21000, 'status' => 'SUCCESS', 'occurred_at' => '2026-10-12 10:00:00'],
            ['kind' => 'COD_RECEIPT', 'amount_fen' => 7650, 'status' => 'SUCCESS', 'occurred_at' => '2026-10-12 18:40:00'],
        ];

        $summary = $this->calc->dailySummary($entries, '2026-10-12');

        self::assertSame(28650, $summary['received_fen']);
        self::assertSame(21000, $summary['online_fen']);
        self::assertSame(7650, $summary['cod_fen']);
        self::assertSame(0, $summary['refund_fen']);
    }

    public function testRefundSubtractsFromReceived(): void
    {
        $entries = [
            ['kind' => 'WX_PAY', 'amount_fen' => 10000, 'status' => 'SUCCESS', 'occurred_at' => '2026-10-12 10:00:00'],
            ['kind' => 'WX_REFUND', 'amount_fen' => -3000, 'status' => 'SUCCESS', 'occurred_at' => '2026-10-12 11:00:00'],
        ];

        $summary = $this->calc->dailySummary($entries, '2026-10-12');

        self::assertSame(7000, $summary['received_fen']);
        self::assertSame(-3000, $summary['refund_fen']);
    }

    /** 退款金额若以正数存储，也应被正确视为扣减。 */
    public function testRefundStoredAsPositiveIsNormalized(): void
    {
        $entries = [
            ['kind' => 'WX_PAY', 'amount_fen' => 10000, 'status' => 'SUCCESS', 'occurred_at' => '2026-10-12 10:00:00'],
            ['kind' => 'WX_REFUND', 'amount_fen' => 3000, 'status' => 'SUCCESS', 'occurred_at' => '2026-10-12 11:00:00'],
        ];

        self::assertSame(7000, $this->calc->dailySummary($entries, '2026-10-12')['received_fen']);
    }

    /** T25 核心：处理中/失败的退款不得扣减实收；未收款 COD 不得计为实收。 */
    public function testNonSuccessEntriesAreExcluded(): void
    {
        $entries = [
            ['kind' => 'WX_PAY', 'amount_fen' => 10000, 'status' => 'SUCCESS', 'occurred_at' => '2026-10-12 10:00:00'],
            ['kind' => 'WX_PAY', 'amount_fen' => 5000, 'status' => 'PENDING', 'occurred_at' => '2026-10-12 10:30:00'],
            ['kind' => 'WX_REFUND', 'amount_fen' => -2000, 'status' => 'PROCESSING', 'occurred_at' => '2026-10-12 11:00:00'],
            ['kind' => 'COD_RECEIPT', 'amount_fen' => 3000, 'status' => 'FAILED', 'occurred_at' => '2026-10-12 12:00:00'],
        ];

        $summary = $this->calc->dailySummary($entries, '2026-10-12');

        self::assertSame(10000, $summary['received_fen']);
        self::assertSame(0, $summary['cod_fen']);
        self::assertSame(0, $summary['refund_fen']);
    }

    public function testBusinessDateFilterExcludesOtherDays(): void
    {
        $entries = [
            ['kind' => 'WX_PAY', 'amount_fen' => 10000, 'status' => 'SUCCESS', 'occurred_at' => '2026-10-11 23:59:00'],
            ['kind' => 'WX_PAY', 'amount_fen' => 5000, 'status' => 'SUCCESS', 'occurred_at' => '2026-10-12 00:01:00'],
        ];

        self::assertSame(5000, $this->calc->dailySummary($entries, '2026-10-12')['received_fen']);
        self::assertSame(10000, $this->calc->dailySummary($entries, '2026-10-11')['received_fen']);
    }

    public function testTopupCountsAsOnline(): void
    {
        $entries = [
            ['kind' => 'TOPUP', 'amount_fen' => 940, 'status' => 'SUCCESS', 'occurred_at' => '2026-10-12 15:00:00'],
        ];

        $summary = $this->calc->dailySummary($entries, '2026-10-12');

        self::assertSame(940, $summary['received_fen']);
        self::assertSame(940, $summary['online_fen']);
        self::assertSame(0, $summary['cod_fen']);
    }

    // ---------- 可退余额 / 防超退 ----------

    public function testRefundableEqualsPaidWhenNoRefund(): void
    {
        $entries = [
            ['kind' => 'WX_PAY', 'amount_fen' => 980, 'status' => 'SUCCESS'],
        ];

        self::assertSame(980, $this->calc->refundableFen($entries));
    }

    public function testRefundableExcludesSucceededRefund(): void
    {
        $entries = [
            ['kind' => 'WX_PAY', 'amount_fen' => 980, 'status' => 'SUCCESS'],
            ['kind' => 'WX_REFUND', 'amount_fen' => -300, 'status' => 'SUCCESS'],
        ];

        self::assertSame(680, $this->calc->refundableFen($entries));
    }

    /** 关键：处理中的退款也要占用额度，防止重复发起。 */
    public function testRefundableExcludesInFlightRefund(): void
    {
        $entries = [
            ['kind' => 'WX_PAY', 'amount_fen' => 980, 'status' => 'SUCCESS'],
            ['kind' => 'WX_REFUND', 'amount_fen' => -300, 'status' => 'PROCESSING'],
        ];

        self::assertSame(680, $this->calc->refundableFen($entries));
    }

    public function testRefundableNeverNegative(): void
    {
        $entries = [
            ['kind' => 'WX_PAY', 'amount_fen' => 980, 'status' => 'SUCCESS'],
            ['kind' => 'WX_REFUND', 'amount_fen' => -980, 'status' => 'SUCCESS'],
        ];

        self::assertSame(0, $this->calc->refundableFen($entries));
    }

    public function testFailedRefundDoesNotConsumeQuota(): void
    {
        $entries = [
            ['kind' => 'WX_PAY', 'amount_fen' => 980, 'status' => 'SUCCESS'],
            ['kind' => 'WX_REFUND', 'amount_fen' => -300, 'status' => 'FAILED'],
        ];

        self::assertSame(980, $this->calc->refundableFen($entries));
    }

    // ---------- 应收余额 ----------

    public function testReceivableForUndeliveredCod(): void
    {
        $entries = [
            ['kind' => 'WX_PAY', 'amount_fen' => 0, 'status' => 'SUCCESS'],
        ];

        self::assertSame(980, $this->calc->receivableFen($entries, 980));
    }

    public function testReceivableZeroAfterCodCollected(): void
    {
        $entries = [
            ['kind' => 'COD_RECEIPT', 'amount_fen' => 980, 'status' => 'SUCCESS'],
        ];

        self::assertSame(0, $this->calc->receivableFen($entries, 980));
    }

    public function testReceivableZeroWhenWechatPaid(): void
    {
        $entries = [
            ['kind' => 'WX_PAY', 'amount_fen' => 980, 'status' => 'SUCCESS'],
        ];

        self::assertSame(0, $this->calc->receivableFen($entries, 980));
    }

    public function testReceivableAfterPartialRefund(): void
    {
        $entries = [
            ['kind' => 'WX_PAY', 'amount_fen' => 980, 'status' => 'SUCCESS'],
            ['kind' => 'WX_REFUND', 'amount_fen' => -300, 'status' => 'SUCCESS'],
        ];

        // 已实付 680，应收 300
        self::assertSame(300, $this->calc->receivableFen($entries, 980));
    }

    // ---------- 业务单号 ----------

    public function testBusinessNoFormat(): void
    {
        $no = LedgerCalculator::businessNo('RK', new \DateTimeImmutable('2026-10-12 19:30:00'), 1);

        self::assertSame('RK20261012193000000001', $no);
    }

    public function testBusinessNoIsUniquePerSequence(): void
    {
        $at = new \DateTimeImmutable('2026-10-12 19:30:00');

        self::assertNotSame(
            LedgerCalculator::businessNo('RK', $at, 1),
            LedgerCalculator::businessNo('RK', $at, 2)
        );
    }
}
