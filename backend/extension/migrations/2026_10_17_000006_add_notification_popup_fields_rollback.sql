-- 回滚顾客端首页通知弹窗字段。
SET @cl_popup_image_rollback_sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'veg_shop_profile'
       AND COLUMN_NAME = 'notification_popup_image_url') > 0,
    'ALTER TABLE `veg_shop_profile` DROP COLUMN `notification_popup_image_url`',
    'SET @cl_migration_noop = 1'
);
PREPARE cl_popup_image_rollback_stmt FROM @cl_popup_image_rollback_sql;
EXECUTE cl_popup_image_rollback_stmt;
DEALLOCATE PREPARE cl_popup_image_rollback_stmt;

SET @cl_popup_title_rollback_sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'veg_shop_profile'
       AND COLUMN_NAME = 'notification_popup_title') > 0,
    'ALTER TABLE `veg_shop_profile` DROP COLUMN `notification_popup_title`',
    'SET @cl_migration_noop = 1'
);
PREPARE cl_popup_title_rollback_stmt FROM @cl_popup_title_rollback_sql;
EXECUTE cl_popup_title_rollback_stmt;
DEALLOCATE PREPARE cl_popup_title_rollback_stmt;
