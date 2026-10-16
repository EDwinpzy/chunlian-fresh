<?php

declare(strict_types=1);

namespace Chunlian\Service;

/**
 * Defines which ShopXO categories belong to the Chunlian Fresh catalog.
 *
 * Root category IDs are explicitly stored in `veg_fresh_category_root`. The
 * six names below are only a one-time bridge for stores upgrading from the
 * original fixed-category release; runtime catalog filtering never depends
 * on category names.
 */
final class FreshCategoryPolicy
{
    private const LEGACY_ROOT_NAMES = [
        '叶菜类',
        '瓜果类',
        '根茎类',
        '蛋豆类',
        '菌菇类',
        '调味配菜',
    ];

    /**
     * Seed the explicit root registry once for an existing installation.
     * The state row prevents a deliberately emptied registry from being
     * repopulated on later requests.
     */
    public static function ensureLegacyRoots(\PDO $pdo, string $tablePrefix): void
    {
        self::assertTablePrefix($tablePrefix);

        $state = $pdo->query(
            'SELECT `legacy_seeded` FROM `veg_fresh_category_state` WHERE `id` = 1 LIMIT 1'
        )->fetchColumn();
        if ($state === false) {
            throw new \RuntimeException('生鲜分类配置未初始化，请先运行扩展数据库迁移');
        }
        if ((int) $state === 1) {
            return;
        }

        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        try {
            $stateStmt = $pdo->query(
                'SELECT `legacy_seeded` FROM `veg_fresh_category_state` WHERE `id` = 1 LIMIT 1 FOR UPDATE'
            );
            $state = $stateStmt->fetchColumn();
            if ($state === false) {
                throw new \RuntimeException('生鲜分类配置未初始化，请先运行扩展数据库迁移');
            }

            if ((int) $state === 0) {
                $findLegacy = $pdo->prepare(
                    "SELECT `id`, `name` FROM `{$tablePrefix}goods_category` WHERE `pid` = 0"
                );
                $findLegacy->execute();
                $legacyNames = array_fill_keys(self::LEGACY_ROOT_NAMES, true);
                $insertRoot = $pdo->prepare(
                    'INSERT IGNORE INTO `veg_fresh_category_root` (`category_id`, `created_at`) '
                    . 'VALUES (:category_id, NOW())'
                );
                $foundLegacy = false;

                foreach ($findLegacy->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                    if (!isset($legacyNames[(string) $row['name']])) {
                        continue;
                    }
                    $foundLegacy = true;
                    $insertRoot->execute(['category_id' => (int) $row['id']]);
                }

                // Keep waiting if the original categories have not been created
                // yet; the first merchant-created category seals this import.
                if ($foundLegacy) {
                    $pdo->exec(
                        'UPDATE `veg_fresh_category_state` SET `legacy_seeded` = 1, `updated_at` = NOW() WHERE `id` = 1'
                    );
                }
            }

            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @return list<string> */
    public static function configuredRootIds(\PDO $pdo, string $tablePrefix): array
    {
        self::ensureLegacyRoots($pdo, $tablePrefix);
        $rows = $pdo->query(
            'SELECT `category_id` FROM `veg_fresh_category_root` ORDER BY `category_id` ASC'
        )->fetchAll(\PDO::FETCH_COLUMN);

        return array_map(static fn (mixed $id): string => (string) (int) $id, $rows);
    }

    /** @param list<array<string,mixed>> $rows @param list<string|int> $configuredRootIds */
    public static function filterEnabledRows(array $rows, array $configuredRootIds): array
    {
        $configured = array_fill_keys(array_map('strval', $configuredRootIds), true);
        $enabledRoots = [];
        foreach ($rows as $row) {
            $id = (string) (int) ($row['id'] ?? 0);
            if ((int) ($row['pid'] ?? 0) === 0 && isset($configured[$id])) {
                $enabledRoots[$id] = true;
            }
        }

        return array_values(array_filter($rows, static function (array $row) use ($configured, $enabledRoots): bool {
            $parentId = (int) ($row['pid'] ?? 0);
            $id = (string) (int) ($row['id'] ?? 0);

            return $parentId === 0
                ? isset($configured[$id])
                : isset($enabledRoots[(string) $parentId]);
        }));
    }

    /** SQL predicate for a configured root or its direct child. */
    public static function categoryPredicate(
        string $tablePrefix,
        string $alias,
        bool $requireEnabled = true,
    ): string {
        self::assertTablePrefix($tablePrefix);
        self::assertAlias($alias);

        $enabledCategory = $requireEnabled ? "{$alias}.`is_enable` = 1 AND " : '';
        $enabledRoot = $requireEnabled ? ' AND configured_root.`is_enable` = 1' : '';

        return "({$enabledCategory}("
            . "({$alias}.`pid` = 0 AND EXISTS ("
            . 'SELECT 1 FROM `veg_fresh_category_root` registered_root '
            . "WHERE registered_root.`category_id` = {$alias}.`id`)) OR "
            . "({$alias}.`pid` > 0 AND EXISTS ("
            . "SELECT 1 FROM `{$tablePrefix}goods_category` configured_root "
            . 'INNER JOIN `veg_fresh_category_root` registered_root '
            . 'ON registered_root.`category_id` = configured_root.`id` '
             . "WHERE configured_root.`id` = {$alias}.`pid` AND configured_root.`pid` = 0{$enabledRoot}"
            . '))))';
    }

    /** Restrict a goods row to a configured category, optionally including disabled categories. */
    public static function goodsPredicate(
        string $tablePrefix,
        string $goodsAlias,
        bool $requireEnabled = true,
    ): string {
        self::assertTablePrefix($tablePrefix);
        self::assertAlias($goodsAlias);

        return 'EXISTS ('
            . "SELECT 1 FROM `{$tablePrefix}goods_category_join` fresh_join "
            . "INNER JOIN `{$tablePrefix}goods_category` fresh_category "
            . 'ON fresh_category.`id` = fresh_join.`category_id` '
            . "WHERE fresh_join.`goods_id` = {$goodsAlias}.`id` AND "
            . self::categoryPredicate($tablePrefix, 'fresh_category', $requireEnabled)
            . ')';
    }

    public static function isAllowed(
        \PDO $pdo,
        string $tablePrefix,
        mixed $categoryId,
    ): bool {
        self::ensureLegacyRoots($pdo, $tablePrefix);
        $id = (string) $categoryId;
        if (preg_match('/^\\d+$/', $id) !== 1 || (int) $id <= 0) {
            return false;
        }

        $stmt = $pdo->prepare(
            "SELECT 1 FROM `{$tablePrefix}goods_category` fresh_category "
            . 'WHERE fresh_category.`id` = :id AND '
            . self::categoryPredicate($tablePrefix, 'fresh_category')
            . ' LIMIT 1' . ($pdo->inTransaction() ? ' FOR UPDATE' : '')
        );
        $stmt->execute(['id' => (int) $id]);

        return $stmt->fetchColumn() !== false;
    }

    private static function assertAlias(string $alias): void
    {
        if (preg_match('/^[a-z][a-z0-9_]*$/i', $alias) !== 1) {
            throw new \InvalidArgumentException('Invalid SQL alias');
        }
    }

    private static function assertTablePrefix(string $tablePrefix): void
    {
        if (preg_match('/^[a-zA-Z0-9_]*$/', $tablePrefix) !== 1) {
            throw new \InvalidArgumentException('Invalid database table prefix');
        }
    }
}
