<?php

declare(strict_types=1);

namespace Chunlian\Service;

use Chunlian\Domain\DeliveryMode;
use Chunlian\Domain\Order;
use Chunlian\Domain\OrderFulfillment;
use Chunlian\Domain\OrderPaymentState;
use Chunlian\Domain\PaymentMethod;
use Chunlian\Domain\RejectionCode;
use Chunlian\Repository\OrderRowMapper;
use Chunlian\Repository\PdoOrderRepository;
use Chunlian\Repository\VersionConflictException;

/**
 * 顾客端订单应用服务（网页版 + 安卓版）。
 *
 * 【为什么顾客端要自己实现下单，而不是调用 ShopXO 原生下单接口】
 * ShopXO 的原生下单是「购物车 → 结算 → 创建订单 → 跳支付」的整链，
 * 参数契约复杂（收货地址 ID、支付方式 ID、优惠、发票……），
 * 而本项目只需要其中很小的一部分：选菜 → 填地址 → 选时间 → 下单。
 * 更关键的是：ShopXO 原生下单**不会写 `veg_order_delivery` 扩展行**，
 * 而履约/支付/收货/修订这四维状态正是本项目的核心不变量所在 ——
 * 订单一旦缺少扩展行，商家端的「开始配送 / 确认送达 / COD 收款」全部无从判断。
 *
 * 因此这里直接按 ShopXO 的表结构建单，并在**同一事务**内补齐四张表：
 *   shopxo_order / shopxo_order_detail / shopxo_order_address
 *   + veg_order_delivery / veg_order_line
 * 这样 ShopXO 后台仍能正常看到订单与会员，商家端也能立刻操作。
 *
 * 【金额】全部服务端计算，请求里出现的任何金额字段一律忽略。
 */
final class CustomerOrderService
{
    public function __construct(
        private readonly \PDO $pdo,
        private readonly PdoOrderRepository $orders,
        private readonly OrderStateMachine $stateMachine,
        private readonly IdempotencyService $idempotency,
        private readonly CustomerCatalogService $catalog,
        private readonly ShopSettingService $setting,
        private readonly string $tablePrefix = 'shopxo_',
    ) {
    }

    // =========================================================
    // 收货地址
    // =========================================================

    public function addresses(int $userId): array
    {
        $p = $this->tablePrefix;
        $stmt = $this->pdo->prepare(
            "SELECT `id`, `name`, `tel`, `address`, `is_default` FROM `{$p}user_address` "
            . "WHERE `user_id` = :uid ORDER BY `is_default` DESC, `id` DESC"
        );
        $stmt->execute(['uid' => $userId]);

        return $this->ok(array_map(static fn (array $r): array => [
            'id' => (string) $r['id'],
            'name' => (string) $r['name'],
            'phone' => (string) $r['tel'],
            'address' => (string) $r['address'],
            'is_default' => ((int) $r['is_default']) === 1,
        ], $stmt->fetchAll(\PDO::FETCH_ASSOC)));
    }

    public function saveAddress(int $userId, array $payload): array
    {
        $name = trim((string) ($payload['name'] ?? ''));
        $phone = trim((string) ($payload['phone'] ?? ''));
        $address = trim((string) ($payload['address'] ?? ''));

        if ($name === '') {
            return $this->fail(RejectionCode::VALIDATION_FAILED, '请填写收货人姓名');
        }
        if (preg_match('/^1[3-9]\d{9}$/', $phone) !== 1) {
            return $this->fail(RejectionCode::VALIDATION_FAILED, '请填写正确的手机号');
        }
        if (mb_strlen($address) < 4) {
            return $this->fail(RejectionCode::VALIDATION_FAILED, '请填写详细地址（小区/楼栋/门牌）');
        }

        $p = $this->tablePrefix;
        $id = isset($payload['id']) ? (string) $payload['id'] : '';
        $now = time();
        $isDefault = !empty($payload['is_default']);

        if ($id !== '' && preg_match('/^\d+$/', $id) === 1) {
            // 改地址前先确认这条地址属于当前顾客 —— 否则改一个 id 就能篡改别人的收货地址
            $check = $this->pdo->prepare("SELECT `id` FROM `{$p}user_address` WHERE `id` = :id AND `user_id` = :uid LIMIT 1");
            $check->execute(['id' => $id, 'uid' => $userId]);
            if ($check->fetch(\PDO::FETCH_ASSOC) === false) {
                return $this->fail(RejectionCode::NOT_FOUND, '地址不存在');
            }

            $this->pdo->prepare(
                "UPDATE `{$p}user_address` SET `name` = :name, `tel` = :tel, `address` = :address, "
                . "`upd_time` = :upd WHERE `id` = :id AND `user_id` = :uid"
            )->execute([
                'name' => mb_substr($name, 0, 30),
                'tel' => $phone,
                'address' => mb_substr($address, 0, 200),
                'upd' => $now,
                'id' => $id,
                'uid' => $userId,
            ]);
            $addressId = (int) $id;
        } else {
            $this->pdo->prepare(
                "INSERT INTO `{$p}user_address` "
                . "(`user_id`, `alias`, `name`, `tel`, `province`, `city`, `county`, `address`, "
                . "`is_default`, `add_time`, `upd_time`) "
                . "VALUES (:uid, '', :name, :tel, 0, 0, 0, :address, 0, :add, :upd)"
            )->execute([
                'uid' => $userId,
                'name' => mb_substr($name, 0, 30),
                'tel' => $phone,
                'address' => mb_substr($address, 0, 200),
                'add' => $now,
                'upd' => $now,
            ]);
            $addressId = (int) $this->pdo->lastInsertId();
        }

        // 第一条地址自动成为默认地址：否则顾客下单时会遇到「有地址但没默认」的空档
        $count = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM `{$p}user_address` WHERE `user_id` = {$userId}"
        )->fetchColumn();

        if ($isDefault || $count === 1) {
            $this->setDefaultAddress($userId, (string) $addressId);
        }

        return $this->addresses($userId);
    }

    public function deleteAddress(int $userId, string $addressId): array
    {
        if (preg_match('/^\d+$/', $addressId) !== 1) {
            return $this->fail(RejectionCode::NOT_FOUND, '地址不存在');
        }

        $p = $this->tablePrefix;
        $stmt = $this->pdo->prepare(
            "DELETE FROM `{$p}user_address` WHERE `id` = :id AND `user_id` = :uid"
        );
        $stmt->execute(['id' => $addressId, 'uid' => $userId]);

        if ($stmt->rowCount() === 0) {
            return $this->fail(RejectionCode::NOT_FOUND, '地址不存在');
        }

        // 删掉的是默认地址时，把剩下最新的一条顶上来，
        // 避免出现「有地址但无默认」导致结算页选不中地址
        $left = $this->pdo->query(
            "SELECT COUNT(*) AS total, SUM(`is_default` = 1) AS defaults "
            . "FROM `{$p}user_address` WHERE `user_id` = {$userId}"
        )->fetch(\PDO::FETCH_ASSOC) ?: ['total' => 0, 'defaults' => 0];

        if ((int) $left['total'] > 0 && (int) $left['defaults'] === 0) {
            $first = $this->pdo->query(
                "SELECT `id` FROM `{$p}user_address` WHERE `user_id` = {$userId} ORDER BY `id` DESC LIMIT 1"
            )->fetchColumn();
            if ($first !== false) {
                $this->setDefaultAddress($userId, (string) $first);
            }
        }

        return $this->addresses($userId);
    }

    public function setDefaultAddress(int $userId, string $addressId): array
    {
        $p = $this->tablePrefix;

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                "UPDATE `{$p}user_address` SET `is_default` = 0 WHERE `user_id` = :uid"
            )->execute(['uid' => $userId]);
            $this->pdo->prepare(
                "UPDATE `{$p}user_address` SET `is_default` = 1 WHERE `id` = :id AND `user_id` = :uid"
            )->execute(['id' => $addressId, 'uid' => $userId]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return $this->addresses($userId);
    }

    // =========================================================
    // 下单
    // =========================================================

    /**
     * 金额预览（不建单）。
     *
     * 结算页要在顾客点「提交订单」之前就显示准确金额，
     * 且**必须由服务端算** —— 客户端算出来的金额与下单时的金额不一致，
     * 是顾客投诉最多的那类问题。
     */
    public function preview(array $payload): array
    {
        $priced = $this->priceItems($payload['items'] ?? []);
        if ($priced['code'] !== 'OK') {
            return $priced;
        }

        $setting = $this->setting->get();
        $deliveryFee = (int) $setting['delivery_fee_fen'];

        $mode = $this->parseDeliveryMode($payload['delivery_mode'] ?? null);
        $timeCheck = $this->checkExpectedAt($mode, $payload['expected_at'] ?? null, (int) $setting['min_lead_minutes']);
        if ($timeCheck !== null) {
            return $timeCheck;
        }

        $lines = $priced['data']['lines'];
        $goodsFen = $priced['data']['goods_total_fen'];

        return $this->ok([
            'lines' => $lines,
            'goods_total_fen' => $goodsFen,
            'delivery_fee_fen' => $deliveryFee,
            'total_fen' => $goodsFen + $deliveryFee,
            'delivery_mode' => $mode->value,
            'shop_open' => $setting['is_open'],
            'shop_name' => $setting['shop_name'],
            'notice' => $setting['notice'],
        ]);
    }

    /**
     * 创建订单。
     *
     * @param array<string,mixed> $payload
     */
    public function create(int $userId, array $payload, string $idemKey): array
    {
        // 幂等：顾客在网络抖动下重复点「提交订单」是最常见的重复来源，
        // 而重复建单意味着重复备货与重复配送 —— 必须去重
        try {
            $outer = $this->idempotency->run(
                (string) $userId,
                'customer.order.create',
                $idemKey,
                $payload,
                fn (): array => $this->createTransactional($userId, $payload)
            );
        } catch (\Throwable $e) {
            // 幂等存储异常不应阻断下单；抛出去交给上层记录
            throw $e;
        }

        if (($outer['code'] ?? '') !== 'OK') {
            return [
                'code' => (string) $outer['code'],
                'message' => (string) ($outer['data']['message'] ?? RejectionCode::IDEMPOTENCY_CONFLICT->defaultMessage()),
                'data' => null,
            ];
        }

        $inner = $outer['data'];
        if (is_array($inner) && isset($inner['code'])) {
            return $inner;
        }

        return $this->ok($inner);
    }

    /** @param array<string,mixed> $payload */
    private function createTransactional(int $userId, array $payload): array
    {
        // 打烊校验必须在事务外先做一次快速失败，事务内再做一次权威判定
        $closed = $this->setting->assertOpen();
        if ($closed !== null) {
            return $closed;
        }

        $priced = $this->priceItems($payload['items'] ?? []);
        if ($priced['code'] !== 'OK') {
            return $priced;
        }
        $lines = $priced['data']['lines'];
        $goodsFen = $priced['data']['goods_total_fen'];

        $contact = $this->parseContact($payload['contact'] ?? null);
        if ($contact === null) {
            return $this->fail(RejectionCode::VALIDATION_FAILED, '请填写收货人、手机号与详细地址');
        }

        $methodRaw = strtoupper((string) ($payload['payment_method'] ?? 'COD'));
        $method = PaymentMethod::tryFrom($methodRaw);
        if ($method === null) {
            return $this->fail(RejectionCode::VALIDATION_FAILED, '支付方式不正确');
        }

        if ($method === PaymentMethod::WECHAT) {
            return $this->fail(RejectionCode::VALIDATION_FAILED, '请选择扫码预付或货到付款');
        }
        if ($method === PaymentMethod::OFFLINE_QR && $this->setting->get()['payment_qr_url'] === '') {
            return $this->fail(RejectionCode::VALIDATION_FAILED, '店主尚未上传收款二维码，请选择货到付款');
        }

        $setting = $this->setting->get();
        if ($setting['is_open'] === false) {
            return $this->fail(RejectionCode::SHOP_CLOSED, '商家暂停营业，暂不能新下单');
        }

        $mode = $this->parseDeliveryMode($payload['delivery_mode'] ?? null);
        $timeCheck = $this->checkExpectedAt($mode, $payload['expected_at'] ?? null, (int) $setting['min_lead_minutes']);
        if ($timeCheck !== null) {
            return $timeCheck;
        }

        $deliveryFee = (int) $setting['delivery_fee_fen'];
        $totalFen = $goodsFen + $deliveryFee;
        $remark = mb_substr(trim((string) ($payload['remark'] ?? '')), 0, 200);
        $clientTypeRaw = (string) ($payload['client_type'] ?? 'h5');
        $clientType = in_array($clientTypeRaw, ['h5', 'android', 'wechat'], true) ? $clientTypeRaw : 'h5';

        $p = $this->tablePrefix;
        $now = time();

        $this->pdo->beginTransaction();
        try {
            // ---------- 1. 库存校验与预占（行锁） ----------
            // 必须在事务内、且在写订单之前：并发下单时两个顾客都读到「有货」
            // 是最典型的超卖路径
            $skuIds = array_map(static fn (array $l): string => (string) $l['sku_id'], $lines);
            $in = implode(',', array_map(static fn (string $id): string => (string) (int) $id, $skuIds));

            $this->pdo->query('SELECT `id` FROM `veg_shop_setting` WHERE `id` = 1 FOR UPDATE');
            $currentSetting = $this->setting->get();
            if (!$currentSetting['is_open']) {
                $this->pdo->rollBack();
                return $this->fail(RejectionCode::SHOP_CLOSED, '商家暂停营业，暂不能新下单');
            }
            if ($method === PaymentMethod::OFFLINE_QR && $currentSetting['payment_qr_url'] === '') {
                $this->pdo->rollBack();
                return $this->fail(RejectionCode::VALIDATION_FAILED, '商家尚未上传收款二维码，请选择货到付款');
            }
            $lockStmt = $this->pdo->query(
                "SELECT b.`id`, b.`inventory` FROM `{$p}goods_spec_base` b "
                . "INNER JOIN `{$p}goods` g ON g.`id` = b.`goods_id` "
                . "WHERE b.`id` IN ({$in}) ORDER BY b.`id` FOR UPDATE"
            );
            $stock = [];
            foreach ($lockStmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $stock[(string) $row['id']] = (int) $row['inventory'];
            }

            // Recheck price, sale status and delivery fee after acquiring the locks.
            $priced = $this->priceItems($payload['items'] ?? []);
            if ($priced['code'] !== 'OK') {
                $this->pdo->rollBack();
                return $priced;
            }
            $lines = $priced['data']['lines'];
            $goodsFen = $priced['data']['goods_total_fen'];
            $totalFen = $goodsFen + (int) $currentSetting['delivery_fee_fen'];

            foreach ($lines as $line) {
                $skuId = (string) $line['sku_id'];
                $available = $stock[$skuId] ?? 0;
                if ($available < (int) $line['qty']) {
                    $this->pdo->rollBack();

                    return $this->fail(RejectionCode::OUT_OF_STOCK, sprintf(
                        '「%s」库存不足，仅剩 %d 件',
                        (string) $line['name'],
                        max(0, $available)
                    ), ['sku_id' => $skuId, 'available' => $available]);
                }
            }

            // ---------- 2. 建单 ----------
            // COD 直接进入「待发货」；微信支付进入「待付款」，付款成功后再转待发货
            $isCod = $method === PaymentMethod::COD;
            $status = $isCod ? 2 : 1;
            $orderNo = $this->generateOrderNo();

            $this->pdo->prepare(
                "INSERT INTO `{$p}order` "
                . "(`order_no`, `user_id`, `status`, `pay_status`, `is_under_line`, `price`, `total_price`, "
                . "`pay_price`, `refund_price`, `buy_number_count`, `client_type`, `order_model`, `user_note`, "
                . "`add_time`, `upd_time`) "
                . "VALUES (:order_no, :user_id, :status, 0, :under_line, :price, :total, '0.00', '0.00', "
                . ":buy_count, :client_type, 0, :remark, :add_time, :upd_time)"
            )->execute([
                'order_no' => $orderNo,
                'user_id' => $userId,
                'status' => $status,
                'under_line' => $isCod ? 1 : 0,
                'price' => OrderRowMapper::fenToDecimal($goodsFen),
                'total' => OrderRowMapper::fenToDecimal($totalFen),
                'buy_count' => array_sum(array_map(static fn (array $l): int => (int) $l['qty'], $lines)),
                'client_type' => $clientType,
                'remark' => $remark,
                'add_time' => $now,
                'upd_time' => $now,
            ]);
            $orderId = (int) $this->pdo->lastInsertId();

            // ---------- 3. 明细 + 订单行快照 ----------
            $detailStmt = $this->pdo->prepare(
                "INSERT INTO `{$p}order_detail` "
                . "(`order_id`, `user_id`, `goods_id`, `title`, `images`, `price`, `total_price`, "
                . "`spec`, `buy_number`, `inventory_unit`, `spec_desc`, `add_time`) "
                . "VALUES (:order_id, :user_id, :goods_id, :title, :images, :price, :total, "
                . ":spec, :buy_number, :unit, :spec_desc, :add_time)"
            );
            $lineStmt = $this->pdo->prepare(
                "INSERT INTO `veg_order_line` "
                . "(`order_id`, `order_detail_id`, `sku_id`, `goods_id`, `name_snapshot`, "
                . "`spec_snapshot`, `image_snapshot`, `unit_fen`, `qty`, `created_at`) "
                . "VALUES (:order_id, :detail_id, :sku_id, :goods_id, :name, :spec, :image, :unit_fen, :qty, NOW())"
            );
            $decStmt = $this->pdo->prepare(
                "UPDATE `{$p}goods_spec_base` SET `inventory` = `inventory` - :qty WHERE `id` = :id"
            );

            foreach ($lines as $line) {
                $detailStmt->execute([
                    'order_id' => $orderId,
                    'user_id' => $userId,
                    'goods_id' => (int) $line['product_id'],
                    'title' => (string) $line['name'],
                    'images' => (string) $line['image'],
                    'price' => OrderRowMapper::fenToDecimal((int) $line['unit_fen']),
                    'total' => OrderRowMapper::fenToDecimal((int) $line['unit_fen'] * (int) $line['qty']),
                    'spec' => json_encode([['value' => (string) $line['spec']]], JSON_UNESCAPED_UNICODE),
                    'buy_number' => (int) $line['qty'],
                    'unit' => (string) ($line['inventory_unit'] ?? ''),
                    'spec_desc' => (string) $line['spec'],
                    'add_time' => $now,
                ]);
                $detailId = (int) $this->pdo->lastInsertId();

                $lineStmt->execute([
                    'order_id' => $orderId,
                    'detail_id' => $detailId,
                    'sku_id' => (int) $line['sku_id'],
                    'goods_id' => (int) $line['product_id'],
                    'name' => (string) $line['name'],
                    'spec' => (string) $line['spec'],
                    'image' => (string) $line['image'],
                    'unit_fen' => (int) $line['unit_fen'],
                    'qty' => (int) $line['qty'],
                ]);

                $decStmt->execute(['qty' => (int) $line['qty'], 'id' => (int) $line['sku_id']]);
            }

            // ---------- 4. 收货地址快照 ----------
            // 订单必须固化当时的收货信息：顾客之后改地址不能影响已提交订单，
            // 店主按订单上的地址送货
            $this->pdo->prepare(
                "INSERT INTO `{$p}order_address` "
                . "(`order_id`, `user_id`, `address_id`, `alias`, `name`, `tel`, `province`, `city`, `county`, "
                . "`address`, `province_name`, `city_name`, `county_name`, `add_time`, `upd_time`) "
                . "VALUES (:order_id, :user_id, 0, '', :name, :tel, 0, 0, 0, :address, '', '', '', :add_time, :upd_time)"
            )->execute([
                'order_id' => $orderId,
                'user_id' => $userId,
                'name' => $contact['name'],
                'tel' => $contact['phone'],
                'address' => $contact['address'],
                'add_time' => $now,
                'upd_time' => $now,
            ]);

            // ---------- 5. 四维状态扩展行 ----------
            $this->pdo->prepare(
                "INSERT INTO `veg_order_delivery` "
                . "(`order_id`, `delivery_mode`, `expected_at`, `fulfillment`, `payment_state`, "
                . "`payment_method`, `receipt_state`, `revision_state`, `version`, `created_at`, `updated_at`) "
                . "VALUES (:order_id, :mode, :expected_at, 'PENDING', 'UNPAID', :method, 'UNCONFIRMED', 'NONE', 1, NOW(), NOW())"
            )->execute([
                'order_id' => $orderId,
                'mode' => $mode->value,
                'expected_at' => $mode === DeliveryMode::SCHEDULED
                    ? $this->normalizeExpectedAt((string) $payload['expected_at'])
                    : null,
                'method' => $method->value,
            ]);

            // ---------- 6. 重算商品聚合库存 ----------
            foreach (array_unique(array_map(static fn (array $l): int => (int) $l['product_id'], $lines)) as $goodsId) {
                $this->refreshGoodsInventory((string) $goodsId);
            }

            // ---------- 7. 审计与事件 ----------
            $this->orders->writeAuditLog((string) $userId, (string) $orderId, 'ORDER_CREATED', [
                'actor_type' => 'CUSTOMER',
                'endpoint' => 'customer.order.create',
                'payment_method' => $method->value,
                'total_fen' => $totalFen,
            ]);
            $this->orders->enqueueEvent('ORDER_CREATED', (string) $orderId, [
                'order_id' => (string) $orderId,
                'order_no' => $orderNo,
                'payment_method' => $method->value,
                'total_fen' => $totalFen,
                'summary' => $this->summaryText($lines),
            ]);

            $fresh = $this->orders->find((string) $orderId);

            $this->pdo->commit();

            return $this->ok([
                'order' => $fresh === null ? null : $this->serializeOrder($fresh),
                'pay_params' => null,
                'payment_ready' => false,
                'payment_hint' => $isCod
                    ? '收到商品后再付款。'
                    : '请使用店主收款二维码付款，店主核实实际到账后安排配送。',
            ], $isCod ? '下单成功，货到付款' : '下单成功，请扫码付款并等待店主核实');
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    // =========================================================
    // 订单读写
    // =========================================================

    public function listOrders(int $userId, ?string $status, int $page, int $pageSize, ?string $keyword = null): array
    {
        $page = max(1, $page);
        $pageSize = min(50, max(1, $pageSize));

        $where = ['o.`user_id` = :uid', 'o.`user_is_delete_time` = 0'];
        $params = ['uid' => $userId];

        // status 是**展示口径**的筛选（ALL/PENDING/DELIVERING/DELIVERED/UNPAID/CANCELLED），
        // 与四维状态一一映射，不在客户端做拼装
        switch ($status) {
            case 'UNPAID':
                $where[] = "d.`payment_state` = 'UNPAID' AND d.`fulfillment` = 'PENDING'";
                break;
            case 'PENDING':
                $where[] = "d.`fulfillment` = 'PENDING' AND NOT (d.`payment_state` = 'UNPAID' AND d.`payment_method` IN ('WECHAT','OFFLINE_QR'))";
                break;
            case 'DELIVERING':
                $where[] = "d.`fulfillment` = 'DELIVERING'";
                break;
            case 'DELIVERED':
                $where[] = "d.`fulfillment` = 'DELIVERED'";
                break;
            case 'FINISHED':
                $where[] = "d.`fulfillment` = 'FINISHED'";
                break;
            case 'CANCELLED':
                $where[] = "d.`fulfillment` = 'CANCELLED'";
                break;
            default:
                break;
        }

        $p = $this->tablePrefix;
        $search = CustomerOrderListFilter::build($keyword, $p);
        if ($search['where_sql'] !== '') {
            $where[] = $search['where_sql'];
            $params = array_merge($params, $search['params']);
        }
        $whereSql = implode(' AND ', $where);

        $countStmt = $this->pdo->prepare(
            "SELECT COUNT(*) AS c FROM `{$p}order` o "
            . "INNER JOIN `veg_order_delivery` d ON d.`order_id` = o.`id` "
            . "WHERE {$whereSql}"
        );
        $countStmt->execute($params);
        $total = (int) ($countStmt->fetch(\PDO::FETCH_ASSOC)['c'] ?? 0);

        $offset = ($page - 1) * $pageSize;
        $stmt = $this->pdo->prepare(
            "SELECT o.`id` FROM `{$p}order` o "
            . "INNER JOIN `veg_order_delivery` d ON d.`order_id` = o.`id` "
            . "WHERE {$whereSql} ORDER BY o.`id` DESC LIMIT {$pageSize} OFFSET {$offset}"
        );
        $stmt->execute($params);

        $list = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $order = $this->orders->find((string) $row['id']);
            if ($order !== null) {
                $list[] = $this->serializeOrder($order);
            }
        }

        return $this->ok([
            'list' => $list,
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
            'has_more' => $offset + count($list) < $total,
        ]);
    }

    public function orderDetail(int $userId, string $orderId): array
    {
        $order = $this->orders->find($orderId);
        if ($order === null) {
            return $this->fail(RejectionCode::NOT_FOUND, '订单不存在');
        }

        if (!$this->belongsTo($orderId, $userId)) {
            return $this->fail(RejectionCode::FORBIDDEN, '无权访问该订单');
        }

        return $this->ok($this->serializeOrder($order));
    }

    /** 取消订单（顾客自助，仅未配送）。 */
    public function cancel(int $userId, string $orderId, int $clientVersion, ?string $reason, string $idemKey): array
    {
        if (!$this->belongsTo($orderId, $userId)) {
            return $this->fail(RejectionCode::NOT_FOUND, '订单不存在');
        }

        return $this->transition(
            $orderId,
            (string) $userId,
            'customer.order.cancel',
            $idemKey,
            ['version' => $clientVersion, 'reason' => $reason],
            function (Order $order) use ($clientVersion, $reason): array {
                $result = $this->stateMachine->cancel($order, $clientVersion);
                if (!$result->success) {
                    return $this->rejected($result);
                }

                $refundFen = (int) $result->get('refund_fen', 0);

                $envelope = $this->applied(
                    $result,
                    'ORDER_CANCELLED',
                    'ORDER_CANCELLED',
                    $refundFen > 0
                        ? sprintf($order->paymentMethod === PaymentMethod::OFFLINE_QR
                            ? '订单已取消，待商家线下退还 %s 元并核实'
                            : '订单已取消，将原路退还 %s 元', number_format($refundFen / 100, 2, '.', ''))
                        : '订单已取消'
                );

                if ($refundFen > 0) {
                    $envelope['extra_outbox'] = [$order->paymentMethod === PaymentMethod::OFFLINE_QR
                        ? 'REFUND_NEEDS_ATTENTION' : 'REFUND_QUERY', ['refund_fen' => $refundFen, 'reason' => $reason]];
                }

                return $envelope;
            }
        );
    }

    /** 顾客确认收货。 */
    public function receive(int $userId, string $orderId, int $clientVersion, string $idemKey): array
    {
        if (!$this->belongsTo($orderId, $userId)) {
            return $this->fail(RejectionCode::NOT_FOUND, '订单不存在');
        }

        return $this->transition(
            $orderId,
            (string) $userId,
            'customer.order.receive',
            $idemKey,
            ['version' => $clientVersion],
            function (Order $order) use ($clientVersion): array {
                $result = $this->stateMachine->confirmReceipt($order, $clientVersion);
                if (!$result->success) {
                    return $this->rejected($result);
                }

                return $this->applied($result, 'ORDER_RECEIVED', 'ORDER_RECEIVED', '已确认收货');
            }
        );
    }

    /**
     * 未付款订单切换支付方式（目前只支持切到 COD）。
     *
     * 【为什么需要它】
     * 当前服务器未配置微信支付商户号，在线支付实际上不可用。
     * 若顾客选了微信支付又无法付款，订单会永久卡在「待付款」，
     * 顾客只能取消重下 —— 体验很差，且取消已付单还要走退款。
     * 这里给一条明确的出路：未付款时把支付方式改成货到付款，订单立即生效。
     */
    public function switchToCod(int $userId, string $orderId, int $clientVersion, string $idemKey): array
    {
        if (!$this->belongsTo($orderId, $userId)) {
            return $this->fail(RejectionCode::NOT_FOUND, '订单不存在');
        }

        return $this->transition(
            $orderId,
            (string) $userId,
            'customer.order.switch-to-cod',
            $idemKey,
            ['version' => $clientVersion],
            function (Order $order) use ($clientVersion): array {
                if ($order->version !== $clientVersion) {
                    return $this->rejectedWith(RejectionCode::ORDER_VERSION_CONFLICT, '订单已发生变化，请刷新后重试');
                }
                if ($order->fulfillment !== OrderFulfillment::PENDING) {
                    return $this->rejectedWith(RejectionCode::ORDER_NOT_EDITABLE, '订单已开始配送，无法修改支付方式');
                }
                if ($order->paymentMethod === PaymentMethod::COD) {
                    return $this->rejectedWith(RejectionCode::VALIDATION_FAILED, '该订单已是货到付款');
                }
                if ($order->paidFen > 0) {
                    return $this->rejectedWith(RejectionCode::VALIDATION_FAILED, '订单已有支付记录，无法切换支付方式');
                }

                $result = TransitionResult::ok([
                    'payment_method' => PaymentMethod::COD,
                    'version' => $order->version + 1,
                ]);

                return $this->applied($result, 'ORDER_PAYMENT_SWITCHED', 'ORDER_PAYMENT_SWITCHED', '已改为货到付款');
            }
        );
    }

    /** 退款进度（只读）。 */
    public function refunds(int $userId, string $orderId): array
    {
        if (!$this->belongsTo($orderId, $userId)) {
            return $this->fail(RejectionCode::NOT_FOUND, '订单不存在');
        }

        $rows = $this->orders->ledgerEntries($orderId);
        $list = [];
        foreach ($rows as $r) {
            $kind = (string) ($r['kind'] ?? '');
            if (!str_contains($kind, 'REFUND')) {
                continue;
            }
            $list[] = [
                'refund_no' => (string) ($r['business_no'] ?? ''),
                'amount_fen' => abs((int) ($r['amount_fen'] ?? 0)),
                'status' => (string) ($r['status'] ?? ''),
                'created_at' => (string) ($r['occurred_at'] ?? ''),
            ];
        }

        return $this->ok(['list' => $list, 'total' => count($list)]);
    }

    /**
     * 修改收货信息 / 期望送达时间 / 备注（不涉及金额）。
     *
     * 【为什么这条路径不走修订引擎】
     * 修订引擎存在的唯一理由是「金额变化需要补款或退款」。
     * 改地址、改时间、改备注都不改变一分钱，走修订会平白产生
     * 一条 `delta_fen = 0` 的修订记录与一次版本推进，
     * 让商家端频繁看到「顾客修改了订单」的告警 —— 反而淹没了真正重要的
     * 「顾客改了商品，需要重新备货」。
     *
     * 因此：只改信息 → 本方法（直接更新，仍需版本号与未配送校验）；
     *       改商品     → 修订引擎（RevisionService）。
     */
    public function updateInfo(int $userId, string $orderId, array $payload): array
    {
        if (!$this->belongsTo($orderId, $userId)) {
            return $this->fail(RejectionCode::NOT_FOUND, '订单不存在');
        }

        $order = $this->orders->find($orderId);
        if ($order === null) {
            return $this->fail(RejectionCode::NOT_FOUND, '订单不存在');
        }

        $clientVersion = (int) ($payload['version'] ?? 0);
        if ($order->version !== $clientVersion) {
            return $this->fail(RejectionCode::ORDER_VERSION_CONFLICT, '订单已发生变化，请刷新后重试', [
                'current_version' => $order->version,
            ]);
        }
        if (!$order->fulfillment->allowsCustomerSelfService()) {
            return $this->fail(RejectionCode::ORDER_NOT_EDITABLE, '商家已开始配送，当前订单不能再自行修改');
        }

        $p = $this->tablePrefix;

        $this->pdo->beginTransaction();
        try {
            $order = $this->orders->findForUpdate($orderId, (string) $userId);
            if ($order === null || $order->version !== $clientVersion) {
                $this->pdo->rollBack();
                return $this->fail(RejectionCode::ORDER_VERSION_CONFLICT, '订单已发生变化，请刷新后重试');
            }
            if (!$order->fulfillment->allowsCustomerSelfService()) {
                $this->pdo->rollBack();
                return $this->fail(RejectionCode::ORDER_NOT_EDITABLE, '商家已开始配送，当前订单不能再自行修改');
            }
            if (isset($payload['contact'])) {
                $contact = $this->parseContact($payload['contact']);
                if ($contact === null) {
                    $this->pdo->rollBack();

                    return $this->fail(RejectionCode::VALIDATION_FAILED, '请填写收货人、手机号与详细地址');
                }

                $this->pdo->prepare(
                    "UPDATE `{$p}order_address` SET `name` = :name, `tel` = :tel, `address` = :address, "
                    . "`upd_time` = :t WHERE `order_id` = :oid"
                )->execute([
                    'name' => $contact['name'],
                    'tel' => $contact['phone'],
                    'address' => $contact['address'],
                    't' => time(),
                    'oid' => $orderId,
                ]);
            }

            if (array_key_exists('remark', $payload)) {
                $this->pdo->prepare(
                    "UPDATE `{$p}order` SET `user_note` = :note, `upd_time` = :t WHERE `id` = :oid"
                )->execute([
                    'note' => mb_substr(trim((string) $payload['remark']), 0, 200),
                    't' => time(),
                    'oid' => $orderId,
                ]);
            }

            $mode = $order->deliveryMode;
            $expectedAt = $order->expectedAt?->format(DATE_ATOM);
            if (array_key_exists('delivery_mode', $payload) || array_key_exists('expected_at', $payload)) {
                $setting = $this->setting->get();
                $mode = $this->parseDeliveryMode($payload['delivery_mode'] ?? $order->deliveryMode->value);
                $expectedRaw = $payload['expected_at'] ?? $expectedAt;
                $timeCheck = $this->checkExpectedAt($mode, $expectedRaw, (int) $setting['min_lead_minutes']);
                if ($timeCheck !== null) {
                    $this->pdo->rollBack();

                    return $timeCheck;
                }
                $expectedAt = $mode === DeliveryMode::SCHEDULED ? (string) $expectedRaw : null;
            }

            // 版本推进：商家端靠 version 判断「我看到的这单是不是最新的」，
            // 收货信息变了也必须推进，否则店主按旧地址送货
            $versionUpdate = $this->pdo->prepare(
                "UPDATE `veg_order_delivery` SET `delivery_mode` = :mode, `expected_at` = :expected_at, "
                . "`version` = `version` + 1, `updated_at` = NOW() WHERE `order_id` = :oid AND `version` = :v"
            );
            $versionUpdate->execute([
                'mode' => $mode->value,
                'expected_at' => $mode === DeliveryMode::SCHEDULED && $expectedAt !== null
                    ? $this->normalizeExpectedAt($expectedAt)
                    : null,
                'oid' => $orderId,
                'v' => $clientVersion,
            ]);
            if ($versionUpdate->rowCount() !== 1) {
                $this->pdo->rollBack();
                return $this->fail(RejectionCode::ORDER_VERSION_CONFLICT, '订单已发生变化，请刷新后重试');
            }

            $this->orders->writeAuditLog((string) $userId, $orderId, 'ORDER_INFO_UPDATED', [
                'actor_type' => 'CUSTOMER',
                'endpoint' => 'customer.order.update-info',
                'fields' => array_keys($payload),
            ]);
            $this->orders->enqueueEvent('ORDER_INFO_UPDATED', $orderId, [
                'order_id' => $orderId, 'version' => $clientVersion + 1,
            ]);

            $fresh = $this->orders->find($orderId);
            $this->pdo->commit();

            return $this->ok(
                $fresh === null ? null : $this->serializeOrder($fresh),
                '订单信息已更新'
            );
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    // =========================================================
    // 内部：定价 / 校验
    // =========================================================

    /**
     * 按 SKU 现价计算明细。
     *
     * @param mixed $rawItems
     */
    private function priceItems(mixed $rawItems): array
    {
        if (!is_array($rawItems) || $rawItems === []) {
            return $this->fail(RejectionCode::VALIDATION_FAILED, '请先选择商品');
        }

        // 合并重复 SKU：客户端可能因为连续点「+」发出同 SKU 的多条明细
        $merged = [];
        foreach ($rawItems as $it) {
            if (!is_array($it)) {
                continue;
            }
            $skuId = (string) ($it['sku_id'] ?? '');
            $qty = (int) ($it['qty'] ?? 0);
            if (preg_match('/^\d+$/', $skuId) !== 1 || $qty <= 0) {
                continue;
            }
            $merged[$skuId] = ($merged[$skuId] ?? 0) + $qty;
        }

        if ($merged === []) {
            return $this->fail(RejectionCode::VALIDATION_FAILED, '商品明细不合法');
        }
        if (count($merged) > 50) {
            return $this->fail(RejectionCode::VALIDATION_FAILED, '单次下单商品种类过多');
        }

        $skus = $this->catalog->skuMap(array_keys($merged));
        $lines = [];
        $total = 0;

        foreach ($merged as $skuId => $qty) {
            $sku = $skus[$skuId] ?? null;
            if ($sku === null) {
                return $this->fail(RejectionCode::NOT_FOUND, '部分商品已不存在，请返回购物车重新选择');
            }
            if ($sku['is_deleted'] || !$sku['is_shelves']) {
                return $this->fail(RejectionCode::NOT_FOUND, sprintf('「%s」已下架，请移除后重试', $sku['title']));
            }
            if ($qty > 99) {
                return $this->fail(RejectionCode::VALIDATION_FAILED, sprintf('「%s」单次最多购买 99 份', $sku['title']));
            }
            if ($sku['inventory'] < $qty) {
                return $this->fail(RejectionCode::OUT_OF_STOCK, sprintf(
                    '「%s」库存不足，仅剩 %d 份',
                    $sku['title'],
                    max(0, $sku['inventory'])
                ), ['sku_id' => $skuId, 'available' => $sku['inventory']]);
            }

            $subtotal = $sku['unit_fen'] * $qty;
            $total += $subtotal;

            $lines[] = [
                'sku_id' => (string) $skuId,
                'product_id' => $sku['goods_id'],
                'name' => $sku['title'],
                'spec' => $sku['spec_text'],
                'image' => $sku['image'],
                'unit_fen' => $sku['unit_fen'],
                'qty' => $qty,
                'subtotal_fen' => $subtotal,
                'inventory_unit' => $sku['inventory_unit'],
            ];
        }

        return $this->ok(['lines' => $lines, 'goods_total_fen' => $total]);
    }

    private function parseDeliveryMode(mixed $raw): DeliveryMode
    {
        $value = strtoupper((string) $raw);

        return DeliveryMode::tryFrom($value) ?? DeliveryMode::ASAP;
    }

    /**
     * 校验预约时间。
     *
     * 返回 null 表示通过；否则返回可直接作为响应的失败信封。
     */
    private function checkExpectedAt(DeliveryMode $mode, mixed $raw, int $minLeadMinutes): ?array
    {
        if ($mode !== DeliveryMode::SCHEDULED) {
            return null;
        }

        $text = trim((string) $raw);
        if ($text === '') {
            return $this->fail(RejectionCode::INVALID_EXPECTED_TIME, '请选择希望送达的时间');
        }

        try {
            $expected = new \DateTimeImmutable($text);
        } catch (\Throwable) {
            return $this->fail(RejectionCode::INVALID_EXPECTED_TIME, '送达时间格式不正确');
        }

        $now = new \DateTimeImmutable();
        $earliest = $now->modify('+' . max(0, $minLeadMinutes) . ' minutes');

        if ($expected <= $earliest) {
            return $this->fail(
                RejectionCode::INVALID_EXPECTED_TIME,
                sprintf('预约时间需晚于当前时间至少 %d 分钟', max(0, $minLeadMinutes))
            );
        }
        if ($expected > $now->modify('+7 days')) {
            return $this->fail(RejectionCode::INVALID_EXPECTED_TIME, '最多支持预约 7 天内的送达时间');
        }

        return null;
    }

    /**
     * 把客户端传来的 ISO8601 时间归一化成 MySQL 的 datetime。
     *
     * 客户端可能传 `2026-10-13T17:30:00+08:00`，MySQL 的 datetime 不接受
     * 带时区偏移的写法（严格模式下会直接报错）。
     * 服务器经营时区是 Asia/Shanghai，因此按该时区落到本地时间。
     */
    private function normalizeExpectedAt(string $raw): string
    {
        try {
            $dt = new \DateTimeImmutable($raw);
        } catch (\Throwable) {
            return date('Y-m-d H:i:s', strtotime('+1 hour') ?: time());
        }

        return $dt->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
    }

    /** @return array{name:string,phone:string,address:string}|null */
    private function parseContact(mixed $raw): ?array
    {
        if (!is_array($raw)) {
            return null;
        }

        $name = trim((string) ($raw['name'] ?? ''));
        $phone = trim((string) ($raw['phone'] ?? ''));
        $address = trim((string) ($raw['address'] ?? ''));

        if ($name === '' || mb_strlen($address) < 4) {
            return null;
        }
        if (preg_match('/^1[3-9]\d{9}$/', $phone) !== 1) {
            return null;
        }

        return [
            'name' => mb_substr($name, 0, 30),
            'phone' => $phone,
            'address' => mb_substr($address, 0, 200),
        ];
    }

    private function belongsTo(string $orderId, int $userId): bool
    {
        if (preg_match('/^\d+$/', $orderId) !== 1) {
            return false;
        }

        $p = $this->tablePrefix;
        $stmt = $this->pdo->prepare("SELECT `id` FROM `{$p}order` WHERE `id` = :id AND `user_id` = :uid LIMIT 1");
        $stmt->execute(['id' => $orderId, 'uid' => $userId]);

        return $stmt->fetch(\PDO::FETCH_ASSOC) !== false;
    }

    /** @param array<int,array<string,mixed>> $lines */
    private function summaryText(array $lines): string
    {
        $parts = [];
        foreach (array_slice($lines, 0, 3) as $l) {
            $parts[] = sprintf('%s×%d', (string) $l['name'], (int) $l['qty']);
        }
        $text = implode(' / ', $parts);
        if (count($lines) > 3) {
            $text .= ' 等 ' . count($lines) . ' 种';
        }

        return mb_substr($text, 0, 120);
    }

    private function generateOrderNo(): string
    {
        // 与 ShopXO 的观感一致：时间戳 + 随机尾数；
        // 唯一性由 order_no 的唯一索引兜底（极端碰撞时插入会失败而不是静默覆盖）
        return date('YmdHis') . str_pad((string) random_int(0, 99999), 5, '0', STR_PAD_LEFT);
    }

    /** 与 CatalogService::refreshGoodsAggregates 同一口径：SKU 变了就要重算商品聚合。 */
    private function refreshGoodsInventory(string $goodsId): void
    {
        $p = $this->tablePrefix;

        $stmt = $this->pdo->prepare(
            "SELECT MIN(`price`) AS min_p, MAX(`price`) AS max_p, COALESCE(SUM(`inventory`), 0) AS total_inv "
            . "FROM `{$p}goods_spec_base` WHERE `goods_id` = :gid"
        );
        $stmt->execute(['gid' => $goodsId]);
        $agg = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];

        if ($agg === [] || $agg['min_p'] === null) {
            return;
        }

        $minPrice = (string) $agg['min_p'];
        $maxPrice = (string) $agg['max_p'];

        $this->pdo->prepare(
            "UPDATE `{$p}goods` SET `price` = :price, `min_price` = :min_p, `max_price` = :max_p, "
            . "`inventory` = :inv, `upd_time` = UNIX_TIMESTAMP() WHERE `id` = :id"
        )->execute([
            'price' => $minPrice === $maxPrice ? $minPrice : $minPrice . '-' . $maxPrice,
            'min_p' => $minPrice,
            'max_p' => $maxPrice,
            'inv' => (int) $agg['total_inv'],
            'id' => $goodsId,
        ]);
    }

    // =========================================================
    // 内部：状态迁移流水线（与商家端同构，但 actor_type = CUSTOMER）
    // =========================================================

    /**
     * @param array<string,mixed> $requestPayload
     */
    private function transition(
        string $orderId,
        string $actorId,
        string $endpoint,
        string $idemKey,
        array $requestPayload,
        callable $handler,
    ): array {
        try {
            $outer = $this->idempotency->run(
                $actorId,
                $endpoint,
                $idemKey,
                $requestPayload,
                fn (): array => $this->transitionTransactional($orderId, $actorId, $endpoint, $handler)
            );
        } catch (VersionConflictException $e) {
            return $this->fail(
                RejectionCode::ORDER_VERSION_CONFLICT,
                $e->getMessage(),
                ['current_version' => $e->actualVersion]
            );
        }

        if (($outer['code'] ?? '') !== 'OK') {
            return [
                'code' => (string) $outer['code'],
                'message' => (string) ($outer['data']['message'] ?? RejectionCode::IDEMPOTENCY_CONFLICT->defaultMessage()),
                'data' => null,
            ];
        }

        $inner = $outer['data'];
        if (is_array($inner) && isset($inner['code'])) {
            return $inner;
        }

        return $this->ok($inner);
    }

    private function transitionTransactional(string $orderId, string $actorId, string $endpoint, callable $handler): array
    {
        $this->pdo->beginTransaction();

        try {
            $order = $this->orders->findForUpdate($orderId, $actorId);
            if ($order === null) {
                $this->pdo->commit();

                return $this->fail(RejectionCode::NOT_FOUND, '订单不存在');
            }

            $outcome = $handler($order);

            // 拒绝也要提交：幂等键必须记住「这个请求已被处理并拒绝」，
            // 否则客户端重试会重新执行一次
            if (($outcome['rejected'] ?? false) === true) {
                $this->pdo->commit();

                return $outcome['envelope'];
            }

            /** @var TransitionResult $transition */
            $transition = $outcome['transition'];
            $this->orders->applyTransition($order, $transition);

            $this->orders->writeAuditLog($actorId, $orderId, (string) $outcome['audit_action'], [
                'actor_type' => 'CUSTOMER',
                'endpoint' => $endpoint,
                'message' => $outcome['message'],
                'from_version' => $order->version,
                'to_version' => $order->version + 1,
            ]);

            $this->orders->enqueueEvent((string) $outcome['outbox'], $orderId, [
                'order_id' => $orderId,
                'message' => $outcome['message'],
            ]);
            if (!empty($outcome['extra_outbox'])) {
                [$type, $payload] = $outcome['extra_outbox'];
                $this->orders->enqueueEvent((string) $type, $orderId, $payload);
            }

            if ($transition->get('cancel_revision') === true) {
                $this->cancelPendingRevision($order);
            }
            $refundFen = (int) $transition->get('refund_fen', 0);
            if ($refundFen > 0 && $order->paymentMethod === PaymentMethod::OFFLINE_QR) {
                $this->pdo->prepare(
                    "INSERT INTO `veg_payment_ledger` (`order_id`, `kind`, `amount_fen`, `status`, "
                    . "`business_no`, `idempotency_key`, `occurred_at`, `created_at`, `updated_at`) "
                    . "VALUES (:oid, 'OFFLINE_REFUND', :amount, 'PROCESSING', :business, :idem, NOW(), NOW(), NOW())"
                )->execute(['oid' => $orderId, 'amount' => -$refundFen,
                    'business' => 'QR-CANCEL-' . $orderId, 'idem' => 'qr-cancel-refund-' . $orderId]);
            }

            // 取消订单要释放库存：不释放就等于「取消一次少一份可卖」
            if (($transition->get('release_stock') ?? false) === true) {
                $this->releaseStock($order);
            }

            $fresh = $this->orders->find($orderId);

            $this->pdo->commit();

            return $this->ok(
                $fresh === null ? null : $this->serializeOrder($fresh),
                $outcome['message']
            );
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    private function releaseStock(Order $order): void
    {
        $p = $this->tablePrefix;
        $stmt = $this->pdo->prepare(
            "UPDATE `{$p}goods_spec_base` SET `inventory` = `inventory` + :qty WHERE `id` = :id"
        );

        $goodsIds = [];
        foreach ($order->lines as $line) {
            if (preg_match('/^\d+$/', $line->skuId) !== 1) {
                continue;
            }
            $stmt->execute(['qty' => $line->qty, 'id' => (int) $line->skuId]);
            $goodsIds[$line->productId] = true;
        }

        foreach (array_keys($goodsIds) as $goodsId) {
            if (preg_match('/^\d+$/', (string) $goodsId) === 1) {
                $this->refreshGoodsInventory((string) $goodsId);
            }
        }
    }

    /** Undo only extra reservations; the effective old lines are released separately. */
    private function cancelPendingRevision(Order $order): void
    {
        $stmt = $this->pdo->prepare("SELECT `id`, `before_json`, `after_json` FROM `veg_order_revision` "
            . "WHERE `order_id` = :oid AND `status` = 'PENDING_TOPUP' FOR UPDATE");
        $stmt->execute(['oid' => $order->id]);
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $revision) {
            $before = []; $after = [];
            foreach ((array) json_decode($revision['before_json'], true) as $line) {
                $before[(string) $line['sku_id']] = ($before[(string) $line['sku_id']] ?? 0) + (int) $line['qty'];
            }
            foreach ((array) json_decode($revision['after_json'], true) as $line) {
                $after[(string) $line['sku_id']] = ($after[(string) $line['sku_id']] ?? 0) + (int) $line['qty'];
            }
            foreach ($after as $sku => $qty) {
                $extra = $qty - ($before[$sku] ?? 0);
                if ($extra <= 0) continue;
                $p = $this->tablePrefix;
                $this->pdo->prepare("UPDATE `{$p}goods_spec_base` SET `inventory` = `inventory` + :qty WHERE `id` = :id")
                    ->execute(['qty' => $extra, 'id' => $sku]);
                $goods = $this->pdo->prepare("SELECT `goods_id` FROM `{$p}goods_spec_base` WHERE `id` = :id");
                $goods->execute(['id' => $sku]);
                $goodsId = $goods->fetchColumn();
                if ($goodsId !== false) $this->refreshGoodsInventory((string) $goodsId);
            }
            $this->pdo->prepare("UPDATE `veg_order_revision` SET `status` = 'FAILED', `reason` = '订单取消，撤销待补款修订', `updated_at` = NOW() WHERE `id` = :id")
                ->execute(['id' => $revision['id']]);
        }
        $this->pdo->prepare("UPDATE `veg_order_delivery` SET `revision_state` = 'NONE' WHERE `order_id` = :oid")
            ->execute(['oid' => $order->id]);
    }

    // =========================================================
    // 序列化
    // =========================================================

    /**
     * 顾客视角的订单 DTO。
     *
     * 与商家端的差别：顾客看的是**自己的**订单，因此姓名与手机号不脱敏
     * （他自己填的，脱敏反而妨碍他核对自己有没有填错）。
     * 但订单列表里仍然只给手机号尾号，避免顾客在公共场合被人看到。
     */
    private function serializeOrder(Order $order): array
    {
        $canSelfService = $order->fulfillment->allowsCustomerSelfService();
        $pending = $this->pdo->prepare("SELECT `delta_fen` FROM `veg_order_revision` WHERE `order_id` = :oid AND `status` = 'PENDING_TOPUP' ORDER BY `id` DESC LIMIT 1");
        $pending->execute(['oid' => $order->id]);
        $pendingFen = (int) ($pending->fetchColumn() ?: 0);

        return [
            'id' => $order->id,
            'order_no' => $order->orderNo,
            'version' => $order->version,
            'fulfillment' => $order->fulfillment->value,
            'fulfillment_text' => $this->fulfillmentText($order->fulfillment),
            'payment_state' => $order->paymentState->value,
            'payment_state_text' => $this->paymentStateText($order->paymentState),
            'receipt_state' => $order->receiptState->value,
            'revision_state' => $order->revisionState->value,
            'pending_topup_fen' => $pendingFen,
            'payment_method' => $order->paymentMethod->value,
            'payment_method_text' => match ($order->paymentMethod) { PaymentMethod::COD => '货到付款（扫码/现金）', PaymentMethod::OFFLINE_QR => '扫码预付（店主核实）', default => '微信支付' },
            'delivery_mode' => $order->deliveryMode->value,
            'expected_at' => $order->expectedAt?->format(DATE_ATOM),
            'lines' => array_map(static fn ($line): array => [
                'sku_id' => $line->skuId,
                'product_id' => $line->productId,
                'name' => $line->nameSnapshot,
                'spec' => $line->specSnapshot,
                'image_url' => $line->imageSnapshot,
                'unit_fen' => $line->unitFen,
                'qty' => $line->qty,
                'subtotal_fen' => $line->subtotal()->fen(),
            ], $order->lines),
            'contact' => [
                'name' => $order->contact->name,
                'phone' => $order->contact->phone,
                'address' => $order->contact->address,
            ],
            'goods_total_fen' => $order->goodsTotal()->fen(),
            'delivery_fee_fen' => $order->deliveryFeeFen,
            'total_fen' => $order->total()->fen(),
            'paid_fen' => $order->paidFen,
            'refunded_fen' => $order->refundedFen,
            'refunding_fen' => $order->refundingFen,
            'receivable_fen' => max(0, $order->receivable()->fen()),
            'refundable_fen' => $order->refundable()->fen(),
            'delivered_at' => $order->deliveredAt?->format(DATE_ATOM),
            'received_at' => $order->receivedAt?->format(DATE_ATOM),
            'remark' => $order->remark,
            // 顾客能不能改/取消由**服务端**裁决，客户端只负责显示 ——
            // 否则一个过期页面就能绕过「已开始配送不可改」
            'can_edit' => $canSelfService && $order->revisionState->allowsNewRevision(),
            'can_cancel' => $canSelfService && $order->fulfillment !== OrderFulfillment::CANCELLED,
            'can_receive' => $order->receiptState->canConfirm($order->fulfillment),
            'can_switch_to_cod' => $canSelfService
                && $order->paymentMethod !== PaymentMethod::COD
                && $order->paidFen === 0,
        ];
    }

    private function fulfillmentText(OrderFulfillment $f): string
    {
        return match ($f) {
            OrderFulfillment::PENDING => '待配送',
            OrderFulfillment::DELIVERING => '配送中',
            OrderFulfillment::DELIVERED => '已送达',
            OrderFulfillment::FINISHED => '已完成',
            OrderFulfillment::CANCELLED => '已取消',
        };
    }

    private function paymentStateText(OrderPaymentState $s): string
    {
        return match ($s) {
            OrderPaymentState::UNPAID => '未付款',
            OrderPaymentState::PAYING => '支付中',
            OrderPaymentState::PAID => '已付款',
            OrderPaymentState::PARTIAL_REFUNDED => '部分退款',
            OrderPaymentState::REFUNDING => '退款处理中',
            OrderPaymentState::REFUNDED => '已退款',
        };
    }

    // =========================================================
    // 信封
    // =========================================================

    private function ok(mixed $data, ?string $message = null): array
    {
        return ['code' => 'OK', 'message' => $message, 'data' => $data];
    }

    private function fail(RejectionCode $code, string $message, mixed $data = null): array
    {
        return ['code' => $code->value, 'message' => $message, 'data' => $data];
    }

    private function applied(TransitionResult $result, string $auditAction, string $outbox, string $message): array
    {
        return [
            'rejected' => false,
            'transition' => $result,
            'audit_action' => $auditAction,
            'outbox' => $outbox,
            'message' => $message,
            'ledger' => null,
            'extra_outbox' => null,
        ];
    }

    private function rejected(TransitionResult $result): array
    {
        return [
            'rejected' => true,
            'envelope' => [
                'code' => $result->code?->value ?? RejectionCode::VALIDATION_FAILED->value,
                'message' => $result->message ?? '操作被拒绝',
                'data' => $result->data,
            ],
        ];
    }

    private function rejectedWith(RejectionCode $code, string $message): array
    {
        return [
            'rejected' => true,
            'envelope' => ['code' => $code->value, 'message' => $message, 'data' => null],
        ];
    }
}
