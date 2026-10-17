-- Install/Upgrade reruns every migration file, so make this historical schema
-- change safe after the column has already been created.
SET @cl_payment_qr_sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'veg_shop_setting'
       AND COLUMN_NAME = 'payment_qr_url') = 0,
    'ALTER TABLE `veg_shop_setting` ADD COLUMN `payment_qr_url` varchar(500) NOT NULL DEFAULT '''' COMMENT ''店主实际收款二维码图片''',
    'SET @cl_migration_noop = 1'
);
PREPARE cl_payment_qr_stmt FROM @cl_payment_qr_sql;
EXECUTE cl_payment_qr_stmt;
DEALLOCATE PREPARE cl_payment_qr_stmt;
