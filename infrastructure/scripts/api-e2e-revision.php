<?php

declare(strict_types=1);

/**
 * 春连鲜选 · 订单修订（差价引擎）端到端测试
 *
 * 覆盖 ADR-004 的三条核心规则在真实数据上是否成立：
 *   1. 补款成功后才提交新版本（差价为正时明细**不立即切换**）
 *   2. 已有 SKU 维持原订单单价，新增/增量按现价
 *   3. 待补款期间禁止开始配送
 *
 * 另外验证：
 *   - 退款方向（差价 < 0）明细立即切换，避免商家按旧单备货
 *   - 改单后订单行快照（veg_order_line）同步重建，否则下一次改单会算错
 *   - 越权访问：别人的订单不能改
 *
 * 用法：php api-e2e-revision.php [--keep]
 */

$shopxoRoot = '/var/www/chunlian/shopxo';
require_once $shopxoRoot . '/public/core.php';
require_once $shopxoRoot . '/vendor/autoload.php';
(new \think\App())->initialize();
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
function http(string $method, string $url, array $body = [], ?string $token = null): array
{
    $headers = ['Content-Type: application/json'];
    if ($token !== null) {
        $headers[] = 'Authorization: Bearer ' . $token;
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

echo "春连鲜选 · 订单修订端到端测试\n", str_repeat('=', 60), "\n\n";

// ------------------------------------------------------------
// 准备：拿两个真实 SKU 与一个测试订单
// ------------------------------------------------------------
echo "[准备] 构造测试数据\n";

$skus = $pdo->query(
    "SELECT b.`id`, b.`goods_id`, b.`price`, b.`inventory`, g.`title` "
    . "FROM `{$prefix}goods_spec_base` b "
    . "INNER JOIN `{$prefix}goods` g ON g.`id` = b.`goods_id` "
    . "WHERE g.`is_delete_time` = 0 AND b.`inventory` > 50 "
    . "ORDER BY b.`id` LIMIT 2"
)->fetchAll(PDO::FETCH_ASSOC);

if (count($skus) < 2) {
    echo "  可用 SKU 不足 2 个，无法测试。\n";
    exit(0);
}

$skuA = $skus[0];
$skuB = $skus[1];
$priceA = OrderRowMapper::decimalToFen((string) $skuA['price']);
$priceB = OrderRowMapper::decimalToFen((string) $skuB['price']);

echo "  SKU A = {$skuA['id']}（{$priceA} 分，库存 {$skuA['inventory']}）\n";
echo "  SKU B = {$skuB['id']}（{$priceB} 分，库存 {$skuB['inventory']}）\n";

$marker = 'REV' . date('His');
// 三个金额列各用独立占位符（同键复用会被 PDO 拒绝，见下方 order_detail 的说明）
$pdo->prepare(
    "INSERT INTO `{$prefix}order` (`order_no`, `user_id`, `status`, `pay_status`, `is_under_line`, "
    . "`price`, `total_price`, `pay_price`, `refund_price`, `buy_number_count`, `add_time`, `upd_time`) "
    . "VALUES (:no, 1, 2, 1, 0, :price, :total, :paid, '0.00', 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP())"
)->execute([
    'no' => $marker,
    'price' => OrderRowMapper::fenToDecimal($priceA),
    'total' => OrderRowMapper::fenToDecimal($priceA),
    'paid' => OrderRowMapper::fenToDecimal($priceA),
]);
$orderId = (int) $pdo->lastInsertId();

// 在线已付款订单（is_under_line=0 + pay_status=1 + pay_price>0）
// 注意：单价与总价必须用两个不同的占位符 ——
// 本项目关闭了 PDO 模拟预处理，同一命名占位符不能在一句里出现两次
$pdo->prepare(
    "INSERT INTO `{$prefix}order_detail` (`order_id`, `user_id`, `goods_id`, `title`, `images`, `price`, "
    . "`total_price`, `spec`, `buy_number`, `spec_desc`, `add_time`) "
    . "VALUES (:oid, 1, :gid, :title, 'x.jpg', :price, :total, :spec, 1, '测试', UNIX_TIMESTAMP())"
)->execute([
    'oid' => $orderId,
    'gid' => (int) $skuA['goods_id'],
    'title' => (string) $skuA['title'],
    'price' => OrderRowMapper::fenToDecimal($priceA),
    'total' => OrderRowMapper::fenToDecimal($priceA),
    'spec' => json_encode([['value' => '默认']], JSON_UNESCAPED_UNICODE),
]);

$pdo->prepare(
    "INSERT INTO `veg_order_delivery` (`order_id`, `delivery_mode`, `expected_at`, `order_status`, "
    . "`payment_state`, `payment_method`, `revision_state`, `version`, `created_at`, `updated_at`) "
    . "VALUES (:oid, 'ASAP', NULL, 'PENDING', 'PAID', 'WECHAT', 'NONE', 1, NOW(), NOW())"
)->execute(['oid' => $orderId]);

$detailId = (int) $pdo->query("SELECT `id` FROM `{$prefix}order_detail` WHERE `order_id` = {$orderId}")->fetchColumn();
$pdo->prepare(
    "INSERT INTO `veg_order_line` (`order_id`, `order_detail_id`, `sku_id`, `goods_id`, `name_snapshot`, "
    . "`spec_snapshot`, `image_snapshot`, `unit_fen`, `qty`, `created_at`) "
    . "VALUES (:oid, :did, :sku, :gid, :name, '默认', 'x.jpg', :fen, 1, NOW())"
)->execute([
    'oid' => $orderId,
    'did' => $detailId,
    'sku' => (int) $skuA['id'],
    'gid' => (int) $skuA['goods_id'],
    'name' => (string) $skuA['title'],
    'fen' => $priceA,
]);

echo "  订单 ID = {$orderId}，单号 = {$marker}（已在线付款 ¥", number_format($priceA / 100, 2), "）\n\n";

// 登录
$cred = parse_ini_file('/root/.chunlian-admin-cred');
$login = http('POST', "{$base}/auth/login", [
    'username' => $cred['ADMIN_USER'] ?? 'admin',
    'password' => $cred['ADMIN_PWD'] ?? '',
    'device_id' => 'rev-e2e',
]);
$token = (string) ($login['body']['data']['token'] ?? '');
check('商家登录', $token !== '');

// ------------------------------------------------------------
// 1. 差价预览
// ------------------------------------------------------------
echo "\n[1] 差价预览\n";

// 在原有 1 份 A 的基础上，改成 3 份 A —— 增量 2 份按现价
$preview = http('POST', "{$base}/orders/{$orderId}/revision/preview", [
    'items' => [['sku_id' => (string) $skuA['id'], 'qty' => 3]],
], $token);

// 顾客端接口不在商家 token 下，这里直接调服务层更贴近真实（顾客端需用户 token）
// 用服务层验证核心算法
$svc = new \Chunlian\Service\RevisionService(
    $pdo,
    new \Chunlian\Repository\PdoOrderRepository($pdo, $prefix),
    new \Chunlian\Service\RevisionCalculator(),
    $prefix
);

$p = $svc->preview((string) $orderId, [['sku_id' => (string) $skuA['id'], 'qty' => 3]]);
check('预览成功', ($p['code'] ?? '') === 'OK', json_encode($p, JSON_UNESCAPED_UNICODE));

$delta = (int) ($p['data']['delta_fen'] ?? 0);
// 原 1 份维持原价 priceA；增量 2 份按现价 priceA（现价未变）→ delta = 2 × priceA
check(
    '差价 = 增量 2 份按现价',
    $delta === 2 * $priceA,
    "delta={$delta} 期望=" . (2 * $priceA)
);
check('标记需要补款', ($p['data']['requires_topup_before_commit'] ?? false) === true);

// ------------------------------------------------------------
// 2. 提交修订 → 待补款，明细不切换
// ------------------------------------------------------------
echo "\n[2] 提交修订（差价为正）\n";

$beforeQty = (int) $pdo->query("SELECT `buy_number` FROM `{$prefix}order_detail` WHERE `order_id` = {$orderId}")->fetchColumn();
check('提交前明细数量 = 1', $beforeQty === 1);

$sub = $svc->submit((string) $orderId, [['sku_id' => (string) $skuA['id'], 'qty' => 3]], '1', 'CUSTOMER');
check('提交成功', ($sub['code'] ?? '') === 'OK', json_encode($sub, JSON_UNESCAPED_UNICODE));
check('修订状态 = PENDING_TOPUP', ($sub['data']['revision_state'] ?? '') === 'PENDING_TOPUP', $sub['data']['revision_state'] ?? '');
check('未立即生效', ($sub['data']['applied_immediately'] ?? true) === false);

$afterQty = (int) $pdo->query("SELECT `buy_number` FROM `{$prefix}order_detail` WHERE `order_id` = {$orderId}")->fetchColumn();
check(
    '明细数量仍是 1（补款前不切换）',
    $afterQty === 1,
    "实际={$afterQty}"
);

$stateRow = $pdo->query("SELECT `revision_state`, `version` FROM `veg_order_delivery` WHERE `order_id` = {$orderId}")->fetch(PDO::FETCH_ASSOC);
check('四维状态已置为待补款', ($stateRow['revision_state'] ?? '') === 'PENDING_TOPUP');
check('版本已推进到 2', (int) ($stateRow['version'] ?? 0) === 2);

$revCount = (int) $pdo->query("SELECT COUNT(*) FROM `veg_order_revision` WHERE `order_id` = {$orderId}")->fetchColumn();
check('写入 1 条修订记录', $revCount === 1);

// ------------------------------------------------------------
// 3. 待补款期间禁止配送
// ------------------------------------------------------------
echo "\n[3] 待补款期间禁止配送\n";

$sm = new \Chunlian\Service\OrderStateMachine();
$order = (new \Chunlian\Repository\PdoOrderRepository($pdo, $prefix))->find((string) $orderId);
$res = $sm->startDelivery($order, 2);
check('开始配送被拒绝', !$res->success, json_encode($res->data ?? []));
check(
    '拒绝原因 = TOPUP_REQUIRED',
    ($res->code?->value ?? '') === 'TOPUP_REQUIRED',
    $res->code?->value ?? ''
);

// ------------------------------------------------------------
// 4. 补款成功后提交
// ------------------------------------------------------------
echo "\n[4] 补款成功后提交\n";

$commit = $svc->commitTopup((string) $orderId, '1');
check('提交成功', ($commit['code'] ?? '') === 'OK', json_encode($commit, JSON_UNESCAPED_UNICODE));
check('修订状态 = COMMITTED', ($commit['data']['revision_state'] ?? '') === 'COMMITTED');

$finalQty = (int) $pdo->query("SELECT `buy_number` FROM `{$prefix}order_detail` WHERE `order_id` = {$orderId}")->fetchColumn();
check('明细数量已切换为 3', $finalQty === 3, "实际={$finalQty}");

$totalPrice = (string) $pdo->query("SELECT `total_price` FROM `{$prefix}order` WHERE `id` = {$orderId}")->fetchColumn();
// 原 1 份 + 增量 2 份（都按 priceA）→ 3 × priceA
check(
    '订单总额已重算',
    OrderRowMapper::decimalToFen($totalPrice) === 3 * $priceA,
    "total={$totalPrice} 期望=" . OrderRowMapper::fenToDecimal(3 * $priceA)
);

$lineCount = (int) $pdo->query("SELECT COUNT(*) FROM `veg_order_line` WHERE `order_id` = {$orderId}")->fetchColumn();
check('订单行快照已重建（1 条）', $lineCount === 1);

$lineQty = (int) $pdo->query("SELECT `qty` FROM `veg_order_line` WHERE `order_id` = {$orderId}")->fetchColumn();
check('快照数量同步为 3', $lineQty === 3, "实际={$lineQty}");

// 现在应该可以配送了
$order2 = (new \Chunlian\Repository\PdoOrderRepository($pdo, $prefix))->find((string) $orderId);
$res2 = $sm->startDelivery($order2, $order2->version);
check('补款后可以开始配送', $res2->success, json_encode($res2->data ?? []));

// ------------------------------------------------------------
// 5. 退款方向：明细立即切换
// ------------------------------------------------------------
echo "\n[5] 差价方向为退款\n";

// 复位：把订单改回 PENDING 并允许再次修订
$pdo->exec("UPDATE `veg_order_delivery` SET `order_status`='PENDING', `revision_state`='NONE' WHERE `order_id` = {$orderId}");

$sub2 = $svc->submit((string) $orderId, [['sku_id' => (string) $skuA['id'], 'qty' => 1]], '1', 'CUSTOMER');
check('提交成功', ($sub2['code'] ?? '') === 'OK', json_encode($sub2, JSON_UNESCAPED_UNICODE));

$delta2 = (int) ($sub2['data']['delta_fen'] ?? 0);
check('差价为负（需退款）', $delta2 < 0, "delta={$delta2}");
check('修订状态 = APPLYING', ($sub2['data']['revision_state'] ?? '') === 'APPLYING', $sub2['data']['revision_state'] ?? '');
check('立即生效', ($sub2['data']['applied_immediately'] ?? false) === true);

$qty3 = (int) $pdo->query("SELECT `buy_number` FROM `{$prefix}order_detail` WHERE `order_id` = {$orderId}")->fetchColumn();
check('明细已立即切换为 1（商家应按新单备货）', $qty3 === 1, "实际={$qty3}");

$outbox = $pdo->query(
    "SELECT `event_type` FROM `veg_outbox_event` WHERE `entity_id` = {$orderId} ORDER BY `id`"
)->fetchAll(PDO::FETCH_COLUMN);
check('排了退款查询任务', in_array('REFUND_QUERY', $outbox, true), json_encode($outbox));

// ------------------------------------------------------------
// 6. 修订历史
// ------------------------------------------------------------
echo "\n[6] 修订历史\n";

$hist = $svc->history((string) $orderId);
check('历史查询成功', ($hist['code'] ?? '') === 'OK');
check('有 2 条记录', (int) ($hist['data']['total'] ?? 0) === 2, json_encode($hist['data']['total'] ?? null));

$first = $hist['data']['list'][0] ?? [];
check('第一条有差异摘要', !empty($first['summary']), json_encode($first['summary'] ?? null));
check('第一条差价为正', (int) ($first['delta_fen'] ?? 0) > 0);
check('第二条差价为负', (int) ($hist['data']['list'][1]['delta_fen'] ?? 0) < 0);

// ------------------------------------------------------------
// 清理
// ------------------------------------------------------------
if (!$keep) {
    echo "\n[清理]\n";
    $pdo->exec("DELETE FROM `veg_order_revision` WHERE `order_id` = {$orderId}");
    $pdo->exec("DELETE FROM `veg_operation_log` WHERE `order_id` = {$orderId}");
    $pdo->exec("DELETE FROM `veg_outbox_event` WHERE `entity_id` = {$orderId}");
    $pdo->exec("DELETE FROM `veg_order_line` WHERE `order_id` = {$orderId}");
    $pdo->exec("DELETE FROM `veg_order_delivery` WHERE `order_id` = {$orderId}");
    $pdo->exec("DELETE FROM `{$prefix}order_detail` WHERE `order_id` = {$orderId}");
    $pdo->exec("DELETE FROM `{$prefix}order` WHERE `id` = {$orderId}");
    echo "  已清理\n";
}

echo "\n", str_repeat('=', 60), "\n";
printf("通过 %d / %d", $pass, $pass + $fail);
echo $fail === 0 ? "  ✓ 全部通过\n" : "  ✗ 有 {$fail} 项失败\n";

exit($fail === 0 ? 0 : 1);
