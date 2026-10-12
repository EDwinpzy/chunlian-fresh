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
use Chunlian\Service\RevisionCalculator;
use PHPUnit\Framework\TestCase;

/**
 * 订单修订与差价引擎测试 —— 对应 T13/T14/T15/T16。
 *
 * 定价规则：旧有 SKU 维持原下单单价；新增 SKU 按现价；增量数量按现价。
 */
final class RevisionCalculatorTest extends TestCase
{
    private RevisionCalculator $calc;

    protected function setUp(): void
    {
        $this->calc = new RevisionCalculator();
    }

    /** 原订单：青菜×2 @3.50 + 土豆×1 @2.80 = 9.80 */
    private function makeOrder(int $version = 1): Order
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

    /** SKU 目录：青菜已涨价到 4.00，土豆 2.80，新增西红柿 4.90。 */
    private function catalog(): array
    {
        return [
            'SKU_102' => ['unit_fen' => 400, 'stock' => 50, 'product_id' => 'P1', 'name' => '小青菜', 'spec' => '500g/份', 'image' => 'a.jpg'],
            'SKU_205' => ['unit_fen' => 280, 'stock' => 50, 'product_id' => 'P2', 'name' => '土豆', 'spec' => '500g/份', 'image' => 'b.jpg'],
            'SKU_301' => ['unit_fen' => 490, 'stock' => 20, 'product_id' => 'P3', 'name' => '西红柿', 'spec' => '500g/份', 'image' => 'c.jpg'],
        ];
    }

    // ---------- 定价规则：旧 SKU 维持原价 ----------

    public function testExistingSkuKeepsOriginalUnitPriceDespiteRepricing(): void
    {
        // 场景：青菜原价 3.50（现价已涨到 4.00），保持土豆×1 不变，青菜仍为 ×2。
        // 预期：青菜此行按**原价 3.50** 计价，而不是现价 4.00。
        $result = $this->calc->preview($this->makeOrder(), [
            ['sku_id' => 'SKU_102', 'qty' => 2],
            ['sku_id' => 'SKU_205', 'qty' => 1],
        ], $this->catalog());

        self::assertTrue($result->success);
        // 明细与原订单完全等价：980 = 3.50×2 + 2.80×1
        self::assertSame(980, $result->get('new_goods_fen'));
        self::assertSame(980, $result->get('old_goods_fen'));
        self::assertSame(0, $result->get('delta_fen'));

        $veg = $this->findPricedLine($result->get('priced_lines'), 'SKU_102');
        self::assertSame(350, $veg['unit_fen'], '旧 SKU 必须维持原下单单价');
        self::assertSame('ORIGINAL_ORDER', $veg['priced_by']);
    }

    /**
     * 只提交青菜、去掉土豆时，青菜仍按原价，但总额下降 → 应退款。
     * 这条用例同时锁死「old_goods_fen 始终是原订单总额」这一语义，
     * 避免把「新明细的青菜小计」误当成旧总额。
     */
    public function testDroppingSkuKeepsRemainingSkuAtOriginalPrice(): void
    {
        $result = $this->calc->preview($this->makeOrder(), [
            ['sku_id' => 'SKU_102', 'qty' => 2],
        ], $this->catalog());

        self::assertTrue($result->success);
        self::assertSame(700, $result->get('new_goods_fen'));
        self::assertSame(980, $result->get('old_goods_fen'), '旧总额恒为原订单商品总额');
        self::assertSame(-280, $result->get('delta_fen'));
        self::assertSame('REFUND', $result->get('delta_type'));

        $veg = $this->findPricedLine($result->get('priced_lines'), 'SKU_102');
        self::assertSame(350, $veg['unit_fen'], '减量后仍按原价');
        self::assertSame('ORIGINAL_ORDER', $veg['priced_by']);
    }

    // ---------- 定价规则：增量数量按现价 ----------

    public function testIncrementalQtyPricedAtCurrentPrice(): void
    {
        // 只提交青菜 2 → 4：2 件按 3.50 + 2 件按 4.00 = 700 + 800 = 1500
        // 注意：土豆被移除，原商品总额 980 → 1500，故 delta = +520（不是 +800）。
        $result = $this->calc->preview($this->makeOrder(), [
            ['sku_id' => 'SKU_102', 'qty' => 4],
        ], $this->catalog());

        self::assertTrue($result->success);
        self::assertSame(1500, $result->get('new_goods_fen'));
        self::assertSame(980, $result->get('old_goods_fen'));
        self::assertSame(520, $result->get('delta_fen'));
        self::assertSame('TOPUP', $result->get('delta_type'));

        $priced = $this->findPricedLine($result->get('priced_lines'), 'SKU_102');
        self::assertSame('MIXED', $priced['priced_by']);
        self::assertSame(4, $priced['qty']);
        self::assertSame(1500, $priced['line_total_fen']);
        // 1500 能被 4 整除 → 无需拆行，加权均价 375
        self::assertSame(375, $priced['unit_fen']);
        self::assertArrayNotHasKey('split_lines', $priced);
    }

    /** 纯增量场景：青菜×2 与土豆×1 都在，仅青菜加量到 4 → delta = 800。 */
    public function testPureIncrementKeepsOtherLinesAndDeltasFullIncrement(): void
    {
        $result = $this->calc->preview($this->makeOrder(), [
            ['sku_id' => 'SKU_102', 'qty' => 4],
            ['sku_id' => 'SKU_205', 'qty' => 1],
        ], $this->catalog());

        self::assertTrue($result->success);
        self::assertSame(1500 + 280, $result->get('new_goods_fen'));
        self::assertSame(800, $result->get('delta_fen'));
        self::assertSame('TOPUP', $result->get('delta_type'));

        $priced = $this->findPricedLine($result->get('priced_lines'), 'SKU_102');
        self::assertSame(1500, $priced['line_total_fen']);
        self::assertSame(375, $priced['unit_fen']);

        $potato = $this->findPricedLine($result->get('priced_lines'), 'SKU_205');
        self::assertSame('ORIGINAL_ORDER', $potato['priced_by']);
        self::assertSame(280, $potato['unit_fen']);
        self::assertSame(1, $potato['qty']);
    }

    // ---------- 回归：混合计价的行小计守恒 ----------

    /**
     * 回归：OrderLine 的不变式 unitFen × qty === lineTotalFen 必须在任何
     * 混合计价场景下恒成立（曾出现 400×4=1600 覆盖 1500 的双计缺陷）。
     */
    public function testMixedPricingKeepsLineInvariantWhenDivisible(): void
    {
        // 青菜 3.50×2 + 4.00×2 = 1500，qty=4 → 1500/4=375 整除
        $result = $this->calc->preview($this->makeOrder(), [
            ['sku_id' => 'SKU_102', 'qty' => 4],
        ], $this->catalog());

        $sum = 0;
        foreach ($result->get('_new_lines') as $line) {
            self::assertSame(
                $line->subtotal()->fen(),
                $line->unitFen * $line->qty,
                '行小计必须等于 单价 × 数量'
            );
            $sum += $line->subtotal()->fen();
        }

        self::assertSame(1500, $sum, '所有订单行小计之和必须等于新商品总额');
        self::assertSame(1500, $result->get('new_goods_fen'));
    }

    /**
     * 回归：无法整除时必须拆行，且拆行后所有行小计之和仍严格守恒。
     *
     * 场景：西红柿原价 3.33（333）×3 = 999，现价 4.00 加量到 4 →
     * lineTotal = 999 + 400 = 1399，1399 % 4 = 3 ≠ 0 → 必须拆成
     * 「原价行 333×3」+「现价行 400×1」两条，合计 1399。
     */
    public function testMixedPricingSplitsLinesWhenNotDivisible(): void
    {
        $order = new Order(
            id: '10026',
            orderNo: '20261012193000002',
            version: 1,
            fulfillment: OrderFulfillment::PENDING,
            paymentState: OrderPaymentState::UNPAID,
            receiptState: OrderReceiptState::UNCONFIRMED,
            revisionState: OrderRevisionState::NONE,
            paymentMethod: PaymentMethod::COD,
            deliveryMode: DeliveryMode::ASAP,
            expectedAt: null,
            lines: [
                new OrderLine('SKU_901', 'P9', '西红柿', '500g/份', 'c.jpg', 333, 3),
            ],
            contact: new Contact('张先生', '13800138000', '阳光小区3栋502室'),
            deliveryFeeFen: 0,
        );

        $catalog = [
            'SKU_901' => ['unit_fen' => 400, 'stock' => 50, 'product_id' => 'P9', 'name' => '西红柿', 'spec' => '500g/份', 'image' => 'c.jpg'],
        ];

        $result = $this->calc->preview($order, [
            ['sku_id' => 'SKU_901', 'qty' => 4],
        ], $catalog);

        self::assertTrue($result->success);
        self::assertSame(1399, $result->get('new_goods_fen'));
        self::assertSame(400, $result->get('delta_fen'));

        $priced = $this->findPricedLine($result->get('priced_lines'), 'SKU_901');
        self::assertTrue($priced['split_lines'] ?? false, '不能整除时必须标记拆行');
        self::assertSame(1399, $priced['line_total_fen']);

        $newLines = $result->get('_new_lines');
        $sameSku = array_values(array_filter(
            $newLines,
            static fn (OrderLine $l) => $l->skuId === 'SKU_901'
        ));

        self::assertCount(2, $sameSku, '应拆为两条订单行');
        self::assertSame(333, $sameSku[0]->unitFen);
        self::assertSame(3, $sameSku[0]->qty);
        self::assertSame(400, $sameSku[1]->unitFen);
        self::assertSame(1, $sameSku[1]->qty);

        $sum = 0;
        foreach ($newLines as $line) {
            self::assertSame($line->subtotal()->fen(), $line->unitFen * $line->qty);
            $sum += $line->subtotal()->fen();
        }
        self::assertSame(1399, $sum, '拆行后行小计之和必须守恒');

        // 拆行不得影响库存差额：总量 3 → 4，仍需预占 1
        self::assertSame(1, $this->calc->stockDelta($order->lines, $newLines)['SKU_901']);
    }

    /** 按 sku_id 取出 priced_lines 中的一条，避免依赖数组下标顺序。 */
    private function findPricedLine(array $pricedLines, string $skuId): array
    {
        foreach ($pricedLines as $line) {
            if (($line['sku_id'] ?? null) === $skuId) {
                return $line;
            }
        }

        self::fail("priced_lines 中未找到 sku_id={$skuId}");
    }

    // ---------- 定价规则：新增 SKU 按现价 ----------

    public function testNewSkuPricedAtCurrentPrice(): void
    {
        // 青菜×2 (旧价 700) + 西红柿×1 (现价 490) = 1190，原 980
        $result = $this->calc->preview($this->makeOrder(), [
            ['sku_id' => 'SKU_102', 'qty' => 2],
            ['sku_id' => 'SKU_301', 'qty' => 1],
        ], $this->catalog());

        self::assertTrue($result->success);
        self::assertSame(1190, $result->get('new_goods_fen'));
        self::assertSame(210, $result->get('delta_fen'));
        self::assertSame('TOPUP', $result->get('delta_type'));
        self::assertTrue($result->get('requires_topup_before_commit'));

        $priced = $result->get('priced_lines');
        $tomato = array_values(array_filter($priced, static fn ($l) => $l['sku_id'] === 'SKU_301'))[0];
        self::assertSame('CURRENT_PRICE', $tomato['priced_by']);
        self::assertSame(490, $tomato['unit_fen']);
    }

    // ---------- T14：已付款减少商品 → 应退款 ----------

    public function testReduceItemsProducesRefundDelta(): void
    {
        // 只留土豆×1 = 280，原 980 → delta = -700
        $result = $this->calc->preview($this->makeOrder(), [
            ['sku_id' => 'SKU_205', 'qty' => 1],
        ], $this->catalog());

        self::assertTrue($result->success);
        self::assertSame(280, $result->get('new_goods_fen'));
        self::assertSame(-700, $result->get('delta_fen'));
        self::assertSame('REFUND', $result->get('delta_type'));
        self::assertFalse($result->get('requires_topup_before_commit'));
        self::assertStringContainsString('退还差价', $result->get('customer_message'));
    }

    // ---------- T16：只改地址/时间 → delta = 0，不触发资金 ----------

    public function testEquivalentItemsProduceZeroDelta(): void
    {
        $result = $this->calc->preview($this->makeOrder(), [
            ['sku_id' => 'SKU_102', 'qty' => 2],
            ['sku_id' => 'SKU_205', 'qty' => 1],
        ], $this->catalog());

        self::assertTrue($result->success);
        self::assertSame(0, $result->get('delta_fen'));
        self::assertSame('NONE', $result->get('delta_type'));
        self::assertFalse($result->get('goods_changed'));

        // 明细未变 → 无库存差额，不触发任何资金动作
        self::assertSame([], $this->calc->stockDelta(
            $this->makeOrder()->lines,
            $result->get('_new_lines')
        ));
        self::assertSame('NONE', $this->calc->fundingAction((int) $result->get('delta_fen'), isPaid: true));
    }

    // ---------- T04：缺货应阻止提交 ----------

    public function testOutOfStockRejected(): void
    {
        $catalog = $this->catalog();
        $catalog['SKU_301']['stock'] = 0;

        $result = $this->calc->preview($this->makeOrder(), [
            ['sku_id' => 'SKU_301', 'qty' => 1],
        ], $catalog);

        self::assertFalse($result->success);
        self::assertSame(RejectionCode::OUT_OF_STOCK, $result->code);
        self::assertNotEmpty($result->get('warnings'));
    }

    public function testUnknownSkuRejected(): void
    {
        $result = $this->calc->preview($this->makeOrder(), [
            ['sku_id' => 'SKU_NOT_EXIST', 'qty' => 1],
        ], $this->catalog());

        self::assertFalse($result->success);
        self::assertSame(RejectionCode::VALIDATION_FAILED, $result->code);
    }

    public function testZeroQtyRejected(): void
    {
        $result = $this->calc->preview($this->makeOrder(), [
            ['sku_id' => 'SKU_102', 'qty' => 0],
        ], $this->catalog());

        self::assertFalse($result->success);
        self::assertSame(RejectionCode::VALIDATION_FAILED, $result->code);
    }

    public function testEmptyItemsRejected(): void
    {
        $result = $this->calc->preview($this->makeOrder(), [], $this->catalog());

        self::assertFalse($result->success);
        self::assertSame(RejectionCode::VALIDATION_FAILED, $result->code);
    }

    public function testPreviewRejectedAfterDispatch(): void
    {
        $order = $this->makeOrder();
        $order->fulfillment = OrderFulfillment::DELIVERING;

        $result = $this->calc->preview($order, [
            ['sku_id' => 'SKU_102', 'qty' => 2],
        ], $this->catalog());

        self::assertFalse($result->success);
        self::assertSame(RejectionCode::ORDER_NOT_EDITABLE, $result->code);
    }

    // ---------- 配送费参与总额计算 ----------

    public function testDeliveryFeeIncludedInTotal(): void
    {
        $order = $this->makeOrder();
        $order->deliveryFeeFen = 200;

        self::assertSame(1180, $order->total()->fen());

        $result = $this->calc->preview($order, [
            ['sku_id' => 'SKU_102', 'qty' => 2],
            ['sku_id' => 'SKU_205', 'qty' => 1],
        ], $this->catalog());

        self::assertSame(1180, $result->get('new_total_fen'));
        self::assertSame(0, $result->get('delta_fen'));
    }

    // ---------- 库存差额 ----------

    public function testStockDeltaComputesIncreaseAndDecrease(): void
    {
        $old = [
            new OrderLine('SKU_102', 'P1', '小青菜', '500g/份', 'a.jpg', 350, 2),
            new OrderLine('SKU_205', 'P2', '土豆', '500g/份', 'b.jpg', 280, 1),
        ];
        $new = [
            new OrderLine('SKU_102', 'P1', '小青菜', '500g/份', 'a.jpg', 350, 4),
            new OrderLine('SKU_301', 'P3', '西红柿', '500g/份', 'c.jpg', 490, 1),
        ];

        $delta = $this->calc->stockDelta($old, $new);

        self::assertSame(2, $delta['SKU_102']);   // 增加 2
        self::assertSame(-1, $delta['SKU_205']);  // 释放 1
        self::assertSame(1, $delta['SKU_301']);   // 新增预占 1
    }

    public function testStockDeltaEmptyWhenUnchanged(): void
    {
        $old = [
            new OrderLine('SKU_102', 'P1', '小青菜', '500g/份', 'a.jpg', 350, 2),
        ];

        self::assertSame([], $this->calc->stockDelta($old, $old));
    }

    /** 修订失败后库存必须对称归还：增加 → 释放。 */
    public function testStockDeltaReversalIsSymmetric(): void
    {
        $old = [new OrderLine('SKU_102', 'P1', '小青菜', '500g/份', 'a.jpg', 350, 2)];
        $new = [new OrderLine('SKU_102', 'P1', '小青菜', '500g/份', 'a.jpg', 350, 5)];

        $delta = $this->calc->stockDelta($old, $new);
        $reverts = $this->calc->stockDelta($new, $old);

        self::assertSame(3, $delta['SKU_102']);
        self::assertSame(-3, $reverts['SKU_102']);
    }

    // ---------- 资金动作判定 ----------

    public function testFundingActionMatrix(): void
    {
        self::assertSame('NONE', $this->calc->fundingAction(0, isPaid: true));
        self::assertSame('NONE', $this->calc->fundingAction(0, isPaid: false));
        self::assertSame('TOPUP', $this->calc->fundingAction(940, isPaid: true));
        self::assertSame('REFUND', $this->calc->fundingAction(-940, isPaid: true));
        self::assertSame('REISSUE_PAYMENT', $this->calc->fundingAction(940, isPaid: false));
        self::assertSame('REISSUE_PAYMENT', $this->calc->fundingAction(-940, isPaid: false));
    }

    // ---------- 客户文案 ----------

    public function testTopupCustomerMessage(): void
    {
        $result = $this->calc->preview($this->makeOrder(), [
            ['sku_id' => 'SKU_102', 'qty' => 2],
            ['sku_id' => 'SKU_301', 'qty' => 1],
        ], $this->catalog());

        self::assertSame(
            '本次修改需补付 ¥2.10，支付成功后才生效。',
            $result->get('customer_message')
        );
    }
}
