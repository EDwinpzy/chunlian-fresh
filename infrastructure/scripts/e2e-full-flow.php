<?php

declare(strict_types=1);

/**
 * 春连鲜选 · 顾客端下单链路端到端测试（仅限隔离 staging）
 *
 * 覆盖：注册/登录 → 地址 → 目录/搜索/分类/详情 → 预览 → 下单(COD) →
 *       改单(差价) → 改信息(无差价) → 商家开始配送 → 顾客取消被拒 →
 *       商家确认送达并收款 → 顾客确认收货 → 库存/预约/打烊/越权 拒绝场景 →
 *       店铺设置与商品上下架。
 *
 * 必须设置 CHUNLIAN_E2E_BASE_URL、CHUNLIAN_E2E_CREDENTIALS，并传入
 * --target=staging --allow-writes。脚本会创建并履约 COD 订单，测试顾客及
 * 已完成订单会保留以维持订单账本完整；不要对正式库运行。
 *   --keep 保留测试商品；默认下架测试商品。
 */

$base = rtrim((string) (getenv('CHUNLIAN_E2E_BASE_URL') ?: ''), '/');
$credentialsFile = (string) (getenv('CHUNLIAN_E2E_CREDENTIALS') ?: '');
$allowWrites = in_array('--allow-writes', $argv, true);
$stagingTarget = in_array('--target=staging', $argv, true);
$keep = in_array('--keep', $argv, true);

if (!$allowWrites || !$stagingTarget || $base === '' || $credentialsFile === '') {
    fwrite(STDERR, "拒绝运行：需设置 staging API/凭据，并传入 --target=staging --allow-writes。\n");
    exit(64);
}

$baseParts = parse_url($base);
$host = strtolower((string) ($baseParts['host'] ?? ''));
if (!in_array((string) ($baseParts['scheme'] ?? ''), ['http', 'https'], true)
    || $host === ''
    || $host === '103.236.97.137'
    || !is_file($credentialsFile)) {
    fwrite(STDERR, "拒绝运行：API 地址/凭据无效，或 API 指向本机/正式服务器。\n");
    exit(64);
}

// A flag alone is insufficient: verify the test-only server marker before any write.
$markerUrl = ($baseParts['scheme'] ?? 'http') . '://' . ($baseParts['host'] ?? '')
    . (isset($baseParts['port']) ? ':' . $baseParts['port'] : '') . '/__qa_environment';
$marker = json_decode((string) @file_get_contents($markerUrl), true);
if (($marker['environment'] ?? '') !== 'isolated-staging'
    || ($marker['database'] ?? '') !== 'chunlian_qa_20261013') {
    fwrite(STDERR, "拒绝运行：隔离测试环境标记未验证。\n");
    exit(64);
}

$pass = 0;
$fail = 0;
$createdCustomer = null;
$createdOrderId = null;
$createdGoodsId = null;
$customerToken = null;
$merchantToken = null;

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

/** @return array{code:string,message:string,data:mixed} */
function http(string $method, string $url, mixed $body = [], ?string $token = null, ?string $idemKey = null): array
{
    global $base;

    $headers = ['Content-Type: application/json', 'Accept: application/json'];
    if ($token !== null) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    if ($idemKey !== null) {
        $headers[] = 'Idempotency-Key: ' . $idemKey;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    if ($body !== null && $body !== [] && $method !== "GET") {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
    }
    $raw = curl_exec($ch);
    $decoded = $raw === false ? null : json_decode((string) $raw, true);

    return is_array($decoded) ? $decoded : ['code' => 'HTTP_' . curl_getinfo($ch, CURLINFO_HTTP_CODE), 'message' => (string) $raw, 'data' => null];
}

function uuid(): string
{
    return sprintf(
        '%04x%04x-%04x-4%03x-%04x-%04x%04x%04x',
        random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff),
        random_int(0, 0xfff), random_int(0, 0x3fff) | 0x8000,
        random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff)
    );
}

echo "春连鲜选 · 顾客端下单链路 E2E\n", str_repeat('=', 60), "\n\n";

echo "[准备] 登录\n";
echo "目标环境：{$host}\n";

$cred = parse_ini_file($credentialsFile);
$login = http('POST', $base . '/merchant/auth/login', [
    'username' => $cred['ADMIN_USER'] ?? 'admin',
    'password' => $cred['ADMIN_PWD'] ?? '',
    'device_id' => 'e2e-full',
]);
$merchantToken = (string) ($login['data']['token'] ?? '');
check('商家登录', $merchantToken !== '', $login['message'] ?? '');

if ($merchantToken === '') {
    exit(1);
}

$initialStoreResponse = http('GET', $base . '/merchant/store', null, $merchantToken);
if (($initialStoreResponse['code'] ?? '') !== 'OK' || !is_array($initialStoreResponse['data'] ?? null)) {
    fwrite(STDERR, "无法读取 staging 营业设置，停止写入测试数据。\n");
    exit(1);
}
$initialStore = $initialStoreResponse['data'];
$restoreStoreSettings = false;
$restoreStore = static function () use (&$restoreStoreSettings, $initialStore, $base, $merchantToken): void {
    if (!$restoreStoreSettings) {
        return;
    }

    $result = http('PATCH', $base . '/merchant/store', [
        'is_open' => (bool) ($initialStore['is_open'] ?? false),
        'delivery_fee_fen' => (int) ($initialStore['delivery_fee_fen'] ?? 0),
    ], $merchantToken);
    if (($result['code'] ?? '') !== 'OK') {
        fwrite(STDERR, "警告：未能恢复 staging 营业状态/配送费，请立即检查。\n");
    }
    $restoreStoreSettings = false;
};
register_shutdown_function($restoreStore);

// ---------------- 注册 & 地址 ----------------
echo "\n[1] 账号与地址\n";

$phone = '139' . str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
$reg = http('POST', $base . '/customer/auth/register', [
    'phone' => $phone,
    'password' => 'abc123456',
    'nickname' => 'E2E顾客',
]);
$customerToken = (string) ($reg['data']['token'] ?? '');
$createdCustomer = (string) ($reg['data']['user']['user_id'] ?? '');
check('顾客注册', $customerToken !== '', $reg['message'] ?? '');

$addr = http('POST', $base . '/customer/addresses', [
    'name' => '李女士',
    'phone' => '13900139000',
    'address' => '春风路8号2单元301',
    'is_default' => true,
], $customerToken);
check('保存地址', ($addr['code'] ?? '') === 'OK', $addr['message'] ?? '');

// ---------------- 目录 ----------------
echo "\n[2] 商品目录\n";

$cats = http('GET', $base . '/customer/catalog/categories');
$expectedFreshCategoryNames = ['叶菜类', '瓜果类', '根茎类', '蛋豆类', '菌菇类', '调味配菜'];
$actualCategoryNames = array_map(static fn (array $c): string => (string) ($c['name'] ?? ''), $cats['data'] ?? []);
$unexpectedCategories = array_diff($actualCategoryNames, $expectedFreshCategoryNames);
check('顾客分类只含六类生鲜', $unexpectedCategories === [], implode(',', $unexpectedCategories));
$freshCatId = null;
foreach (($cats['data'] ?? []) as $c) {
    if (($c['name'] ?? '') === '叶菜类') {
        $freshCatId = (string) $c['id'];
    }
}
check('分类列表含生鲜分类', $freshCatId !== null);
if ($freshCatId === null) {
    fwrite(STDERR, "staging 未配置生鲜分类，停止后续写入测试。\n");
    exit(1);
}

$byCat = http('GET', $base . "/customer/catalog/goods?category_id={$freshCatId}&page=1&page_size=5");
check('按分类查商品', ($byCat['code'] ?? '') === 'OK' && !empty($byCat['data']['list']), $byCat['message'] ?? '');

// 列表接口只给摘要，SKU 必须再查一次详情 —— 这也是前端「加购前先取 SKU」的原因
$list = $byCat['data']['list'] ?? [];
$goodsId = (string) ($list[0]['id'] ?? '');
check('分类下有商品', $goodsId !== '');

$detail = http('GET', $base . '/customer/catalog/goods/' . $goodsId);
check('商品详情含 SKU', ($detail['code'] ?? '') === 'OK', $detail['message'] ?? '');
$sku = (string) ($detail['data']['skus'][0]['sku_id'] ?? '');
check('取到可售 SKU', $sku !== '', json_encode($detail['data']['skus'] ?? [], JSON_UNESCAPED_UNICODE));

// ---------------- 预览 & 下单 ----------------
echo "\n[3] 下单（货到付款）\n";

$preview = http('POST', $base . '/customer/orders/preview', [
    'items' => [['sku_id' => $sku, 'qty' => 1]],
    'delivery_mode' => 'ASAP',
], $customerToken);
check('下单预览', ($preview['code'] ?? '') === 'OK', $preview['message'] ?? '');

$key = uuid();
$idemPayload = [
    'client_request_id' => $key,
    'items' => [['sku_id' => $sku, 'qty' => 1]],
    'contact' => ['name' => '李女士', 'phone' => '13900139000', 'address' => '春风路8号2单元301'],
    'delivery_mode' => 'ASAP',
    'payment_method' => 'COD',
    'remark' => '放门口',
];
$order = http('POST', $base . '/customer/orders', $idemPayload, $customerToken, $key);
$createdOrderId = (string) ($order['data']['order']['id'] ?? '');
check('COD 下单成功', $createdOrderId !== '', $order['message'] ?? '');

// 幂等：用**完全相同**的请求体重放，验证返回首次结果而不是新建一单
$idemPayload = [
    'client_request_id' => $key,
    'items' => [['sku_id' => $sku, 'qty' => 1]],
    'contact' => ['name' => '李女士', 'phone' => '13900139000', 'address' => '春风路8号2单元301'],
    'delivery_mode' => 'ASAP',
    'payment_method' => 'COD',
    'remark' => '放门口',
];
$order2 = http('POST', $base . '/customer/orders', $idemPayload, $customerToken, $key);
check('幂等键去重（重复下单返回同一单）', (string) ($order2['data']['order']['id'] ?? '') === $createdOrderId, $order2['message'] ?? '');

$conflict = http('POST', $base . '/customer/orders', [
    'client_request_id' => $key,
    'items' => [['sku_id' => $sku, 'qty' => 2]],
    'contact' => ['name' => '李女士', 'phone' => '13900139000', 'address' => '春风路8号2单元301'],
    'delivery_mode' => 'ASAP',
    'payment_method' => 'COD',
], $customerToken, $key);
check('同 key 不同体被拒（防重复下单掩盖的真实变化）', ($conflict['code'] ?? '') === 'IDEMPOTENCY_CONFLICT', $conflict['message'] ?? '');

// ---------------- 改单 ----------------
echo "\n[4] 改单（差价引擎）\n";

$rev = http('POST', $base . "/customer/orders/{$createdOrderId}/revision/preview", [
    'items' => [['sku_id' => $sku, 'qty' => 3]],
], $customerToken);
check('差价预览成功', ($rev['code'] ?? '') === 'OK', $rev['message'] ?? '');
$delta = (int) ($rev['data']['delta_fen'] ?? 0);
check('差价为增量（>0，按现价计增量）', $delta > 0, "delta={$delta}");

$revSubmit = http('POST', $base . "/customer/orders/{$createdOrderId}/revision", [
    'items' => [['sku_id' => $sku, 'qty' => 3]],
], $customerToken, uuid());
check('改单提交（未付款立即生效）', ($revSubmit['code'] ?? '') === 'OK', $revSubmit['message'] ?? '');

$o = http('GET', $base . "/customer/orders/{$createdOrderId}", [], $customerToken);
$qtyAfter = (int) ($o['data']['lines'][0]['qty'] ?? 0);
check('明细已切换为 3', $qtyAfter === 3, "qty={$qtyAfter}");

// 改信息（不涉金额）
$info = http('POST', $base . "/customer/orders/{$createdOrderId}/info", [
    'version' => (int) ($o['data']['version'] ?? 0),
    'contact' => ['name' => '李女士', 'phone' => '13900139000', 'address' => '春风路8号2单元302'],
    'delivery_mode' => 'SCHEDULED',
    'expected_at' => date('Y-m-d', strtotime('+1 day')) . 'T18:30:00+08:00',
    'remark' => '改成明天晚上送',
], $customerToken);
check('改信息（无差价）', ($info['code'] ?? '') === 'OK', $info['message'] ?? '');
check('收货地址已更新', ($info['data']['contact']['address'] ?? '') === '春风路8号2单元302');

// ---------------- 配送与收款 ----------------
echo "\n[5] 履约与收款\n";

$mo = http('GET', $base . "/merchant/orders/{$createdOrderId}", null, $merchantToken);
$v = (int) ($mo['data']['version'] ?? 0);
$disp = http('POST', $base . "/merchant/orders/{$createdOrderId}/deliver", [
    'version' => $v,
], $merchantToken, uuid());
check('商家开始配送', str_contains($disp['message'] ?? '', '开始配送'), $disp['message'] ?? '');

$cancelAfter = http('POST', $base . "/customer/orders/{$createdOrderId}/cancel", [
    'version' => (int) ($disp['data']['version'] ?? 0),
], $customerToken, uuid());
check('配送后顾客取消被拒', ($cancelAfter['code'] ?? '') === 'ORDER_NOT_EDITABLE', $cancelAfter['message'] ?? '');

$mo2 = http('GET', $base . "/merchant/orders/{$createdOrderId}", null, $merchantToken);
$v2 = (int) ($mo2['data']['version'] ?? 0);
$deliv = http('POST', $base . "/merchant/orders/{$createdOrderId}/confirm-delivery", [
    'version' => $v2,
    'collected_on_delivery' => true,
    'collection_method' => 'CASH',
], $merchantToken, uuid());
check('确认送达并登记收款', str_contains($deliv['message'] ?? '', '登记收款'), $deliv['message'] ?? '');

$mo3 = http('GET', $base . "/merchant/orders/{$createdOrderId}", null, $merchantToken);
$v3 = (int) ($mo3['data']['version'] ?? 0);
$recv = http('POST', $base . "/customer/orders/{$createdOrderId}/receive", [
    'version' => $v3,
], $customerToken, uuid());
check('顾客确认收货', ($recv['code'] ?? '') === 'OK', $recv['message'] ?? '');
check('订单进入已完成', ($recv['data']['order_status'] ?? '') === 'FINISHED');

// ---------------- 拒绝场景 ----------------
echo "\n[6] 拒绝场景（服务端裁决）\n";

// 精确构造缺货：建一个只有 3 件的商品，顾客买 4 件。
// （用 99999 件会被「单次最多 99 份」这条校验先拦住，返回的是另一种拒绝码，
//   测不到真正的库存分支）
$lowGoods = http('POST', $base . '/merchant/goods', [
    'title' => 'E2E 小份量测试菜',
    'price_fen' => 100,
    'inventory' => 3,
    'inventory_unit' => '份',
    'category_id' => $freshCatId,
    'is_shelves' => true,
], $merchantToken, uuid());
$lowGoodsId = (string) ($lowGoods['data']['id'] ?? '');
$lowSku = http('GET', $base . "/merchant/goods/{$lowGoodsId}", null, $merchantToken);
$lowSkuId = (string) ($lowSku['data']['skus'][0]['sku_id'] ?? '');

$over = http('POST', $base . '/customer/orders', [
    'items' => [['sku_id' => $lowSkuId, 'qty' => 4]],
    'contact' => ['name' => '李女士', 'phone' => '13900139000', 'address' => '春风路8号'],
    'delivery_mode' => 'ASAP',
    'payment_method' => 'COD',
], $customerToken, uuid());
check('库存不足被拒', ($over['code'] ?? '') === 'OUT_OF_STOCK', $over['message'] ?? '');

$qtyCap = http('POST', $base . '/customer/orders', [
    'items' => [['sku_id' => $lowSkuId, 'qty' => 999]],
    'contact' => ['name' => '李女士', 'phone' => '13900139000', 'address' => '春风路8号'],
    'delivery_mode' => 'ASAP',
    'payment_method' => 'COD',
], $customerToken, uuid());
check('超量购买被拒', ($qtyCap['code'] ?? '') === 'VALIDATION_FAILED', $qtyCap['message'] ?? '');

if ($lowGoodsId !== '') {
    http('DELETE', $base . "/merchant/goods/{$lowGoodsId}", null, $merchantToken);
}

$early = http('POST', $base . '/customer/orders', [
    'items' => [['sku_id' => $sku, 'qty' => 1]],
    'contact' => ['name' => '李女士', 'phone' => '13900139000', 'address' => '春风路8号'],
    'delivery_mode' => 'SCHEDULED',
    'expected_at' => '2020-01-01T10:00:00+08:00',
    'payment_method' => 'COD',
], $customerToken, uuid());
check('过期预约时间被拒', ($early['code'] ?? '') === 'INVALID_EXPECTED_TIME', $early['message'] ?? '');

$restoreStoreSettings = true;
$closed = http('PATCH', $base . '/merchant/store', ['is_open' => false], $merchantToken);
check('打烊开关生效', ($closed['code'] ?? '') === 'OK', $closed['message'] ?? '');

$whileClosed = http('POST', $base . '/customer/orders', [
    'items' => [['sku_id' => $sku, 'qty' => 1]],
    'contact' => ['name' => '李女士', 'phone' => '13900139000', 'address' => '春风路8号'],
    'delivery_mode' => 'ASAP',
    'payment_method' => 'COD',
], $customerToken, uuid());
check('打烊时拒绝新下单', ($whileClosed['code'] ?? '') === 'SHOP_CLOSED', $whileClosed['message'] ?? '');

// 越权
$phone2 = '138' . str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
$reg2 = http('POST', $base . '/customer/auth/register', ['phone' => $phone2, 'password' => 'abc123456']);
$other = http('GET', $base . "/customer/orders/{$createdOrderId}", [], (string) ($reg2['data']['token'] ?? ''));
check('越权访问他人订单被拒', ($other['code'] ?? '') === 'FORBIDDEN', $other['message'] ?? '');

// ---------------- 商品与店铺 ----------------
echo "\n[7] 商品与店铺\n";

$newGoods = http('POST', $base . '/merchant/goods', [
    'title' => 'E2E 测试菜',
    'price_fen' => 199,
    'inventory' => 10,
    'inventory_unit' => '份',
    'category_id' => $freshCatId,
    'spec_text' => '约300g/份',
    'is_shelves' => true,
], $merchantToken, uuid());
$createdGoodsId = (string) ($newGoods['data']['id'] ?? '');
check('商家新增商品', $createdGoodsId !== '', $newGoods['message'] ?? '');

$shelves = http('POST', $base . "/merchant/goods/{$createdGoodsId}/shelves", ['is_shelves' => false], $merchantToken);
check('商品下架', str_contains($shelves['message'] ?? '', '下架'), $shelves['message'] ?? '');

$invisible = http('GET', $base . "/customer/catalog/goods/{$createdGoodsId}");
check('下架后顾客端不可见', ($invisible['code'] ?? '') === 'NOT_FOUND', $invisible['message'] ?? '');

$restoreStoreSettings = true;
$fee = http('PATCH', $base . '/merchant/store', ['delivery_fee_fen' => 300], $merchantToken);
check('修改配送费', (int) ($fee['data']['delivery_fee_fen'] ?? -1) === 300, $fee['message'] ?? '');

$dashboard = http('GET', $base . '/merchant/dashboard', null, $merchantToken);
check(
    '工作台含店铺信息与最近订单',
    ($dashboard['code'] ?? '') === 'OK'
    && is_array($dashboard['data']['store'] ?? null)
    && is_array($dashboard['data']['recent_orders'] ?? null),
    ''
);

// ---------------- 清理 ----------------
echo "\n[清理]\n";

$restoreStore();

if (!$keep && $merchantToken !== '' && $createdGoodsId !== '') {
    http('DELETE', $base . "/merchant/goods/{$createdGoodsId}", null, $merchantToken);
    echo "  已删除测试商品 #{$createdGoodsId}\n";
}

if (!$keep) {
    echo "  测试顾客 #{$createdCustomer} 与订单 #{$createdOrderId} 保留在演示库中（不影响既有数据）\n";
}

echo "\n", str_repeat('=', 60), "\n";
printf("通过 %d / %d", $pass, $pass + $fail);
echo $fail === 0 ? "  ✓ 全部通过\n" : "  ✗ 有 {$fail} 项失败\n";
exit($fail === 0 ? 0 : 1);
