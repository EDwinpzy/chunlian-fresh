<?php

declare(strict_types=1);

/**
 * 春连鲜选 —— ShopXO 6.9.2 命令行安装器。
 *
 * 用途：当 8095 端口无法从公网访问（运营商未转发）时，
 *       无法使用 install.php 网页向导。本脚本直接落地
 *       网页向导的等价操作，使系统安装完成、可随时服务。
 *
 * 严格镜像 app/install/controller/Index.php 的行为：
 *   1. Confirm()  → 生成 config/database.php
 *   2. Add()      → 导入 config/shopxo.sql（前缀替换）
 *                → 更新管理员账号密码（md5(salt+pwd)）
 *                → 更新加密串
 *
 * 密码算法已验证（app/common.php:5073）：
 *   LoginPwdEncryption($pwd,$salt) === md5($salt . trim($pwd))
 *   验证依据：官方默认行 salt=577975, login_pwd=1f6d168f...
 *             对应明文 "shopxo" —— 已实测比对通过。
 *
 * 用法：
 *   php install-cli.php --write-config      # 仅生成配置文件
 *   php install-cli.php --apply             # 建表 + 配置 + 管理员
 *
 * ⚠️  执行前请确认数据库为空。已安装时（database.php 存在）会拒绝。
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "仅允许命令行执行\n");
    exit(1);
}

$ROOT = '/var/www/chunlian/shopxo';
$CONFIG_DB = $ROOT . '/config/database.php';

$opts = [];
foreach (array_slice($argv, 1) as $a) {
    if (str_starts_with($a, '--')) {
        $opts[substr($a, 2)] = true;
    }
}

$apply = isset($opts['apply']);
$writeConfigOnly = isset($opts['write-config']);

if (!$apply && !$writeConfigOnly) {
    echo "用法：php install-cli.php --write-config | --apply\n";
    exit(0);
}

// ---- 参数（与网页向导表单同名字段）----
$params = [
    'DB_TYPE'        => 'mysql',
    'DB_CHARSET'     => 'utf8mb4',
    'DB_HOST'        => getenv('DB_HOST') ?: '127.0.0.1',
    'DB_PORT'        => getenv('DB_PORT') ?: '3306',
    'DB_NAME'        => getenv('DB_NAME') ?: '',
    'DB_USER'        => getenv('DB_USER') ?: '',
    'DB_PWD'         => getenv('DB_PASS') ?: '',
    'DB_PREFIX'      => getenv('DB_PREFIX') ?: 'shopxo_',
    'ADMIN_USERNAME' => getenv('ADMIN_USERNAME') ?: 'admin',
    'ADMIN_PWD'      => getenv('ADMIN_PWD') ?: '',
];

echo "=== 春连鲜选 ShopXO 命令行安装 ===\n";
echo "模式: " . ($apply ? 'apply（完整安装）' : 'write-config（仅写配置）') . "\n\n";

// ---- 前置校验 ----
if (file_exists($CONFIG_DB)) {
    fwrite(STDERR, "✗ 已存在 config/database.php —— ShopXO 已安装。\n");
    fwrite(STDERR, "  如需重装，请先备份并删除该文件，并清空数据库。\n");
    exit(1);
}

foreach (['DB_NAME', 'DB_USER', 'DB_PWD'] as $k) {
    if ($params[$k] === '') {
        fwrite(STDERR, "✗ 缺少参数 {$k}\n");
        exit(1);
    }
}

if ($apply && $params['ADMIN_PWD'] === '') {
    fwrite(STDERR, "✗ apply 模式必须提供 ADMIN_PWD\n");
    exit(1);
}

// ---- 连接数据库 ----
$dsn = sprintf(
    'mysql:host=%s;port=%s;dbname=%s;charset=%s',
    $params['DB_HOST'], $params['DB_PORT'], $params['DB_NAME'], $params['DB_CHARSET']
);

try {
    $pdo = new PDO($dsn, $params['DB_USER'], $params['DB_PWD'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    fwrite(STDERR, "✗ 数据库连接失败: " . $e->getMessage() . "\n");
    exit(1);
}

echo "✓ 数据库连接成功\n";

$ver = $pdo->query('SELECT VERSION() AS v')->fetch()['v'];
if (version_compare($ver, '5.6', '<')) {
    fwrite(STDERR, "✗ MySQL 版本过低: {$ver}（utf8mb4 需 >= 5.6）\n");
    exit(1);
}
echo "✓ MySQL {$ver}\n\n";

// ---- 生成 config/database.php（严格照拄官方模板）----
function renderDatabaseConfig(array $p): string
{
    return <<<PHP
<?php
// +----------------------------------------------------------------------
// | ShopXO 国内领先企业级B2C免费开源电商系统
// +----------------------------------------------------------------------
// | Copyright (c) 2011~2099 http://shopxo.net All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( https://opensource.org/licenses/mit-license.php )
// +----------------------------------------------------------------------
// | Author: Devil
// +----------------------------------------------------------------------

// +----------------------------------------------------------------------
// | 数据库配置（由 install-cli.php 生成）
// +----------------------------------------------------------------------
return [
    'default'         => 'mysql',
    'time_query_rule' => [],
    'auto_timestamp'  => true,
    'datetime_format' => 'Y-m-d H:i:s',
    'connections'     => [
        'mysql' => [
            'type'            => '{$p['DB_TYPE']}',
            'hostname'        => '{$p['DB_HOST']}',
            'database'        => '{$p['DB_NAME']}',
            'username'        => '{$p['DB_USER']}',
            'password'        => '{$p['DB_PWD']}',
            'hostport'        => '{$p['DB_PORT']}',
            'params'          => [
                \PDO::ATTR_CASE => \PDO::CASE_LOWER,
                \PDO::ATTR_EMULATE_PREPARES => true,
            ],
            'charset'         => '{$p['DB_CHARSET']}',
            'prefix'          => '{$p['DB_PREFIX']}',
            'deploy'          => 0,
            'rw_separate'     => false,
            'master_num'      => 1,
            'slave_no'        => '',
            'fields_strict'   => true,
            'break_reconnect' => false,
            'trigger_sql'     => false,
            'fields_cache'    => false,
        ]
    ]
];
PHP;
}

$dbStr = renderDatabaseConfig($params);

if ($writeConfigOnly) {
    if (@file_put_contents($CONFIG_DB, $dbStr) === false) {
        fwrite(STDERR, "✗ 写入 {$CONFIG_DB} 失败（权限？）\n");
        exit(1);
    }
    echo "✓ 已生成 config/database.php\n";
    exit(0);
}

// ---- apply：导入表结构 ----
$sqlFile = $ROOT . '/config/shopxo.sql';
if (!is_file($sqlFile)) {
    fwrite(STDERR, "✗ 找不到 {$sqlFile}\n");
    exit(1);
}

$sql = (string) file_get_contents($sqlFile);

// 表前缀替换（正同官方逻辑）
if ($params['DB_PREFIX'] !== 'sxo_') {
    $sql = str_replace("`sxo_", " `{$params['DB_PREFIX']}", $sql);
}

$statements = preg_split("/;[\r\n]+/", $sql);
$success = 0;
$failure = 0;
$errors = [];

foreach ($statements as $stmt) {
    $stmt = trim($stmt);
    if ($stmt === '') {
        continue;
    }
    try {
        $pdo->exec($stmt);
        $success++;
    } catch (PDOException $e) {
        $failure++;
        if (count($errors) < 5) {
            $errors[] = substr(preg_replace('/\s+/', ' ', $stmt), 0, 100) . ' → ' . $e->getMessage();
        }
    }
}

echo "SQL 执行: 成功 {$success} 条, 失败 {$failure} 条\n";

if ($failure > 0) {
    echo "\n前几条错误：\n";
    foreach ($errors as $e) {
        echo "  - {$e}\n";
    }
    fwrite(STDERR, "\n✗ 存在失败语句，中止。请检查后重试。\n");
    exit(1);
}

// ---- 更新管理员账号 ----
// 已验证算法（app/common.php:5073）：md5($salt . trim($pwd))
$salt = (string) random_int(100000, 999999);
$pwdHash = md5($salt . trim($params['ADMIN_PWD']));

$stmt = $pdo->prepare(
    "UPDATE `{$params['DB_PREFIX']}admin`
     SET username = ?, login_pwd = ?, login_salt = ?
     WHERE id = 1"
);
$stmt->execute([$params['ADMIN_USERNAME'], $pwdHash, $salt]);
echo "✓ 管理员已设置: {$params['ADMIN_USERNAME']}（影响 {$stmt->rowCount()} 行）\n";

// ---- 自检：用相同算法验证刚写入的密码 ----
$row = $pdo->query(
    "SELECT username, login_pwd, login_salt FROM `{$params['DB_PREFIX']}admin` WHERE id = 1"
)->fetch();
if ($row && md5($row['login_salt'] . trim($params['ADMIN_PWD'])) === $row['login_pwd']) {
    echo "  ✓ 密码哈希自检通过（回读验证）\n";
} else {
    fwrite(STDERR, "  ✗ 密码哈希自检失败！请勿使用当前凭据。\n");
    exit(1);
}

// ---- 更新加密串 ----
$secret = md5((string) (time() + random_int(100, 99999)));
$stmt = $pdo->prepare(
    "UPDATE `{$params['DB_PREFIX']}config`
     SET value = ?
     WHERE only_tag = 'common_data_encryption_secret'"
);
$stmt->execute([$secret]);
echo "✓ 加密串已更新（影响 {$stmt->rowCount()} 行）\n";

// ---- 写配置文件 ----
if (@file_put_contents($CONFIG_DB, $dbStr) === false) {
    fwrite(STDERR, "✗ 写入 config/database.php 失败\n");
    exit(1);
}
@chmod($CONFIG_DB, 0644);
echo "✓ 已生成 config/database.php\n";

// ---- 汇总 ----
$count = (int) $pdo->query(
    "SELECT COUNT(*) FROM information_schema.tables
     WHERE table_schema = DATABASE()"
)->fetchColumn();

echo "\n" . str_repeat('-', 44) . "\n";
echo "✓ ShopXO 安装完成\n";
echo "  库内表数 : {$count}\n";
echo "  表前缀   : {$params['DB_PREFIX']}\n";
echo "  管理员   : " . ($row['username'] ?? '(未找到)') . "\n";
echo "\n注意：安装后请重命名 admin.php 为不可猜测的文件名（官方安全实践）。\n";
