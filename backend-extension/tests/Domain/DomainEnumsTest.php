<?php

declare(strict_types=1);

namespace Chunlian\Tests\Domain;

use Chunlian\Domain\Contact;
use Chunlian\Domain\DeliveryMode;
use Chunlian\Domain\OrderFulfillment;
use Chunlian\Domain\OrderPaymentState;
use Chunlian\Domain\OrderReceiptState;
use Chunlian\Domain\OrderRevisionState;
use Chunlian\Domain\PaymentMethod;
use Chunlian\Domain\RejectionCode;
use PHPUnit\Framework\TestCase;

final class DomainEnumsTest extends TestCase
{
    public function testOnlyPendingAllowsCustomerSelfService(): void
    {
        self::assertTrue(OrderFulfillment::PENDING->allowsCustomerSelfService());
        self::assertFalse(OrderFulfillment::DELIVERING->allowsCustomerSelfService());
        self::assertFalse(OrderFulfillment::DELIVERED->allowsCustomerSelfService());
        self::assertFalse(OrderFulfillment::FINISHED->allowsCustomerSelfService());
        self::assertFalse(OrderFulfillment::CANCELLED->allowsCustomerSelfService());
    }

    public function testMerchantPrimaryActionPerStatus(): void
    {
        self::assertSame('START_DELIVERY', OrderFulfillment::PENDING->merchantPrimaryAction());
        self::assertSame('CONFIRM_DELIVERY', OrderFulfillment::DELIVERING->merchantPrimaryAction());
        self::assertSame('NONE', OrderFulfillment::DELIVERED->merchantPrimaryAction());
    }

    public function testFulfillmentTransitions(): void
    {
        self::assertTrue(OrderFulfillment::PENDING->canTransitionTo(OrderFulfillment::DELIVERING));
        self::assertTrue(OrderFulfillment::PENDING->canTransitionTo(OrderFulfillment::CANCELLED));
        self::assertTrue(OrderFulfillment::DELIVERING->canTransitionTo(OrderFulfillment::DELIVERED));

        // 不允许跳步
        self::assertFalse(OrderFulfillment::PENDING->canTransitionTo(OrderFulfillment::DELIVERED));
        self::assertFalse(OrderFulfillment::PENDING->canTransitionTo(OrderFulfillment::FINISHED));
        // 终态不可再迁移
        self::assertFalse(OrderFulfillment::CANCELLED->canTransitionTo(OrderFulfillment::DELIVERING));
        self::assertFalse(OrderFulfillment::FINISHED->canTransitionTo(OrderFulfillment::CANCELLED));
    }

    public function testPaymentStateHelpers(): void
    {
        self::assertTrue(OrderPaymentState::PAID->isSettled());
        self::assertFalse(OrderPaymentState::UNPAID->isSettled());
        self::assertTrue(OrderPaymentState::REFUNDING->isRefundInFlight());
        self::assertFalse(OrderPaymentState::REFUNDED->isRefundInFlight());
        self::assertTrue(OrderPaymentState::UNPAID->allowsNewPayment());
        self::assertFalse(OrderPaymentState::PAID->allowsNewPayment());
    }

    public function testReceiptRequiresDelivered(): void
    {
        self::assertTrue(
            OrderReceiptState::UNCONFIRMED->canConfirm(OrderFulfillment::DELIVERED)
        );
        self::assertFalse(
            OrderReceiptState::UNCONFIRMED->canConfirm(OrderFulfillment::DELIVERING)
        );
        self::assertFalse(
            OrderReceiptState::CONFIRMED->canConfirm(OrderFulfillment::DELIVERED)
        );
    }

    public function testRevisionStateBlocksDispatch(): void
    {
        self::assertTrue(OrderRevisionState::PENDING_TOPUP->blocksDispatch());
        self::assertTrue(OrderRevisionState::APPLYING->blocksDispatch());
        self::assertFalse(OrderRevisionState::NONE->blocksDispatch());
        self::assertFalse(OrderRevisionState::COMMITTED->blocksDispatch());
        self::assertFalse(OrderRevisionState::FAILED->blocksDispatch());
    }

    public function testOnlyCodRequiresCollectionOnDelivery(): void
    {
        self::assertTrue(PaymentMethod::COD->requiresCollectionOnDelivery());
        self::assertFalse(PaymentMethod::WECHAT->requiresCollectionOnDelivery());
        self::assertTrue(PaymentMethod::COD->dispatchableImmediately());
    }

    public function testScheduledDeliveryMustBeFuture(): void
    {
        $now = new \DateTimeImmutable('2026-10-12 19:00:00');

        // 预约过去时间 → 拒绝
        self::assertFalse(DeliveryMode::validateExpectedAt(
            DeliveryMode::SCHEDULED,
            new \DateTimeImmutable('2026-10-11 17:30:00'),
            $now
        ));

        // 预约太近（少于 30 分钟缓冲）→ 拒绝
        self::assertFalse(DeliveryMode::validateExpectedAt(
            DeliveryMode::SCHEDULED,
            $now->modify('+10 minutes'),
            $now
        ));

        // 预约明天 17:30 → 通过
        self::assertTrue(DeliveryMode::validateExpectedAt(
            DeliveryMode::SCHEDULED,
            new \DateTimeImmutable('2026-10-13 17:30:00'),
            $now
        ));

        // SCHEDULED 不传时间 → 拒绝
        self::assertFalse(DeliveryMode::validateExpectedAt(
            DeliveryMode::SCHEDULED,
            null,
            $now
        ));
    }

    public function testAsapAcceptsNullTime(): void
    {
        $now = new \DateTimeImmutable('2026-10-12 19:00:00');

        self::assertTrue(DeliveryMode::validateExpectedAt(DeliveryMode::ASAP, null, $now));
        self::assertFalse(DeliveryMode::validateExpectedAt(
            DeliveryMode::ASAP,
            $now->modify('-1 hour'),
            $now
        ));
    }

    public function testPhoneValidation(): void
    {
        self::assertTrue(Contact::isValidPhone('13800138000'));
        self::assertTrue(Contact::isValidPhone('19912345678'));
        self::assertFalse(Contact::isValidPhone('12345678901'));
        self::assertFalse(Contact::isValidPhone('1380013800'));
        self::assertFalse(Contact::isValidPhone('138001380000'));
    }

    public function testContactMasking(): void
    {
        $contact = new Contact('张先生', '13800138000', '阳光小区3栋502室');
        self::assertSame('138****8000', $contact->maskedPhone());
    }

    public function testContactRejectsBlankName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Contact('  ', '13800138000', '阳光小区3栋502室');
    }

    public function testRejectionCodeHttpStatusMapping(): void
    {
        self::assertSame(409, RejectionCode::ORDER_NOT_EDITABLE->httpStatus());
        self::assertSame(409, RejectionCode::TOPUP_REQUIRED->httpStatus());
        self::assertSame(409, RejectionCode::COD_ALREADY_COLLECTED->httpStatus());
        self::assertSame(422, RejectionCode::INVALID_EXPECTED_TIME->httpStatus());
        self::assertSame(404, RejectionCode::NOT_FOUND->httpStatus());
        self::assertSame(403, RejectionCode::FORBIDDEN->httpStatus());
    }
}
