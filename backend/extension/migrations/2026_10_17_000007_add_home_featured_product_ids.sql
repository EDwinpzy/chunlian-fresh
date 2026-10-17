-- 店铺首页每日鲜选商品按店家选择顺序保存为 JSON 字符串数组。
SET @cl_home_featured_sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'veg_shop_profile'
       AND COLUMN_NAME = 'home_featured_product_ids') = 0,
    'ALTER TABLE `veg_shop_profile` ADD COLUMN `home_featured_product_ids` text NULL COMMENT ''每日鲜选商品 ID JSON 数组''',
    'SET @cl_migration_noop = 1'
);
PREPARE cl_home_featured_stmt FROM @cl_home_featured_sql;
EXECUTE cl_home_featured_stmt;
DEALLOCATE PREPARE cl_home_featured_stmt;

SET @cl_home_featured_nullable = (
    SELECT IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'veg_shop_profile'
      AND COLUMN_NAME = 'home_featured_product_ids'
);

-- Bootstrap replays migrations on requests. Backfill and alter only while the
-- column is still nullable, so later requests do not scan or rebuild the table.
SET @cl_home_featured_backfill_sql = IF(
    @cl_home_featured_nullable = 'YES',
    'UPDATE `veg_shop_profile` SET `home_featured_product_ids` = ''[]'' WHERE `home_featured_product_ids` IS NULL OR `home_featured_product_ids` = ''''',
    'SELECT 1'
);
PREPARE cl_home_featured_backfill_stmt FROM @cl_home_featured_backfill_sql;
EXECUTE cl_home_featured_backfill_stmt;
DEALLOCATE PREPARE cl_home_featured_backfill_stmt;

SET @cl_home_featured_alter_sql = IF(
    @cl_home_featured_nullable = 'YES',
    'ALTER TABLE `veg_shop_profile` MODIFY COLUMN `home_featured_product_ids` text NOT NULL COMMENT ''每日鲜选商品 ID JSON 数组''',
    'SELECT 1'
);
PREPARE cl_home_featured_alter_stmt FROM @cl_home_featured_alter_sql;
EXECUTE cl_home_featured_alter_stmt;
DEALLOCATE PREPARE cl_home_featured_alter_stmt;
