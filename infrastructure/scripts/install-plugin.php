<?php

declare(strict_types=1);

/**
 * 春连鲜选 · ShopXO 插件安装器
 *
 * 用途：在没有网页后台交互的情况下，把 `chunlianfresh` 插件注册进 ShopXO。
 * 等价于在后台「应用管理」里点击安装，但不依赖浏览器与商店服务。
 *
 * 它做四件事（全部幂等，可重复执行）：
 *   1. 校验插件文件与领域层是否就位
 *   2. 在 `shopxo_plugins` 表登记插件（已存在则更新）
 *   3. 生成 `app/event.php`，把插件声明的钩子注册进 ThinkPHP 事件系统
 *   4. 执行插件 `Event::Install()`，建立 `veg_*` 扩展表
 *
 * 用法：
 *   php install-plugin.php --check     # 只校验环境，不改动任何东西
 *   php install-plugin.php --apply     # 执行安装
 *
 * 【为什么要自己生成 app/event.php】
 * ShopXO 的 `PluginsAdminService::PluginsHookDeployment()` 会读取每个已启用插件的
 * `config.json` 里的 `hook` 段，合并后写入 `app/event.php`。
 * 但那一步只在**后台点击操作**时触发。命令行安装必须自己做同一件事，
 * 否则钩子不会生效 —— 而钩子不生效的表现是「订单行快照没有写入」，
 * 直到改单功能报错才会被发现。
 */

$root = dirname(__DIR__, 2) . '/shopxo';
if (!is_dir($root)) {
    // 允许通过参数指定 ShopXO 根目录
    $root = $argv[2] ?? '';
}

if ($root === '' || !is_dir($root)) {
    fwrite(STDERR, "用法: php install-plugin.php [--check|--apply] [ShopXO 根目录]\n");
    exit(2);
}

$apply = in_array('--apply', $argv, true);
$checkOnly = in_array('--check', $argv, true) || !$apply;

$PLUGIN = 'chunlianfresh';

echo "春连鲜选 · ShopXO 插件安装器\n";
echo str_repeat('=', 60), "\n";
echo "ShopXO 根目录: {$root}\n";
echo "模式        : ", $apply ? 'APPLY（会写入）' : 'CHECK（只读校验）', "\n\n";

$fail = static function (string $msg): never {
    fwrite(STDERR, "✗ {$msg}\n");
    exit(1);
};

// ------------------------------------------------------------
// 1. 校验插件文件
// ------------------------------------------------------------
$pluginDir = $root . '/app/plugins/' . $PLUGIN;
if (!is_dir($pluginDir)) {
    $fail("插件目录不存在：{$pluginDir}");
}
echo "✓ 插件目录存在\n";

$required = ['Bootstrap.php', 'Event.php', 'Hook.php', 'config.json', 'api/Merchant.php'];
foreach ($required as $f) {
    if (!is_file($pluginDir . '/' . $f)) {
        $fail("缺少插件文件：{$f}");
    }
}
echo "✓ 插件文件齐全（", count($required), " 个必需文件）\n";

// 领域层
$extRoot = dirname($root) . '/backend-extension';
if (!is_dir($extRoot . '/src')) {
    $fail("找不到领域层：{$extRoot}/src（应把 backend-extension 与 shopxo 放在同级）");
}
echo "✓ 领域层就位：{$extRoot}/src\n";

// 迁移脚本
$migrations = glob($extRoot . '/migrations/*.sql') ?: [];
$migrations = array_values(array_filter($migrations, static fn ($f) => !str_contains($f, '_rollback')));
if ($migrations === []) {
    $fail("找不到迁移脚本：{$extRoot}/migrations");
}
echo "✓ 迁移脚本 ", count($migrations), " 个\n";

// ------------------------------------------------------------
// 2. 数据库连接
// ------------------------------------------------------------
$configFile = $root . '/config/database.php';
if (!is_file($configFile)) {
    $fail("找不到数据库配置：{$configFile}");
}

/** @var array<string,mixed> $config */
$config = require $configFile;
$default = (string) ($config['default'] ?? 'mysql');
$conn = $config['connections'][$default] ?? $config;
$prefix = (string) ($conn['prefix'] ?? 'shopxo_');

try {
    $pdo = new PDO(
        sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            (string) ($conn['hostname'] ?? '127.0.0.1'),
            (int) ($conn['hostport'] ?? 3306),
            (string) ($conn['database'] ?? '')
        ),
        (string) ($conn['username'] ?? ''),
        (string) ($conn['password'] ?? ''),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (Throwable $e) {
    $fail('数据库连接失败：' . $e->getMessage());
}
echo "✓ 数据库连接成功（表前缀 {$prefix}）\n";

if ($checkOnly) {
    echo "\n环境校验通过。加 --apply 执行安装。\n";
    exit(0);
}

// ------------------------------------------------------------
// 3. 登记插件（幂等）
// ------------------------------------------------------------
$data = json_encode([
    'base' => [
        'author' => 'chunlian',
        'version' => '1.0.0',
        'name' => '春连鲜选扩展',
        'describe' => '四维订单状态、COD 收款、订单修订与差价资金引擎、商家端 API',
    ],
], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

$stmt = $pdo->prepare("SELECT `id` FROM `{$prefix}plugins` WHERE `plugins` = :p LIMIT 1");
$stmt->execute(['p' => $PLUGIN]);
$existing = $stmt->fetchColumn();

if ($existing === false) {
    $stmt = $pdo->prepare(
        "INSERT INTO `{$prefix}plugins` "
        . "(`name`, `plugins`, `plugins_category_id`, `plugins_menu_control`, `plugins_is_second_domain`, "
        . "`data`, `is_enable`, `sort`, `add_time`, `upd_time`) "
        . "VALUES (:name, :plugins, 4, '', 0, :data, 1, 0, :t, :t)"
    );
    $stmt->execute(['name' => '春连鲜选扩展', 'plugins' => $PLUGIN, 'data' => $data, 't' => time()]);
    echo "✓ 插件已登记（id=", $pdo->lastInsertId(), "）\n";
} else {
    // 已存在则确保启用并刷新配置
    $stmt = $pdo->prepare(
        "UPDATE `{$prefix}plugins` SET `data` = :data, `is_enable` = 1, `upd_time` = :t WHERE `plugins` = :p"
    );
    $stmt->execute(['data' => $data, 't' => time(), 'p' => $PLUGIN]);
    echo "✓ 插件记录已更新并启用（id={$existing}）\n";
}

// ------------------------------------------------------------
// 4. 生成 app/event.php（钩子注册）
// ------------------------------------------------------------
$eventFile = $root . '/app/event.php';

// 保留系统级钩子（AppInit / HttpRun 等），只重建 listen 段
$systemHooks = ['AppInit', 'HttpRun', 'HttpEnd', 'RouteLoaded', 'LogRecord'];
$listen = [];
if (is_file($eventFile)) {
    $tags = require $eventFile;
    if (is_array($tags)) {
        foreach ($systemHooks as $h) {
            if (isset($tags[$h])) {
                $listen[$h] = $tags[$h];
            }
        }
    }
}

// 收集所有已启用插件的钩子声明
$enabled = $pdo->query("SELECT `plugins` FROM `{$prefix}plugins` WHERE `is_enable` = 1")->fetchAll(PDO::FETCH_COLUMN);
foreach ($enabled as $plugin) {
    $cfgFile = $root . '/app/plugins/' . $plugin . '/config.json';
    if (!is_file($cfgFile)) {
        continue;
    }
    $cfg = json_decode((string) file_get_contents($cfgFile), true);
    if (!is_array($cfg) || empty($cfg['hook']) || !is_array($cfg['hook'])) {
        continue;
    }
    foreach ($cfg['hook'] as $hookKey => $handlers) {
        $listen[$hookKey] = isset($listen[$hookKey])
            ? array_merge($listen[$hookKey], $handlers)
            : $handlers;
    }
}

$content = "<?php\n"
    . "// +----------------------------------------------------------------------\n"
    . "// | 应用行为扩展定义文件（由 install-plugin.php 生成）\n"
    . "// +----------------------------------------------------------------------\n"
    . "// | 注意：本文件由插件安装器生成，后台的「应用管理」也会重写它。\n"
    . "// | 手工改动会在下次部署插件钩子时丢失 —— 钩子请写在插件的 config.json 里。\n"
    . "// +----------------------------------------------------------------------\n\n"
    . 'return ' . var_export(['listen' => $listen], true) . ";\n";

if (file_put_contents($eventFile, $content) === false) {
    $fail("写入失败：{$eventFile}（检查目录权限）");
}
@chmod($eventFile, 0644);
echo "✓ 钩子已注册到 app/event.php（", count($listen), " 个钩子键）\n";
foreach (array_keys($listen) as $k) {
    echo "    · {$k}\n";
}

// ------------------------------------------------------------
// 5. 建立扩展表
// ------------------------------------------------------------
// 【为什么交给 mysql CLI 而不是自己拆 SQL 语句】
// 迁移文件含多行块注释、COMMENT 文本里可能出现分号、语句以空行分隔。
// 自己写拆分器必须正确处理所有这些情况，稍有遗漏就会把注释当 SQL 发出去
// （实际踩过：块注释的尾部被当成语句，MySQL 报 1064）。
// `mysql` 客户端原生支持多语句文件，是这件事的标准工具。
echo "\n执行扩展表迁移...\n";

$mysqlBin = trim((string) shell_exec('command -v mysql 2>/dev/null'));
if ($mysqlBin === '') {
    $fail('找不到 mysql 客户端，无法执行迁移');
}

foreach ($migrations as $file) {
    // 用 MYSQL_PWD 传密码，避免出现在进程列表里（ps 可见）
    $env = [
        'MYSQL_PWD' => (string) ($conn['password'] ?? ''),
        'PATH' => (string) getenv('PATH'),
    ];

    $cmd = sprintf(
        '%s --host=%s --port=%d --user=%s %s < %s',
        escapeshellcmd($mysqlBin),
        escapeshellarg((string) ($conn['hostname'] ?? '127.0.0.1')),
        (int) ($conn['hostport'] ?? 3306),
        escapeshellarg((string) ($conn['username'] ?? '')),
        escapeshellarg((string) ($conn['database'] ?? '')),
        escapeshellarg($file)
    );

    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($cmd, $descriptors, $pipes, null, $env);
    if (!is_resource($proc)) {
        $fail('无法执行迁移：' . basename($file));
    }

    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($proc);

    if ($exitCode !== 0) {
        $fail(sprintf(
            '迁移失败：%s（exit=%d）%s',
            basename($file),
            $exitCode,
            trim($stderr) !== '' ? ' → ' . trim($stderr) : ''
        ));
    }

    echo "  ✓ ", basename($file), "\n";
    if (trim($stdout) !== '') {
        echo "    ", trim($stdout), "\n";
    }
}

// ------------------------------------------------------------
// 6. 汇总
// ------------------------------------------------------------
$tables = $pdo->query(
    "SELECT `table_name` FROM information_schema.tables "
    . "WHERE `table_schema` = DATABASE() AND `table_name` LIKE 'veg\\_%' ORDER BY `table_name`"
)->fetchAll(PDO::FETCH_COLUMN);

echo "\n", str_repeat('=', 60), "\n";
echo "安装完成。扩展表 ", count($tables), " 张：\n";
foreach ($tables as $t) {
    echo "  · {$t}\n";
}
echo "\n商家端 API 入口：\n";
echo "  POST /api.php?s=/plugins/index&pluginsname={$PLUGIN}&pluginscontrol=merchant&pluginsaction=dispatch\n";
echo "  或经 Nginx 重写： /api/v1/merchant/...\n";
