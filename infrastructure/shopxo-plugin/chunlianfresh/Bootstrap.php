<?php

declare(strict_types=1);

/**
 * 春连鲜选 ShopXO 插件 —— 引导文件
 *
 * 【职责】
 *   1. 把领域层（backend-extension）的 `Chunlian\` 命名空间注册进自动加载
 *   2. 读取环境变量，构造 PDO 连接
 *
 * 【为什么手写 spl_autoload_register 而不用 Composer】
 * 生产服务器**没有 composer CLI**（vendor/bin 下只有 pinyin 与 var-dump-server），
 * 无法执行 `composer dump-autoload`。
 * 因此不能通过改 composer.json 的 psr-4 来注册命名空间 ——
 * 那样改了也不会生效，还会让人误以为已经注册。
 *
 * 【为什么领域层不放进插件目录】
 * `backend-extension/src/` 是**零框架依赖**的纯领域层：
 * 不 import 任何 `app\` 或 `think\` 命名空间，因此可以脱离 ShopXO 单独测试。
 * ShopXO 的耦合全部收敛在插件的控制器里。
 * 若把领域层复制进插件目录，就会出现两份代码，且升级时容易只改一份。
 */

namespace app\plugins\chunlianfresh;

/**
 * 解析领域层根目录。
 *
 * 默认假设 backend-extension 与 shopxo 目录同级：
 *   /var/www/chunlian/
 *   ├── shopxo/                    ← ShopXO
 *   └── backend-extension/         ← 领域层
 *
 * 部署结构不同时，用环境变量 CHUNLIAN_EXTENSION_PATH 覆盖。
 */
function extension_root(): string
{
    static $root = null;
    if ($root !== null) {
        return $root;
    }

    $configured = getenv('CHUNLIAN_EXTENSION_PATH');
    if (is_string($configured) && $configured !== '') {
        $root = rtrim($configured, '/\\');

        return $root;
    }

    // __DIR__ = {SHOPXO_ROOT}/app/plugins/chunlianfresh
    // dirname ×3 = {SHOPXO_ROOT}
    $shopxoRoot = dirname(__DIR__, 3);
    $root = dirname($shopxoRoot) . '/backend-extension';

    return $root;
}

/**
 * 注册 `Chunlian\` 命名空间的 PSR-4 自动加载。
 * 重复调用安全（只注册一次）。
 */
function register_autoloader(): void
{
    static $registered = false;
    if ($registered) {
        return;
    }
    $registered = true;

    $root = extension_root();
    if (!is_dir($root . '/src')) {
        throw new \RuntimeException(sprintf(
            '找不到领域层目录：%s/src。'
            . '请确认 backend-extension 与 shopxo 同级，或设置环境变量 CHUNLIAN_EXTENSION_PATH。',
            $root
        ));
    }

    spl_autoload_register(static function (string $class) use ($root): void {
        $prefix = 'Chunlian\\';
        if (!str_starts_with($class, $prefix)) {
            return;
        }

        $relative = substr($class, strlen($prefix));
        $file = $root . '/src/' . str_replace('\\', '/', $relative) . '.php';

        if (is_file($file)) {
            require_once $file;
        }
    });
}

/**
 * 读取 ShopXO 的数据库连接配置。
 *
 * ShopXO 的结构是嵌套的：
 *   [ 'default' => 'mysql', 'connections' => [ 'mysql' => [ 'hostname' => ..., ... ] ] ]
 *
 * 这里兼容两种形态：优先读嵌套结构，读不到再退回扁平结构
 * （不同 ShopXO 小版本或自定义配置可能简化过）。
 *
 * @return array<string,mixed>
 */
function db_config(): array
{
    static $cache = null;
    if (is_array($cache)) {
        return $cache;
    }

    $shopxoRoot = dirname(__DIR__, 3);
    $configFile = $shopxoRoot . '/config/database.php';
    if (!is_file($configFile)) {
        throw new \RuntimeException('找不到 ShopXO 数据库配置：' . $configFile);
    }

    /** @var array<string,mixed> $config */
    $config = require $configFile;

    // 嵌套结构（ShopXO 标准）
    $default = (string) ($config['default'] ?? 'mysql');
    if (isset($config['connections'][$default]) && is_array($config['connections'][$default])) {
        $cache = $config['connections'][$default];

        return $cache;
    }

    // 扁平结构兜底
    $cache = $config;

    return $cache;
}

/**
 * 建立 PDO 连接。
 *
 * 【为什么复用 ShopXO 的库与凭据，而不是另开连接】
 * 扩展表与 ShopXO 原生表在同一个库里，跨表 JOIN 是常态
 * （例如「COD 已送达未收款」需要 join veg_order_delivery 与 shopxo_order）。
 * 用独立连接会带来两个连接看到不同数据快照的问题，事务也无法覆盖两张表 ——
 * 而本项目的核心不变量（送达与收款必须原子）正依赖同事务写入。
 */
function pdo(): \PDO
{
    static $pdo = null;
    if ($pdo instanceof \PDO) {
        return $pdo;
    }

    $config = db_config();

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        (string) ($config['hostname'] ?? '127.0.0.1'),
        (int) ($config['hostport'] ?? 3306),
        (string) ($config['database'] ?? ''),
        (string) ($config['charset'] ?? 'utf8mb4')
    );

    $pdo = new \PDO(
        $dsn,
        (string) ($config['username'] ?? ''),
        (string) ($config['password'] ?? ''),
        [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            // ShopXO 自己用 EMULATE_PREPARES=true，但本项目在扩展层**关闭**它：
            // 真预处理能确保参数不被当作 SQL 拼接，对处理金额与订单号的代码更安全。
            \PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );

    return $pdo;
}

/** ShopXO 表前缀（用于拼接 `{prefix}order` 等原生表名）。 */
function table_prefix(): string
{
    return (string) (db_config()['prefix'] ?? 'shopxo_');
}

/**
 * 把多语句 SQL 文件拆成可逐条执行的语句。
 *
 * 【为什么不能用 explode(';') 或「按行去掉 -- 注释再切分」】
 * 迁移文件里有三种会让朴素实现出错的内容：
 *   1. `/* ... *​/` 块注释 —— 跨行，且其中的分号不是语句分隔符
 *   2. `--` 行注释 —— 必须识别，否则注释文本会被当作 SQL 发出去
 *   3. 字符串字面量里的分号 —— 例如 COMMENT '以;分隔' 中的分号
 *
 * 实际踩过的坑：块注释的尾部被当成语句发给 MySQL，报 1064 语法错误，
 * 而错误信息指向的位置与真实原因（注释没被剥掉）毫无关系，排查成本很高。
 *
 * 因此这里用一个最小的词法扫描：跟踪引号状态，
 * 只在引号外、且不在注释中的分号处切分。
 *
 * @return string[]
 */
function split_sql(string $sql): array
{
    // 先剥掉块注释（这一步可以在引号外安全地用正则做，
    // 因为我们的迁移文件不会在字符串里写 /* */）
    $sql = (string) preg_replace('#/\*.*?\*/#s', '', $sql);

    $statements = [];
    $current = '';
    $length = strlen($sql);

    $inSingle = false;   // '...'
    $inDouble = false;   // "..."
    $inBacktick = false; // `...`

    for ($i = 0; $i < $length; $i++) {
        $ch = $sql[$i];

        // 行注释：仅当不在任何引号内
        if (!$inSingle && !$inDouble && !$inBacktick && $ch === '-' && ($sql[$i + 1] ?? '') === '-') {
            $newline = strpos($sql, "\n", $i);
            $i = $newline === false ? $length : $newline;
            continue;
        }

        // 反斜杠转义：直接跳过下一个字符，避免把 \' 当成引号结束
        if ($ch === '\\' && ($inSingle || $inDouble)) {
            $current .= $ch . ($sql[$i + 1] ?? '');
            $i++;
            continue;
        }

        if ($ch === "'" && !$inDouble && !$inBacktick) {
            // '' 是 SQL 里的转义单引号，不应切换状态
            if ($inSingle && ($sql[$i + 1] ?? '') === "'") {
                $current .= "''";
                $i++;
                continue;
            }
            $inSingle = !$inSingle;
        } elseif ($ch === '"' && !$inSingle && !$inBacktick) {
            $inDouble = !$inDouble;
        } elseif ($ch === '`' && !$inSingle && !$inDouble) {
            $inBacktick = !$inBacktick;
        } elseif ($ch === ';' && !$inSingle && !$inDouble && !$inBacktick) {
            $trimmed = trim($current);
            if ($trimmed !== '') {
                $statements[] = $trimmed;
            }
            $current = '';
            continue;
        }

        $current .= $ch;
    }

    $trimmed = trim($current);
    if ($trimmed !== '') {
        $statements[] = $trimmed;
    }

    return $statements;
}

/**
 * 执行目录下的全部迁移脚本（按文件名排序）。
 *
 * 跳过 `*_rollback.sql` —— 那是回滚脚本，安装时绝不能执行。
 *
 * @return array{files:int, statements:int}
 */
function run_migrations(\PDO $pdo): array
{
    $dir = extension_root() . '/migrations';
    if (!is_dir($dir)) {
        throw new \RuntimeException('找不到迁移目录：' . $dir);
    }

    $files = glob($dir . '/*.sql') ?: [];
    $files = array_values(array_filter(
        $files,
        static fn (string $f): bool => !str_contains($f, '_rollback')
    ));
    sort($files);

    $statements = 0;
    foreach ($files as $file) {
        foreach (split_sql((string) file_get_contents($file)) as $statement) {
            $pdo->exec($statement);
            $statements++;
        }
    }

    return ['files' => count($files), 'statements' => $statements];
}

// 引导时立即注册
register_autoloader();
