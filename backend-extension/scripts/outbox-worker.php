<?php

declare(strict_types=1);

/**
 * Outbox 事件消费者（单 worker）。
 *
 * 用法：
 *   php scripts/outbox-worker.php --once        # 处理一批后退出（推荐 cron 每分钟调用）
 *   php scripts/outbox-worker.php --loop        # 常驻循环
 *   php scripts/outbox-worker.php --status      # 只打印积压状态
 *
 * 职责：支付核对、退款查询、App 提醒重试。
 *
 * ⚠️  方案 §8.1 / §13.3：禁止在 HTTP 请求内做长耗时外部调用或无限重试。
 *     本 worker 用指数退避 + 最大重试次数，超限标记 DEAD 进入人工处理。
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "仅允许命令行执行\n");
    exit(1);
}

$config = require dirname(__DIR__) . '/config/extension.php';
$maxRetry = (int) $config['outbox']['max_retry'];
$backoffBase = (int) $config['outbox']['backoff_base_seconds'];
$backoffCap = (int) $config['outbox']['backoff_cap_seconds'];

$options = [];
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--')) {
        $options[substr($arg, 2)] = true;
    }
}

$mode = $options['loop'] ?? false ? 'loop' : ($options['status'] ?? false ? 'status' : 'once');

echo "=== 春连鲜选 Outbox Worker ===\n";
echo "模式: {$mode} | 最大重试: {$maxRetry}\n\n";

$pdo = connect();
if ($pdo === null) {
    exit(1);
}

if ($mode === 'status') {
    printStatus($pdo);
    exit(0);
}

$running = true;

do {
    $processed = processBatch($pdo, $maxRetry, $backoffBase, $backoffCap);

    if ($processed === 0) {
        echo "[" . now() . "] 无待处理事件\n";
    }

    if ($mode === 'once') {
        $running = false;
    } else {
        sleep(5);
    }
} while ($running);

function connect(): ?PDO
{
    $host = getenv('DB_HOST') ?: '127.0.0.1';
    $port = (int) (getenv('DB_PORT') ?: 3306);
    $name = getenv('DB_NAME') ?: '';
    $user = getenv('DB_USER') ?: '';
    $pass = getenv('DB_PASS') ?: '';

    if ($name === '' || $user === '') {
        fwrite(STDERR, "缺少 DB_NAME / DB_USER 环境变量\n");

        return null;
    }

    try {
        return new PDO(
            "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
            $user,
            $pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    } catch (PDOException $e) {
        fwrite(STDERR, "数据库连接失败: " . $e->getMessage() . "\n");

        return null;
    }
}

function processBatch(PDO $pdo, int $maxRetry, int $backoffBase, int $backoffCap): int
{
    // 抢占一批事件（避免多 worker 重复处理）
    $stmt = $pdo->prepare(
        "SELECT id, event_type, entity_id, payload, retry_count
         FROM veg_outbox_event
         WHERE status = 'PENDING'
           AND (next_retry_at IS NULL OR next_retry_at <= NOW())
         ORDER BY id ASC
         LIMIT 20"
    );
    $stmt->execute();
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($events === []) {
        // 同时检查 DEAD 积压
        $dead = (int) $pdo->query("SELECT COUNT(*) FROM veg_outbox_event WHERE status = 'DEAD'")->fetchColumn();
        if ($dead > 0) {
            echo "[" . now() . "] ⚠ 存在 {$dead} 条 DEAD 事件，需人工处理\n";
        }

        return 0;
    }

    $processed = 0;

    foreach ($events as $event) {
        $id = (int) $event['id'];
        $type = (string) $event['event_type'];
        $retry = (int) $event['retry_count'];

        try {
            handleEvent($pdo, $type, $event);

            $pdo->prepare(
                "UPDATE veg_outbox_event SET status='SENT', sent_at=NOW(), updated_at=NOW(), last_error=NULL WHERE id=?"
            )->execute([$id]);

            echo "[" . now() . "] ✓ #{$id} {$type}\n";
            $processed++;
        } catch (\Throwable $e) {
            $retry++;
            $message = substr($e->getMessage(), 0, 480);

            if ($retry >= $maxRetry) {
                $pdo->prepare(
                    "UPDATE veg_outbox_event SET status='DEAD', retry_count=?, last_error=?, updated_at=NOW() WHERE id=?"
                )->execute([$retry, $message, $id]);

                echo "[" . now() . "] ✗ #{$id} {$type} → DEAD（需人工处理）：{$message}\n";
            } else {
                // 指数退避
                $delay = min($backoffCap, $backoffBase * $retry);
                $pdo->prepare(
                    "UPDATE veg_outbox_event
                     SET status='PENDING', retry_count=?, last_error=?, next_retry_at=DATE_ADD(NOW(), INTERVAL ? SECOND), updated_at=NOW()
                     WHERE id=?"
                )->execute([$retry, $message, $delay, $id]);

                echo "[" . now() . "] ↻ #{$id} {$type} 失败，{$delay}s 后重试（第 {$retry} 次）：{$message}\n";
            }
            $processed++;
        }
    }

    return $processed;
}

/**
 * 事件分发。
 *
 * 具体业务实现（调用微信查单/退款查询/推送）需在接入真实商户配置后完成。
 * 这里只保留分发骨架与告警路径，**不伪造成功**。
 */
function handleEvent(PDO $pdo, string $type, array $event): void
{
    $payload = json_decode((string) $event['payload'], true) ?: [];

    switch ($type) {
        case 'PAYMENT_VERIFY':
            // TODO(接入微信支付后实现): 调用查询订单 API，验签后幂等更新
            // veg_payment_ledger.status = SUCCESS，并置订单 payment_state = PAID
            throw new \RuntimeException('PAYMENT_VERIFY 未实现：需接入微信支付查询 API');

        case 'REFUND_QUERY':
            // TODO: 查询退款结果，更新 veg_payment_ledger
            throw new \RuntimeException('REFUND_QUERY 未实现：需接入微信退款查询 API');

        case 'ORDER_CREATED':
        case 'ORDER_REVISED':
        case 'ORDER_CANCELLED':
            // App 提醒：写入通知表 / 触发推送。首期依赖 App 前台轮询兜底。
            // 这里不视为失败，避免因未接入推送而无限重试。
            return;

        case 'REFUND_NEEDS_ATTENTION':
            // 退款异常：标记订单/流水需人工处理，并在 App 售后页展示。
            $orderId = $event['entity_id'] ?? null;
            if ($orderId !== null) {
                $pdo->prepare(
                    "INSERT INTO veg_operation_log (actor_type, actor_id, order_id, action, detail_json, created_at)
                     VALUES ('SYSTEM', NULL, ?, 'REFUND_NEEDS_ATTENTION', ?, NOW())"
                )->execute([$orderId, json_encode($payload, JSON_UNESCAPED_UNICODE)]);
            }

            return;

        default:
            throw new \RuntimeException("未知事件类型: {$type}");
    }
}

function printStatus(PDO $pdo): void
{
    $rows = $pdo->query(
        "SELECT status, COUNT(*) AS c FROM veg_outbox_event GROUP BY status"
    )->fetchAll(PDO::FETCH_ASSOC);

    echo "事件积压：\n";
    if ($rows === []) {
        echo "  （空）\n";
    }
    foreach ($rows as $row) {
        echo sprintf("  %-10s %d\n", $row['status'], $row['c']);
    }

    $oldest = $pdo->query(
        "SELECT MIN(created_at) FROM veg_outbox_event WHERE status='PENDING'"
    )->fetchColumn();

    if ($oldest) {
        echo "\n最旧待处理事件: {$oldest}\n";
    }
}

function now(): string
{
    return date('Y-m-d H:i:s');
}
