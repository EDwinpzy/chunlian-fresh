<?php

declare(strict_types=1);

/**
 * 春连鲜选 · 订单行 SKU 捕获验证
 *
 * 验证 docs/SHOPXO-AUDIT.md §5 提出的缺口修复是否真的生效：
 *   在真实 ShopXO 数据上，用官方方法把 `goods_id + spec` 解析回 SKU 主键，
 *   并确认 `veg_order_line` 落库正确。
 *
 * 【为什么必须验证这一步】
 * ShopXO 的 `shopxo_order_detail` 不存 SKU 身份。本项目的改单引擎
 * 全靠 `veg_order_line.sku_id` 才能按 SKU 比对旧单与新单算差价。
 * 如果这个捕获静默失效，改单功能会在「算错钱」时才暴露 ——
 * 那时已经产生了真实的资金差错。
 *
 * 用法：php verify-sku-capture.php
 */

$shopxoRoot = '/var/www/chunlian/shopxo';

// ------------------------------------------------------------
// 引导 ShopXO 运行时
// ------------------------------------------------------------
// 【为什么要走完整引导，而不是只 require 几个文件】
// 本脚本要直接调用 ShopXO 官方的 `GoodsService::GoodsSpecDetail()`，
// 它内部依赖框架的全局辅助函数（MyLang / MyC / ParamsChecked …）。
// 只 require autoload 能加载到类，但一调用就会
// 「Call to undefined function app\service\MyLang()」。
//
// `App::initialize()` 会自动加载 `app/common.php`（ThinkPHP 的约定），
// 那些辅助函数就在那里定义。这也是官方 `think` CLI 入口的做法。
//
// core.php 需要若干 $_SERVER 变量，CLI 下没有，这里补上最小集合。
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST'] = '127.0.0.1';
$_SERVER['SERVER_ADDR'] = '127.0.0.1';
$_SERVER['SERVER_PORT'] = '80';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['DOCUMENT_ROOT'] = $shopxoRoot . '/public';

require_once $shopxoRoot . '/public/core.php';
require_once $shopxoRoot . '/vendor/autoload.php';

(new \think\App())->initialize();

require_once $shopxoRoot . '/app/plugins/chunlianfresh/Bootstrap.php';

use app\plugins\chunlianfresh\Hook;
use app\service\GoodsService;

use function app\plugins\chunlianfresh\pdo;
use function app\plugins\chunlianfresh\table_prefix;

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

$pdo = pdo();
$prefix = table_prefix();

echo "春连鲜选 · 订单行 SKU 捕获验证\n", str_repeat('=', 60), "\n\n";

// ------------------------------------------------------------
// 1. 找一个多规格商品作为样本
// ------------------------------------------------------------
echo "[1] 选取样本商品\n";

$goods = $pdo->query(
    "SELECT `id`, `title`, `is_exist_many_spec` FROM `{$prefix}goods` "
    . "WHERE `is_exist_many_spec` = 1 LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);

if ($goods === false) {
    // 没有多规格商品则退回单规格
    $goods = $pdo->query(
        "SELECT `id`, `title`, `is_exist_many_spec` FROM `{$prefix}goods` LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);
}

if ($goods === false) {
    echo "  没有商品数据，跳过（这是空库的正常情况）\n";
    exit(0);
}

$goodsId = (int) $goods['id'];
echo "  商品 id={$goodsId} 「{$goods['title']}」多规格=", (int) $goods['is_exist_many_spec'], "\n";

// ------------------------------------------------------------
// 2. 取该商品的一个 SKU 及其规格值
// ------------------------------------------------------------
echo "\n[2] 解析 SKU\n";

$specBase = $pdo->query(
    "SELECT `id`, `goods_id`, `price` FROM `{$prefix}goods_spec_base` WHERE `goods_id` = {$goodsId} LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);

if ($specBase === false) {
    echo "  该商品没有 spec_base 记录，跳过\n";
    exit(0);
}

$expectedSkuId = (int) $specBase['id'];
echo "  期望 SKU id = {$expectedSkuId}，price = {$specBase['price']}\n";

// 取该 SKU 的规格值组合
$specValues = $pdo->query(
    "SELECT `goods_spec_base_id`, `value`, `md5_key` FROM `{$prefix}goods_spec_value` "
    . "WHERE `goods_spec_base_id` = {$expectedSkuId}"
)->fetchAll(PDO::FETCH_ASSOC);

echo "  规格值 ", count($specValues), " 项\n";

// 构造 ShopXO 下单时存储的 spec 结构（含 key 与 value）
$specPayload = [];
foreach ($specValues as $sv) {
    $specPayload[] = [
        'value' => (string) $sv['value'],
        'key' => (string) ($sv['md5_key'] ?? ''),
    ];
}

if ($specPayload === []) {
    // 单规格商品：spec 为空
    echo "  该 SKU 无规格值（单规格商品）\n";
}

// ------------------------------------------------------------
// 3. 用 ShopXO 官方方法解析
// ------------------------------------------------------------
echo "\n[3] 官方方法解析\n";

$ret = GoodsService::GoodsSpecDetail(['id' => $goodsId, 'spec' => $specPayload]);
check(
    'GoodsSpecDetail 调用成功',
    ($ret['code'] ?? -1) === 0,
    (string) ($ret['msg'] ?? '')
);

$resolvedId = (int) ($ret['data']['spec_base']['id'] ?? 0);
check(
    '解析出的 SKU 与预期一致',
    $resolvedId === $expectedSkuId,
    "期望 {$expectedSkuId}，实际 {$resolvedId}"
);

// ------------------------------------------------------------
// 4. 落库验证
// ------------------------------------------------------------
echo "\n[4] 落库验证\n";

$marker = 'SKUCHK' . date('His');
$pdo->prepare(
    "INSERT INTO `{$prefix}order` (`order_no`, `user_id`, `status`, `pay_status`, `is_under_line`, "
    . "`price`, `total_price`, `pay_price`, `refund_price`, `buy_number_count`, `add_time`, `upd_time`) "
    . "VALUES (:no, 1, 2, 0, 1, '0.01', '0.01', '0.00', '0.00', 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP())"
)->execute(['no' => $marker]);
$orderId = (int) $pdo->lastInsertId();

$pdo->prepare(
    "INSERT INTO `{$prefix}order_detail` (`order_id`, `user_id`, `goods_id`, `title`, `images`, `price`, "
    . "`total_price`, `spec`, `buy_number`, `spec_desc`, `add_time`) "
    // 注意：单价与总价用两个不同的占位符。
    // PDO 关闭模拟预处理后（本项目刻意如此，防止参数被当作 SQL 拼接），
    // 同一个命名占位符不能在同一条语句里出现两次。
    . "VALUES (:oid, 1, :gid, :title, 'x.jpg', :price, :total, :spec, 1, '测试规格', UNIX_TIMESTAMP())"
)->execute([
    'oid' => $orderId,
    'gid' => $goodsId,
    'title' => (string) $goods['title'],
    'price' => (string) $specBase['price'],
    'total' => (string) $specBase['price'],
    'spec' => json_encode($specPayload, JSON_UNESCAPED_UNICODE),
]);

$hook = new Hook();
$written = $hook->captureForOrder($orderId);
check('captureForOrder 写入行', $written === 1, "written={$written}");

$row = $pdo->query("SELECT `sku_id`, `unit_fen`, `qty`, `name_snapshot` FROM `veg_order_line` WHERE `order_id` = {$orderId}")->fetch(PDO::FETCH_ASSOC);
check('订单行已落库', $row !== false);
check(
    'sku_id 与预期一致',
    (int) ($row['sku_id'] ?? -1) === $expectedSkuId,
    'sku_id=' . ($row['sku_id'] ?? 'null') . " 期望 {$expectedSkuId}"
);
check(
    '单价转整数分正确',
    (int) ($row['unit_fen'] ?? -1) === (int) round(((float) $specBase['price']) * 100),
    'unit_fen=' . ($row['unit_fen'] ?? 'null')
);

// 幂等：重复捕获不应产生第二行
$again = $hook->captureForOrder($orderId);
$count = (int) $pdo->query("SELECT COUNT(*) FROM `veg_order_line` WHERE `order_id` = {$orderId}")->fetchColumn();
check('重复捕获不产生重复行', $count === 1, "count={$count}，第二次写入={$again}");

// 清理
$pdo->exec("DELETE FROM `veg_order_line` WHERE `order_id` = {$orderId}");
$pdo->exec("DELETE FROM `{$prefix}order_detail` WHERE `order_id` = {$orderId}");
$pdo->exec("DELETE FROM `{$prefix}order` WHERE `id` = {$orderId}");
echo "\n  已清理测试数据\n";

echo "\n", str_repeat('=', 60), "\n";
printf("通过 %d / %d", $pass, $pass + $fail);
echo $fail === 0 ? "  ✓ 全部通过\n" : "  ✗ 有 {$fail} 项失败\n";

exit($fail === 0 ? 0 : 1);
