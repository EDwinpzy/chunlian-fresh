<?php

declare(strict_types=1);

/**
 * 迁移执行器。
 *
 * 用法：
 *   php scripts/migrate.php --dry-run     # 预演全部正向迁移，只打印 SQL
 *   php scripts/migrate.php --apply       # 按顺序执行全部正向迁移
 *   php scripts/migrate.php --rollback    # 回滚初始建表迁移（危险，需 --force）
 *
 * ⚠️  执行前必须已完成数据库备份。
 *     本脚本不会自动备份，也不会自动确认表结构兼容性。
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "仅允许命令行执行\n");
    exit(1);
}

$root = dirname(__DIR__);
$options = parseOptions($argv);

$mode = $options['apply'] ?? false ? 'apply'
    : ($options['rollback'] ?? false ? 'rollback' : 'dry-run');

echo "=== 春连鲜选 迁移执行器 ===\n";
echo "模式: {$mode}\n\n";

if ($mode === 'rollback' && !($options['force'] ?? false)) {
    echo "回滚是破坏性操作，必须显式加 --force。\n";
    echo "提示：回滚前请先归档 veg_payment_ledger 与 veg_order_revision。\n";
    exit(0);
}

$migrationFiles = $mode === 'rollback'
    ? ["{$root}/migrations/2026_10_12_000001_create_veg_tables_rollback.sql"]
    : array_values(array_filter(
        glob("{$root}/migrations/*.sql") ?: [],
        static fn (string $path): bool => !str_contains($path, '_rollback')
    ));
sort($migrationFiles);

if ($migrationFiles === [] || array_filter($migrationFiles, static fn (string $path): bool => !is_file($path)) !== []) {
    fwrite(STDERR, "找不到迁移文件。\n");
    exit(1);
}

$statementsByFile = [];
$statementCount = 0;
foreach ($migrationFiles as $file) {
    $statementsByFile[$file] = splitStatements((string) file_get_contents($file));
    $statementCount += count($statementsByFile[$file]);
}

echo "迁移文件数: " . count($migrationFiles) . "\n";
foreach ($migrationFiles as $file) {
    echo "  - " . basename($file) . " (" . count($statementsByFile[$file]) . " 条语句)\n";
}
echo "语句总数: {$statementCount}\n\n";

if ($mode === 'dry-run') {
    echo "--- 预演（未连接数据库）---\n";
    $sequence = 0;
    foreach ($migrationFiles as $file) {
        echo "\n" . basename($file) . "\n";
        foreach ($statementsByFile[$file] as $stmt) {
            echo sprintf("[%02d] %s\n", ++$sequence, firstLine($stmt));
        }
    }
    echo "\n预演结束。确认无误后使用 --apply 正式执行。\n";
    exit(0);
}

// 连接数据库（凭据来自环境变量）
$host = getenv('DB_HOST') ?: '127.0.0.1';
$port = (int) (getenv('DB_PORT') ?: 3306);
$name = getenv('DB_NAME') ?: '';
$user = getenv('DB_USER') ?: '';
$pass = getenv('DB_PASS') ?: '';
$prefix = getenv('DB_PREFIX') ?: 'shopxo_';

if (preg_match('/^[a-zA-Z0-9_]*$/', $prefix) !== 1) {
    fwrite(STDERR, "DB_PREFIX 只能包含英文字母、数字和下划线。\n");
    exit(1);
}

if ($name === '' || $user === '') {
    fwrite(STDERR, "缺少 DB_NAME / DB_USER 环境变量。请先 source infrastructure/.env\n");
    exit(1);
}

$dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    fwrite(STDERR, "数据库连接失败: " . $e->getMessage() . "\n");
    exit(1);
}

$pdo->exec('SET @cl_shopxo_prefix = ' . $pdo->quote($prefix));

echo "已连接 {$host}:{$port}/{$name}\n";
echo "ShopXO 表前缀: {$prefix}（迁移脚本使用独立 veg_ 前缀，不受影响）\n\n";

$ok = 0;
$failed = 0;
$sequence = 0;

foreach ($migrationFiles as $file) {
    echo "\n" . basename($file) . "\n";
    foreach ($statementsByFile[$file] as $stmt) {
        $label = sprintf('[%02d] %s', ++$sequence, firstLine($stmt));
        try {
            $pdo->exec($stmt);
            echo "✓ {$label}\n";
            $ok++;
        } catch (PDOException $e) {
            echo "✗ {$label}\n    错误: " . $e->getMessage() . "\n";
            $failed++;
            if ($mode === 'apply') {
                fwrite(STDERR, "\n迁移中断。请检查错误并恢复备份后重试。\n");
                exit(1);
            }
        }
    }
}

echo "\n完成: 成功 {$ok} / 失败 {$failed}\n";

if ($mode === 'apply' && $failed === 0) {
    echo "\n后续步骤：\n";
    echo "  1. 清理 ShopXO 缓存\n";
    echo "  2. 运行健康检查：php scripts/health-check.php\n";
    echo "  3. 部署扩展代码并确认路由生效\n";
}

function parseOptions(array $argv): array
{
    $options = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (str_starts_with($arg, '--')) {
            $options[substr($arg, 2)] = true;
        }
    }

    return $options;
}

/** 按分号拆分语句，跳过引号与注释内的分号。 */
function splitStatements(string $sql): array
{
    $statements = [];
    $buffer = '';
    $inSingle = false;
    $inDouble = false;
    $inBacktick = false;
    $inLineComment = false;
    $inBlockComment = false;
    $length = strlen($sql);

    for ($i = 0; $i < $length; $i++) {
        $char = $sql[$i];
        $next = $i + 1 < $length ? $sql[$i + 1] : '';

        if ($inLineComment) {
            if ($char === "\n") {
                $inLineComment = false;
                $buffer .= $char;
            }
            continue;
        }

        if ($inBlockComment) {
            if ($char === '*' && $next === '/') {
                $inBlockComment = false;
                $i++;
            }
            continue;
        }

        if (!$inSingle && !$inDouble && !$inBacktick) {
            if ($char === '-' && $next === '-') {
                $inLineComment = true;
                $i++;
                continue;
            }
            if ($char === '#') {
                $inLineComment = true;
                continue;
            }
            if ($char === '/' && $next === '*') {
                $inBlockComment = true;
                $i++;
                continue;
            }
        }

        if ($char === "'" && !$inDouble && !$inBacktick) {
            $inSingle = !$inSingle;
        } elseif ($char === '"' && !$inSingle && !$inBacktick) {
            $inDouble = !$inDouble;
        } elseif ($char === '`' && !$inSingle && !$inDouble) {
            $inBacktick = !$inBacktick;
        }

        if ($char === ';' && !$inSingle && !$inDouble && !$inBacktick) {
            $trimmed = trim($buffer);
            if ($trimmed !== '') {
                $statements[] = $trimmed;
            }
            $buffer = '';
            continue;
        }

        $buffer .= $char;
    }

    $trimmed = trim($buffer);
    if ($trimmed !== '') {
        $statements[] = $trimmed;
    }

    return $statements;
}

function firstLine(string $sql): string
{
    $line = strtok($sql, "\n") ?: '';

    return strlen($line) > 110 ? substr($line, 0, 107) . '...' : $line;
}
