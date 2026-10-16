<?php

declare(strict_types=1);

namespace Chunlian\Tests\Repository;

use Chunlian\Domain\Contact;
use Chunlian\Domain\DeliveryMode;
use Chunlian\Domain\OrderFulfillment;
use Chunlian\Domain\OrderPaymentState;
use Chunlian\Domain\OrderReceiptState;
use Chunlian\Domain\OrderRevisionState;
use Chunlian\Domain\PaymentMethod;
use Chunlian\Repository\OrderRowMapper;
use PHPUnit\Framework\TestCase;

/**
 * 行映射器测试。
 *
 * 持久化里真正容易出错的是**映射**，不是 SQL。本文件覆盖：
 *   - decimal(10,2) ↔ 整数分的精确换算（浮点陷阱）
 *   - 枚举未知取值时的降级**与告警**（静默降级是已踩过的坑）
 *   - ShopXO 单一 status 兜底映射的固有缺陷
 *   - 历史脏数据（手机号不规范）不得导致读取整体失败
 */
final class OrderRowMapperTest extends TestCase
{
    private OrderRowMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new OrderRowMapper();
    }

    // =========================================================
    // 金额换算
    // =========================================================

    /**
     * 浮点陷阱回归测试。
     *
     * `(int)(8.7 * 100)` 在 IEEE754 下得到 869 而不是 870。
     * 这类偏差在金额上就是对账差额，必须用字符串解析规避。
     */
    public function testDecimalToFenAvoidsFloatError(): void
    {
        self::assertSame(870, OrderRowMapper::decimalToFen('8.70'));
        self::assertSame(870, OrderRowMapper::decimalToFen(8.70));

        // 经典浮点陷阱样本
        self::assertSame(1234, OrderRowMapper::decimalToFen('12.34'));
        self::assertSame(2030, OrderRowMapper::decimalToFen('20.30'));
        self::assertSame(100, OrderRowMapper::decimalToFen('1.00'));
        self::assertSame(1, OrderRowMapper::decimalToFen('0.01'));

        // 直接相乘的写法会错，这里显式记录对照
        self::assertNotSame((int) (8.7 * 100), OrderRowMapper::decimalToFen('8.70'));
    }

    public function testDecimalToFenHandlesEdgeValues(): void
    {
        self::assertSame(0, OrderRowMapper::decimalToFen(null));
        self::assertSame(0, OrderRowMapper::decimalToFen(''));
        self::assertSame(0, OrderRowMapper::decimalToFen('0'));
        self::assertSame(0, OrderRowMapper::decimalToFen('0.00'));
        self::assertSame(10000, OrderRowMapper::decimalToFen('100'));
        self::assertSame(10000, OrderRowMapper::decimalToFen('100.00'));
        self::assertSame(-350, OrderRowMapper::decimalToFen('-3.50'));
        self::assertSame(350, OrderRowMapper::decimalToFen('+3.50'));
    }

    /** 超过两位小数时第三位四舍五入。 */
    public function testDecimalToFenRoundsThirdDecimal(): void
    {
        self::assertSame(1235, OrderRowMapper::decimalToFen('12.345'));
        self::assertSame(1234, OrderRowMapper::decimalToFen('12.344'));
        self::assertSame(1234, OrderRowMapper::decimalToFen('12.3449'));
    }

    /** decimal(10,2) 上限不得溢出。 */
    public function testDecimalToFenHandlesUpperBound(): void
    {
        self::assertSame(9999999999, OrderRowMapper::decimalToFen('99999999.99'));
    }

    public function testDecimalToFenRejectsGarbage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        OrderRowMapper::decimalToFen('abc');
    }

    public function testDecimalToFenRejectsScientificNotation(): void
    {
        // 科学计数法说明数据来源异常，应当暴露而不是猜测
        $this->expectException(\InvalidArgumentException::class);
        OrderRowMapper::decimalToFen('1.0E-5');
    }

    public function testFenToDecimal(): void
    {
        self::assertSame('12.34', OrderRowMapper::fenToDecimal(1234));
        self::assertSame('0.00', OrderRowMapper::fenToDecimal(0));
        self::assertSame('0.05', OrderRowMapper::fenToDecimal(5));
        self::assertSame('3.50', OrderRowMapper::fenToDecimal(350));
        self::assertSame('-3.50', OrderRowMapper::fenToDecimal(-350));
        self::assertSame('99999999.99', OrderRowMapper::fenToDecimal(9999999999));
    }

    /** 往返一致性：这是防金额误差最有力的一条断言。 */
    public function testFenRoundTripIsLossless(): void
    {
        foreach ([0, 1, 5, 99, 100, 350, 980, 1234, 99999, 9999999999, -1, -350, -1234] as $fen) {
            self::assertSame(
                $fen,
                OrderRowMapper::decimalToFen(OrderRowMapper::fenToDecimal($fen)),
                sprintf('往返换算后 %d 分发生了偏移', $fen)
            );
        }
    }

    // =========================================================
    // 枚举解析与降级告警
    // =========================================================

    public function testKnownEnumsParseWithoutWarning(): void
    {
        $order = $this->mapper->toOrder(
            $this->orderRow(),
            $this->addressRow(),
            $this->deliveryRow(),
            [$this->lineRow()]
        );

        self::assertSame(OrderFulfillment::DELIVERED, $order->fulfillment);
        self::assertSame(OrderPaymentState::UNPAID, $order->paymentState);
        self::assertSame(OrderRevisionState::NONE, $order->revisionState);
        self::assertSame(PaymentMethod::COD, $order->paymentMethod);
        self::assertSame([], $this->mapper->flushWarnings());
    }

    public function testDeliveryFeeComesFromTheOrderPriceSnapshot(): void
    {
        $order = $this->mapper->toOrder(
            $this->orderRow(['price' => '9.80', 'total_price' => '10.80']),
            $this->addressRow(),
            $this->deliveryRow(),
            [
                $this->lineRow(),
                $this->lineRow(['sku_id' => '205', 'unit_fen' => '280', 'qty' => '1']),
            ]
        );

        self::assertSame(100, $order->deliveryFeeFen);
        self::assertSame(1080, $order->total()->fen());
    }

    public function testRemarkMapsFromShopxoUserNote(): void
    {
        $order = $this->mapper->toOrder(
            $this->orderRow(['user_note' => '请放在门口，谢谢']),
            $this->addressRow(),
            $this->deliveryRow(),
            [$this->lineRow()]
        );

        self::assertSame('请放在门口，谢谢', $order->remark);
    }

    /**
     * 未知枚举取值必须降级**并且留下告警**。
     *
     * 静默降级是本项目已经踩过的坑：客户端枚举与服务端不一致时，
     * 界面显示错误状态却毫无提示。降级必须可被发现。
     */
    public function testUnknownEnumDegradesAndWarns(): void
    {
        $row = $this->deliveryRow();
        $row['fulfillment'] = 'PARTIAL_DELIVERED';   // 服务端不存在的取值

        $order = $this->mapper->toOrder($this->orderRow(), $this->addressRow(), $row, [$this->lineRow()]);

        // 降级到安全默认值，不抛异常
        self::assertSame(OrderFulfillment::PENDING, $order->fulfillment);

        // 但必须留下痕迹
        $warnings = $this->mapper->flushWarnings();
        self::assertCount(1, $warnings);
        self::assertStringContainsString('PARTIAL_DELIVERED', $warnings[0]);
        self::assertStringContainsString('未知取值', $warnings[0]);
    }

    public function testEmptyEnumValueWarns(): void
    {
        $row = $this->deliveryRow();
        $row['receipt_state'] = '';

        $this->mapper->toOrder($this->orderRow(), $this->addressRow(), $row, [$this->lineRow()]);

        $warnings = $this->mapper->flushWarnings();
        self::assertNotEmpty($warnings);
        self::assertStringContainsString('receipt_state', $warnings[0]);
    }

    public function testFlushWarningsClearsBuffer(): void
    {
        $row = $this->deliveryRow();
        $row['fulfillment'] = 'NOPE';

        $this->mapper->toOrder($this->orderRow(), $this->addressRow(), $row, [$this->lineRow()]);

        self::assertNotEmpty($this->mapper->flushWarnings());
        self::assertSame([], $this->mapper->flushWarnings(), '第二次 flush 应为空');
    }

    // =========================================================
    // ShopXO status 兜底映射
    // =========================================================

    public function testShopxoStatusFallbackMapping(): void
    {
        self::assertSame(OrderFulfillment::PENDING, OrderRowMapper::fulfillmentFromShopxoStatus(0));
        self::assertSame(OrderFulfillment::PENDING, OrderRowMapper::fulfillmentFromShopxoStatus(1));
        self::assertSame(OrderFulfillment::PENDING, OrderRowMapper::fulfillmentFromShopxoStatus(2));
        self::assertSame(OrderFulfillment::DELIVERING, OrderRowMapper::fulfillmentFromShopxoStatus(3));
        self::assertSame(OrderFulfillment::FINISHED, OrderRowMapper::fulfillmentFromShopxoStatus(4));
        self::assertSame(OrderFulfillment::CANCELLED, OrderRowMapper::fulfillmentFromShopxoStatus(5));
        self::assertSame(OrderFulfillment::CANCELLED, OrderRowMapper::fulfillmentFromShopxoStatus(6));
    }

    /**
     * 兜底的固有缺陷：ShopXO `status=4` 无法区分「已送达」与「已完成」。
     *
     * 这不是实现问题，而是 ShopXO 单一状态模型的限制 ——
     * 它只能映射到 FINISHED，还原不出 DELIVERED。
     * 因此 `veg_order_delivery` 是必需品而非可选优化。
     */
    public function testShopxoStatusCannotDistinguishDeliveredFromFinished(): void
    {
        $mapped = OrderRowMapper::fulfillmentFromShopxoStatus(4);

        self::assertSame(OrderFulfillment::FINISHED, $mapped);
        self::assertNotSame(OrderFulfillment::DELIVERED, $mapped);
    }

    /** 扩展行缺失时必须告警，不能静默兜底。 */
    public function testMissingDeliveryRowWarnsAboutInaccurateFallback(): void
    {
        $order = $this->mapper->toOrder(
            $this->orderRow(['status' => 3]),
            $this->addressRow(),
            null,   // 扩展行缺失
            [$this->lineRow()]
        );

        self::assertSame(OrderFulfillment::DELIVERING, $order->fulfillment);

        $warnings = $this->mapper->flushWarnings();
        self::assertNotEmpty($warnings);
        self::assertStringContainsString('veg_order_delivery', $warnings[0]);
    }

    /** 扩展行缺失时，`is_under_line=1` 应推断为 COD。 */
    public function testMissingDeliveryRowInfersCodFromUnderLineFlag(): void
    {
        $order = $this->mapper->toOrder(
            $this->orderRow(['is_under_line' => 1]),
            $this->addressRow(),
            null,
            [$this->lineRow()]
        );

        self::assertSame(PaymentMethod::COD, $order->paymentMethod);
    }

    // =========================================================
    // 聚合组装
    // =========================================================

    public function testAssemblesAmountsAndLines(): void
    {
        $order = $this->mapper->toOrder(
            $this->orderRow(),
            $this->addressRow(),
            $this->deliveryRow(),
            [$this->lineRow(), $this->lineRow(['sku_id' => 205, 'unit_fen' => 280, 'qty' => 1])]
        );

        self::assertSame(2, count($order->lines));
        // 350×2 + 280×1 = 980
        self::assertSame(980, $order->goodsTotal()->fen());
        self::assertSame('102', $order->lines[0]->skuId);
        self::assertSame('205', $order->lines[1]->skuId);
    }

    public function testPaidAndRefundedFromShopxoColumns(): void
    {
        $order = $this->mapper->toOrder(
            $this->orderRow(['pay_price' => '9.80', 'refund_price' => '3.00']),
            $this->addressRow(),
            $this->deliveryRow(),
            [$this->lineRow()]
        );

        self::assertSame(980, $order->paidFen);
        self::assertSame(300, $order->refundedFen);
        // 可退 = 980 − 300 − 0
        self::assertSame(680, $order->refundable()->fen());
    }

    /**
     * 历史脏数据不得导致读取整体失败。
     *
     * 库里存在座机号/全角数字/带国家码的历史订单是常态。
     * 严格校验会让整条列表查询抛异常，商家连别的单都看不到。
     */
    public function testLenientContactHandlesLegacyMessyPhone(): void
    {
        $order = $this->mapper->toOrder(
            $this->orderRow(),
            // 全角数字 + 连字符：１３８-００１３-８０００
            // 字段名 tel
            $this->addressRow(['tel' => '１３８-００１３-８０００']),
            $this->deliveryRow(),
            [$this->lineRow()]
        );

        self::assertSame('13800138000', $order->contact->phone);
        self::assertTrue($order->contact->hasDialablePhone());
    }

    public function testLenientContactStripsCountryCode(): void
    {
        $order = $this->mapper->toOrder(
            $this->orderRow(),
            // 字段名 tel
            $this->addressRow(['tel' => '+86 138 0013 8000']),
            $this->deliveryRow(),
            [$this->lineRow()]
        );

        self::assertSame('13800138000', $order->contact->phone);
    }

    /** 座机号不该让读取失败，但必须能被识别为「不可拨打」。 */
    public function testLandlineDoesNotBreakReadButIsFlagged(): void
    {
        $order = $this->mapper->toOrder(
            $this->orderRow(),
            // 字段名 tel
            $this->addressRow(['tel' => '010-12345678']),
            $this->deliveryRow(),
            [$this->lineRow()]
        );

        self::assertFalse($order->contact->hasDialablePhone());

        $warnings = $this->mapper->flushWarnings();
        self::assertNotEmpty($warnings);
        self::assertStringContainsString('不可直接拨打', implode(' ', $warnings));
    }

    public function testEmptyAddressFieldsBecomePlaceholder(): void
    {
        $order = $this->mapper->toOrder(
            $this->orderRow(),
            // 姓名与地址各段全空 —— 历史数据里确实存在这种订单
            $this->addressRow([
                'name' => '',
                'address' => '',
                // 注意清空的是**名称**字段：province/city/county 是区域 ID，
                // 置空它们不会影响拼出来的地址字符串
                'province_name' => '',
                'city_name' => '',
                'county_name' => '',
            ]),
            $this->deliveryRow(),
            [$this->lineRow()]
        );

        self::assertSame('（未填写）', $order->contact->name);
        self::assertSame('（未填写）', $order->contact->address);
    }

    /** 地址由省市区 + 详细地址拼接而成。 */
    public function testAddressIsConcatenatedFromParts(): void
    {
        $order = $this->mapper->toOrder(
            $this->orderRow(),
            $this->addressRow(),
            $this->deliveryRow(),
            [$this->lineRow()]
        );

        self::assertSame('浙江省杭州市西湖区阳光小区3栋502室', $order->contact->address);
    }

    public function testVersionComesFromExtensionRow(): void
    {
        $order = $this->mapper->toOrder(
            $this->orderRow(),
            $this->addressRow(),
            $this->deliveryRow(['version' => 7]),
            [$this->lineRow()]
        );

        self::assertSame(7, $order->version);
    }

    public function testDeliveryModeAndExpectedAt(): void
    {
        $order = $this->mapper->toOrder(
            $this->orderRow(),
            $this->addressRow(),
            $this->deliveryRow([
                'delivery_mode' => 'SCHEDULED',
                'expected_at' => '2026-10-13 09:30:00',
            ]),
            [$this->lineRow()]
        );

        self::assertSame(DeliveryMode::SCHEDULED, $order->deliveryMode);
        self::assertNotNull($order->expectedAt);
        self::assertSame('2026-10-13 09:30:00', $order->expectedAt->format('Y-m-d H:i:s'));
    }

    // =========================================================
    // 时间转换
    // =========================================================

    public function testToDateTimeHandlesUnixSeconds(): void
    {
        $dt = OrderRowMapper::toDateTime(1760000000);

        self::assertNotNull($dt);
        self::assertSame('Asia/Shanghai', $dt->getTimezone()->getName());
    }

    public function testToDateTimeReturnsNullForZeroAndGarbage(): void
    {
        self::assertNull(OrderRowMapper::toDateTime(0));
        self::assertNull(OrderRowMapper::toDateTime('0'));
        self::assertNull(OrderRowMapper::toDateTime(null));
        self::assertNull(OrderRowMapper::toDateTime(''));
    }

    public function testToDateTimeAcceptsDateString(): void
    {
        $dt = OrderRowMapper::toDateTime('2026-10-12 20:00:00');

        self::assertNotNull($dt);
        self::assertSame('2026-10-12 20:00:00', $dt->format('Y-m-d H:i:s'));
    }

    // =========================================================
    // 测试数据
    // =========================================================

    private function orderRow(array $overrides = []): array
    {
        return array_merge([
            'id' => '10025',
            'order_no' => '20261012193000001',
            'user_id' => '7',
            'status' => 4,
            'pay_status' => 0,
            'is_under_line' => 1,
            'price' => '9.80',
            'total_price' => '9.80',
            'pay_price' => '0.00',
            'refund_price' => '0.00',
            'add_time' => 1760000000,
        ], $overrides);
    }

    /**
     * shopxo_order_address 的测试行。
     *
     * 【字段名依据实际表结构】
     *   - 手机号列是 `tel`，不是 `phone`
     *   - `province` / `city` / `county` 是**区域 ID（int）**，
     *     名称在同行的 `province_name` / `city_name` / `county_name`
     */
    private function addressRow(array $overrides = []): array
    {
        return array_merge([
            'order_id' => '10025',
            'name' => '张先生',
            'tel' => '13800138000',
            'province' => 11,
            'city' => 3301,
            'county' => 330106,
            'province_name' => '浙江省',
            'city_name' => '杭州市',
            'county_name' => '西湖区',
            'address' => '阳光小区3栋502室',
        ], $overrides);
    }

    private function deliveryRow(array $overrides = []): array
    {
        return array_merge([
            'order_id' => '10025',
            'delivery_mode' => 'ASAP',
            'expected_at' => null,
            'fulfillment' => 'DELIVERED',
            'payment_state' => 'UNPAID',
            'payment_method' => 'COD',
            'receipt_state' => 'UNCONFIRMED',
            'revision_state' => 'NONE',
            'delivered_at' => '2026-10-12 19:40:00',
            'received_at' => null,
            'version' => 3,
        ], $overrides);
    }

    private function lineRow(array $overrides = []): array
    {
        return array_merge([
            'order_id' => '10025',
            'order_detail_id' => '5001',
            'sku_id' => '102',
            'goods_id' => '1',
            'name_snapshot' => '小青菜',
            'spec_snapshot' => '500g/份',
            'image_snapshot' => 'a.jpg',
            'unit_fen' => '350',
            'qty' => '2',
        ], $overrides);
    }
}
