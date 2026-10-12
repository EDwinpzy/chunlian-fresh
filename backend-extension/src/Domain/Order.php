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

    /** 可退余额：总已实付 − 总已成功退款。防超退。 */
    public function refundable(): Money
    {
        return Money::fromFen($this->paidFen)->subtract(Money::fromFen($this->refundedFen));
    }
}
