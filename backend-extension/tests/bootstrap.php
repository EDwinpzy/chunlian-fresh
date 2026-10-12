<?php

declare(strict_types=1);

/**
 * PHPUnit 引导文件。
 *
 * 优先使用 composer autoload；未安装依赖时回退到最小 PSR-4 自动加载，
 * 便于在无 composer 环境中直接跑单测。
 */

date_default_timezone_set('Asia/Shanghai');

$autoload = __DIR__ . '/../vendor/autoload.php';
if (is_file($autoload)) {
    require $autoload;

    return;
}

spl_autoload_register(static function (string $class): void {
    $prefixes = [
        'Chunlian\\Tests\\' => __DIR__ . '/',
        'Chunlian\\' => __DIR__ . '/../src/',
    ];

    foreach ($prefixes as $prefix => $baseDir) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }

        $relative = substr($class, strlen($prefix));
        $file = $baseDir . str_replace('\\', '/', $relative) . '.php';

        if (is_file($file)) {
            require $file;
        }
    }
});
