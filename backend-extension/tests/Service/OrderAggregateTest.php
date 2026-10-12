<?php

declare(strict_types=1);

namespace Chunlian\Tests\Service;

use Chunlian\Domain\Contact;
use Chunlian\Domain\DeliveryMode;
use Chunlian\Domain\Order;
use Chunlian\Domain\OrderFulfillment;
use Chunlian\Domain\OrderLine;
use Chunlian\Domain\OrderPaymentState;
use Chunlian\Domain\OrderReceiptState;
use Chunlian\Domain\OrderRevisionState;
use Chunlian\Domain\PaymentMethod;
use PHPUnit\Framework\TestCase;

/**
 * 订单聚合根测试 —— 金额与可退余额的计算基础。
 */
final class OrderAggregateTest extends TestCase
{
    private function makeOrder(int $deliveryFeeFen = 0): Order
    {
        return new Order(
            id: '10025',
            orderNo: '20261012193000001',
            version: 1,
            fulfillment: OrderFulfillment::PENDING,
            paymentState: OrderPaymentState::UNPAID,
            receiptState: OrderReceiptState::UNCONFIRMED,
            revisionState: OrderRevisionState::NONE,
            paymentMethod: PaymentMethod::COD,
            deliveryMode: DeliveryMode::ASAP,
            expectedAt: null,
            lines: [
                new OrderLine('SKU_102', 'P1', '小青菜', '500g/份', 'a.jpg', 350, 2),
                new OrderLine('SKU_205', 'P2', '土豆', '500g/份', 'b.jpg', 280, 1),
            ],
            contact: new Contact('张先生', '13800138000', '阳光小区3栋502室'),
            deliveryFeeFen: $deliveryFeeFen,
        );
    }

    public function testGoodsTotalSumsLineSubtotals(): void
    {
        self::assertSame(980, $this->makeOrder()->goodsTotal()->fen());
    }

    public function testTotalAddsDeliveryFee(): void
    {
        self::assertSame(980, $this->makeOrder()->total()->fen());
        self::assertSame(1180, $this->makeOrder(200)->total()->fen());
    }

    public function testReceivableEqualsTotalWhenUnpaid(): void
    {
        self::assertSame(980, $this->makeOrder()->receivable()->fen());
    }

    public function testReceivableZeroWhenFullyPaid(): void
    {
        $order = $this->makeOrder();
        $order->paidFen = 980;

        self::assertSame(0, $order->receivable()->fen());
    }

    public function testRefundableEqualsPaidWhenNoRefund(): void
    {
        $order = $this->makeOrder();
        $order->paidFen = 980;

        self::assertSame(980, $order->refundable()->fen());
    }

    public function testRefundableExcludesRefunded(): void
    {
        $order = $this->makeOrder();
        $order->paidFen = 980;
        $order->refundedFen = 300;

        self::assertSame(680, $order->refundable()->fen());
    }

    /**
     * 回归测试：可退余额必须扣除**处理中**的退款。
     *
     * 退款受理后要等第三方回调，这段窗口内钱还没出去，但额度必须已被占用。
     * 若只减「已成功退款」，同一笔钱会在窗口期内被退两次 —— 真实资金损失。
     * 见 docs/ARCHITECTURE.md ADR-005。
     */
    public function testRefundableExcludesInFlightRefund(): void
    {
        $order = $this->makeOrder();
        $order->paidFen = 1000;
        $order->refundedFen = 0;
        $order->refundingFen = 1000;   // 一笔全额退款正在处理中

        // 额度已被占用完，不能再发起第二次退款
        self::assertSame(0, $order->refundable()->fen());
    }

    public function testRefundableSubtractsBothRefundedAndRefunding(): void
    {
        $order = $this->makeOrder();
        $order->paidFen = 1000;
        $order->refundedFen = 300;     // 已成功
        $order->refundingFen = 200;    // 处理中

        // 1000 − 300 − 200 = 500
        self::assertSame(500, $order->refundable()->fen());
    }

    public function testRefundableNeverNegative(): void
    {
        $order = $this->makeOrder();
        $order->paidFen = 100;
        $order->refundedFen = 200;     // 数据异常（人工改库等）

        self::assertSame(0, $order->refundable()->fen());
    }

    public function testLineSubtotalUsesIntegerCents(): void
    {
        $line = new OrderLine('SKU_102', 'P1', '小青菜', '500g/份', 'a.jpg', 350, 3);

        self::assertSame(1050, $line->subtotal()->fen());
    }

    public function testLineRejectsZeroQty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new OrderLine('SKU_102', 'P1', '小青菜', '500g/份', 'a.jpg', 350, 0);
    }

    public function testLineSnapshotSerialization(): void
    {
        $line = new OrderLine('SKU_102', 'P1', '小青菜', '500g/份', 'a.jpg', 350, 2);
        $array = $line->toArray();

        self::assertSame('SKU_102', $array['sku_id']);
        self::assertSame(350, $array['unit_fen']);
        self::assertSame(2, $array['qty']);
        self::assertSame(700, $array['subtotal_fen']);
    }

    public function testLineFromArrayRoundTrip(): void
    {
        $original = new OrderLine('SKU_102', 'P1', '小青菜', '500g/份', 'a.jpg', 350, 2);
        $restored = OrderLine::fromArray($original->toArray());

        self::assertSame($original->skuId, $restored->skuId);
        self::assertSame($original->unitFen, $restored->unitFen);
        self::assertSame($original->qty, $restored->qty);
        self::assertSame($original->subtotal()->fen(), $restored->subtotal()->fen());
    }
}
