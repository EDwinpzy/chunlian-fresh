<?php

declare(strict_types=1);

/**
 * 扩展层健康检查。
 *
 * 用法：php scripts/health-check.php
 *
 * 检查项：
 *   1. 数据库连通性
 *   2. veg_* 扩展表是否齐备
 *   3. 关键唯一索引是否存在（资金安全依赖）
 *   4. outbox 积压与 DEAD 事件
 *   5. 未结补款修订是否超时
 *   6. COD 应收挂账情况
 *
 * 退出码：0 = 全部通过，1 = 存在失败项。
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "仅允许命令行执行\n");
    exit(1);
}

$host = getenv('DB_HOST') ?: '127.0.0.1';
$port = (int) (getenv('DB_PORT') ?: 3306);
$name = getenv('DB_NAME') ?: '';
$user = getenv('DB_USER') ?: '';
$pass = getenv('DB_PASS') ?: '';

echo "=== 春连鲜选 健康检查 ===\n";
echo date('Y-m-d H:i:s') . "\n\n";

if ($name === '' || $user === '') {
    fwrite(STDERR, "缺少 DB_NAME / DB_USER 环境变量\n");
    exit(1);
}

try {
    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    fwrite(STDERR, "✗ 数据库连接失败: " . $e->getMessage() . "\n");
    exit(1);
}

$failures = 0;

// ---- 1. 表存在性 ----
$requiredTables = [
    'veg_order_delivery',
    'veg_order_revision',
    'veg_payment_ledger',
    'veg_operation_log',
    'veg_outbox_event',
    'veg_merchant_session',
    'veg_idempotency',
];

echo "[1] 扩展表\n";
foreach ($requiredTables as $table) {
    $exists = (bool) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = '{$table}'"
    )->fetchColumn();

    if ($exists) {
        echo "  ✓ {$table}\n";
    } else {
        echo "  ✗ {$table} 缺失\n";
        $failures++;
    }
}

// ---- 2. 关键唯一索引 ----
$requiredIndexes = [
    ['veg_payment_ledger', 'uk_business_no'],
    ['veg_payment_ledger', 'uk_idem_key'],
    ['veg_payment_ledger', 'uk_provider_trade'],
    ['veg_order_revision', 'uk_order_to_version'],
    ['veg_idempotency', 'uk_scope_key'],
];

echo "\n[2] 资金安全唯一索引\n";
foreach ($requiredIndexes as [$table, $index]) {
    $exists = (bool) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.statistics
         WHERE table_schema = DATABASE() AND table_name = '{$table}' AND index_name = '{$index}'"
    )->fetchColumn();

    if ($exists) {
        echo "  ✓ {$table}.{$index}\n";
    } else {
        echo "  ✗ {$table}.{$index} 缺失（资金重复入账风险）\n";
        $failures++;
    }
}

// ---- 3. outbox 积压 ----
echo "\n[3] 异步任务\n";
$pending = (int) $pdo->query(
    "SELECT COUNT(*) FROM veg_outbox_event WHERE status = 'PENDING'"
)->fetchColumn();
$dead = (int) $pdo->query(
    "SELECT COUNT(*) FROM veg_outbox_event WHERE status = 'DEAD'"
)->fetchColumn();

echo "  待处理: {$pending}\n";
if ($dead > 0) {
    echo "  ✗ DEAD 事件: {$dead}（需人工处理）\n";
    $failures++;
} else {
    echo "  ✓ DEAD 事件: 0\n";
}

// ---- 4. 超时未结补款 ----
$stale = (int) $pdo->query(
    "SELECT COUNT(*) FROM veg_order_revision
     WHERE status = 'PENDING_TOPUP'
       AND created_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE)"
)->fetchColumn();

echo "\n[4] 超时未结补款修订: {$stale}\n";
if ($stale > 0) {
    echo "  ⚠ 存在超时未结补款，应撤销并归还预占库存\n";
}

// ---- 5. COD 应收挂账 ----
// 说明：仅计数，不做金额汇总。
//   veg_* 扩展表**不冗余存订单金额**，订单总额属于 ShopXO 原生表；
//   在未完成 ShopXO 源码审计前，健康检查不 JOIN 未核实的官方表结构。
//   应收金额以 veg_payment_ledger 与订单总额差额为准，由对账接口负责。
$receivable = $pdo->query(
    "SELECT COUNT(*) AS c
     FROM veg_order_delivery d
     WHERE d.payment_method = 'COD' AND d.fulfillment = 'DELIVERED' AND d.payment_state = 'UNPAID'"
)->fetch();

echo "\n[5] COD 待收款挂账: {$receivable['c']} 单\n";
if ((int) $receivable['c'] > 0) {
    echo "  ⚠ 待收款总额需以账本为准（见 veg_payment_ledger），此处仅计数\n";
}

// ---- 6. 退款处理中 ----
$refunding = (int) $pdo->query(
    "SELECT COUNT(*) FROM veg_order_delivery WHERE payment_state = 'REFUNDING'"
)->fetchColumn();
$failedRefunds = (int) $pdo->query(
    "SELECT COUNT(*) FROM veg_payment_ledger WHERE kind = 'WX_REFUND' AND status = 'FAILED'"
)->fetchColumn();

echo "\n[6] 退款\n";
echo "  处理中: {$refunding}\n";
if ($failedRefunds > 0) {
    echo "  ✗ 退款失败流水: {$failedRefunds}（需人工处理）\n";
    $failures++;
} else {
    echo "  ✓ 退款失败流水: 0\n";
}

// ---- 汇总 ----
echo "\n" . str_repeat('-', 40) . "\n";
if ($failures === 0) {
    echo "✓ 健康检查全部通过\n";
    exit(0);
}

echo "✗ 存在 {$failures} 项失败，请处理后再继续\n";
exit(1);
