-- Optional schema rollback for an explicitly approved database downgrade.
-- The release rollback script does not execute this file: it restores code
-- only and leaves forward-compatible data/schema in place.
-- All deployed writers dual-write `fulfillment` and `receipt_state`, so the
-- canonical `order_status` column can be removed while preserving the 1.0.5 schema.
SET @cl_legacy_order_columns = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'veg_order_delivery'
      AND COLUMN_NAME IN ('fulfillment', 'receipt_state')
);

SET @cl_drop_order_status_expected_index_sql = IF(
    @cl_legacy_order_columns = 2
    AND
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'veg_order_delivery'
       AND INDEX_NAME = 'idx_order_status_expected') > 0,
    'ALTER TABLE `veg_order_delivery` DROP INDEX `idx_order_status_expected`',
    'SELECT 1'
);
PREPARE cl_drop_order_status_expected_index_stmt FROM @cl_drop_order_status_expected_index_sql;
EXECUTE cl_drop_order_status_expected_index_stmt;
DEALLOCATE PREPARE cl_drop_order_status_expected_index_stmt;

SET @cl_drop_order_status_receivable_index_sql = IF(
    @cl_legacy_order_columns = 2
    AND
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'veg_order_delivery'
       AND INDEX_NAME = 'idx_order_status_receivable') > 0,
    'ALTER TABLE `veg_order_delivery` DROP INDEX `idx_order_status_receivable`',
    'SELECT 1'
);
PREPARE cl_drop_order_status_receivable_index_stmt FROM @cl_drop_order_status_receivable_index_sql;
EXECUTE cl_drop_order_status_receivable_index_stmt;
DEALLOCATE PREPARE cl_drop_order_status_receivable_index_stmt;

SET @cl_drop_order_status_sql = IF(
    @cl_legacy_order_columns = 2
    AND
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'veg_order_delivery'
       AND COLUMN_NAME = 'order_status') > 0,
    'ALTER TABLE `veg_order_delivery` DROP COLUMN `order_status`',
    'SELECT 1'
);
PREPARE cl_drop_order_status_stmt FROM @cl_drop_order_status_sql;
EXECUTE cl_drop_order_status_stmt;
DEALLOCATE PREPARE cl_drop_order_status_stmt;
