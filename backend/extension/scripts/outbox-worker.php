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
 * 职责：支付核对、退款查询、商家新订单系统通知。
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
$getuiConfig = is_array($config['getui'] ?? null) ? $config['getui'] : [];
$shopxoTablePrefix = getenv('DB_PREFIX') ?: 'shopxo_';
if (preg_match('/^[a-zA-Z0-9_]*$/', $shopxoTablePrefix) !== 1) {
    fwrite(STDERR, "DB_PREFIX 只能包含英文字母、数字和下划线。\n");
    exit(1);
}

require_once dirname(__DIR__) . '/src/Repository/PdoPushDeviceRepository.php';
require_once dirname(__DIR__) . '/src/Service/PdoOutboxWorkerLease.php';
require_once dirname(__DIR__) . '/src/Service/GetuiRestV2Client.php';
require_once dirname(__DIR__) . '/src/Service/MerchantPushNotificationService.php';

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
$workerLockName = 'chunlian_veg_outbox_worker';
$lockStmt = $pdo->prepare('SELECT GET_LOCK(?, 0)');
$lockStmt->execute([$workerLockName]);
if ((int) $lockStmt->fetchColumn() !== 1) {
    echo "已有 Outbox Worker 正在运行，本进程退出\n";
    exit(0);
}

try {
    do {
        $processed = processBatch($pdo, $maxRetry, $backoffBase, $backoffCap, $getuiConfig, $shopxoTablePrefix);

        if ($processed === 0) {
            echo "[" . now() . "] 无待处理事件\n";
        }

        if ($mode === 'once') {
            $running = false;
        } else {
            sleep(5);
        }
    } while ($running);
} finally {
    $releaseStmt = $pdo->prepare('SELECT RELEASE_LOCK(?)');
    $releaseStmt->execute([$workerLockName]);
}

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

function processBatch(
    PDO $pdo,
    int $maxRetry,
    int $backoffBase,
    int $backoffCap,
    array $getuiConfig,
    string $shopxoTablePrefix,
): int
{
    $lease = new Chunlian\Service\PdoOutboxWorkerLease(
        $pdo,
        bin2hex(random_bytes(16)),
        Chunlian\Service\PdoOutboxWorkerLease::DEFAULT_LEASE_SECONDS,
        $maxRetry,
        $backoffBase,
        $backoffCap,
    );
    $processed = 0;

    // Claim and finish one event at a time. The MySQL named lock serializes
    // normal worker runs; the per-row compare-and-set also protects against a
    // lost connection, stale lock, or another consumer that does not use it.
    while ($processed < 20 && ($event = $lease->claimNext()) !== null) {
        $id = (int) $event['id'];
        $type = (string) $event['event_type'];
        $retry = (int) $event['retry_count'];

        try {
            handleEvent($pdo, $type, $event, $getuiConfig, $shopxoTablePrefix, $lease);

            if (!$lease->markSent($id)) {
                echo '[' . now() . "] #{$id} {$type} lease 已失效，跳过旧 worker 的状态更新\n";
            } else {
                echo '[' . now() . "] ✓ #{$id} {$type}\n";
            }
        } catch (\Throwable $e) {
            $retry++;
            $message = substr($e->getMessage(), 0, 480);
            $delay = min($backoffCap, $backoffBase * $retry);
            $updated = $lease->markFailed($id, $retry, $maxRetry, $delay, $message);

            if (!$updated) {
                echo '[' . now() . "] #{$id} {$type} lease 已失效，未覆盖新 worker 的状态\n";
            } elseif ($retry >= $maxRetry) {
                echo '[' . now() . "] ✗ #{$id} {$type} → DEAD（需人工处理）：{$message}\n";
            } else {
                echo '[' . now() . "] ↻ #{$id} {$type} 失败，{$delay}s 后重试（第 {$retry} 次）：{$message}\n";
            }
        }

        $processed++;
    }

    if ($processed === 0) {
        // 同时检查 DEAD 积压
        $dead = (int) $pdo->query("SELECT COUNT(*) FROM veg_outbox_event WHERE status = 'DEAD'")->fetchColumn();
        if ($dead > 0) {
            echo "[" . now() . "] ⚠ 存在 {$dead} 条 DEAD 事件，需人工处理\n";
        }

        return 0;
    }

    return $processed;
}

/**
 * 事件分发。
 *
 * 支付核验与退款查询仍需接入对应商户配置；ORDER_CREATED 已通过 GeTui
 * 实际发送。推送配置或调用失败会抛出异常，由批处理逻辑退避重试并在
 * 超限后标记 DEAD，不伪造成功。
 */
function handleEvent(
    PDO $pdo,
    string $type,
    array $event,
    array $getuiConfig,
    string $shopxoTablePrefix,
    Chunlian\Service\PdoOutboxWorkerLease $lease,
): void
{
    $decodedPayload = json_decode((string) $event['payload'], true);
    $payload = is_array($decodedPayload) ? $decodedPayload : [];

    switch ($type) {
        case 'PAYMENT_VERIFY':
            // TODO(接入微信支付后实现): 调用查询订单 API，验签后幂等更新
            // veg_payment_ledger.status = SUCCESS，并置订单 payment_state = PAID
            throw new \RuntimeException('PAYMENT_VERIFY 未实现：需接入微信支付查询 API');

        case 'REFUND_QUERY':
            // TODO: 查询退款结果，更新 veg_payment_ledger
            throw new \RuntimeException('REFUND_QUERY 未实现：需接入微信退款查询 API');

        case 'ORDER_CREATED':
            if (!is_array($decodedPayload)) {
                throw new \RuntimeException('ORDER_CREATED payload must be a JSON object');
            }
            $workerPush = $payload['_worker_push'] ?? [];
            if (!is_array($workerPush)) {
                throw new \RuntimeException('ORDER_CREATED push progress is malformed');
            }
            $pushResults = $workerPush['merchant_cid_results'] ?? [];
            if (!is_array($pushResults)) {
                throw new \RuntimeException('ORDER_CREATED push progress is malformed');
            }

            $eventId = (int) ($event['id'] ?? 0);
            $checkpoint = static function (string $cid, string $status) use (&$payload, &$workerPush, $eventId, $lease): void {
                $workerPush['merchant_cid_results'][hash('sha256', $cid)] = $status;
                $payload['_worker_push'] = $workerPush;
                $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                if (!$lease->savePayload($eventId, $encoded)) {
                    throw new \RuntimeException('Outbox worker lease expired while saving GeTui CID progress');
                }
            };
            $renew = static function () use ($eventId, $lease): void {
                if (!$lease->renew($eventId)) {
                    throw new \RuntimeException('Outbox worker lease expired before GeTui push');
                }
            };

            $recipients = (new Chunlian\Service\MerchantPushNotificationService($pdo, $getuiConfig))
                ->dispatchOrderCreated(
                    $eventId,
                    (string) ($event['entity_id'] ?? ''),
                    $shopxoTablePrefix,
                    $pushResults,
                    $checkpoint,
                    $renew,
                );
            if ($recipients === 0) {
                echo '[' . now() . "] #" . (int) ($event['id'] ?? 0) . " ORDER_CREATED: no active merchant push devices; skipped\n";
            }

            return;

        case 'ORDER_REVISED':
        case 'ORDER_CANCELLED':
            // These event types remain in the in-app feed; only ORDER_CREATED has
            // a configured system-notification recipient policy in this rollout.
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
