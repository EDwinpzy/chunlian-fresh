<?php

declare(strict_types=1);

/**
 * 春连鲜选 ShopXO 插件 —— 配置服务
 *
 * ShopXO 通过约定类名发现插件的配置声明：
 *   \app\plugins\{plugins}\service\BaseService
 * 并读取两个静态属性：
 *   - `$base_config_private_field`  配置中的敏感字段（输出给前台时会被剔除）
 *   - `$base_config_attachment_field` 附件字段（会做 URL 转换）
 *
 * 见 `PluginsService::PluginsBaseSerData()`。
 *
 * 【为什么必须显式声明私有字段】
 * 插件配置会通过 `plugins_base` 下发到小程序/App 的公共配置里。
 * 若不声明，微信支付密钥、商家 API 密钥这类内容会被一并下发到客户端 ——
 * 任何人都能从抓包里拿到。这不是"多一层保险"，而是必须做的一步。
 */

namespace app\plugins\chunlianfresh\service;

final class BaseService
{
    /**
     * 输出到前台时会被移除的字段。
     *
     * 命名与 `veg_*` 配置键保持一致，便于对照排查。
     */
    public static array $base_config_private_field = [
        'wxpay_mch_id',
        'wxpay_api_v3_key',
        'wxpay_serial_no',
        'wxpay_private_key',
        'merchant_api_secret',
    ];

    /**
     * 附件类字段（此处无，保留空数组以符合 ShopXO 的约定）。
     */
    public static array $base_config_attachment_field = [];

    /**
     * 配置输出前的处理钩子。
     *
     * ShopXO 在 `PluginsBaseList()` 中会调用它（若存在）。
     * 这里做一次兜底剔除：即使有人往私有字段列表里漏加了一项，
     * 只要键名匹配敏感词根，也会被移除。
     *
     * 【为什么要有这层兜底】
     * 密钥泄露是不可逆的 —— 事后轮换意味着改配置、重启、通知所有端。
     * 与其依赖"记得加进列表"，不如再加一道基于命名的过滤。
     */
    public static function BaseConfigHandle(array $config): array
    {
        if ($config === []) {
            return $config;
        }

        $sensitiveRoots = ['key', 'secret', 'pwd', 'password', 'token', 'private', 'serial'];

        foreach (array_keys($config) as $k) {
            $lower = strtolower((string) $k);
            foreach ($sensitiveRoots as $root) {
                if (str_contains($lower, $root)) {
                    unset($config[$k]);
                    break;
                }
            }
        }

        return $config;
    }
}
