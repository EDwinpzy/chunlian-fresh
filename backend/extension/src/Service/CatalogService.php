<?php

declare(strict_types=1);

namespace Chunlian\Service;

use Chunlian\Domain\RejectionCode;
use Chunlian\Repository\OrderRowMapper;

/**
 * 商品管理服务（商家端）。
 *
 * 【为什么商家端必须有商品管理，而不是跳 Web 后台】
 * docs/UI-SPEC.md §8.1 明确要求：「商品管理不跳 Web 后台；改价、上架、
 * 库存与拍照均在 App 内完成」。
 * 生鲜店主的真实场景是：清晨在店里盘点时用手机改库存与价格。
 * 让他切换到电脑浏览器登录后台，这件事就不会被做 —— 而库存不准会直接
 * 导致超卖或顾客下单后被退款。
 *
 * 【金额口径】
 * ShopXO 的 `shopxo_goods_spec_base.price` 是 `decimal(10,2)`。
 * 本服务的对外接口一律用**整数分**（`*_fen`），在边界处转换。
 * 转换走 `OrderRowMapper`，保证与订单侧同一套算法（字符串解析，不用浮点）。
 *
 * 【聚合一致性】
 * `shopxo_goods` 上有几个**冗余聚合字段**：`min_price` / `max_price` /
 * `price`（范围字符串）/ `inventory`（SKU 库存合计）。
 * 改动任一 SKU 的价格或库存后必须重算它们，否则：
 *   - 列表页显示旧价格 → 顾客看到的价格与实际下单价不一致
 *   - `inventory` 与 SKU 实际库存不符 → 超卖
 * 因此本服务的写操作一律在**同一事务**内更新 SKU 与聚合字段。
 */
final class CatalogService
{
    public function __construct(
        private readonly \PDO $pdo,
        private readonly string $tablePrefix = 'shopxo_',
    ) {
        FreshCategoryPolicy::ensureLegacyRoots($this->pdo, $this->tablePrefix);
    }

    // =========================================================
    // 读
    // =========================================================

    /** 商品分类（供筛选与编辑时选择）。 */
    public function categories(): array
    {
        $p = $this->tablePrefix;
        $rows = $this->pdo->query(
            "SELECT `id`, `pid`, `name`, `is_enable`, `sort` FROM `{$p}goods_category` "
            . "WHERE `is_enable` = 1 ORDER BY `sort` DESC, `id` ASC"
        )->fetchAll(\PDO::FETCH_ASSOC);
        $rows = FreshCategoryPolicy::filterEnabledRows(
            $rows,
            FreshCategoryPolicy::configuredRootIds($this->pdo, $p),
        );

        return $this->ok(array_map(static fn (array $r): array => [
            'id' => (string) $r['id'],
            'parent_id' => (string) $r['pid'],
            'name' => (string) $r['name'],
        ], $rows));
    }

    /**
     * 商品列表。
     *
     * @param string|null $keyword     按标题模糊搜索
     * @param bool|null   $onSaleOnly  只看已上架
     */
    public function listGoods(?string $keyword, ?bool $onSaleOnly, int $page, int $pageSize): array
    {
        $page = max(1, $page);
        $pageSize = min(100, max(1, $pageSize));
        $p = $this->tablePrefix;

        // 已删除商品必须排除：ShopXO 用 is_delete_time 做软删除
        // 商家仍需查找和迁移已停用分类中的商品；顾客端由 CustomerCatalogService 单独要求启用状态。
        $where = ['g.`is_delete_time` = 0', FreshCategoryPolicy::goodsPredicate($p, 'g', false)];
        $params = [];

        if ($keyword !== null && trim($keyword) !== '') {
            $where[] = 'g.`title` LIKE :kw';
            $params['kw'] = '%' . trim($keyword) . '%';
        }
        if ($onSaleOnly === true) {
            $where[] = 'g.`is_shelves` = 1';
        }

        $whereSql = implode(' AND ', $where);

        $countStmt = $this->pdo->prepare("SELECT COUNT(*) AS c FROM `{$p}goods` g WHERE {$whereSql}");
        $countStmt->execute($params);
        $total = (int) ($countStmt->fetch(\PDO::FETCH_ASSOC)['c'] ?? 0);

        $offset = ($page - 1) * $pageSize;
        $stmt = $this->pdo->prepare(
            "SELECT g.`id`, g.`title`, g.`images`, g.`price`, g.`min_price`, g.`max_price`, "
            . "g.`inventory`, g.`inventory_unit`, g.`is_shelves`, g.`is_exist_many_spec`, "
            . "g.`sales_count`, g.`simple_desc`, g.`spec_desc` "
            . "FROM `{$p}goods` g WHERE {$whereSql} "
            // 缺货与下架的排前面 —— 这两类最需要店主处理
            . "ORDER BY g.`is_shelves` ASC, (g.`inventory` = 0) DESC, g.`id` DESC "
            . "LIMIT {$pageSize} OFFSET {$offset}"
        );
        $stmt->execute($params);

        $list = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $list[] = $this->serializeGoodsSummary($row);
        }

        return $this->ok([
            'list' => $list,
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
        ]);
    }

    /** 商品详情（含 SKU 明细）。 */
    public function goodsDetail(string $goodsId): array
    {
        $p = $this->tablePrefix;

        $stmt = $this->pdo->prepare(
            "SELECT `id`, `title`, `images`, `price`, `min_price`, `max_price`, `inventory`, "
            . "`inventory_unit`, `is_shelves`, `is_exist_many_spec`, `sales_count`, "
            . "`simple_desc`, `spec_desc`, `buy_min_number`, `buy_max_number` "
            . "FROM `{$p}goods` g WHERE g.`id` = :id AND g.`is_delete_time` = 0 AND "
            . FreshCategoryPolicy::goodsPredicate($p, 'g', false) . ' LIMIT 1'
        );
        $stmt->execute(['id' => $goodsId]);
        $goods = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($goods === false) {
            return $this->fail(RejectionCode::NOT_FOUND, '商品不存在');
        }

        $categoryStmt = $this->pdo->prepare(
            "SELECT cj.`category_id` FROM `{$p}goods_category_join` cj "
            . "INNER JOIN `{$p}goods_category` c ON c.`id` = cj.`category_id` "
            . 'WHERE cj.`goods_id` = :gid AND ' . FreshCategoryPolicy::categoryPredicate($p, 'c', false)
            . ' ORDER BY c.`sort` DESC, cj.`category_id` ASC LIMIT 1'
        );
        $categoryStmt->execute(['gid' => $goodsId]);
        $goods['category_id'] = (string) ($categoryStmt->fetchColumn() ?: '');

        // SKU 列表
        $skuStmt = $this->pdo->prepare(
            "SELECT `id`, `price`, `original_price`, `inventory` "
            . "FROM `{$p}goods_spec_base` WHERE `goods_id` = :gid ORDER BY `id` ASC"
        );
        $skuStmt->execute(['gid' => $goodsId]);
        $skus = $skuStmt->fetchAll(\PDO::FETCH_ASSOC);

        // 规格值（用于展示「500g/份」这类描述）
        $valStmt = $this->pdo->prepare(
            "SELECT `goods_spec_base_id`, `value` FROM `{$p}goods_spec_value` WHERE `goods_id` = :gid"
        );
        $valStmt->execute(['gid' => $goodsId]);
        $valuesBySku = [];
        foreach ($valStmt->fetchAll(\PDO::FETCH_ASSOC) as $v) {
            $valuesBySku[(int) $v['goods_spec_base_id']][] = (string) $v['value'];
        }

        $skuList = [];
        foreach ($skus as $s) {
            $skuId = (int) $s['id'];
            $skuList[] = [
                'sku_id' => (string) $skuId,
                'spec_text' => implode('，', $valuesBySku[$skuId] ?? []),
                'price_fen' => OrderRowMapper::decimalToFen((string) $s['price']),
                'original_price_fen' => OrderRowMapper::decimalToFen((string) $s['original_price']),
                'inventory' => (int) $s['inventory'],
            ];
        }

        $summary = $this->serializeGoodsSummary($goods);
        $summary['category_id'] = $goods['category_id'];
        $summary['skus'] = $skuList;

        return $this->ok($summary);
    }

    // =========================================================
    // 写
    // =========================================================

    /**
     * 上架 / 下架。
     *
     * 【为什么这属于敏感动作】下架会让顾客立刻无法下单；
     * 若误操作且未察觉，等于当天这部分生意直接消失。
     * 因此写审计，并由客户端做二次确认 + 撤销提示。
     */
    public function setShelves(string $goodsId, bool $onShelves, string $actorId): array
    {
        $p = $this->tablePrefix;

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                "SELECT g.`id`, g.`title`, g.`is_shelves` FROM `{$p}goods` g "
                . "WHERE g.`id` = :id AND g.`is_delete_time` = 0 AND "
                . FreshCategoryPolicy::goodsPredicate($p, 'g', false) . ' LIMIT 1 FOR UPDATE'
            );
            $stmt->execute(['id' => $goodsId]);
            $goods = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($goods === false) {
                $this->pdo->rollBack();

                return $this->fail(RejectionCode::NOT_FOUND, '商品不存在');
            }

            if ((int) $goods['is_shelves'] === ($onShelves ? 1 : 0)) {
                // 已是目标状态：幂等返回，不重复写审计
                $this->pdo->commit();

                return $this->goodsDetailWithMessage(
                    $goodsId,
                    $onShelves ? '商品已是上架状态' : '商品已是下架状态'
                );
            }

            $this->pdo->prepare(
                "UPDATE `{$p}goods` SET `is_shelves` = :v, `upd_time` = UNIX_TIMESTAMP() WHERE `id` = :id"
            )->execute(['v' => $onShelves ? 1 : 0, 'id' => $goodsId]);

            $this->writeAudit($actorId, 'GOODS_SHELVES', $goodsId, [
                'title' => (string) $goods['title'],
                'from' => (int) $goods['is_shelves'],
                'to' => $onShelves ? 1 : 0,
            ]);

            $this->pdo->commit();

            return $this->goodsDetailWithMessage($goodsId, $onShelves ? '已上架' : '已下架');
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * 修改 SKU 的价格与库存。
     *
     * 【为什么必须重算聚合字段】
     * `shopxo_goods` 上的 `min_price` / `max_price` / `price` / `inventory`
     * 是冗余聚合。只改 SKU 不重算它们，列表页会显示旧价格与旧库存 ——
     * 顾客看到的价格与实际下单价不一致，库存也会算错导致超卖。
     *
     * @param int|null $priceFen  新单价（整数分）；null 表示不改
     * @param int|null $inventory 新库存；null 表示不改
     */
    public function updateSku(
        string $goodsId,
        string $skuId,
        ?int $priceFen,
        ?int $inventory,
        string $actorId,
    ): array {
        if ($priceFen === null && $inventory === null) {
            return $this->fail(RejectionCode::VALIDATION_FAILED, '没有需要修改的字段');
        }
        if ($priceFen !== null && $priceFen < 0) {
            return $this->fail(RejectionCode::VALIDATION_FAILED, '价格不能为负');
        }
        if ($inventory !== null && $inventory < 0) {
            return $this->fail(RejectionCode::VALIDATION_FAILED, '库存不能为负');
        }

        $p = $this->tablePrefix;

        $this->pdo->beginTransaction();
        try {
            $goodsCheck = $this->pdo->prepare(
                "SELECT g.`id` FROM `{$p}goods` g WHERE g.`id` = :gid "
                . 'AND g.`is_delete_time` = 0 AND ' . FreshCategoryPolicy::goodsPredicate($p, 'g', false)
                . ' LIMIT 1 FOR UPDATE'
            );
            $goodsCheck->execute(['gid' => $goodsId]);
            if ($goodsCheck->fetchColumn() === false) {
                $this->pdo->rollBack();

                return $this->fail(RejectionCode::NOT_FOUND, '商品不存在');
            }

            // 行锁：并发改同一 SKU 时避免后写覆盖先写
            $stmt = $this->pdo->prepare(
                "SELECT `id`, `goods_id`, `price`, `inventory` FROM `{$p}goods_spec_base` "
                . "WHERE `id` = :sid AND `goods_id` = :gid LIMIT 1 FOR UPDATE"
            );
            $stmt->execute(['sid' => $skuId, 'gid' => $goodsId]);
            $sku = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($sku === false) {
                $this->pdo->rollBack();

                return $this->fail(RejectionCode::NOT_FOUND, 'SKU 不存在或不属于该商品');
            }

            $sets = ['`price` = :price', '`inventory` = :inventory'];
            $params = [
                'price' => $priceFen !== null
                    ? OrderRowMapper::fenToDecimal($priceFen)
                    : (string) $sku['price'],
                'inventory' => $inventory ?? (int) $sku['inventory'],
                'sid' => $skuId,
            ];

            $this->pdo->prepare(
                "UPDATE `{$p}goods_spec_base` SET " . implode(', ', $sets) . " WHERE `id` = :sid"
            )->execute($params);

            $this->refreshGoodsAggregates($goodsId);

            $this->writeAudit($actorId, 'GOODS_SKU_UPDATE', $goodsId, [
                'sku_id' => $skuId,
                'price_from_fen' => OrderRowMapper::decimalToFen((string) $sku['price']),
                'price_to_fen' => $priceFen,
                'inventory_from' => (int) $sku['inventory'],
                'inventory_to' => $inventory,
            ]);

            $this->pdo->commit();

            return $this->goodsDetail($goodsId);
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    // =========================================================
    // 内部
    // =========================================================

    /**
     * 重算 `shopxo_goods` 上的冗余聚合字段。
     *
     * `price` 是 char(60)：
     *   单规格 → "2100.12"
     *   多规格 → "4500.00-5500.00"（范围字符串）
     * 这两个格式都出现在真实数据里，改动价格后必须按当前 SKU 情况重写。
     */
    private function refreshGoodsAggregates(string $goodsId): void
    {
        $p = $this->tablePrefix;

        $stmt = $this->pdo->prepare(
            "SELECT MIN(`price`) AS min_p, MAX(`price`) AS max_p, COALESCE(SUM(`inventory`), 0) AS total_inv "
            . "FROM `{$p}goods_spec_base` WHERE `goods_id` = :gid"
        );
        $stmt->execute(['gid' => $goodsId]);
        $agg = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];

        if ($agg === [] || $agg['min_p'] === null) {
            // 该商品没有任何 SKU —— 不覆盖现有值，避免把数据抹掉
            return;
        }

        $minPrice = (string) $agg['min_p'];
        $maxPrice = (string) $agg['max_p'];
        $totalInventory = (int) $agg['total_inv'];

        $priceText = ($minPrice === $maxPrice)
            ? $minPrice
            : $minPrice . '-' . $maxPrice;

        $this->pdo->prepare(
            "UPDATE `{$p}goods` SET `price` = :price, `min_price` = :min_p, `max_price` = :max_p, "
            . "`inventory` = :inv, `upd_time` = UNIX_TIMESTAMP() WHERE `id` = :id"
        )->execute([
            'price' => $priceText,
            'min_p' => $minPrice,
            'max_p' => $maxPrice,
            'inv' => $totalInventory,
            'id' => $goodsId,
        ]);
    }

    /**
     * 商品详情 + 操作结果提示。
     *
     * 【为什么上/下架要回详情，而不是只回 `{goods_id, is_shelves}`】
     * 客户端把响应的 `data` 直接反序列化为商品对象（与改价接口同一份契约）。
     * 只回两个字段会因缺 `id` / `title` 等必填字段而解析失败 ——
     * 结果是：下架**实际成功了**，店主却看到「服务端返回了无法解析的数据」，
     * 于是以为没生效而反复点击。回详情还让客户端无需再拉一次列表。
     */
    private function goodsDetailWithMessage(string $goodsId, string $message): array
    {
        $detail = $this->goodsDetail($goodsId);
        if ($detail['code'] !== 'OK') {
            return $detail;
        }

        $detail['message'] = $message;

        return $detail;
    }

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
            'detail_json' => json_encode(
                array_merge(['goods_id' => $goodsId], $detail),
                JSON_UNESCAPED_UNICODE
            ),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** @param array<string,mixed> $row */
    private function serializeGoodsSummary(array $row): array
    {
        $minFen = OrderRowMapper::decimalToFen((string) ($row['min_price'] ?? 0));
        $maxFen = OrderRowMapper::decimalToFen((string) ($row['max_price'] ?? 0));

        return [
            'id' => (string) $row['id'],
            'title' => (string) $row['title'],
            'image' => (string) ($row['images'] ?? ''),
            'simple_desc' => (string) ($row['simple_desc'] ?? ''),
            'spec_desc' => (string) ($row['spec_desc'] ?? ''),
            'inventory_unit' => (string) ($row['inventory_unit'] ?? ''),
            'inventory' => (int) ($row['inventory'] ?? 0),
            'sales_count' => (int) ($row['sales_count'] ?? 0),
            'is_shelves' => ((int) ($row['is_shelves'] ?? 0)) === 1,
            'has_many_spec' => ((int) ($row['is_exist_many_spec'] ?? 0)) === 1,
            // 对外一律整数分，不输出「元」字符串
            'min_price_fen' => $minFen,
            'max_price_fen' => $maxFen,
            'is_out_of_stock' => ((int) ($row['inventory'] ?? 0)) === 0,
        ];
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
