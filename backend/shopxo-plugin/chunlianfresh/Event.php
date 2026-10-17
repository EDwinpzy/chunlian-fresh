<?php

declare(strict_types=1);

/**
 * 春连鲜选 ShopXO 插件 —— 生命周期事件
 *
 * ShopXO 通过 `PluginsService::PluginsEventCall()` 调用本类的同名方法。
 * 支持：Upload / BeginInstall / Install / Uninstall / Download /
 *       BeginUpgrade / Upgrade / Delete
 *
 * 【设计原则：卸载不删数据】
 * `Uninstall` 只做标记与日志，**不删表、不清数据**。
 * 理由：卸载插件往往是为了排查问题或临时停用；
 * 若顺手把订单履约、资金流水、审计日志一起删掉，
 * 一次误操作就造成不可恢复的账目丢失。
 * 需要彻底清理时，应当由运维显式执行 migrations 下的 rollback 脚本。
 */

namespace app\plugins\chunlianfresh;

require_once __DIR__ . '/Bootstrap.php';

use function app\plugins\chunlianfresh\extension_root;
use function app\plugins\chunlianfresh\pdo;
use function app\plugins\chunlianfresh\run_migrations;

final class Event
{
    /**
     * 安装：建立扩展表。
     *
     * 【幂等】迁移脚本必须可重复执行：建表使用 `IF NOT EXISTS`，
     * 初始化数据使用 `INSERT IGNORE`，新增字段先检查 `INFORMATION_SCHEMA`。
     * 这样安装或升级重跑全部迁移时，不会因已有表或字段而中断。
     */
    public function Install(array $params = []): array
    {
        try {
            $result = run_migrations(pdo());
            $this->log(sprintf(
                '安装完成：%d 个迁移文件，%d 条语句',
                $result['files'],
                $result['statements']
            ));

            return $this->ok(sprintf(
                '春连鲜选扩展表已就绪（%d 个文件 / %d 条语句）',
                $result['files'],
                $result['statements']
            ));
        } catch (\Throwable $e) {
            $this->log('安装失败：' . $e->getMessage());

            return $this->fail('安装失败：' . $e->getMessage());
        }
    }

    /** 安装前校验：确认领域层与迁移脚本都在。 */
    public function BeginInstall(array $params = []): array
    {
        $root = extension_root();
        if (!is_dir($root . '/src')) {
            return $this->fail(sprintf(
                '找不到领域层：%s/src。请确认 backend-extension 与 shopxo 同级，'
                . '或设置环境变量 CHUNLIAN_EXTENSION_PATH',
                $root
            ));
        }

        $migrations = glob($root . '/migrations/*.sql') ?: [];
        if ($migrations === []) {
            return $this->fail(sprintf('找不到迁移脚本：%s/migrations', $root));
        }

        return $this->ok('环境校验通过');
    }

    /**
     * 卸载：**保留数据**。
     * 见文件头说明 —— 删数据必须是显式的运维动作。
     */
    public function Uninstall(array $params = []): array
    {
        $this->log('插件已卸载；扩展表与数据按设计保留，如需清理请手动执行 rollback 脚本');

        return $this->ok('插件已卸载。扩展表与数据已保留，不会自动删除。');
    }

    /** 升级：重跑迁移（幂等），补齐新增表。 */
    public function Upgrade(array $params = []): array
    {
        return $this->Install($params);
    }

    /** 删除：同样不碰数据。 */
    public function Delete(array $params = []): array
    {
        $this->log('插件文件被删除；数据库中的扩展表与数据仍保留');

        return $this->ok('已删除插件文件，数据保留。');
    }

    // =========================================================
    // 内部
    // =========================================================

    private function ok(string $message): array
    {
        return ['code' => 0, 'msg' => $message, 'data' => ''];
    }

    private function fail(string $message): array
    {
        return ['code' => -1, 'msg' => $message, 'data' => ''];
    }

    private function log(string $message): void
    {
        try {
            $dir = dirname(__DIR__, 3) . '/runtime/chunlian';
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            @file_put_contents(
                $dir . '/plugin.log',
                sprintf("[%s] %s\n", date('Y-m-d H:i:s'), $message),
                FILE_APPEND
            );
        } catch (\Throwable) {
            // 忽略
        }
    }
}
