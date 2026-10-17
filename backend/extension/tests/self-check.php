<?php

declare(strict_types=1);

/**
 * 轻量自检运行器（无需 PHPUnit）。
 *
 * 用法：php tests/self-check.php
 *
 * 用途：在未安装 composer 依赖的环境中快速验证核心业务规则。
 * 完整测试套件请使用 `composer test`（PHPUnit 10）。
 *
 * 覆盖的核心规则：
 *   - 金额永远以「分」整数运算，无浮点漂移
 *   - 四维状态独立（COD 送达未收款 = DELIVERED + UNPAID）
 *   - 有待补款时禁止开始配送
 *   - 已开始配送后禁止顾客自助改/取消
 *   - 已付款减量 → 退款差额；增量 → 补款
 *   - 旧 SKU 维持原下单单价，增量与新增按现价
 *   - 幂等：重复请求只执行一次副作用
 *   - 防超退：处理中的退款也占用可退额度
 */

require __DIR__ . '/bootstrap.php';

use Chunlian\Domain\CollectionMethod;
use Chunlian\Domain\Contact;
use Chunlian\Domain\DeliveryMode;
use Chunlian\Domain\LedgerKind;
use Chunlian\Domain\Money;
use Chunlian\Domain\Order;
use Chunlian\Domain\OrderStatus;
use Chunlian\Domain\OrderLine;
use Chunlian\Domain\OrderPaymentState;
use Chunlian\Domain\OrderRevisionState;
use Chunlian\Domain\PaymentMethod;
use Chunlian\Domain\RejectionCode;
use Chunlian\Domain\RevisionDeltaType;
use Chunlian\Service\IdempotencyService;
use Chunlian\Service\InMemoryIdempotencyStore;
use Chunlian\Service\LedgerCalculator;
use Chunlian\Service\FreshCategoryPolicy;
use Chunlian\Service\OrderStateMachine;
use Chunlian\Service\RevisionCalculator;

final class SelfCheck
{
    private int $passed = 0;
    private int $failed = 0;
    private array $failures = [];

    public function check(string $name, callable $assertion): void
    {
        try {
            $assertion();
            $this->passed++;
            echo "  ✓ {$name}\n";
        } catch (Throwable $e) {
            $this->failed++;
            $this->failures[] = "{$name}: {$e->getMessage()}";
            echo "  ✗ {$name}\n      {$e->getMessage()}\n";
        }
    }

    public function summary(): int
    {
        $total = $this->passed + $this->failed;
        echo "\n" . str_repeat('=', 60) . "\n";
        echo "通过 {$this->passed} / {$total}";
        if ($this->failed > 0) {
            echo "  ✗ 失败 {$this->failed}\n";
            foreach ($this->failures as $f) {
                echo "  - {$f}\n";
            }

            return 1;
        }
        echo "  ✓ 全部通过\n";

        return 0;
    }
}

function assertSame(mixed $expected, mixed $actual, string $label = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf(
            '%s期望 %s，实际 %s',
            $label === '' ? '' : "{$label}：",
            var_export($expected, true),
            var_export($actual, true)
        ));
    }
}

function assertTrue(bool $value, string $label = ''): void
{
    if ($value !== true) {
        throw new RuntimeException(($label === '' ? '' : "{$label}：") . '期望 true，实际 false');
    }
}

function assertFalse(bool $value, string $label = ''): void
{
    if ($value !== false) {
        throw new RuntimeException(($label === '' ? '' : "{$label}：") . '期望 false，实际 true');
    }
}

/** 标准测试订单：青菜×2 @3.50 + 土豆×1 @2.80 = 9.80 */
function makeOrder(int $version = 1, PaymentMethod $method = PaymentMethod::COD): Order
{
    return new Order(
        id: '10025',
        orderNo: '20261012193000001',
        version: $version,
        orderStatus: OrderStatus::PENDING,
        paymentState: OrderPaymentState::UNPAID,
        revisionState: OrderRevisionState::NONE,
        paymentMethod: $method,
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

function catalog(): array
{
    return [
        'SKU_102' => ['unit_fen' => 400, 'stock' => 50, 'product_id' => 'P1', 'name' => '小青菜', 'spec' => '500g/份', 'image' => 'a.jpg'],
        'SKU_205' => ['unit_fen' => 280, 'stock' => 50, 'product_id' => 'P2', 'name' => '土豆', 'spec' => '500g/份', 'image' => 'b.jpg'],
        'SKU_301' => ['unit_fen' => 490, 'stock' => 20, 'product_id' => 'P3', 'name' => '西红柿', 'spec' => '500g/份', 'image' => 'c.jpg'],
    ];
}

$t = new SelfCheck();
$sm = new OrderStateMachine();
$calc = new RevisionCalculator();
$ledger = new LedgerCalculator();

echo "春连鲜选 后端核心规则自检\n";
echo str_repeat('=', 60) . "\n\n";

echo "[金额] 整数分运算\n";

$t->check('3.50 解析为 350 分', function (): void {
    assertSame(350, Money::fromYuanString('3.50')->fen());
});

$t->check('0.1 + 0.2 = 0.30（无浮点漂移）', function (): void {
    assertSame(30, Money::fromYuanString('0.10')->add(Money::fromYuanString('0.20'))->fen());
});

$t->check('拒绝三位小数', function (): void {
    try {
        Money::fromYuanString('3.505');
        throw new RuntimeException('应当拒绝三位小数');
    } catch (InvalidArgumentException) {
        // 预期
    }
});

$t->check('订单总额 = 商品小计 + 配送费', function (): void {
    $order = makeOrder();
    assertSame(980, $order->total()->fen());
    assertSame(700, $order->lines[0]->subtotal()->fen());
});

echo "\n[状态机] 四维独立与迁移守卫\n";

$t->check('仅 PENDING 允许顾客自助改/取消', function (): void {
    assertTrue(OrderStatus::PENDING->allowsCustomerSelfService());
    assertFalse(OrderStatus::DELIVERING->allowsCustomerSelfService());
    assertFalse(OrderStatus::DELIVERED->allowsCustomerSelfService());
});

$t->check('不允许跳步：PENDING → DELIVERED', function (): void {
    assertFalse(OrderStatus::PENDING->canTransitionTo(OrderStatus::DELIVERED));
    assertTrue(OrderStatus::PENDING->canTransitionTo(OrderStatus::DELIVERING));
});

$t->check('T20 待补款修订时禁止开始配送', function () use ($sm): void {
    $order = makeOrder();
    $order->revisionState = OrderRevisionState::PENDING_TOPUP;
    $result = $sm->startDelivery($order, 1);
    assertFalse($result->success);
    assertSame(RejectionCode::TOPUP_REQUIRED, $result->code);
    assertSame(409, $result->httpStatus());
});

$t->check('T19 版本冲突拒绝', function () use ($sm): void {
    $order = makeOrder(version: 2);
    $result = $sm->startDelivery($order, 1);
    assertFalse($result->success);
    assertSame(RejectionCode::ORDER_VERSION_CONFLICT, $result->code);
    assertSame(2, $result->get('current_version'));
});

$t->check('开始配送成功并锁定顾客编辑', function () use ($sm): void {
    $result = $sm->startDelivery(makeOrder(version: 1), 1);
    assertTrue($result->success);
    assertSame(OrderStatus::DELIVERING, $result->get('order_status'));
    assertSame(2, $result->get('version'));
    assertTrue($result->get('locked_customer_editing'));
});

$t->check('T21 已开始配送后顾客不可再修改', function () use ($sm): void {
    $order = makeOrder(version: 3);
    $order->orderStatus = OrderStatus::DELIVERING;
    $result = $sm->canStartRevision($order, 3);
    assertFalse($result->success);
    assertSame(RejectionCode::ORDER_NOT_EDITABLE, $result->code);
});

$t->check('T22 已开始配送后顾客不可自助取消（给售后入口）', function () use ($sm): void {
    $order = makeOrder(version: 3);
    $order->orderStatus = OrderStatus::DELIVERING;
    $result = $sm->cancel($order, 3);
    assertFalse($result->success);
    assertSame(RejectionCode::ORDER_NOT_EDITABLE, $result->code);
    assertSame('AFTER_SALES', $result->get('next_step'));
});

echo "\n[COD 送达与收款] 合并动作的正确语义\n";

$t->check('T24 COD 已收款并送达：同事务写 DELIVERED + 流水', function () use ($sm): void {
    $order = makeOrder(version: 4);
    $order->orderStatus = OrderStatus::DELIVERING;
    $result = $sm->confirmDelivery($order, 4, receivedMoney: true, collectedFen: 980);
    assertTrue($result->success);
    assertSame(OrderStatus::DELIVERED, $result->get('order_status'));
    assertSame(980, $result->get('paid_fen'));
    assertSame(OrderPaymentState::PAID, $result->get('payment_state'));
    assertSame(0, $result->get('receivable_fen'));
    assertTrue($result->get('write_cod_receipt'));
});

$t->check('T25 COD 仅送达未收款：保留应收，不记实收', function () use ($sm): void {
    $order = makeOrder(version: 4);
    $order->orderStatus = OrderStatus::DELIVERING;
    $result = $sm->confirmDelivery($order, 4, receivedMoney: false);
    assertTrue($result->success);
    assertSame(OrderStatus::DELIVERED, $result->get('order_status'));
    assertSame(OrderPaymentState::UNPAID, $result->get('payment_state'));
    assertSame(980, $result->get('receivable_fen'));
    assertFalse($result->get('write_cod_receipt'));
});

$t->check('实收金额必须与应收一致', function () use ($sm): void {
    $order = makeOrder(version: 4);
    $order->orderStatus = OrderStatus::DELIVERING;
    $result = $sm->confirmDelivery($order, 4, receivedMoney: true, collectedFen: 900);
    assertFalse($result->success);
    assertSame(RejectionCode::VALIDATION_FAILED, $result->code);
});

$t->check('T23 在线已付款订单送达不重复收款', function () use ($sm): void {
    $order = makeOrder(version: 4, method: PaymentMethod::WECHAT);
    $order->orderStatus = OrderStatus::DELIVERING;
    $order->paidFen = 980;
    $order->paymentState = OrderPaymentState::PAID;

    $result = $sm->confirmDelivery($order, 4, receivedMoney: false);
    assertTrue($result->success);
    assertSame(980, $result->get('paid_fen'));
    assertFalse($result->get('write_cod_receipt'));
});

$t->check('在线订单不得登记 COD 收款', function () use ($sm): void {
    $order = makeOrder(version: 4, method: PaymentMethod::WECHAT);
    $order->orderStatus = OrderStatus::DELIVERING;
    $order->paidFen = 980;
    $result = $sm->confirmDelivery($order, 4, receivedMoney: true, collectedFen: 980);
    assertFalse($result->success);
});

$t->check('T26 送达后补记收款只生效一次', function () use ($sm): void {
    $order = makeOrder(version: 5);
    $order->orderStatus = OrderStatus::DELIVERED;

    $first = $sm->recordCodReceipt($order, 5, 980);
    assertTrue($first->success);

    // 已收后再补记 → 拒绝
    $order->paidFen = 980;
    $order->paymentState = OrderPaymentState::PAID;
    $order->version = 6;

    $second = $sm->recordCodReceipt($order, 6, 980);
    assertFalse($second->success);
    assertSame(RejectionCode::COD_ALREADY_COLLECTED, $second->code);
});

echo "\n[修订与差价] 定价规则与资金方向\n";

$t->check('旧有 SKU 维持原下单单价（尽管已涨价）', function () use ($calc): void {
    $result = $calc->preview(makeOrder(), [['sku_id' => 'SKU_102', 'qty' => 2]], catalog());
    assertTrue($result->success);
    assertSame(700, $result->get('new_goods_fen'));
    assertSame('ORIGINAL_ORDER', $result->get('priced_lines')[0]['priced_by']);
});

$t->check('增量数量按现价', function () use ($calc): void {
    // 青菜 2→4：2×350 + 2×400 = 700 + 800 = 1500
    // 注意：本明细未包含土豆，原订单 980 → 新 1500，delta = +520
    $result = $calc->preview(makeOrder(), [['sku_id' => 'SKU_102', 'qty' => 4]], catalog());
    assertSame(1500, $result->get('new_goods_fen'));
    assertSame(520, $result->get('delta_fen'));
    assertSame('MIXED', $result->get('priced_lines')[0]['priced_by']);
    assertSame(1500, $result->get('priced_lines')[0]['line_total_fen']);

    // 不减土豆时纯增量：青菜 2→4 + 土豆 1，980 → 700+800+280 = 1780，delta = +800
    $pure = $calc->preview(makeOrder(), [
        ['sku_id' => 'SKU_102', 'qty' => 4],
        ['sku_id' => 'SKU_205', 'qty' => 1],
    ], catalog());
    assertSame(1780, $pure->get('new_goods_fen'));
    assertSame(800, $pure->get('delta_fen'));
});

$t->check('新增 SKU 按现价', function () use ($calc): void {
    $result = $calc->preview(makeOrder(), [
        ['sku_id' => 'SKU_102', 'qty' => 2],
        ['sku_id' => 'SKU_301', 'qty' => 1],
    ], catalog());
    assertSame(1190, $result->get('new_goods_fen'));
    assertSame(210, $result->get('delta_fen'));
    assertSame('TOPUP', $result->get('delta_type'));
    assertTrue($result->get('requires_topup_before_commit'));
});

$t->check('T14 已付款减量 → 退款差额', function () use ($calc): void {
    $result = $calc->preview(makeOrder(), [['sku_id' => 'SKU_205', 'qty' => 1]], catalog());
    assertSame(-700, $result->get('delta_fen'));
    assertSame('REFUND', $result->get('delta_type'));
    assertFalse($result->get('requires_topup_before_commit'));
});

$t->check('T16 等价明细 → delta=0，无资金动作', function () use ($calc): void {
    $result = $calc->preview(makeOrder(), [
        ['sku_id' => 'SKU_102', 'qty' => 2],
        ['sku_id' => 'SKU_205', 'qty' => 1],
    ], catalog());
    assertSame(0, $result->get('delta_fen'));
    assertSame('NONE', $result->get('delta_type'));
    assertFalse($result->get('goods_changed'));
});

$t->check('T04 库存不足阻止提交', function () use ($calc): void {
    $c = catalog();
    $c['SKU_301']['stock'] = 0;
    $result = $calc->preview(makeOrder(), [['sku_id' => 'SKU_301', 'qty' => 1]], $c);
    assertFalse($result->success);
    assertSame(RejectionCode::OUT_OF_STOCK, $result->code);
});

$t->check('库存差额对称可逆', function () use ($calc): void {
    $old = [new OrderLine('SKU_102', 'P1', '小青菜', '500g/份', 'a.jpg', 350, 2)];
    $new = [new OrderLine('SKU_102', 'P1', '小青菜', '500g/份', 'a.jpg', 350, 5)];
    assertSame(3, $calc->stockDelta($old, $new)['SKU_102']);
    assertSame(-3, $calc->stockDelta($new, $old)['SKU_102']);
});

$t->check('回归：混合计价行 unit×qty 必须等于行小计', function () use ($calc): void {
    // 曾出现缺陷：混合计价时把 unitFen 存成现价，导致 subtotal 被重复放大。
    // 青菜 2→4，原价 3.50 现价 4.00 → 行小计必须是 1500，不是 1600。
    $result = $calc->preview(makeOrder(), [['sku_id' => 'SKU_102', 'qty' => 4]], catalog());
    assertSame(1500, $result->get('new_goods_fen'));

    foreach ($result->get('lines') as $line) {
        assertSame(
            $line['subtotal_fen'],
            $line['unit_fen'] * $line['qty'],
            "行 {$line['sku_id']} unit×qty 应等于 subtotal"
        );
    }
});

$t->check('回归：无法整除时拆行，仍保证行小计守恒', function () use ($calc): void {
    // 原价 333/3 件 → 现价 400，数量 3→4：
    // 3×333 + 1×400 = 1399，1399/4 不能整除 → 必须拆成两条行
    $order = new Order(
        id: '90001',
        orderNo: 'NO90001',
        version: 1,
        orderStatus: OrderStatus::PENDING,
        paymentState: OrderPaymentState::UNPAID,
        revisionState: OrderRevisionState::NONE,
        paymentMethod: PaymentMethod::COD,
        deliveryMode: DeliveryMode::ASAP,
        expectedAt: null,
        lines: [new OrderLine('SKU_X', 'PX', '奇数价商品', '1份', 'x.jpg', 333, 3)],
        contact: new Contact('张先生', '13800138000', 'address'),
        deliveryFeeFen: 0,
    );

    $catalog = [
        'SKU_X' => ['unit_fen' => 400, 'stock' => 99, 'product_id' => 'PX', 'name' => '奇数价商品', 'spec' => '1份', 'image' => 'x.jpg'],
    ];

    $result = $calc->preview($order, [['sku_id' => 'SKU_X', 'qty' => 4]], $catalog);
    assertTrue($result->success);
    assertSame(1399, $result->get('new_goods_fen'));

    $sum = 0;
    $lines = $result->get('lines');
    foreach ($lines as $line) {
        assertSame($line['subtotal_fen'], $line['unit_fen'] * $line['qty'], "行 {$line['sku_id']} 小计守恒");
        $sum += $line['subtotal_fen'];
    }
    assertSame(1399, $sum, '所有行小计之和等于订单商品总额');
    assertSame(2, count($lines), '应拆为原价行 + 现价行');
});

$t->check('资金动作矩阵正确', function () use ($calc): void {
    assertSame('NONE', $calc->fundingAction(0, true));
    assertSame('TOPUP', $calc->fundingAction(940, true));
    assertSame('REFUND', $calc->fundingAction(-940, true));
    assertSame('REISSUE_PAYMENT', $calc->fundingAction(940, false));
});

echo "\n[取消与退款] 防超退\n";

$t->check('T18 COD 未收款取消：无退款，释放库存', function () use ($sm): void {
    $result = $sm->cancel(makeOrder(version: 2), 2);
    assertTrue($result->success);
    assertSame(0, $result->get('refund_fen'));
    assertTrue($result->get('release_stock'));
});

$t->check('T17 已付款取消：发起全额退款', function () use ($sm): void {
    $order = makeOrder(version: 3, method: PaymentMethod::WECHAT);
    $order->paidFen = 980;
    $order->paymentState = OrderPaymentState::PAID;
    $result = $sm->cancel($order, 3);
    assertSame(980, $result->get('refund_fen'));
    assertSame(OrderPaymentState::REFUNDING, $result->get('payment_state'));
});

$t->check('部分退款后取消：应退扣减已退金额', function () use ($sm): void {
    $order = makeOrder(version: 3, method: PaymentMethod::WECHAT);
    $order->paidFen = 980;
    $order->refundedFen = 300;
    $order->paymentState = OrderPaymentState::PARTIAL_REFUNDED;
    $result = $sm->cancel($order, 3);
    assertSame(680, $result->get('refund_fen'));
});

$t->check('全部已退后取消：退款金额为 0', function () use ($sm): void {
    $order = makeOrder(version: 3, method: PaymentMethod::WECHAT);
    $order->paidFen = 980;
    $order->refundedFen = 980;
    $order->paymentState = OrderPaymentState::REFUNDED;
    $result = $sm->cancel($order, 3);
    assertSame(0, $result->get('refund_fen'));
});

$t->check('可退余额排除处理中退款（防重复发起）', function () use ($ledger): void {
    $entries = [
        ['kind' => 'WX_PAY', 'amount_fen' => 980, 'status' => 'SUCCESS'],
        ['kind' => 'WX_REFUND', 'amount_fen' => -300, 'status' => 'PROCESSING'],
    ];
    assertSame(680, $ledger->refundableFen($entries));
});

$t->check('失败的退款不占用可退额度', function () use ($ledger): void {
    $entries = [
        ['kind' => 'WX_PAY', 'amount_fen' => 980, 'status' => 'SUCCESS'],
        ['kind' => 'WX_REFUND', 'amount_fen' => -300, 'status' => 'FAILED'],
    ];
    assertSame(980, $ledger->refundableFen($entries));
});

echo "\n[统计口径] 实收与应收分离\n";

$t->check('今日实收 = 微信 + COD − 退款', function () use ($ledger): void {
    $entries = [
        ['kind' => 'WX_PAY', 'amount_fen' => 21000, 'status' => 'SUCCESS', 'occurred_at' => '2026-10-12 10:00:00'],
        ['kind' => 'COD_RECEIPT', 'amount_fen' => 7650, 'status' => 'SUCCESS', 'occurred_at' => '2026-10-12 18:40:00'],
        ['kind' => 'WX_REFUND', 'amount_fen' => -1000, 'status' => 'SUCCESS', 'occurred_at' => '2026-10-12 19:00:00'],
    ];
    $s = $ledger->dailySummary($entries, '2026-10-12');
    assertSame(27650, $s['received_fen']);
    assertSame(21000, $s['online_fen']);
    assertSame(7650, $s['cod_fen']);
});

$t->check('未成功的流水不计入实收', function () use ($ledger): void {
    $entries = [
        ['kind' => 'WX_PAY', 'amount_fen' => 10000, 'status' => 'SUCCESS', 'occurred_at' => '2026-10-12 10:00:00'],
        ['kind' => 'WX_PAY', 'amount_fen' => 5000, 'status' => 'PENDING', 'occurred_at' => '2026-10-12 10:30:00'],
        ['kind' => 'WX_REFUND', 'amount_fen' => -2000, 'status' => 'PROCESSING', 'occurred_at' => '2026-10-12 11:00:00'],
    ];
    assertSame(10000, $ledger->dailySummary($entries, '2026-10-12')['received_fen']);
});

$t->check('COD 已送达未收款不计入实收但计入应收', function () use ($ledger): void {
    assertSame(0, $ledger->dailySummary([], '2026-10-12')['received_fen']);
    assertSame(980, $ledger->receivableFen([], 980));
});

echo "\n[幂等] 重复操作不重复入账\n";

$t->check('T12/T27 同一幂等键只执行一次副作用', function (): void {
    $calls = 0;
    $service = new IdempotencyService(new InMemoryIdempotencyStore());
    $op = function () use (&$calls): array {
        $calls++;

        return ['ledger_id' => 3012];
    };

    $key = '9f1c2f3e-4a5b-4c6d-8e7f-0a1b2c3d4e5f';
    $payload = ['order_version' => 4, 'received_money' => true];

    $a = $service->run('merchant:1', '/orders/10025/deliver', $key, $payload, $op);
    $b = $service->run('merchant:1', '/orders/10025/deliver', $key, $payload, $op);
    $c = $service->run('merchant:1', '/orders/10025/deliver', $key, $payload, $op);

    assertSame(1, $calls, '操作执行次数');
    assertFalse($a['replayed']);
    assertTrue($b['replayed']);
    assertTrue($c['replayed']);
    assertSame(3012, $b['data']['ledger_id']);
});

$t->check('同键不同请求体 → IDEMPOTENCY_CONFLICT', function (): void {
    $service = new IdempotencyService(new InMemoryIdempotencyStore());
    $key = '11111111-2222-4333-8444-555555555555';

    $service->run('merchant:1', '/x', $key, ['amount' => 100], static fn (): array => ['ok' => true]);
    $result = $service->run('merchant:1', '/x', $key, ['amount' => 200], static fn (): array => ['ok' => true]);

    assertSame(RejectionCode::IDEMPOTENCY_CONFLICT->value, $result['code']);
});

$t->check('失败操作释放幂等键，允许重试', function (): void {
    $service = new IdempotencyService(new InMemoryIdempotencyStore());
    $key = 'abcdefab-1111-4222-8333-444455556666';

    try {
        $service->run('m:1', '/x', $key, ['a' => 1], static function (): array {
            throw new RuntimeException('boom');
        });
    } catch (RuntimeException) {
        // 预期
    }

    $result = $service->run('m:1', '/x', $key, ['a' => 1], static fn (): array => ['ok' => true]);
    assertFalse($result['replayed']);
});

echo "\n[配送时间] 预约校验\n";

$t->check('T08 拒绝预约过去时间', function () use ($sm): void {
    $now = new DateTimeImmutable('2026-10-12 19:00:00');
    $result = $sm->validateDeliveryTime(
        DeliveryMode::SCHEDULED,
        new DateTimeImmutable('2026-10-11 17:30:00'),
        $now
    );
    assertFalse($result->success);
    assertSame(RejectionCode::INVALID_EXPECTED_TIME, $result->code);
});

$t->check('T07 接受预约明天 17:30', function () use ($sm): void {
    $now = new DateTimeImmutable('2026-10-12 19:00:00');
    $result = $sm->validateDeliveryTime(
        DeliveryMode::SCHEDULED,
        new DateTimeImmutable('2026-10-13 17:30:00'),
        $now
    );
    assertTrue($result->success);
});

$t->check('T06 尽快配送无需预约时间', function () use ($sm): void {
    $result = $sm->validateDeliveryTime(
        DeliveryMode::ASAP,
        null,
        new DateTimeImmutable('2026-10-12 19:00:00')
    );
    assertTrue($result->success);
});

echo "\n[隐私] 手机号脱敏\n";

$t->check('列表脱敏：138****8000', function (): void {
    $contact = new Contact('张先生', '13800138000', '阳光小区3栋502室');
    assertSame('138****8000', $contact->maskedPhone());
});

$t->check('拒绝非法手机号', function (): void {
    assertFalse(Contact::isValidPhone('12345678901'));
    assertTrue(Contact::isValidPhone('13800138000'));
});

echo "\n[目录边界] 自有客户端仅暴露生鲜分类\n";

$t->check('显式根类登记保留生鲜根类及子类并排除 ShopXO 演示分类', function (): void {
    $rows = [
        ['id' => 1, 'pid' => 0, 'name' => '数码办公'],
        ['id' => 2, 'pid' => 0, 'name' => '叶菜类'],
        ['id' => 3, 'pid' => 2, 'name' => '本地叶菜'],
        ['id' => 4, 'pid' => 1, 'name' => '笔记本电脑'],
        ['id' => 5, 'pid' => 0, 'name' => '瓜果类'],
    ];
    $filtered = FreshCategoryPolicy::filterEnabledRows($rows, ['2', '5']);
    assertSame(['2', '3', '5'], array_map(static fn (array $row): string => (string) $row['id'], $filtered));
});

$t->check('商品目录 SQL 使用持久化根类登记且括号完整', function (): void {
    $sql = FreshCategoryPolicy::goodsPredicate('shopxo_', 'g');
    assertTrue(str_contains($sql, 'shopxo_goods_category_join'));
    assertTrue(str_contains($sql, 'veg_fresh_category_root'));
    assertFalse(str_contains($sql, '叶菜类'));
    assertSame(substr_count($sql, '('), substr_count($sql, ')'));
});

$t->check('商家仍能读取停用分类商品，顾客端 SQL 会排除停用分类', function (): void {
    $merchantSql = FreshCategoryPolicy::goodsPredicate('shopxo_', 'g', false);
    $customerSql = FreshCategoryPolicy::goodsPredicate('shopxo_', 'g', true);
    assertFalse(str_contains($merchantSql, '`is_enable`'));
    assertTrue(str_contains($customerSql, '`is_enable`'));
    assertSame(substr_count($merchantSql, '('), substr_count($merchantSql, ')'));
});

exit($t->summary());
