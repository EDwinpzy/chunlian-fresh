<?php

declare(strict_types=1);

namespace Chunlian\Repository;

use Chunlian\Domain\CollectionMethod;
use Chunlian\Domain\Contact;
use Chunlian\Domain\DeliveryMode;
use Chunlian\Domain\Order;
use Chunlian\Domain\OrderFulfillment;
use Chunlian\Domain\OrderLine;
use Chunlian\Domain\OrderPaymentState;
use Chunlian\Domain\OrderReceiptState;
use Chunlian\Domain\OrderRevisionState;
use Chunlian\Domain\PaymentMethod;

/**
 * 行映射器 —— 数据库行 ↔ 领域对象。
 *
 * 【为什么单独抽出这一层】
 * 持久化里真正容易出错的是**映射**，不是 SQL：
 *   - ShopXO 用 `decimal(10,2)` 存钱，我们用整数分 → 换算必须精确
 *   - 枚举取值不一致时要能**被发现**，而不是静默降级
 *   - ShopXO 的单一 `status` 与我们的四维状态语义不同 → 必须明确谁是真源
 * 这些逻辑全部是纯函数，可以脱离数据库完整测试。
 * SQL 本身很直白，放到集成测试里对真实 MySQL 验证即可。
 *
 * 【真源划分 —— 本层最重要的约定】
 *   履约 / 支付 / 收货 / 修订 四维状态  → `veg_order_delivery`（我方表，**唯一真源**）
 *   商品行（含 SKU 身份）              → `veg_order_line`（我方表，**唯一真源**）
 *   订单号 / 金额 / 收货地址            → `shopxo_order` + `shopxo_order_address`（ShopXO 原生）
 *
 *   ShopXO 的 `status` 字段**不作为履约真源**，仅在缺失扩展行时兜底。
 *   原因：它的 `4 = 已完成` 把「已送达」与「已完成」合并了，
 *   直接采信会把 COD 未收款显示成已完成 → 漏账。
 */
final class OrderRowMapper
{
    /** 映射过程中的降级记录，供调用方写日志/告警 */
    private array $warnings = [];

    /** @return string[] 取出并清空警告 */
    public function flushWarnings(): array
    {
        $w = $this->warnings;
        $this->warnings = [];

        return $w;
    }

    private function warn(string $message): void
    {
        $this->warnings[] = $message;
    }

    // =========================================================
    // 金额换算：decimal(10,2) ↔ 整数分
    // =========================================================

    /**
     * `decimal(10,2)` → 整数分。
     *
     * 【为什么走字符串而不是 float】
     * `(int)(12.34 * 100)` 在 IEEE754 下可能得到 1233 而不是 1234。
     * 金额换算只要出现一次这类偏差，对账就不平。
     * 因此按字符串拆分整数位与小数位，全程整数运算。
     *
     * 超过两位小数时第三位四舍五入（decimal(10,2) 理论上不会出现，
     * 但人工改库或数据迁移可能带来）。
     *
     * @throws \InvalidArgumentException 值不是合法数字
     */
    public static function decimalToFen(string|int|float|null $value): int
    {
        if ($value === null) {
            return 0;
        }

        $s = trim((string) $value);
        if ($s === '') {
            return 0;
        }

        $negative = false;
        if ($s[0] === '-') {
            $negative = true;
            $s = substr($s, 1);
        } elseif ($s[0] === '+') {
            $s = substr($s, 1);
        }

        // 科学计数法（如 1.0E-5）不在支持范围 —— 出现即说明数据来源异常，应当暴露而非猜测
        if (!preg_match('/^\d*(?:\.\d*)?$/', $s) || $s === '.' || $s === '') {
            throw new \InvalidArgumentException(sprintf('无法解析金额：%s', (string) $value));
        }

        $parts = explode('.', $s, 2);
        $intPart = $parts[0] === '' ? '0' : $parts[0];
        $fracPart = $parts[1] ?? '';

        // 补齐到 3 位，第 3 位用于四舍五入
        $frac3 = str_pad(substr($fracPart, 0, 3), 3, '0');

        $fen = ((int) $intPart) * 100 + (int) substr($frac3, 0, 2);
        if ((int) $frac3[2] >= 5) {
            $fen += 1;
        }

        return $negative ? -$fen : $fen;
    }

    /**
     * 整数分 → `decimal(10,2)` 字符串（写回 ShopXO 原生表时使用）。
     *
     * 例：1234 → "12.34"；-350 → "-3.50"；0 → "0.00"
     */
    public static function fenToDecimal(int $fen): string
    {
        $negative = $fen < 0;
        $abs = $negative ? -$fen : $fen;

        return sprintf(
            '%s%d.%02d',
            $negative ? '-' : '',
            intdiv($abs, 100),
            $abs % 100
        );
    }

    // =========================================================
    // 枚举解析
    // =========================================================

    /**
     * 解析枚举；未知取值时降级并记录警告。
     *
     * 【为什么降级而不是抛异常】
     * 读取路径抛异常会让一条脏数据毁掉整个列表查询 ——
     * 商家连别的单都看不到，损失远大于"某一单状态显示为默认值"。
     *
     * 【为什么必须记录警告】
     * 静默降级是本项目已经踩过的坑：客户端枚举与服务端不一致时，
     * 界面显示错误状态却毫无提示，排查成本极高。
     * 降级必须留下痕迹，才能被日志/监控发现。
     *
     * @template T of \BackedEnum
     * @param class-string<T> $enumClass
     * @return T
     */
    private function parseEnum(string $enumClass, ?string $raw, \BackedEnum $default, string $field, string $orderId): \BackedEnum
    {
        if ($raw === null || $raw === '') {
            $this->warn(sprintf('订单 %s 的 %s 为空，降级为 %s', $orderId, $field, $default->value));

            return $default;
        }

        $value = $enumClass::tryFrom($raw);
        if ($value === null) {
            $this->warn(sprintf(
                '订单 %s 的 %s 出现未知取值 "%s"，降级为 %s（请核对客户端与服务端枚举是否一致）',
                $orderId,
                $field,
                $raw,
                $default->value
            ));

            return $default;
        }

        return $value;
    }

    // =========================================================
    // 组装聚合
    // =========================================================

    /**
     * 组装订单聚合。
     *
     * @param array<string,mixed>      $orderRow    shopxo_order 行
     * @param array<string,mixed>|null $addressRow  shopxo_order_address 行（可为 null）
     * @param array<string,mixed>|null $deliveryRow veg_order_delivery 行（缺失时用 ShopXO status 兜底）
     * @param array<int,array<string,mixed>> $lineRows veg_order_line 行
     */
    public function toOrder(
        array $orderRow,
        ?array $addressRow,
        ?array $deliveryRow,
        array $lineRows,
    ): Order {
        $orderId = (string) $orderRow['id'];
        $shopxoStatus = (int) ($orderRow['status'] ?? 0);

        // ---------- 四维状态 ----------
        if ($deliveryRow === null) {
            // 扩展行缺失：通常是插件安装前创建的历史订单
            $this->warn(sprintf(
                '订单 %s 缺少 veg_order_delivery 扩展行，已用 ShopXO status=%d 兜底；'
                . '该兜底无法区分「已送达」与「已完成」，履约状态可能不准',
                $orderId,
                $shopxoStatus
            ));

            $fulfillment = self::fulfillmentFromShopxoStatus($shopxoStatus);
            $paymentState = $this->paymentStateFromShopxo($orderRow);
            $receiptState = OrderReceiptState::UNCONFIRMED;
            $revisionState = OrderRevisionState::NONE;
            $paymentMethod = ((int) ($orderRow['is_under_line'] ?? 0)) === 1
                ? PaymentMethod::COD
                : PaymentMethod::WECHAT;
            $deliveryMode = DeliveryMode::ASAP;
            $expectedAt = null;
            $version = 1;
            $deliveredAt = null;
            $receivedAt = null;
        } else {
            $fulfillment = $this->parseEnum(
                OrderFulfillment::class,
                (string) $deliveryRow['fulfillment'],
                OrderFulfillment::PENDING,
                'fulfillment',
                $orderId
            );
            $paymentState = $this->parseEnum(
                OrderPaymentState::class,
                (string) $deliveryRow['payment_state'],
                OrderPaymentState::UNPAID,
                'payment_state',
                $orderId
            );
            $receiptState = $this->parseEnum(
                OrderReceiptState::class,
                (string) $deliveryRow['receipt_state'],
                OrderReceiptState::UNCONFIRMED,
                'receipt_state',
                $orderId
            );
            $revisionState = $this->parseEnum(
                OrderRevisionState::class,
                (string) $deliveryRow['revision_state'],
                OrderRevisionState::NONE,
                'revision_state',
                $orderId
            );
            $paymentMethod = $this->parseEnum(
                PaymentMethod::class,
                (string) $deliveryRow['payment_method'],
                PaymentMethod::COD,
                'payment_method',
                $orderId
            );
            $deliveryMode = $this->parseEnum(
                DeliveryMode::class,
                (string) $deliveryRow['delivery_mode'],
                DeliveryMode::ASAP,
                'delivery_mode',
                $orderId
            );
            $expectedAt = self::toDateTime($deliveryRow['expected_at'] ?? null);
            $version = (int) ($deliveryRow['version'] ?? 1);
            $deliveredAt = self::toDateTime($deliveryRow['delivered_at'] ?? null);
            $receivedAt = self::toDateTime($deliveryRow['received_at'] ?? null);
        }

        // ---------- 商品行 ----------
        $lines = [];
        foreach ($lineRows as $row) {
            $lines[] = new OrderLine(
                skuId: (string) $row['sku_id'],
                productId: (string) $row['goods_id'],
                nameSnapshot: (string) $row['name_snapshot'],
                specSnapshot: (string) $row['spec_snapshot'],
                imageSnapshot: (string) $row['image_snapshot'],
                unitFen: (int) $row['unit_fen'],
                qty: (int) $row['qty'],
            );
        }

        // ---------- 收货信息 ----------
        // 用宽松构造：历史数据里的手机号可能不规范，不该让列表查询整体失败
        //
        // 【字段名注意】ShopXO 的地址表用的是 `tel` 而不是 `phone`；
        // 且 `province` / `city` / `county` 是**区域 ID（int）**，
        // 名称在同行的 `province_name` / `city_name` / `county_name` 里。
        // 拼地址必须用名称字段，用 ID 会拼出「11 3301 330106 文一西路」这种东西。
        $contact = Contact::fromRawLenient(
            name: (string) ($addressRow['name'] ?? ''),
            rawPhone: (string) ($addressRow['tel'] ?? ''),
            address: trim(
                (string) ($addressRow['province_name'] ?? '')
                . (string) ($addressRow['city_name'] ?? '')
                . (string) ($addressRow['county_name'] ?? '')
                . (string) ($addressRow['address'] ?? '')
            ),
        );
        if (!$contact->hasDialablePhone()) {
            $this->warn(sprintf('订单 %s 的收货手机号不可直接拨打，请核对原始数据', $orderId));
        }

        return new Order(
            id: $orderId,
            orderNo: (string) ($orderRow['order_no'] ?? ''),
            version: $version,
            fulfillment: $fulfillment,
            paymentState: $paymentState,
            receiptState: $receiptState,
            revisionState: $revisionState,
            paymentMethod: $paymentMethod,
            deliveryMode: $deliveryMode,
            expectedAt: $expectedAt,
            lines: $lines,
            contact: $contact,
            deliveryFeeFen: 0, // 配送费已含在 ShopXO 的 total_price 中，不单独拆分
            paidFen: self::decimalToFen($orderRow['pay_price'] ?? 0),
            refundedFen: self::decimalToFen($orderRow['refund_price'] ?? 0),
            refundingFen: 0,   // 处理中退款需查资金流水汇总，由调用方填充
            deliveredAt: $deliveredAt,
            receivedAt: $receivedAt,
        );
    }

    // =========================================================
    // 兜底映射（仅在扩展行缺失时使用）
    // =========================================================

    /**
     * ShopXO 单一 status → 我们的履约维度（**仅兜底**）。
     *
     * 取值来源：`app/lang/zh.php` 的 `common_order_status`
     *   0 待确认 / 1 待付款 / 2 待发货 / 3 待收货 / 4 已完成 / 5 已取消 / 6 已关闭
     *
     * 【兜底的固有缺陷】
     * ShopXO 的 `4 已完成` 同时涵盖「已送达」与「已完成」两种状态，
     * 因此这里只能映射到 FINISHED —— 无法还原 DELIVERED。
     * 这正是必须有 `veg_order_delivery` 的原因，不是可选优化。
     */
    public static function fulfillmentFromShopxoStatus(int $status): OrderFulfillment
    {
        return match ($status) {
            2 => OrderFulfillment::PENDING,      // 待发货
            3 => OrderFulfillment::DELIVERING,   // 待收货 = 已发货
            4 => OrderFulfillment::FINISHED,     // 已完成
            5, 6 => OrderFulfillment::CANCELLED, // 已取消 / 已关闭
            default => OrderFulfillment::PENDING, // 0 待确认 / 1 待付款
        };
    }

    /** ShopXO 支付字段 → 我们的支付维度（**仅兜底**）。 */
    private function paymentStateFromShopxo(array $orderRow): OrderPaymentState
    {
        $payStatus = (int) ($orderRow['pay_status'] ?? 0);
        $payFen = self::decimalToFen($orderRow['pay_price'] ?? 0);
        $refundFen = self::decimalToFen($orderRow['refund_price'] ?? 0);

        if ($refundFen > 0) {
            return $refundFen >= $payFen
                ? OrderPaymentState::REFUNDED
                : OrderPaymentState::PARTIAL_REFUNDED;
        }

        return $payStatus === 1 ? OrderPaymentState::PAID : OrderPaymentState::UNPAID;
    }

    // =========================================================
    // 时间
    // =========================================================

    /** ShopXO 时间戳为 int 秒；也接受已是日期字符串的情况。 */
    public static function toDateTime(mixed $value): ?\DateTimeImmutable
    {
        if ($value === null || $value === '' || $value === 0 || $value === '0') {
            return null;
        }

        if (is_numeric($value)) {
            $ts = (int) $value;
            if ($ts <= 0) {
                return null;
            }

            return (new \DateTimeImmutable('@' . $ts))->setTimezone(new \DateTimeZone('Asia/Shanghai'));
        }

        try {
            return new \DateTimeImmutable((string) $value, new \DateTimeZone('Asia/Shanghai'));
        } catch (\Exception) {
            return null;
        }
    }
}
