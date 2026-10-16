<?php

declare(strict_types=1);

namespace Chunlian\Service;

use Chunlian\Domain\RejectionCode;
use Chunlian\Repository\OrderRowMapper;

/**
 * 商品创建与编辑（商家端写操作）。
 *
 * 【为什么单开一个服务，而不是塞进 CatalogService】
 * `CatalogService` 只做「已有商品的改价/库存/上下架」—— 那是最频繁、
 * 最需要低延迟的日常动作。新增商品是低频、字段多、要连带建 SKU 与分类关联的
 * 重操作，两者的校验与事务边界完全不同，混在一起会让日常改价这条热路径
 * 也被迫加载一堆无关逻辑。
 *
 * 【为什么新商品一律建「单规格」】
 * 本项目的定价口径是固定规格（方案 §1.3）：1份(约500g)、1把、1袋、10枚/盒。
 * 每个规格是独立商品，而不是同一商品下的多规格 ——
 * 因为顾客侧的商品卡要显示「500g/份 ¥3.50」这种一图一价，
 * 多规格会让列表页必须展开规格才能比价，反而更难选。
 */
final class GoodsWriteService
{
    public function __construct(
        private readonly \PDO $pdo,
        private readonly string $tablePrefix = 'shopxo_',
    ) {
        FreshCategoryPolicy::ensureLegacyRoots($this->pdo, $this->tablePrefix);
    }

    /**
     * 新建商品（同时建默认 SKU 与分类关联）。
     *
     * @param array<string,mixed> $payload
     */
    public function create(array $payload, string $actorId): array
    {
        $title = trim((string) ($payload['title'] ?? ''));
        if ($title === '') {
            return $this->fail(RejectionCode::VALIDATION_FAILED, '商品名称不能为空');
        }
        if (mb_strlen($title) > 120) {
            return $this->fail(RejectionCode::VALIDATION_FAILED, '商品名称不能超过 120 个字');
        }

        $priceFen = (int) ($payload['price_fen'] ?? 0);
        if ($priceFen <= 0) {
            return $this->fail(RejectionCode::VALIDATION_FAILED, '请填写售价');
        }
        if ($priceFen > 100000000) {
            return $this->fail(RejectionCode::VALIDATION_FAILED, '售价超出合理范围');
        }

        $inventory = (int) ($payload['inventory'] ?? 0);
        if ($inventory < 0) {
            return $this->fail(RejectionCode::VALIDATION_FAILED, '库存不能为负');
        }

        $unit = trim((string) ($payload['inventory_unit'] ?? '份')) ?: '份';
        $image = trim((string) ($payload['image'] ?? ''));
        $desc = mb_substr(trim((string) ($payload['simple_desc'] ?? '')), 0, 120);
        $specText = mb_substr(trim((string) ($payload['spec_text'] ?? '')), 0, 40);
        $categoryId = (string) ($payload['category_id'] ?? '');
        $isShelves = !array_key_exists('is_shelves', $payload) || (bool) $payload['is_shelves'];

        $p = $this->tablePrefix;
        $now = time();
        $priceDecimal = OrderRowMapper::fenToDecimal($priceFen);

        $this->pdo->beginTransaction();
        try {
            // Lock/validate the selected category in the same transaction as
            // its goods association, so category deletion cannot race creation.
            if (!FreshCategoryPolicy::isAllowed($this->pdo, $p, $categoryId)) {
                $this->pdo->rollBack();
                return $this->fail(RejectionCode::VALIDATION_FAILED, '请选择有效的生鲜分类');
            }

            // ---------- 商品主表 ----------
            $this->pdo->prepare(
                "INSERT INTO `{$p}goods` "
                . "(`title`, `title_color`, `simple_desc`, `spec_desc`, `images`, `original_price`, "
                . "`min_original_price`, `max_original_price`, `price`, `min_price`, `max_price`, "
                . "`inventory`, `inventory_unit`, `is_shelves`, `is_exist_many_spec`, `content_web`, "
                . "`buy_min_number`, `buy_max_number`, `is_deduction_inventory`, `sort_level`, "
                . "`is_delete_time`, `add_time`, `upd_time`) "
                . "VALUES (:title, '', :simple_desc, :spec_desc, :images, :orig, :orig_min, :orig_max, "
                . ":price, :min_price, :max_price, :inventory, :unit, :is_shelves, 0, '', "
                . "1, 0, 1, 0, 0, :add_time, :upd_time)"
            )->execute([
                'title' => $title,
                'simple_desc' => $desc,
                'spec_desc' => $specText,
                'images' => $image,
                // 原价留空（0）而不是随便填一个「划线价」——
                // 虚构原价属于价格欺诈，宁可没有
                'orig' => '0.00',
                'orig_min' => '0.00',
                'orig_max' => '0.00',
                'price' => $priceDecimal,
                'min_price' => $priceDecimal,
                'max_price' => $priceDecimal,
                'inventory' => $inventory,
                'unit' => $unit,
                'is_shelves' => $isShelves ? 1 : 0,
                'add_time' => $now,
                'upd_time' => $now,
            ]);
            $goodsId = (int) $this->pdo->lastInsertId();

            // ---------- 默认 SKU ----------
            $this->pdo->prepare(
                "INSERT INTO `{$p}goods_spec_base` "
                . "(`goods_id`, `price`, `original_price`, `inventory`, `buy_min_number`, `buy_max_number`, "
                . "`weight`, `volume`, `coding`, `barcode`, `inventory_unit`, `extends`, `add_time`) "
                . "VALUES (:goods_id, :price, '0.00', :inventory, 1, 0, 0, 0, '', '', :unit, '[]', :add_time)"
            )->execute([
                'goods_id' => $goodsId,
                'price' => $priceDecimal,
                'inventory' => $inventory,
                'unit' => $unit,
                'add_time' => $now,
            ]);
            $skuId = (int) $this->pdo->lastInsertId();

            // 规格值：让顾客端能显示「约500g/份」
            if ($specText !== '') {
                $this->pdo->prepare(
                    "INSERT INTO `{$p}goods_spec_value` "
                    . "(`goods_id`, `goods_spec_base_id`, `value`) VALUES (:goods_id, :sku_id, :value)"
                )->execute(['goods_id' => $goodsId, 'sku_id' => $skuId, 'value' => $specText]);
            }

            // ---------- 分类关联 ----------
            $this->pdo->prepare(
                "INSERT INTO `{$p}goods_category_join` (`goods_id`, `category_id`, `add_time`) "
                . "VALUES (:goods_id, :category_id, :add_time)"
            )->execute(['goods_id' => $goodsId, 'category_id' => (int) $categoryId, 'add_time' => $now]);

            $this->writeAudit($actorId, 'GOODS_CREATE', (string) $goodsId, [
                'title' => $title,
                'price_fen' => $priceFen,
                'inventory' => $inventory,
                'is_shelves' => $isShelves,
            ]);

            $this->pdo->commit();

            return $this->ok([
                'id' => (string) $goodsId,
                'sku_id' => (string) $skuId,
            ], '商品已创建');
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * 编辑商品基础信息（名称 / 描述 / 规格文本 / 分类 / 主图）。
     *
     * 价格与库存走 `CatalogService::updateSku()` —— 那两个字段有聚合一致性要求，
     * 放在一处维护比两处各写一遍安全。
     *
     * @param array<string,mixed> $payload
     */
    public function update(string $goodsId, array $payload, string $actorId): array
    {
        if (preg_match('/^\d+$/', $goodsId) !== 1) {
            return $this->fail(RejectionCode::NOT_FOUND, '商品不存在');
        }

        $p = $this->tablePrefix;
        $sets = [];
        $params = ['id' => $goodsId];

        if (array_key_exists('title', $payload)) {
            $title = trim((string) $payload['title']);
            if ($title === '') {
                return $this->fail(RejectionCode::VALIDATION_FAILED, '商品名称不能为空');
            }
            $sets[] = '`title` = :title';
            $params['title'] = mb_substr($title, 0, 120);
        }

        if (array_key_exists('simple_desc', $payload)) {
            $sets[] = '`simple_desc` = :simple_desc';
            $params['simple_desc'] = mb_substr(trim((string) $payload['simple_desc']), 0, 120);
        }

        if (array_key_exists('spec_desc', $payload)) {
            $sets[] = '`spec_desc` = :spec_desc';
            $params['spec_desc'] = mb_substr(trim((string) $payload['spec_desc']), 0, 40);
        }

        if (array_key_exists('image', $payload)) {
            $image = trim((string) $payload['image']);
            // 只在传了新图时覆盖；传空字符串视为「不改」，避免误清空主图
            if ($image !== '') {
                $sets[] = '`images` = :images';
                $params['images'] = $image;
            }
        }

        if (array_key_exists('inventory_unit', $payload)) {
            $sets[] = '`inventory_unit` = :inventory_unit';
            $params['inventory_unit'] = mb_substr(trim((string) $payload['inventory_unit']), 0, 10);
        }

        if ($sets === [] && !array_key_exists('category_id', $payload)) {
            return $this->fail(RejectionCode::VALIDATION_FAILED, '没有需要修改的字段');
        }

        $this->pdo->beginTransaction();
        try {
            if (array_key_exists('category_id', $payload)
                && !FreshCategoryPolicy::isAllowed($this->pdo, $p, $payload['category_id'])) {
                $this->pdo->rollBack();
                return $this->fail(RejectionCode::VALIDATION_FAILED, '请选择有效的生鲜分类');
            }

            $check = $this->pdo->prepare(
                "SELECT g.`id`, g.`title` FROM `{$p}goods` g "
                . 'WHERE g.`id` = :id AND g.`is_delete_time` = 0 AND '
                . FreshCategoryPolicy::goodsPredicate($p, 'g', false) . ' LIMIT 1 FOR UPDATE'
            );
            $check->execute(['id' => $goodsId]);
            $goods = $check->fetch(\PDO::FETCH_ASSOC);
            if ($goods === false) {
                $this->pdo->rollBack();

                return $this->fail(RejectionCode::NOT_FOUND, '商品不存在');
            }

            if ($sets !== []) {
                $sets[] = '`upd_time` = ' . time();
                $this->pdo->prepare(
                    "UPDATE `{$p}goods` SET " . implode(', ', $sets) . ' WHERE `id` = :id'
                )->execute($params);
            }

            if (array_key_exists('category_id', $payload)) {
                $categoryId = (string) $payload['category_id'];
                $this->pdo->prepare("DELETE FROM `{$p}goods_category_join` WHERE `goods_id` = :gid")
                    ->execute(['gid' => $goodsId]);
                $this->pdo->prepare(
                    "INSERT INTO `{$p}goods_category_join` (`goods_id`, `category_id`, `add_time`) "
                    . "VALUES (:gid, :cid, :t)"
                )->execute(['gid' => $goodsId, 'cid' => (int) $categoryId, 't' => time()]);
            }

            $this->writeAudit($actorId, 'GOODS_UPDATE', $goodsId, array_diff_key($payload, ['image' => 1]));

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return $this->ok(['id' => $goodsId], '已保存');
    }

    /**
     * 逻辑删除（下架 + 打删除标记）。
     *
     * 【为什么不物理删除】
     * 方案 §2.2 A08 明确要求：有历史订单的商品只能下架，不能物理删除。
     * 订单明细里存的是名称快照，但 `goods_id` 仍要能反查；
     * 物理删除会让历史订单的商品链接全部 404，售后与对账都没法做。
     */
    public function archive(string $goodsId, string $actorId): array
    {
        if (preg_match('/^\d+$/', $goodsId) !== 1) {
            return $this->fail(RejectionCode::NOT_FOUND, '商品不存在');
        }

        $p = $this->tablePrefix;

        $stmt = $this->pdo->prepare(
            "SELECT g.`id`, g.`title` FROM `{$p}goods` g "
            . 'WHERE g.`id` = :id AND g.`is_delete_time` = 0 AND '
            . FreshCategoryPolicy::goodsPredicate($p, 'g', false) . ' LIMIT 1'
        );
        $stmt->execute(['id' => $goodsId]);
        $goods = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($goods === false) {
            return $this->fail(RejectionCode::NOT_FOUND, '商品不存在');
        }

        $this->pdo->prepare(
            "UPDATE `{$p}goods` SET `is_shelves` = 0, `is_delete_time` = :deleted, `upd_time` = :updated WHERE `id` = :id"
        )->execute(['deleted' => time(), 'updated' => time(), 'id' => $goodsId]);

        $this->writeAudit($actorId, 'GOODS_ARCHIVE', $goodsId, ['title' => (string) $goods['title']]);

        return $this->ok(['id' => $goodsId], '商品已删除（历史订单不受影响）');
    }

    // =========================================================
    // 内部
    // =========================================================

    /** @param array<string,mixed> $detail */
    private function writeAudit(string $actorId, string $action, string $goodsId, array $detail): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO `veg_operation_log` '
            . '(`actor_type`, `actor_id`, `order_id`, `action`, `detail_json`, `created_at`) '
            . 'VALUES (:actor_type, :actor_id, NULL, :action, :detail_json, :created_at)'
        );
        $stmt->execute([
            'actor_type' => 'MERCHANT',
            'actor_id' => $actorId === '' ? null : $actorId,
            'action' => $action,
            'detail_json' => json_encode(array_merge(['goods_id' => $goodsId], $detail), JSON_UNESCAPED_UNICODE),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function ok(mixed $data, ?string $message = null): array
    {
        return ['code' => 'OK', 'message' => $message, 'data' => $data];
    }

    private function fail(RejectionCode $code, string $message): array
    {
        return ['code' => $code->value, 'message' => $message, 'data' => null];
    }
}
