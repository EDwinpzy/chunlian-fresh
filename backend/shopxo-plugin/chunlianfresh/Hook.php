<?php

declare(strict_types=1);

/**
 * 春连鲜选 ShopXO 插件 —— 钩子处理器
 *
 * 【本文件解决的核心问题】
 * ShopXO 的 `shopxo_order_detail` **不保存 SKU 身份**（`spec_base_id`）。
 * 它只有 `goods_id` + `spec`(JSON 文本) + `price` + `buy_number`。
 *
 * 而本项目的改单引擎（ADR-004）必须按 SKU 比对旧单与新单才能算差价，
 * 定价规则也要求「已有 SKU 维持原单价、新增 SKU 按现价」——
 * 这都需要稳定的 SKU 主键。
 *
 * 靠 `spec` 文本反推不可行：
 *   - 商品改了规格模板 → 文本变了 → 反推失败
 *   - 同一规格文本理论上可对应不同 spec_base → 歧义
 * 两种情况的后果都是差价算错 → 少收或多收钱。
 *
 * 【为什么选 `plugins_service_buy_order_insert_end` 而不是
 *   `..._order_detail_insert_begin`】
 * `detail_insert_begin` 在明细**插入之前**触发，此时还没有
 * `order_detail_id`，无法建立「快照行 ↔ 明细行」的对应关系。
 * 而 `order_insert_end` 在订单与其全部明细插入完成之后、事务提交之前触发，
 * 此时可以回查 `shopxo_order_detail` 拿到真实的明细 ID。
 *
 * 【容错策略】
 * 钩子处于下单事务内，捕获失败**绝不能**让顾客下不了单。
 * 因此所有异常都被吞掉并写日志；同时提供 backfill 命令
 * （`scripts/backfill-order-lines.php`）用于补齐遗漏的订单行。
 */

namespace app\plugins\chunlianfresh;

use app\service\GoodsService;

// Hook.php 与 Bootstrap.php 同级，不能写成 dirname(__DIR__)（那会退到 plugins/ 目录）
require_once __DIR__ . '/Bootstrap.php';

use function app\plugins\chunlianfresh\pdo;

final class Hook
{
    /**
     * 订单创建完成后：捕获每个明细行的 SKU 身份。
     *
     * @param array<string,mixed> $params 由 MyEventTrigger 传入
     */
    public function captureOrderLines(array $params): void
    {
        $orderId = (int) ($params['order_id'] ?? 0);
        if ($orderId <= 0) {
            $this->log('钩子未提供 order_id，跳过');

            return;
        }

        try {
            $captured = $this->captureForOrder($orderId);
            $this->log(sprintf('订单 %d 捕获 %d 条订单行', $orderId, $captured));
        } catch (\Throwable $e) {
            // 绝不让扩展逻辑把下单流程带崩。遗漏的行由 backfill 补齐。
            $this->log(sprintf('订单 %d 捕获订单行失败：%s', $orderId, $e->getMessage()));
        }
    }

    /**
     * 为指定订单补齐订单行快照。
     *
     * 公开方法，供 backfill 脚本复用 —— 保证「钩子实时捕获」与
     * 「事后补齐」走的是同一段逻辑，不会出现两条口径。
     *
     * @return int 本次写入的行数
     */
    public function captureForOrder(int $orderId): int
    {
        $pdo = pdo();

        // 明细已插入，可以拿到真实 ID
        $stmt = $pdo->prepare(
            sprintf(
                'SELECT `id`, `goods_id`, `title`, `images`, `price`, `spec`, `buy_number`, `spec_desc` '
                . 'FROM `%sorder_detail` WHERE `order_id` = :oid ORDER BY `id` ASC',
                \app\plugins\chunlianfresh\table_prefix()
            )
        );
        $stmt->execute(['oid' => $orderId]);
        $details = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if ($details === []) {
            return 0;
        }

        $insert = $pdo->prepare(
            'INSERT IGNORE INTO `veg_order_line` '
            . '(`order_id`, `order_detail_id`, `sku_id`, `goods_id`, `name_snapshot`, '
            . '`spec_snapshot`, `image_snapshot`, `unit_fen`, `qty`, `created_at`) '
            . 'VALUES (:order_id, :detail_id, :sku_id, :goods_id, :name, :spec, :image, :unit_fen, :qty, :created_at)'
        );

        $now = date('Y-m-d H:i:s');
        $written = 0;

        foreach ($details as $detail) {
            $skuId = $this->resolveSkuId((int) $detail['goods_id'], $detail['spec'] ?? '');
            if ($skuId <= 0) {
                // 解析不出 SKU：该行仍要落库，否则改单时行数对不上，
                // 差价计算会出现「凭空多出/少了一行」的错误。
                // 用 0 标记「身份未知」，改单逻辑需据此拒绝改单而不是猜。
                $this->log(sprintf(
                    '订单 %d 明细 %d 无法解析 SKU（goods_id=%d），以 sku_id=0 落库；该订单不可改单',
                    $orderId,
                    (int) $detail['id'],
                    (int) $detail['goods_id']
                ));
            }

            $insert->execute([
                'order_id' => $orderId,
                'detail_id' => (int) $detail['id'],
                'sku_id' => $skuId,
                'goods_id' => (int) $detail['goods_id'],
                'name' => mb_substr((string) $detail['title'], 0, 160),
                'spec' => mb_substr($this->specText($detail), 0, 255),
                'image' => mb_substr((string) $detail['images'], 0, 255),
                // 单价以 ShopXO 存的价格为准（decimal），转整数分落库。
                // 用字符串拆分而非浮点乘法 —— 见 OrderRowMapper::decimalToFen 的说明。
                'unit_fen' => $this->decimalToFen((string) $detail['price']),
                'qty' => (int) $detail['buy_number'],
                'created_at' => $now,
            ]);

            $written += $insert->rowCount();
        }

        return $written;
    }

    /**
     * 由 goods_id + spec 解析 SKU 主键。
     *
     * 用 ShopXO 自己的 `GoodsService::GoodsSpecDetail()`，
     * 而不是自己写一套匹配逻辑 —— 自己写会在商品规格结构变化时悄悄失配，
     * 而且失配是静默的，等到算差价时才暴露。
     */
    private function resolveSkuId(int $goodsId, mixed $specRaw): int
    {
        if ($goodsId <= 0) {
            return 0;
        }

        $spec = is_string($specRaw) ? json_decode($specRaw, true) : $specRaw;
        if (!is_array($spec)) {
            $spec = [];
        }

        try {
            $ret = GoodsService::GoodsSpecDetail(['id' => $goodsId, 'spec' => $spec]);
        } catch (\Throwable $e) {
            $this->log(sprintf('GoodsSpecDetail 异常（goods_id=%d）：%s', $goodsId, $e->getMessage()));

            return 0;
        }

        if (($ret['code'] ?? -1) !== 0) {
            return 0;
        }

        return (int) ($ret['data']['spec_base']['id'] ?? 0);
    }

    /**
     * decimal 字符串 → 整数分（与 OrderRowMapper::decimalToFen 同一算法）。
     *
     * 【为什么不直接 (int)(floatval * 100)】
     * `(int)(8.7 * 100)` 在 IEEE754 下是 869 而不是 870。
     * 金额换算出现一次这类偏差，对账就不平。
     */
    private function decimalToFen(string $value): int
    {
        $s = trim($value);
        if ($s === '') {
            return 0;
        }

        $negative = false;
        if ($s[0] === '-') {
            $negative = true;
            $s = substr($s, 1);
        }

        if (preg_match('/^\d*(?:\.\d*)?$/', $s) !== 1 || $s === '.') {
            return 0;
        }

        $parts = explode('.', $s, 2);
        $intPart = $parts[0] === '' ? '0' : $parts[0];
        $frac3 = str_pad(substr($parts[1] ?? '', 0, 3), 3, '0');

        $fen = ((int) $intPart) * 100 + (int) substr($frac3, 0, 2);
        if ((int) $frac3[2] >= 5) {
            $fen += 1;
        }

        return $negative ? -$fen : $fen;
    }

    /** 规格展示文本（仅用于界面展示，不作身份标识）。 */
    private function specText(array $detail): string
    {
        $spec = $detail['spec'] ?? '';
        if (is_string($spec) && $spec !== '') {
            $decoded = json_decode($spec, true);
            if (is_array($decoded)) {
                $parts = [];
                foreach ($decoded as $item) {
                    if (is_array($item) && isset($item['value'])) {
                        $parts[] = (string) $item['value'];
                    } elseif (is_scalar($item)) {
                        $parts[] = (string) $item;
                    }
                }
                if ($parts !== []) {
                    return implode('，', $parts);
                }
            }

            return $spec;
        }

        return (string) ($detail['spec_desc'] ?? '');
    }

    private function log(string $message): void
    {
        try {
            // __DIR__ = {SHOPXO_ROOT}/app/plugins/chunlianfresh
            $dir = dirname(__DIR__, 3) . '/runtime/chunlian';
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            @file_put_contents(
                $dir . '/hook.log',
                sprintf("[%s] %s\n", date('Y-m-d H:i:s'), $message),
                FILE_APPEND
            );
        } catch (\Throwable) {
            // 日志失败不应影响主流程
        }
    }
}
