-- 回滚店铺首页每日鲜选商品配置字段。
SET @cl_home_featured_rollback_sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'veg_shop_profile'
       AND COLUMN_NAME = 'home_featured_product_ids') > 0,
    'ALTER TABLE `veg_shop_profile` DROP COLUMN `home_featured_product_ids`',
    'SET @cl_migration_noop = 1'
);
PREPARE cl_home_featured_rollback_stmt FROM @cl_home_featured_rollback_sql;
EXECUTE cl_home_featured_rollback_stmt;
DEALLOCATE PREPARE cl_home_featured_rollback_stmt;
