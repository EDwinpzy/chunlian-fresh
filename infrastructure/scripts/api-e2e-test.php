<?php

declare(strict_types=1);

/**
 * 春连鲜选 · 商家端 API 端到端测试
 *
 * 在真实 ShopXO 库上走完整流程：
 *   建测试订单 → 登录 → 开始配送 → 确认送达（COD 收款）→ 重复请求验幂等
 *   → 版本冲突验证 → 应收款清单 → 当日汇总
 *
 * 【为什么要写这个脚本而不是只做单测】
 * 单测覆盖了状态机与映射逻辑，但覆盖不到：
 *   - ShopXO 真实表结构的字段名（实际踩过 `tel` vs `phone`、区域 ID vs 名称）
 *   - 插件分发链路（路由 → 鉴权 → 控制器 → 服务 → 仓储）
 *   - 幂等键在真实唯一约束下的行为
 *   - 乐观锁 CAS 在真实 UPDATE 下的行数判定
 * 这些只有打真实接口才能发现。
 *
 * 用法：php api-e2e-test.php [--keep]
 *   默认跑完清理测试数据；--keep 保留以便人工查看
 */

$shopxoRoot = '/var/www/chunlian/shopxo';
require_once $shopxoRoot . '/app/plugins/chunlianfresh/Bootstrap.php';

use Chunlian\Repository\OrderRowMapper;

use function app\plugins\chunlianfresh\pdo;
use function app\plugins\chunlianfresh\table_prefix;

$keep = in_array('--keep', $argv, true);
$base = 'http://127.0.0.1:8095/api/v1/merchant';

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  ✓ {$label}\n";
    } else {
        $fail++;
        echo "  ✗ {$label}", $detail !== '' ? "  → {$detail}" : '', "\n";
    }
}

/** @return array{status:int, body:array} */
function http(string $method, string $url, array $body = [], ?string $token = null, ?string $idemKey = null): array
{
    $headers = ['Content-Type: application/json'];
    if ($token !== null) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    if ($idemKey !== null) {
        $headers[] = 'Idempotency-Key: ' . $idemKey;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    if ($body !== [] && $method !== 'GET') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
    }

    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['status' => $status, 'body' => is_string($raw) ? (json_decode($raw, true) ?: []) : []];
}

$pdo = pdo();
$prefix = table_prefix();

echo "春连鲜选 · 商家端 API 端到端测试\n", str_repeat('=', 60), "\n\n";

// ------------------------------------------------------------
// 准备：建一张测试订单
// ------------------------------------------------------------
echo "[准备] 创建测试订单\n";

$marker = 'E2E' . date('His');
$now = time();

$pdo->prepare(
    "INSERT INTO `{$prefix}order` (`order_no`, `user_id`, `status`, `pay_status`, `is_under_line`, "
    . "`price`, `total_price`, `pay_price`, `refund_price`, `buy_number_count`, `add_time`, `upd_time`) "
    . "VALUES (:no, 1, 2, 0, 1, '9.80', '9.80', '0.00', '0.00', 3, UNIX_TIMESTAMP(), UNIX_TIMESTAMP())"
)->execute(['no' => $marker]);
$orderId = (int) $pdo->lastInsertId();

$pdo->prepare(
    "INSERT INTO `{$prefix}order_address` (`order_id`, `user_id`, `name`, `tel`, `province`, `city`, `county`, "
    . "`province_name`, `city_name`, `county_name`, `address`, `add_time`) "
    . "VALUES (:oid, 1, '张先生', '13800138000', 11, 3301, 330106, '浙江省', '杭州市', '西湖区', '阳光小区3栋502室', UNIX_TIMESTAMP())"
)->execute(['oid' => $orderId]);

$pdo->prepare(
    "INSERT INTO `{$prefix}order_detail` (`order_id`, `user_id`, `goods_id`, `title`, `images`, `price`, "
    . "`total_price`, `spec`, `buy_number`, `spec_desc`, `add_time`) "
    . "VALUES (:oid, 1, 1, '小青菜', 'a.jpg', '3.50', '7.00', :spec, 2, '500g/份', UNIX_TIMESTAMP())"
)->execute(['oid' => $orderId, 'spec' => json_encode([['value' => '500g/份']], JSON_UNESCAPED_UNICODE)]);

$pdo->prepare(
    "INSERT INTO `{$prefix}order_detail` (`order_id`, `user_id`, `goods_id`, `title`, `images`, `price`, "
    . "`total_price`, `spec`, `buy_number`, `spec_desc`, `add_time`) "
    . "VALUES (:oid, 1, 1, '土豆', 'b.jpg', '2.80', '2.80', :spec, 1, '500g/份', UNIX_TIMESTAMP())"
)->execute(['oid' => $orderId, 'spec' => json_encode([['value' => '500g/份']], JSON_UNESCAPED_UNICODE)]);

// 扩展表：四维状态
$pdo->prepare(
    "INSERT INTO `veg_order_delivery` (`order_id`, `delivery_mode`, `expected_at`, `fulfillment`, "
    . "`payment_state`, `payment_method`, `receipt_state`, `revision_state`, `version`, `created_at`, `updated_at`) "
    . "VALUES (:oid, 'ASAP', NULL, 'PENDING', 'UNPAID', 'COD', 'UNCONFIRMED', 'NONE', 1, NOW(), NOW())"
)->execute(['oid' => $orderId]);

// 扩展表：订单行快照（模拟钩子已捕获）
$detailIds = $pdo->query("SELECT `id`, `price`, `buy_number`, `title` FROM `{$prefix}order_detail` WHERE `order_id` = {$orderId} ORDER BY `id`")->fetchAll(PDO::FETCH_ASSOC);
foreach ($detailIds as $i => $d) {
    $pdo->prepare(
        "INSERT INTO `veg_order_line` (`order_id`, `order_detail_id`, `sku_id`, `goods_id`, `name_snapshot`, "
        . "`spec_snapshot`, `image_snapshot`, `unit_fen`, `qty`, `created_at`) "
        . "VALUES (:oid, :did, :sku, 1, :name, '500g/份', 'x.jpg', :fen, :qty, NOW())"
    )->execute([
        'oid' => $orderId,
        'did' => (int) $d['id'],
        'sku' => 100 + $i,
        'name' => (string) $d['title'],
        'fen' => OrderRowMapper::decimalToFen((string) $d['price']),
        'qty' => (int) $d['buy_number'],
    ]);
}

echo "  订单 ID = {$orderId}，单号 = {$marker}\n\n";

// ------------------------------------------------------------
// 1. 登录
// ------------------------------------------------------------
echo "[1] 登录\n";
$cred = parse_ini_file('/root/.chunlian-admin-cred');
$login = http('POST', "{$base}/auth/login", [
    'username' => $cred['ADMIN_USER'] ?? 'admin',
    'password' => $cred['ADMIN_PWD'] ?? '',
    'device_id' => 'e2e-test',
]);
check('登录返回 200', $login['status'] === 200, "status={$login['status']}");
check('信封 code = OK', ($login['body']['code'] ?? '') === 'OK', json_encode($login['body'], JSON_UNESCAPED_UNICODE));
$token = (string) ($login['body']['data']['token'] ?? '');
check('拿到 token', $token !== '');

// 错误密码
$bad = http('POST', "{$base}/auth/login", ['username' => 'admin', 'password' => 'wrong-pwd-xxx']);
check('错误密码被拒绝', ($bad['body']['code'] ?? '') === 'FORBIDDEN');

echo "\n[2] 订单详情（读）\n";
$detail = http('GET', "{$base}/orders/{$orderId}", [], $token);
check('读取成功', ($detail['body']['code'] ?? '') === 'OK');
check('履约 = PENDING', ($detail['body']['data']['fulfillment'] ?? '') === 'PENDING');
check('支付 = UNPAID', ($detail['body']['data']['payment_state'] ?? '') === 'UNPAID');
check('金额为整数分 total=980', ($detail['body']['data']['total_fen'] ?? null) === 980, json_encode($detail['body']['data']['total_fen'] ?? null));
check('应收 = 980', ($detail['body']['data']['receivable_fen'] ?? null) === 980);
check('手机号已脱敏', ($detail['body']['data']['contact']['masked_phone'] ?? '') === '138****8000', $detail['body']['data']['contact']['masked_phone'] ?? '');
check('地址由名称字段拼成', ($detail['body']['data']['contact']['address'] ?? '') === '浙江省杭州市西湖区阳光小区3栋502室', $detail['body']['data']['contact']['address'] ?? '');
check('订单行 2 条', count($detail['body']['data']['lines'] ?? []) === 2);

// ------------------------------------------------------------
// 3. 开始配送
// ------------------------------------------------------------
echo "\n[3] 开始配送\n";
$uuid1 = sprintf('%s-%s-4%s-%s-%s', bin2hex(random_bytes(4)), bin2hex(random_bytes(2)), substr(bin2hex(random_bytes(2)), 1), 'a' . substr(bin2hex(random_bytes(2)), 1), bin2hex(random_bytes(6)));

$deliver = http('POST', "{$base}/orders/{$orderId}/deliver", ['version' => 1], $token, $uuid1);
check('配送成功', ($deliver['body']['code'] ?? '') === 'OK', json_encode($deliver['body'], JSON_UNESCAPED_UNICODE));
check('履约 → DELIVERING', ($deliver['body']['data']['fulfillment'] ?? '') === 'DELIVERING');
check('version → 2', ($deliver['body']['data']['version'] ?? null) === 2);

// 幂等重放：同键同体
$replay = http('POST', "{$base}/orders/{$orderId}/deliver", ['version' => 1], $token, $uuid1);
check('同幂等键重放返回 OK', ($replay['body']['code'] ?? '') === 'OK');
check('重放不推进版本（仍为 2）', ($replay['body']['data']['version'] ?? null) === 2, 'version=' . ($replay['body']['data']['version'] ?? 'null'));

// 乐观锁：用过期 version
$uuid2 = sprintf('%s-%s-4%s-%s-%s', bin2hex(random_bytes(4)), bin2hex(random_bytes(2)), substr(bin2hex(random_bytes(2)), 1), 'a' . substr(bin2hex(random_bytes(2)), 1), bin2hex(random_bytes(6)));
$conflict = http('POST', "{$base}/orders/{$orderId}/deliver", ['version' => 1], $token, $uuid2);
check('过期 version 被拒（409 语义）', ($conflict['body']['code'] ?? '') === 'ORDER_VERSION_CONFLICT', json_encode($conflict['body'], JSON_UNESCAPED_UNICODE));

// ------------------------------------------------------------
// 4. 确认送达 + COD 收款
// ------------------------------------------------------------
echo "\n[4] 确认送达（COD 收款）\n";
$uuid3 = sprintf('%s-%s-4%s-%s-%s', bin2hex(random_bytes(4)), bin2hex(random_bytes(2)), substr(bin2hex(random_bytes(2)), 1), 'a' . substr(bin2hex(random_bytes(2)), 1), bin2hex(random_bytes(6)));
$confirm = http('POST', "{$base}/orders/{$orderId}/confirm-delivery", [
    'version' => 2,
    'collected_on_delivery' => true,
    'collection_method' => 'CASH',
], $token, $uuid3);
check('确认送达成功', ($confirm['body']['code'] ?? '') === 'OK', json_encode($confirm['body'], JSON_UNESCAPED_UNICODE));
check('履约 → DELIVERED', ($confirm['body']['data']['fulfillment'] ?? '') === 'DELIVERED');
check('支付 → PAID', ($confirm['body']['data']['payment_state'] ?? '') === 'PAID');
check('已收 = 980', ($confirm['body']['data']['paid_fen'] ?? null) === 980);
check('应收归零', ($confirm['body']['data']['receivable_fen'] ?? null) === 0);

// 资金流水
$ledger = $pdo->query("SELECT `kind`, `amount_fen`, `status` FROM `veg_payment_ledger` WHERE `order_id` = {$orderId}")->fetchAll(PDO::FETCH_ASSOC);
check('写入 1 条 COD 收款流水', count($ledger) === 1, 'count=' . count($ledger));
check('流水类型 COD_RECEIPT', ($ledger[0]['kind'] ?? '') === 'COD_RECEIPT');
check('流水金额 980', (int) ($ledger[0]['amount_fen'] ?? 0) === 980);

// 审计
$audit = $pdo->query("SELECT `action` FROM `veg_operation_log` WHERE `order_id` = {$orderId} ORDER BY `id`")->fetchAll(PDO::FETCH_COLUMN);
check('写入审计（DISPATCH + DELIVER）', $audit === ['DISPATCH', 'DELIVER'], json_encode($audit));

// outbox
$outbox = $pdo->query("SELECT `event_type` FROM `veg_outbox_event` WHERE `entity_id` = {$orderId} ORDER BY `id`")->fetchAll(PDO::FETCH_COLUMN);
check('写入 outbox 事件', count($outbox) >= 2, json_encode($outbox));

// ------------------------------------------------------------
// 5. 重复收款防护
// ------------------------------------------------------------
echo "\n[5] 重复收款防护\n";
$uuid4 = sprintf('%s-%s-4%s-%s-%s', bin2hex(random_bytes(4)), bin2hex(random_bytes(2)), substr(bin2hex(random_bytes(2)), 1), 'a' . substr(bin2hex(random_bytes(2)), 1), bin2hex(random_bytes(6)));
$again = http('POST', "{$base}/orders/{$orderId}/cod-receipt", [
    'version' => 3,
    'collected_fen' => 980,
    'collection_method' => 'CASH',
], $token, $uuid4);
check('已收款后补记被拒', ($again['body']['code'] ?? '') !== 'OK', json_encode($again['body'], JSON_UNESCAPED_UNICODE));

$ledgerAfter = (int) $pdo->query("SELECT COUNT(*) FROM `veg_payment_ledger` WHERE `order_id` = {$orderId}")->fetchColumn();
check('未产生第二条流水', $ledgerAfter === 1, "count={$ledgerAfter}");

// ------------------------------------------------------------
// 6. 应收款与汇总
// ------------------------------------------------------------
echo "\n[6] 应收款与汇总\n";
$recv = http('GET', "{$base}/receivables", [], $token);
check('应收款接口可用', ($recv['body']['code'] ?? '') === 'OK');
$inList = false;
foreach ($recv['body']['data'] ?? [] as $row) {
    if ((string) $row['order_id'] === (string) $orderId) {
        $inList = true;
    }
}
check('已收款订单不在应收清单里', !$inList);

$sum = http('GET', "{$base}/settlement/daily", [], $token);
check('当日汇总可用', ($sum['body']['code'] ?? '') === 'OK');
check('今日 COD 实收 = 980', ($sum['body']['data']['received_cod_fen'] ?? null) === 980, json_encode($sum['body']['data'] ?? []));

$dash = http('GET', "{$base}/dashboard", [], $token);
check('首页统计可用', ($dash['body']['code'] ?? '') === 'OK');

// ------------------------------------------------------------
// 清理
// ------------------------------------------------------------
if (!$keep) {
    echo "\n[清理] 删除测试数据\n";
    $pdo->exec("DELETE FROM `veg_payment_ledger` WHERE `order_id` = {$orderId}");
    $pdo->exec("DELETE FROM `veg_operation_log` WHERE `order_id` = {$orderId}");
    $pdo->exec("DELETE FROM `veg_outbox_event` WHERE `entity_id` = {$orderId}");
    $pdo->exec("DELETE FROM `veg_order_line` WHERE `order_id` = {$orderId}");
    $pdo->exec("DELETE FROM `veg_order_delivery` WHERE `order_id` = {$orderId}");
    $pdo->exec("DELETE FROM `{$prefix}order_detail` WHERE `order_id` = {$orderId}");
    $pdo->exec("DELETE FROM `{$prefix}order_address` WHERE `order_id` = {$orderId}");
    $pdo->exec("DELETE FROM `{$prefix}order` WHERE `id` = {$orderId}");
    echo "  已清理\n";
} else {
    echo "\n[保留] 测试订单 {$orderId} 未清理\n";
}

echo "\n", str_repeat('=', 60), "\n";
printf("通过 %d / %d", $pass, $pass + $fail);
echo $fail === 0 ? "  ✓ 全部通过\n" : "  ✗ 有 {$fail} 项失败\n";

exit($fail === 0 ? 0 : 1);
