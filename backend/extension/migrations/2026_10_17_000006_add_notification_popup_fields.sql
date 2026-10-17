-- 顾客端首页通知弹窗内容。Install/Upgrade会重跑全部迁移，按字段是否存在幂等添加。
SET @cl_popup_title_sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'veg_shop_profile'
       AND COLUMN_NAME = 'notification_popup_title') = 0,
    'ALTER TABLE `veg_shop_profile` ADD COLUMN `notification_popup_title` varchar(80) NOT NULL DEFAULT '''' COMMENT ''顾客端通知弹窗标题''',
    'SET @cl_migration_noop = 1'
);
PREPARE cl_popup_title_stmt FROM @cl_popup_title_sql;
EXECUTE cl_popup_title_stmt;
DEALLOCATE PREPARE cl_popup_title_stmt;

SET @cl_popup_image_sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'veg_shop_profile'
       AND COLUMN_NAME = 'notification_popup_image_url') = 0,
    'ALTER TABLE `veg_shop_profile` ADD COLUMN `notification_popup_image_url` varchar(500) NOT NULL DEFAULT '''' COMMENT ''顾客端通知弹窗图片地址''',
    'SET @cl_migration_noop = 1'
);
PREPARE cl_popup_image_stmt FROM @cl_popup_image_sql;
EXECUTE cl_popup_image_stmt;
DEALLOCATE PREPARE cl_popup_image_stmt;
