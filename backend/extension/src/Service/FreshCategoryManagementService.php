<?php

declare(strict_types=1);

namespace Chunlian\Service;

use Chunlian\Domain\RejectionCode;

/** Merchant-side management for the configured fresh-food category tree. */
final class FreshCategoryManagementService
{
    private const MAX_NAME_LENGTH = 60;
    private const MAX_SORT = 65535;

    public function __construct(
        private readonly \PDO $pdo,
        private readonly string $tablePrefix = 'shopxo_',
    ) {
        if (preg_match('/^[a-zA-Z0-9_]*$/', $this->tablePrefix) !== 1) {
            throw new \InvalidArgumentException('Invalid database table prefix');
        }
    }

    /** Return all configured categories, including disabled roots and children. */
    public function list(): array
    {
        FreshCategoryPolicy::ensureLegacyRoots($this->pdo, $this->tablePrefix);
        $p = $this->tablePrefix;
        $rows = $this->pdo->query(
            "SELECT category.`id`, category.`pid`, category.`name`, category.`sort`, category.`is_enable`, "
            . "(SELECT COUNT(DISTINCT category_goods.`goods_id`) FROM `{$p}goods_category_join` category_goods "
            . "INNER JOIN `{$p}goods_category` assigned_category ON assigned_category.`id` = category_goods.`category_id` "
            . 'WHERE assigned_category.`id` = category.`id` OR assigned_category.`pid` = category.`id`) AS `product_count` '
            . "FROM `{$p}goods_category` category WHERE "
            . '(category.`pid` = 0 AND EXISTS ('
            . 'SELECT 1 FROM `veg_fresh_category_root` root WHERE root.`category_id` = category.`id`)) OR '
            . '(category.`pid` > 0 AND EXISTS ('
            . "SELECT 1 FROM `{$p}goods_category` parent "
            . 'INNER JOIN `veg_fresh_category_root` root ON root.`category_id` = parent.`id` '
            . 'WHERE parent.`id` = category.`pid` AND parent.`pid` = 0)) '
            . 'ORDER BY category.`sort` DESC, category.`id` ASC'
        )->fetchAll(\PDO::FETCH_ASSOC);

        $roots = [];
        foreach ($rows as $row) {
            if ((int) $row['pid'] !== 0) {
                continue;
            }
            $id = (string) (int) $row['id'];
            $roots[$id] = $this->serializeCategory($row, null) + ['children' => []];
        }

        foreach ($rows as $row) {
            $parentId = (string) (int) $row['pid'];
            if ($parentId === '0' || !isset($roots[$parentId])) {
                continue;
            }
            $roots[$parentId]['children'][] = $this->serializeCategory($row, $parentId) + ['children' => []];
        }

        return $this->ok(array_values($roots));
    }

    /** Create a root when parent_id is absent/null, otherwise create one direct child. */
    public function create(array $payload, string $actorId): array
    {
        $unknown = array_diff(array_keys($payload), ['name', 'parent_id', 'sort', 'is_enabled']);
        if ($unknown !== []) {
            return $this->fail(RejectionCode::VALIDATION_FAILED, '分类参数不受支持');
        }

        $name = $this->validatedName($payload['name'] ?? null);
        if (is_array($name)) {
            return $name;
        }
        $parentId = $this->parseParentId($payload['parent_id'] ?? null);
        if (is_array($parentId)) {
            return $parentId;
        }
        $sort = $this->parseSort($payload['sort'] ?? 0);
        if (is_array($sort)) {
            return $sort;
        }
        $enabled = $this->parseEnabled($payload['is_enabled'] ?? true);
        if (is_array($enabled)) {
            return $enabled;
        }

        FreshCategoryPolicy::ensureLegacyRoots($this->pdo, $this->tablePrefix);
        $p = $this->tablePrefix;
        try {
            $this->pdo->beginTransaction();
            if ($parentId !== null && !$this->isConfiguredRoot($parentId, true)) {
                $this->pdo->rollBack();
                return $this->fail(RejectionCode::VALIDATION_FAILED, '请选择有效的生鲜根分类');
            }
            if ($this->nameExists($parentId ?? 0, $name)) {
                $this->pdo->rollBack();
                return $this->fail(RejectionCode::VALIDATION_FAILED, '同一分组下已存在相同名称的分类');
            }

            $now = time();
            $stmt = $this->pdo->prepare(
                "INSERT INTO `{$p}goods_category` "
                . '(`pid`, `name`, `vice_name`, `icon`, `icon_active`, `describe`, `bg_color`, '
                . '`is_home_recommended`, `sort`, `is_enable`, `add_time`, `upd_time`) '
                . 'VALUES (:pid, :name, \'\', \'\', \'\', \'\', \'#FFFFFF\', 0, :sort, :enabled, :added_at, :updated_at)'
            );
            $stmt->execute([
                'pid' => $parentId ?? 0,
                'name' => $name,
                'sort' => $sort,
                'enabled' => $enabled ? 1 : 0,
                'added_at' => $now,
                'updated_at' => $now,
            ]);
            $id = (string) (int) $this->pdo->lastInsertId();

            if ($parentId === null) {
                $this->pdo->prepare(
                    'INSERT INTO `veg_fresh_category_root` (`category_id`, `created_at`) VALUES (:id, NOW())'
                )->execute(['id' => (int) $id]);
            }
            $this->pdo->exec(
                'UPDATE `veg_fresh_category_state` SET `legacy_seeded` = 1, `updated_at` = NOW() WHERE `id` = 1'
            );

            $category = [
                'id' => $id,
                'name' => $name,
                'parent_id' => $parentId === null ? null : (string) $parentId,
                'sort' => $sort,
                'is_enabled' => $enabled,
            ];
            $this->writeAudit($actorId, 'FRESH_CATEGORY_CREATE', null, $category);
            $this->pdo->commit();

            return $this->ok($category, '分类已创建');
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /** Patch a category name, display order, and/or enabled state. */
    public function update(string $id, array $payload, string $actorId): array
    {
        $unknown = array_diff(array_keys($payload), ['name', 'sort', 'is_enabled']);
        if ($unknown !== []) {
            return $this->fail(RejectionCode::VALIDATION_FAILED, '分类参数不受支持');
        }
        if ($payload === []) {
            return $this->fail(RejectionCode::VALIDATION_FAILED, '请至少修改一个分类字段');
        }
        $categoryId = $this->parseId($id);
        if ($categoryId === null) {
            return $this->fail(RejectionCode::VALIDATION_FAILED, '分类 ID 不正确');
        }

        $patch = [];
        if (array_key_exists('name', $payload)) {
            $name = $this->validatedName($payload['name']);
            if (is_array($name)) {
                return $name;
            }
            $patch['name'] = $name;
        }
        if (array_key_exists('sort', $payload)) {
            $sort = $this->parseSort($payload['sort']);
            if (is_array($sort)) {
                return $sort;
            }
            $patch['sort'] = $sort;
        }
        if (array_key_exists('is_enabled', $payload)) {
            $enabled = $this->parseEnabled($payload['is_enabled']);
            if (is_array($enabled)) {
                return $enabled;
            }
            $patch['is_enabled'] = $enabled;
        }

        FreshCategoryPolicy::ensureLegacyRoots($this->pdo, $this->tablePrefix);
        try {
            $this->pdo->beginTransaction();
            $before = $this->findConfiguredCategory($categoryId, true);
            if ($before === null) {
                $this->pdo->rollBack();
                return $this->fail(RejectionCode::NOT_FOUND, '分类不存在');
            }

            if (isset($patch['name']) && $patch['name'] !== $before['name']) {
                if ($this->nameExists($before['parent_id'] === null ? 0 : (int) $before['parent_id'], $patch['name'], $categoryId)) {
                    $this->pdo->rollBack();
                    return $this->fail(RejectionCode::VALIDATION_FAILED, '同一分组下已存在相同名称的分类');
                }
            }

            $sets = [];
            $params = ['id' => $categoryId];
            foreach ($patch as $key => $value) {
                if ($key === 'is_enabled') {
                    $sets[] = '`is_enable` = :is_enable';
                    $params['is_enable'] = $value ? 1 : 0;
                } else {
                    $sets[] = "`{$key}` = :{$key}";
                    $params[$key] = $value;
                }
            }
            $sets[] = '`upd_time` = :upd_time';
            $params['upd_time'] = time();
            $this->pdo->prepare(
                'UPDATE `' . $this->tablePrefix . 'goods_category` SET ' . implode(', ', $sets) . ' WHERE `id` = :id'
            )->execute($params);

            $after = array_replace($before, $patch);
            $this->writeAudit($actorId, 'FRESH_CATEGORY_UPDATE', $before, $after);
            $this->pdo->commit();

            return $this->ok($after, '分类已保存');
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /** Delete only an unused category with no children or product associations. */
    public function delete(string $id, string $actorId): array
    {
        $categoryId = $this->parseId($id);
        if ($categoryId === null) {
            return $this->fail(RejectionCode::VALIDATION_FAILED, '分类 ID 不正确');
        }

        FreshCategoryPolicy::ensureLegacyRoots($this->pdo, $this->tablePrefix);
        $p = $this->tablePrefix;
        try {
            $this->pdo->beginTransaction();
            $before = $this->findConfiguredCategory($categoryId, true);
            if ($before === null) {
                $this->pdo->rollBack();
                return $this->fail(RejectionCode::NOT_FOUND, '分类不存在');
            }

            $child = $this->pdo->prepare("SELECT 1 FROM `{$p}goods_category` WHERE `pid` = :id LIMIT 1");
            $child->execute(['id' => $categoryId]);
            if ($child->fetchColumn() !== false) {
                $this->pdo->rollBack();
                return $this->fail(RejectionCode::VALIDATION_FAILED, '该分类下还有子分类，请先移除子分类');
            }

            $goods = $this->pdo->prepare(
                "SELECT 1 FROM `{$p}goods_category_join` WHERE `category_id` = :id LIMIT 1"
            );
            $goods->execute(['id' => $categoryId]);
            if ($goods->fetchColumn() !== false) {
                $this->pdo->rollBack();
                return $this->fail(RejectionCode::VALIDATION_FAILED, '该分类仍关联商品，请先调整商品分类');
            }

            $this->writeAudit($actorId, 'FRESH_CATEGORY_DELETE', $before, null);
            if ($before['parent_id'] === null) {
                $this->pdo->prepare('DELETE FROM `veg_fresh_category_root` WHERE `category_id` = :id')
                    ->execute(['id' => $categoryId]);
            }
            $this->pdo->prepare("DELETE FROM `{$p}goods_category` WHERE `id` = :id")
                ->execute(['id' => $categoryId]);
            $this->pdo->commit();

            return $this->ok(['id' => (string) $categoryId, 'deleted' => true], '分类已删除');
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    private function isConfiguredRoot(int $id, bool $lock): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM `' . $this->tablePrefix . 'goods_category` category '
            . 'INNER JOIN `veg_fresh_category_root` root ON root.`category_id` = category.`id` '
            . 'WHERE category.`id` = :id AND category.`pid` = 0'
            . ($lock ? ' FOR UPDATE' : '')
        );
        $stmt->execute(['id' => $id]);

        return $stmt->fetchColumn() !== false;
    }

    /** @return array{id:string,name:string,parent_id:?string,sort:int,is_enabled:bool,product_count:int}|null */
    private function findConfiguredCategory(int $id, bool $lock): ?array
    {
        $p = $this->tablePrefix;
        $stmt = $this->pdo->prepare(
            "SELECT category.`id`, category.`pid`, category.`name`, category.`sort`, category.`is_enable` "
            . "FROM `{$p}goods_category` category WHERE category.`id` = :id AND ("
            . '(category.`pid` = 0 AND EXISTS ('
            . 'SELECT 1 FROM `veg_fresh_category_root` root WHERE root.`category_id` = category.`id`)) OR '
            . '(category.`pid` > 0 AND EXISTS ('
            . "SELECT 1 FROM `{$p}goods_category` parent "
            . 'INNER JOIN `veg_fresh_category_root` root ON root.`category_id` = parent.`id` '
            . 'WHERE parent.`id` = category.`pid` AND parent.`pid` = 0))) '
            . 'LIMIT 1' . ($lock ? ' FOR UPDATE' : '')
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $this->serializeCategory($row, (int) $row['pid'] === 0 ? null : (string) (int) $row['pid']);
    }

    private function nameExists(int $parentId, string $name, ?int $excludeId = null): bool
    {
        $sql = 'SELECT 1 FROM `' . $this->tablePrefix . 'goods_category` '
            . 'WHERE `pid` = :pid AND `name` = :name';
        $params = ['pid' => $parentId, 'name' => $name];
        if ($excludeId !== null) {
            $sql .= ' AND `id` <> :exclude_id';
            $params['exclude_id'] = $excludeId;
        }
        $stmt = $this->pdo->prepare($sql . ' LIMIT 1');
        $stmt->execute($params);

        return $stmt->fetchColumn() !== false;
    }

    /** @return array{id:string,name:string,parent_id:?string,sort:int,is_enabled:bool,product_count:int} */
    private function serializeCategory(array $row, ?string $parentId): array
    {
        return [
            'id' => (string) (int) $row['id'],
            'name' => (string) $row['name'],
            'parent_id' => $parentId,
            'sort' => (int) $row['sort'],
            'is_enabled' => (int) $row['is_enable'] === 1,
            'product_count' => (int) ($row['product_count'] ?? 0),
        ];
    }

    /** @return string|array */
    private function validatedName(mixed $value): string|array
    {
        if (!is_string($value)) {
            return $this->fail(RejectionCode::VALIDATION_FAILED, '分类名称必须是文本');
        }
        $name = trim($value);
        if ($name === '') {
            return $this->fail(RejectionCode::VALIDATION_FAILED, '分类名称不能为空');
        }
        if (mb_strlen($name) > self::MAX_NAME_LENGTH) {
            return $this->fail(RejectionCode::VALIDATION_FAILED, '分类名称不能超过 60 个字');
        }

        return $name;
    }

    /** @return int|null|array */
    private function parseParentId(mixed $value): int|null|array
    {
        if ($value === null) {
            return null;
        }
        $id = $this->parseId($value);
        if ($id === null) {
            return $this->fail(RejectionCode::VALIDATION_FAILED, '上级分类 ID 不正确');
        }

        return $id;
    }

    /** @return int|array */
    private function parseSort(mixed $value): int|array
    {
        if (!is_int($value) || $value < 0 || $value > self::MAX_SORT) {
            return $this->fail(RejectionCode::VALIDATION_FAILED, '排序值必须是 0 到 65535 的整数');
        }

        return $value;
    }

    /** @return bool|array */
    private function parseEnabled(mixed $value): bool|array
    {
        if (!is_bool($value)) {
            return $this->fail(RejectionCode::VALIDATION_FAILED, 'is_enabled 必须是布尔值');
        }

        return $value;
    }

    private function parseId(mixed $value): ?int
    {
        if ((!is_string($value) && !is_int($value)) || preg_match('/^\\d+$/', (string) $value) !== 1) {
            return null;
        }
        $id = (int) $value;

        return $id > 0 ? $id : null;
    }

    private function writeAudit(string $actorId, string $action, ?array $before, ?array $after): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO `veg_operation_log` '
            . '(`actor_type`, `actor_id`, `order_id`, `action`, `detail_json`, `created_at`) '
            . 'VALUES (\'MERCHANT\', :actor_id, NULL, :action, :detail_json, :created_at)'
        );
        $stmt->execute([
            'actor_id' => $actorId === '' ? null : (int) $actorId,
            'action' => $action,
            'detail_json' => json_encode(['before' => $before, 'after' => $after], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
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
