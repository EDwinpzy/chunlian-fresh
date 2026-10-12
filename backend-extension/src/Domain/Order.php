<?php

declare(strict_types=1);

namespace Chunlian\Domain;

/**
 * 订单聚合根（四维状态 + 版本 + 快照）。
 *
 * 只承载**业务规则**；持久化由 Repository 负责。
 */
final class Order
{
    public function __construct(
        public readonly string $id,
        public readonly string $orderNo,
        public int $version,
        public OrderFulfillment $fulfillment,
        public OrderPaymentState $paymentState,
        public OrderReceiptState $receiptState,
        public OrderRevisionState $revisionState,
        public readonly PaymentMethod $paymentMethod,
        public readonly DeliveryMode $deliveryMode,
        public readonly ?\DateTimeImmutable $expectedAt,
        /** @var OrderLine[] */
        public array $lines,
        public Contact $contact,
        public int $deliveryFeeFen = 0,
        public int $paidFen = 0,
        public int $refundedFen = 0,
        /**
         * 处理中的退款金额（已受理、尚未出最终结果）。
         *
         * 【为什么必须单独记账】
         * 退款不是瞬时完成的：受理后要等微信退款回调或主动查单。
         * 这段时间里钱**还没退出去，但已经不能再被退第二次**。
         * 若可退余额只减「已成功退款」，就会在这段窗口内算出偏大的可退额度，
         * 同一笔钱可能被发起两次退款 —— 这是真实的资金损失，不是理论风险。
         *
         * 与 `LedgerCalculator::refundableFen()` 的口径保持一致：
         * PROCESSING / PENDING 状态的退款流水都占用额度。
         */
        public int $refundingFen = 0,
        public ?\DateTimeImmutable $deliveredAt = null,
        public ?\DateTimeImmutable $receivedAt = null,
        public string $remark = '',
    ) {
    }

    public function goodsTotal(): Money
    {
        $sum = Money::zero();
        foreach ($this->lines as $line) {
            $sum = $sum->add($line->subtotal());
        }

        return $sum;
    }

    public function total(): Money
    {
        return $this->goodsTotal()->add(Money::fromFen($this->deliveryFeeFen));
    }

    /** 顾客自助修改/取消的权威裁决：仅未出发时允许。 */
    public function canCustomerEdit(): bool
    {
        return $this->fulfillment->allowsCustomerSelfService();
    }

    public function canCustomerCancel(): bool
    {
        return $this->fulfillment->allowsCustomerSelfService();
    }

    /** 尚待收取的金额（COD 已送达未收款时 > 0）。 */
    public function receivable(): Money
    {
        return $this->total()->subtract(Money::fromFen($this->paidFen));
    }

    /**
     * 可退余额。防超退。
     *
     * 公式：`已实付 − 已成功退款 − 处理中退款`
     *
     * 【必须减去「处理中」】
     * 退款受理后要等第三方回调，这段窗口内钱还没出去但额度必须已被占用。
     * 只减「已成功退款」会让同一笔钱在窗口期内被退两次。
     * 见 docs/ARCHITECTURE.md ADR-005。
     */
    public function refundable(): Money
    {
        $available = $this->paidFen - $this->refundedFen - $this->refundingFen;

        // 数据异常（例如人工改库）时不允许出现负数可退额度
        return Money::fromFen(max(0, $available));
    }
}
