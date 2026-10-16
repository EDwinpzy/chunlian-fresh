<?php

declare(strict_types=1);

namespace Chunlian\Service;

use Chunlian\Domain\RejectionCode;
use Chunlian\Repository\OrderRowMapper;

/**
 * 顾客端商品目录（只读）。
 *
 * 【为什么顾客端自己读库，而不是转发 ShopXO 的 /api.php?s=/goods/...】
 * 三个现实原因：
 *   1. ShopXO 原生接口的字段名与金额单位（元字符串）跟本项目其余接口
 *      （一律整数分）不一致，客户端要在两套口径之间来回转换，极易出错；
 *   2. 原生接口没有「同一商品多图 + SKU 价格区间 + 库存状态」的稳定契约，
 *      而这三项恰好是生鲜商品卡与详情页必须的；
 *   3. 顾客端要按本项目的设计规范（方案 §4/§5）输出，字段命名必须可控。
 *
 * 本服务不写商品或订单数据。迁移后首次读取时会检查并一次性登记旧版
 * 固定生鲜根分类的 ID；若旧分类尚未创建，检查会在后续读取时重试。
 * 下单与改单在 CustomerOrderService / RevisionService。
 */
final class CustomerCatalogService
{
    public function __construct(
        private readonly \PDO $pdo,
        private readonly string $tablePrefix = 'shopxo_',
        /** 图片绝对地址前缀，例如 http://103.236.97.137:8095 */
        private readonly string $assetBaseUrl = '',
    ) {
        FreshCategoryPolicy::ensureLegacyRoots($this->pdo, $this->tablePrefix);
    }

    /**
     * 分类树：只返回一级分类及其直接子类（本项目的品类很浅，不做无限层级）。
     *
     * 【排序为什么是 sort DESC】
     * `sort` 越大越靠前，与 `shopxo_goods.sort_level` 同一套惯例
     * （否则同一个后台里两个字段排序方向相反，店主调完顺序会发现前后端不一致）。
     * 商城原有演示分类保留在 ShopXO 数据中，但不会暴露给本项目顾客端；
     * 根分类 ID 由商家配置并持久化，根类停用时子类也会从顾客端隐藏。
     */
    public function categories(): array
    {
        $p = $this->tablePrefix;
        $rows = $this->pdo->query(
            "SELECT `id`, `pid`, `name`, `icon`, `icon_active` FROM `{$p}goods_category` "
            . "WHERE `is_enable` = 1 ORDER BY `sort` DESC, `id` ASC"
        )->fetchAll(\PDO::FETCH_ASSOC);
        $rows = FreshCategoryPolicy::filterEnabledRows(
            $rows,
            FreshCategoryPolicy::configuredRootIds($this->pdo, $p),
        );

        $roots = [];
        $children = [];
        foreach ($rows as $r) {
            $pid = (int) $r['pid'];
            if ($pid === 0) {
                $roots[] = $r;
            } else {
                $children[$pid][] = $r;
            }
        }

        $list = [];
        foreach ($roots as $r) {
            $id = (int) $r['id'];
            $list[] = [
                'id' => (string) $id,
                'name' => (string) $r['name'],
                'icon_url' => $this->assetUrl((string) $r['icon']),
                'children' => array_map(fn (array $c): array => [
                    'id' => (string) $c['id'],
                    'name' => (string) $c['name'],
                    'icon_url' => $this->assetUrl((string) $c['icon']),
                ], $children[$id] ?? []),
            ];
        }

        return $this->ok($list);
    }

    /**
     * 商品列表（顾客可见口径：未删除 + 已上架）。
     *
     * @param string|null $categoryId 分类筛选（含子分类）
     * @param string|null $keyword    标题模糊搜索
     * @param string|null $sort       new=最新 / price_asc / price_desc / sales
     */
    public function goodsList(
        ?string $categoryId,
        ?string $keyword,
        ?string $sort,
        int $page,
        int $pageSize,
    ): array {
        $page = max(1, $page);
        $pageSize = min(50, max(1, $pageSize));
        $p = $this->tablePrefix;
        FreshCategoryPolicy::ensureLegacyRoots($this->pdo, $p);

        // 顾客侧永远只看已上架且未删除的商品 —— 这是硬约束，
        // 不提供「包含下架」的开关，避免调用方误传导致下架商品泄露到前端
        $where = [
            'g.`is_delete_time` = 0',
            'g.`is_shelves` = 1',
            FreshCategoryPolicy::goodsPredicate($p, 'g'),
        ];
        $params = [];

        if ($categoryId !== null && $categoryId !== '') {
            if (!FreshCategoryPolicy::isAllowed($this->pdo, $p, $categoryId)) {
                return $this->ok([
                    'list' => [],
                    'total' => 0,
                    'page' => $page,
                    'page_size' => $pageSize,
                    'has_more' => false,
                ]);
            }
            // 【为什么用 EXISTS 而不是 JOIN + DISTINCT】
            // 一个商品可以挂在多个分类下，用 JOIN 会产生重复行，必须 DISTINCT 去重。
            // 但 MySQL 的 `SELECT DISTINCT ... ORDER BY g.sort_level` 会直接报
            // 3065：ORDER BY 的列不在 SELECT 列表里，与 DISTINCT 不兼容 ——
            // 而 sort_level 又必须参与排序。EXISTS 子查询天然不产生重复行，
            // 也就不需要 DISTINCT，排序可以自由使用任意列。
            $where[] = 'EXISTS ('
                . "SELECT 1 FROM `{$p}goods_category_join` cj WHERE cj.`goods_id` = g.`id` AND ("
                . 'cj.`category_id` = :cid OR cj.`category_id` IN ('
                . "SELECT `id` FROM `{$p}goods_category` WHERE `pid` = :cid2)))";
            $params['cid'] = (int) $categoryId;
            $params['cid2'] = (int) $categoryId;
        }

        if ($keyword !== null && trim($keyword) !== '') {
            $where[] = 'g.`title` LIKE :kw';
            $params['kw'] = '%' . trim($keyword) . '%';
        }

        $orderBy = match ($sort) {
            'price_asc' => 'g.`min_price` ASC, g.`id` DESC',
            'price_desc' => 'g.`min_price` DESC, g.`id` DESC',
            'sales' => 'g.`sales_count` DESC, g.`id` DESC',
            default => 'g.`sort_level` DESC, g.`id` DESC',
        };

        $whereSql = implode(' AND ', $where);

        $countStmt = $this->pdo->prepare(
            "SELECT COUNT(*) AS c FROM `{$p}goods` g WHERE {$whereSql}"
        );
        $countStmt->execute($params);
        $total = (int) ($countStmt->fetch(\PDO::FETCH_ASSOC)['c'] ?? 0);

        $offset = ($page - 1) * $pageSize;
        $stmt = $this->pdo->prepare(
            "SELECT g.`id`, g.`title`, g.`images`, g.`simple_desc`, g.`spec_desc`, "
            . "g.`inventory`, g.`inventory_unit`, g.`min_price`, g.`max_price`, g.`sales_count`, "
            . "g.`is_exist_many_spec` "
            . "FROM `{$p}goods` g WHERE {$whereSql} "
            . "ORDER BY {$orderBy} LIMIT {$pageSize} OFFSET {$offset}"
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
            'has_more' => $offset + count($list) < $total,
        ]);
    }

    /** 商品详情（含 SKU）。 */
    public function goodsDetail(string $goodsId): array
    {
        FreshCategoryPolicy::ensureLegacyRoots($this->pdo, $this->tablePrefix);
        if (preg_match('/^\d+$/', $goodsId) !== 1) {
            return $this->fail(RejectionCode::NOT_FOUND, '商品不存在');
        }

        $p = $this->tablePrefix;
        $stmt = $this->pdo->prepare(
            "SELECT `id`, `title`, `images`, `simple_desc`, `spec_desc`, `content_web`, "
            . "`inventory`, `inventory_unit`, `min_price`, `max_price`, `original_price`, "
            . "`sales_count`, `is_exist_many_spec`, `buy_min_number`, `buy_max_number`, "
            . "`produce_region`, `produce_company` "
            . "FROM `{$p}goods` g WHERE g.`id` = :id AND g.`is_delete_time` = 0 "
            . 'AND g.`is_shelves` = 1 AND ' . FreshCategoryPolicy::goodsPredicate($p, 'g') . ' LIMIT 1'
        );
        $stmt->execute(['id' => $goodsId]);
        $goods = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($goods === false) {
            return $this->fail(RejectionCode::NOT_FOUND, '商品已下架或不存在');
        }

        $skuStmt = $this->pdo->prepare(
            "SELECT `id`, `price`, `original_price`, `inventory`, `inventory_unit` "
            . "FROM `{$p}goods_spec_base` WHERE `goods_id` = :gid ORDER BY `price` ASC, `id` ASC"
        );
        $skuStmt->execute(['gid' => $goodsId]);
        $skus = $skuStmt->fetchAll(\PDO::FETCH_ASSOC);

        // 规格文本（「约500g/份」这类）按 SKU 归组
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
            $inventory = (int) $s['inventory'];
            $skuList[] = [
                'sku_id' => (string) $skuId,
                'spec_text' => implode('，', $valuesBySku[$skuId] ?? []) ?: '默认规格',
                'price_fen' => OrderRowMapper::decimalToFen((string) $s['price']),
                'original_price_fen' => OrderRowMapper::decimalToFen((string) $s['original_price']),
                'inventory' => $inventory,
                'is_out_of_stock' => $inventory <= 0,
            ];
        }

        $summary = $this->serializeGoodsSummary($goods);
        $summary['skus'] = $skuList;
        $summary['images'] = $this->imageList((string) $goods['images']);
        $summary['content'] = $this->plainText((string) ($goods['content_web'] ?? ''));
        $summary['origin'] = (string) ($goods['produce_region'] ?? '');
        $summary['buy_min_number'] = max(1, (int) ($goods['buy_min_number'] ?? 1));
        $summary['buy_max_number'] = (int) ($goods['buy_max_number'] ?? 0);

        return $this->ok($summary);
    }

    /**
     * 按 SKU ID 批量取「当前价 + 库存 + 名称快照」。
     *
     * 下单与改单都要用，抽出来避免两处各写一遍 SQL 而慢慢分叉。
     *
     * @param string[] $skuIds
     * @return array<string,array{sku_id:string,goods_id:string,title:string,spec_text:string,
     *                            image:string,unit_fen:int,inventory:int,is_shelves:bool}>
     */
    public function skuMap(array $skuIds): array
    {
        FreshCategoryPolicy::ensureLegacyRoots($this->pdo, $this->tablePrefix);
        $skuIds = array_values(array_unique(array_filter(
            $skuIds,
            // 注意：PHP 会把纯数字的字符串键转成 int，调用方传进来的可能是 int，
            // 这里两种都接受，否则「购物车里明明有货」却报「商品不存在」
            static fn ($v): bool => (is_string($v) || is_int($v)) && (string) $v !== ''
        )));
        if ($skuIds === []) {
            return [];
        }

        $p = $this->tablePrefix;
        // 参数是服务端解析出来的纯数字字符串，这里再做一次白名单过滤，
        // 保证拼进 IN 列表的内容一定是数字（不依赖调用方永远传对）
        $safe = [];
        foreach ($skuIds as $id) {
            $id = (string) $id;
            if (preg_match('/^\d+$/', $id) === 1) {
                $safe[] = $id;
            }
        }
        if ($safe === []) {
            return [];
        }

        $in = implode(',', $safe);
        $rows = $this->pdo->query(
            "SELECT b.`id` AS sku_id, b.`goods_id`, b.`price`, b.`inventory`, "
            . "g.`title`, g.`images`, g.`is_shelves`, g.`is_delete_time`, g.`inventory_unit` "
            . "FROM `{$p}goods_spec_base` b "
            . "INNER JOIN `{$p}goods` g ON g.`id` = b.`goods_id` "
            . "WHERE b.`id` IN ({$in}) AND " . FreshCategoryPolicy::goodsPredicate($p, 'g')
        )->fetchAll(\PDO::FETCH_ASSOC);

        $valStmt = $this->pdo->prepare(
            "SELECT `goods_spec_base_id`, `value` FROM `{$p}goods_spec_value` WHERE `goods_spec_base_id` IN ({$in})"
        );
        $valStmt->execute();
        $valuesBySku = [];
        foreach ($valStmt->fetchAll(\PDO::FETCH_ASSOC) as $v) {
            $valuesBySku[(int) $v['goods_spec_base_id']][] = (string) $v['value'];
        }

        $map = [];
        foreach ($rows as $r) {
            $skuId = (string) $r['sku_id'];
            $map[$skuId] = [
                'sku_id' => $skuId,
                'goods_id' => (string) $r['goods_id'],
                'title' => (string) $r['title'],
                'spec_text' => implode('，', $valuesBySku[(int) $r['sku_id']] ?? []) ?: '默认规格',
                'image' => $this->assetUrl($this->firstImage((string) $r['images'])),
                'unit_fen' => OrderRowMapper::decimalToFen((string) $r['price']),
                'inventory' => (int) $r['inventory'],
                'inventory_unit' => (string) ($r['inventory_unit'] ?? ''),
                'is_shelves' => ((int) $r['is_shelves']) === 1,
                'is_deleted' => ((int) $r['is_delete_time']) !== 0,
            ];
        }

        return $map;
    }

    // =========================================================
    // 内部
    // =========================================================

    /** @param array<string,mixed> $row */
    private function serializeGoodsSummary(array $row): array
    {
        $minFen = OrderRowMapper::decimalToFen((string) ($row['min_price'] ?? 0));
        $maxFen = OrderRowMapper::decimalToFen((string) ($row['max_price'] ?? 0));
        $inventory = (int) ($row['inventory'] ?? 0);

        return [
            'id' => (string) $row['id'],
            'title' => (string) $row['title'],
            'image_url' => $this->assetUrl($this->firstImage((string) ($row['images'] ?? ''))),
            'simple_desc' => (string) ($row['simple_desc'] ?? ''),
            'spec_desc' => (string) ($row['spec_desc'] ?? ''),
            'inventory_unit' => (string) ($row['inventory_unit'] ?? ''),
            'inventory' => $inventory,
            'sales_count' => (int) ($row['sales_count'] ?? 0),
            'has_many_spec' => ((int) ($row['is_exist_many_spec'] ?? 0)) === 1,
            'min_price_fen' => $minFen,
            'max_price_fen' => $maxFen,
            // 顾客端只需要「能否下单」这一个判断，服务端算好，避免两端各写一套
            'is_out_of_stock' => $inventory <= 0,
        ];
    }

    /** `shopxo_goods.images` 是逗号分隔的路径列表。 */
    private function firstImage(string $images): string
    {
        foreach (explode(',', $images) as $one) {
            $one = trim($one);
            if ($one !== '') {
                return $one;
            }
        }

        return '';
    }

    /** @return string[] */
    private function imageList(string $images): array
    {
        $out = [];
        foreach (explode(',', $images) as $one) {
            $one = trim($one);
            if ($one !== '') {
                $out[] = $this->assetUrl($one);
            }
        }

        return $out;
    }

    private function assetUrl(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $path) === 1) {
            return $path;
        }

        return rtrim($this->assetBaseUrl, '/') . '/' . ltrim($path, '/');
    }

    /** 商品详情富文本去掉标签，只保留可读文本（顾客端不渲染任意 HTML）。 */
    private function plainText(string $html): string
    {
        $text = (string) preg_replace('#<br\s*/?>#i', "\n", $html);
        $text = (string) preg_replace('#</p>#i', "\n", $text);
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace("/\n{3,}/", "\n\n", $text));
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
