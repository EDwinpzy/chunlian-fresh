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
use Chunlian\Domain\RejectionCode;
use Chunlian\Service\OrderStateMachine;
use PHPUnit\Framework\TestCase;

final class OrderStateMachineTest extends TestCase
{
    private OrderStateMachine $sm;

    protected function setUp(): void
    {
        $this->sm = new OrderStateMachine();
    }

    /** 构造一个 COD 待配送订单：青菜×2 (3.50) + 土豆×1 (2.80) = 9.80。 */
    private function makeCodOrder(int $version = 1): Order
    {
        return new Order(
            id: '10025',
            orderNo: '20261012193000001',
            version: $version,
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
            deliveryFeeFen: 0,
        );
    }

    /** 构造一个微信已付款待配送订单。 */
    private function makePaidWechatOrder(int $paidFen = 980, int $version = 3): Order
    {
        $order = $this->makeCodOrder($version);
        $order->paidFen = $paidFen;
        $order->paymentState = OrderPaymentState::PAID;

        return new Order(
            id: $order->id,
            orderNo: $order->orderNo,
            version: $order->version,
            fulfillment: $order->fulfillment,
            paymentState: OrderPaymentState::PAID,
            receiptState: $order->receiptState,
            revisionState: $order->revisionState,
            paymentMethod: PaymentMethod::WECHAT,
            deliveryMode: $order->deliveryMode,
            expectedAt: null,
            lines: $order->lines,
            contact: $order->contact,
            deliveryFeeFen: 0,
            paidFen: $paidFen,
        );
    }

    // ---------- T20：有待补款修订时开始配送应被拒绝 ----------

    public function testStartDeliveryRejectedWhenTopupPending(): void
    {
        $order = $this->makeCodOrder();
        $order->revisionState = OrderRevisionState::PENDING_TOPUP;

        $result = $this->sm->startDelivery($order, 1);

        self::assertFalse($result->success);
        self::assertSame(RejectionCode::TOPUP_REQUIRED, $result->code);
        self::assertSame(409, $result->httpStatus());
        self::assertStringContainsString('补款', $result->message);
    }

    public function testStartDeliveryRejectedWhenApplying(): void
    {
        $order = $this->makeCodOrder();
        $order->revisionState = OrderRevisionState::APPLYING;

        $result = $this->sm->startDelivery($order, 1);

        self::assertFalse($result->success);
        self::assertSame(RejectionCode::TOPUP_REQUIRED, $result->code);
    }

    // ---------- T19：版本冲突 ----------

    public function testStartDeliveryRejectedOnVersionConflict(): void
    {
        $order = $this->makeCodOrder(version: 2);

        $result = $this->sm->startDelivery($order, 1);

        self::assertFalse($result->success);
        self::assertSame(RejectionCode::ORDER_VERSION_CONFLICT, $result->code);
        self::assertSame(2, $result->get('current_version'));
    }

    // ---------- 正常开始配送 ----------

    public function testStartDeliverySucceedsAndLocksCustomerEditing(): void
    {
        $order = $this->makeCodOrder(version: 1);

        $result = $this->sm->startDelivery($order, 1);

        self::assertTrue($result->success);
        self::assertSame(OrderFulfillment::DELIVERING, $result->get('fulfillment'));
        self::assertSame(2, $result->get('version'));
        self::assertTrue($result->get('locked_customer_editing'));
    }

    public function testStartDeliveryRejectedWhenAlreadyDelivering(): void
    {
        $order = $this->makeCodOrder();
        $order->fulfillment = OrderFulfillment::DELIVERING;

        $result = $this->sm->startDelivery($order, 1);

        self::assertFalse($result->success);
        self::assertSame(RejectionCode::ORDER_NOT_EDITABLE, $result->code);
    }

    public function testDispatchableHelper(): void
    {
        $order = $this->makeCodOrder();
        self::assertTrue($this->sm->isDispatchable($order));

        $order->revisionState = OrderRevisionState::PENDING_TOPUP;
        self::assertFalse($this->sm->isDispatchable($order));
    }

    // ---------- T24：COD 已收款并送达 ----------

    public function testCodConfirmDeliveryWithMoneyCollected(): void
    {
        $order = $this->makeCodOrder(version: 4);
        $order->fulfillment = OrderFulfillment::DELIVERING;

        $result = $this->sm->confirmDelivery($order, 4, receivedMoney: true, collectedFen: 980);

        self::assertTrue($result->success);
        self::assertSame(OrderFulfillment::DELIVERED, $result->get('fulfillment'));
        self::assertSame(980, $result->get('paid_fen'));
        self::assertSame(OrderPaymentState::PAID, $result->get('payment_state'));
        self::assertSame(0, $result->get('receivable_fen'));
        self::assertTrue($result->get('write_cod_receipt'));
        self::assertSame(5, $result->get('version'));
    }

    // ---------- T25：COD 仅送达未收款，保留应收 ----------

    public function testCodConfirmDeliveryWithoutCollectionKeepsReceivable(): void
    {
        $order = $this->makeCodOrder(version: 4);
        $order->fulfillment = OrderFulfillment::DELIVERING;

        $result = $this->sm->confirmDelivery($order, 4, receivedMoney: false);

        self::assertTrue($result->success);
        self::assertSame(OrderFulfillment::DELIVERED, $result->get('fulfillment'));
        self::assertSame(0, $result->get('paid_fen'));
        self::assertSame(OrderPaymentState::UNPAID, $result->get('payment_state'));
        self::assertSame(980, $result->get('receivable_fen'));
        self::assertFalse($result->get('write_cod_receipt'));
    }

    public function testCodCollectedAmountMustEqualReceivable(): void
    {
        $order = $this->makeCodOrder(version: 4);
        $order->fulfillment = OrderFulfillment::DELIVERING;

        $result = $this->sm->confirmDelivery($order, 4, receivedMoney: true, collectedFen: 900);

        self::assertFalse($result->success);
        self::assertSame(RejectionCode::VALIDATION_FAILED, $result->code);
        self::assertSame(980, $result->get('receivable_fen'));
    }

    public function testCodCollectedWithoutAmountRejected(): void
    {
        $order = $this->makeCodOrder(version: 4);
        $order->fulfillment = OrderFulfillment::DELIVERING;

        $result = $this->sm->confirmDelivery($order, 4, receivedMoney: true, collectedFen: null);

        self::assertFalse($result->success);
        self::assertSame(RejectionCode::VALIDATION_FAILED, $result->code);
    }

    // ---------- T23：已付订单确认送达不重复记账 ----------

    public function testWechatOrderConfirmDeliveryDoesNotCollectAgain(): void
    {
        $order = $this->makePaidWechatOrder(paidFen: 980, version: 4);
        $order->fulfillment = OrderFulfillment::DELIVERING;

        $result = $this->sm->confirmDelivery($order, 4, receivedMoney: false);

        self::assertTrue($result->success);
        self::assertSame(OrderFulfillment::DELIVERED, $result->get('fulfillment'));
        self::assertSame(980, $result->get('paid_fen'));
        self::assertFalse($result->get('write_cod_receipt'));
    }

    public function testWechatOrderRejectsCollectionAttempt(): void
    {
        $order = $this->makePaidWechatOrder(paidFen: 980, version: 4);
        $order->fulfillment = OrderFulfillment::DELIVERING;

        $result = $this->sm->confirmDelivery($order, 4, receivedMoney: true, collectedFen: 980);

        self::assertFalse($result->success);
        self::assertSame(RejectionCode::VALIDATION_FAILED, $result->code);
    }

    public function testConfirmDeliveryRequiresDeliveringState(): void
    {
        $order = $this->makeCodOrder(version: 4); // 仍是 PENDING

        $result = $this->sm->confirmDelivery($order, 4, receivedMoney: false);

        self::assertFalse($result->success);
        self::assertSame(RejectionCode::ORDER_NOT_EDITABLE, $result->code);
    }

    public function testConfirmDeliveryVersionConflict(): void
    {
        $order = $this->makeCodOrder(version: 5);
        $order->fulfillment = OrderFulfillment::DELIVERING;

        $result = $this->sm->confirmDelivery($order, 4, receivedMoney: false);

        self::assertFalse($result->success);
        self::assertSame(RejectionCode::ORDER_VERSION_CONFLICT, $result->code);
    }

    // ---------- T26 / T27：补记收款与防重复 ----------

    public function testRecordCodReceiptAfterUndeliveredCollection(): void
    {
        $order = $this->makeCodOrder(version: 5);
        $order->fulfillment = OrderFulfillment::DELIVERED;

        $result = $this->sm->recordCodReceipt($order, 5, 980);

        self::assertTrue($result->success);
        self::assertSame(980, $result->get('paid_fen'));
        self::assertSame(OrderPaymentState::PAID, $result->get('payment_state'));
        self::assertSame(0, $result->get('receivable_fen'));
        self::assertTrue($result->get('write_cod_receipt'));
    }

    public function testRecordCodReceiptRejectedWhenAlreadyCollected(): void
    {
        $order = $this->makeCodOrder(version: 6);
        $order->fulfillment = OrderFulfillment::DELIVERED;
        $order->paidFen = 980;
        $order->paymentState = OrderPaymentState::PAID;

        $result = $this->sm->recordCodReceipt($order, 6, 980);

        self::assertFalse($result->success);
        self::assertSame(RejectionCode::COD_ALREADY_COLLECTED, $result->code);
    }

    public function testRecordCodReceiptRejectedBeforeDelivery(): void
    {
        $order = $this->makeCodOrder(version: 5); // PENDING

        $result = $this->sm->recordCodReceipt($order, 5, 980);

        self::assertFalse($result->success);
        self::assertSame(RejectionCode::ORDER_NOT_EDITABLE, $result->code);
    }

    public function testRecordCodReceiptRejectedForWechatOrder(): void
    {
        $order = $this->makePaidWechatOrder(paidFen: 0, version: 5);
        $order->fulfillment = OrderFulfillment::DELIVERED;

        $result = $this->sm->recordCodReceipt($order, 5, 980);

        self::assertFalse($result->success);
        self::assertSame(RejectionCode::VALIDATION_FAILED, $result->code);
    }

    // ---------- T17 / T18：取消订单 ----------

    public function testCancelUnpaidCodReleasesStockWithoutRefund(): void
    {
        $order = $this->makeCodOrder(version: 2);

        $result = $this->sm->cancel($order, 2);

        self::assertTrue($result->success);
        self::assertSame(OrderFulfillment::CANCELLED, $result->get('fulfillment'));
        self::assertSame(0, $result->get('refund_fen'));
        self::assertTrue($result->get('release_stock'));
        self::assertSame(3, $result->get('version'));
    }

    public function testCancelPaidOrderTriggersRefund(): void
    {
        $order = $this->makePaidWechatOrder(paidFen: 980, version: 3);
        $order->paymentState = OrderPaymentState::PAID;

        $result = $this->sm->cancel($order, 3);

        self::assertTrue($result->success);
        self::assertSame(OrderFulfillment::CANCELLED, $result->get('fulfillment'));
        self::assertSame(980, $result->get('refund_fen'));
        self::assertSame(OrderPaymentState::REFUNDING, $result->get('payment_state'));
    }

    /** 防超退：已有部分退款时，应退金额必须扣减。 */
    public function testCancelRefundAmountExcludesAlreadyRefunded(): void
    {
        $order = $this->makePaidWechatOrder(paidFen: 980, version: 3);
        $order->paymentState = OrderPaymentState::PARTIAL_REFUNDED;
        $order->refundedFen = 300;

        $result = $this->sm->cancel($order, 3);

        self::assertTrue($result->success);
        self::assertSame(680, $result->get('refund_fen'));
    }

    /** 全部已退后取消不得再退。 */
    public function testCancelAfterFullRefundRefundsZero(): void
    {
        $order = $this->makePaidWechatOrder(paidFen: 980, version: 3);
        $order->paymentState = OrderPaymentState::REFUNDED;
        $order->refundedFen = 980;

        $result = $this->sm->cancel($order, 3);

        self::assertTrue($result->success);
        self::assertSame(0, $result->get('refund_fen'));
    }

    // ---------- T21 / T22：已开始配送后禁止自助改/取消 ----------

    public function testCancelRejectedAfterDispatch(): void
    {
        $order = $this->makeCodOrder(version: 3);
        $order->fulfillment = OrderFulfillment::DELIVERING;

        $result = $this->sm->cancel($order, 3);

        self::assertFalse($result->success);
        self::assertSame(RejectionCode::ORDER_NOT_EDITABLE, $result->code);
        self::assertSame('AFTER_SALES', $result->get('next_step'));
    }

    public function testRevisionRejectedAfterDispatch(): void
    {
        $order = $this->makeCodOrder(version: 3);
        $order->fulfillment = OrderFulfillment::DELIVERING;

        $result = $this->sm->canStartRevision($order, 3);

        self::assertFalse($result->success);
        self::assertSame(RejectionCode::ORDER_NOT_EDITABLE, $result->code);
    }

    public function testRevisionRejectedWhenTopupPending(): void
    {
        $order = $this->makeCodOrder(version: 3);
        $order->revisionState = OrderRevisionState::PENDING_TOPUP;

        $result = $this->sm->canStartRevision($order, 3);

        self::assertFalse($result->success);
        self::assertSame(RejectionCode::TOPUP_REQUIRED, $result->code);
    }

    public function testRevisionAllowedWhenPending(): void
    {
        $order = $this->makeCodOrder(version: 3);

        $result = $this->sm->canStartRevision($order, 3);

        self::assertTrue($result->success);
    }

    // ---------- T28：确认收货 ----------

    public function testConfirmReceiptRequiresDelivered(): void
    {
        $order = $this->makeCodOrder(version: 5);
        $order->fulfillment = OrderFulfillment::DELIVERED;

        $result = $this->sm->confirmReceipt($order, 5);

        self::assertTrue($result->success);
        self::assertSame(OrderFulfillment::FINISHED, $result->get('fulfillment'));
        self::assertSame(OrderReceiptState::CONFIRMED, $result->get('receipt_state'));
    }

    public function testConfirmReceiptRejectedWhenDelivering(): void
    {
        $order = $this->makeCodOrder(version: 5);
        $order->fulfillment = OrderFulfillment::DELIVERING;

        $result = $this->sm->confirmReceipt($order, 5);

        self::assertFalse($result->success);
    }

    /** 收货独立于支付：COD 送达未收款也能确认收货。 */
    public function testConfirmReceiptIndependentOfPayment(): void
    {
        $order = $this->makeCodOrder(version: 5);
        $order->fulfillment = OrderFulfillment::DELIVERED;
        $order->paymentState = OrderPaymentState::UNPAID;

        $result = $this->sm->confirmReceipt($order, 5);

        self::assertTrue($result->success);
        self::assertSame(OrderPaymentState::UNPAID, $order->paymentState);
    }

    // ---------- T08：预约时间校验 ----------

    public function testValidateDeliveryTimeRejectsPast(): void
    {
        $now = new \DateTimeImmutable('2026-10-12 19:00:00');

        $result = $this->sm->validateDeliveryTime(
            DeliveryMode::SCHEDULED,
            new \DateTimeImmutable('2026-10-11 17:30:00'),
            $now
        );

        self::assertFalse($result->success);
        self::assertSame(RejectionCode::INVALID_EXPECTED_TIME, $result->code);
    }

    public function testValidateDeliveryTimeAcceptsFuture(): void
    {
        $now = new \DateTimeImmutable('2026-10-12 19:00:00');

        $result = $this->sm->validateDeliveryTime(
            DeliveryMode::SCHEDULED,
            new \DateTimeImmutable('2026-10-13 17:30:00'),
            $now
        );

        self::assertTrue($result->success);
    }

    // ---------- 补款方向 ----------

    public function testRequiresTopupBeforeCommit(): void
    {
        self::assertTrue($this->sm->requiresTopupBeforeCommit(940));
        self::assertFalse($this->sm->requiresTopupBeforeCommit(-940));
        self::assertFalse($this->sm->requiresTopupBeforeCommit(0));
    }
}
