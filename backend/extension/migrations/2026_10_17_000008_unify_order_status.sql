-- Expand-only compatibility migration for the 1.0.5 Android clients.
-- Keep `fulfillment` and `receipt_state` as writable legacy aliases while the
-- backend uses `order_status` as its canonical state. This migration is safe
-- to replay during ShopXO bootstrap and does not rewrite ShopXO order/payment
-- rows or create synthetic ledger entries.

SET @cl_had_order_status = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'veg_order_delivery'
      AND COLUMN_NAME = 'order_status'
);
SET @cl_had_fulfillment = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'veg_order_delivery'
      AND COLUMN_NAME = 'fulfillment'
);
SET @cl_had_receipt_state = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'veg_order_delivery'
      AND COLUMN_NAME = 'receipt_state'
);

SET @cl_add_order_status_sql = IF(
    @cl_had_order_status = 0,
    'ALTER TABLE `veg_order_delivery` ADD COLUMN `order_status` varchar(16) NOT NULL DEFAULT ''PENDING'' COMMENT ''Canonical order stage; legacy clients use fulfillment''',
    'SELECT 1'
);
PREPARE cl_add_order_status_stmt FROM @cl_add_order_status_sql;
EXECUTE cl_add_order_status_stmt;
DEALLOCATE PREPARE cl_add_order_status_stmt;

SET @cl_add_fulfillment_sql = IF(
    @cl_had_fulfillment = 0,
    'ALTER TABLE `veg_order_delivery` ADD COLUMN `fulfillment` varchar(16) NOT NULL DEFAULT ''PENDING'' COMMENT ''Legacy 1.0.5 order-stage alias''',
    'SELECT 1'
);
PREPARE cl_add_fulfillment_stmt FROM @cl_add_fulfillment_sql;
EXECUTE cl_add_fulfillment_stmt;
DEALLOCATE PREPARE cl_add_fulfillment_stmt;

SET @cl_add_receipt_state_sql = IF(
    @cl_had_receipt_state = 0,
    'ALTER TABLE `veg_order_delivery` ADD COLUMN `receipt_state` varchar(16) NOT NULL DEFAULT ''UNCONFIRMED'' COMMENT ''Legacy 1.0.5 receipt-state alias''',
    'SELECT 1'
);
PREPARE cl_add_receipt_state_stmt FROM @cl_add_receipt_state_sql;
EXECUTE cl_add_receipt_state_stmt;
DEALLOCATE PREPARE cl_add_receipt_state_stmt;

-- If a previous non-expand migration already removed either legacy column,
-- reconstruct its best available value from the canonical column first.
SET @cl_restore_fulfillment_sql = IF(
    @cl_had_fulfillment = 0 AND @cl_had_order_status = 1,
    'UPDATE `veg_order_delivery` SET `fulfillment` = IF(`receipt_state` = ''CONFIRMED'', ''FINISHED'', `order_status`)',
    'SELECT 1'
);
PREPARE cl_restore_fulfillment_stmt FROM @cl_restore_fulfillment_sql;
EXECUTE cl_restore_fulfillment_stmt;
DEALLOCATE PREPARE cl_restore_fulfillment_stmt;

SET @cl_restore_receipt_state_sql = IF(
    @cl_had_receipt_state = 0 AND @cl_had_order_status = 1,
    'UPDATE `veg_order_delivery` SET `receipt_state` = IF(`order_status` = ''FINISHED'', ''CONFIRMED'', ''UNCONFIRMED'')',
    'SELECT 1'
);
PREPARE cl_restore_receipt_state_stmt FROM @cl_restore_receipt_state_sql;
EXECUTE cl_restore_receipt_state_stmt;
DEALLOCATE PREPARE cl_restore_receipt_state_stmt;

-- Legacy rows are authoritative when an older 1.0.5 server has been restored.
-- The release runner sets @cl_legacy_sync=1 for this one-time reconciliation;
-- a fresh schema also reconciles once when order_status is first added. Normal
-- ShopXO requests replay this file, so skip table scans when already expanded.
SET @cl_sync_legacy_state_sql = IF(
    @cl_had_order_status = 0
      OR @cl_had_fulfillment = 0
      OR @cl_had_receipt_state = 0
      OR COALESCE(@cl_legacy_sync, 0) = 1,
    'UPDATE `veg_order_delivery` SET `order_status` = IF(`receipt_state` = ''CONFIRMED'', ''FINISHED'', `fulfillment`) WHERE `order_status` <> IF(`receipt_state` = ''CONFIRMED'', ''FINISHED'', `fulfillment`)',
    'SELECT 1'
);
PREPARE cl_sync_legacy_state_stmt FROM @cl_sync_legacy_state_sql;
EXECUTE cl_sync_legacy_state_stmt;
DEALLOCATE PREPARE cl_sync_legacy_state_stmt;

SET @cl_backfill_received_at_sql = IF(
    @cl_had_order_status = 0
      OR @cl_had_fulfillment = 0
      OR @cl_had_receipt_state = 0
      OR COALESCE(@cl_legacy_sync, 0) = 1,
    'UPDATE `veg_order_delivery` SET `received_at` = COALESCE(`received_at`, `updated_at`, NOW()) WHERE `receipt_state` = ''CONFIRMED'' AND `received_at` IS NULL',
    'SELECT 1'
);
PREPARE cl_backfill_received_at_stmt FROM @cl_backfill_received_at_sql;
EXECUTE cl_backfill_received_at_stmt;
DEALLOCATE PREPARE cl_backfill_received_at_stmt;

-- Add canonical indexes without removing the legacy indexes used by rollback
-- code. Keeping both also avoids a table-wide index replacement at release.
SET @cl_add_order_status_expected_index_sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'veg_order_delivery'
       AND INDEX_NAME = 'idx_order_status_expected') = 0,
    'ALTER TABLE `veg_order_delivery` ADD INDEX `idx_order_status_expected` (`order_status`, `expected_at`)',
    'SELECT 1'
);
PREPARE cl_add_order_status_expected_index_stmt FROM @cl_add_order_status_expected_index_sql;
EXECUTE cl_add_order_status_expected_index_stmt;
DEALLOCATE PREPARE cl_add_order_status_expected_index_stmt;

SET @cl_add_order_status_receivable_index_sql = IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'veg_order_delivery'
       AND INDEX_NAME = 'idx_order_status_receivable') = 0,
    'ALTER TABLE `veg_order_delivery` ADD INDEX `idx_order_status_receivable` (`order_status`, `payment_method`, `payment_state`)',
    'SELECT 1'
);
PREPARE cl_add_order_status_receivable_index_stmt FROM @cl_add_order_status_receivable_index_sql;
EXECUTE cl_add_order_status_receivable_index_stmt;
DEALLOCATE PREPARE cl_add_order_status_receivable_index_stmt;
