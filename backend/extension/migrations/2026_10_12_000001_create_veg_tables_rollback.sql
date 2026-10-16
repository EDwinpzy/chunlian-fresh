-- ============================================================
-- 春连鲜选 扩展表回滚
-- 对应：2026_10_12_000001_create_veg_tables.sql
--
-- ⚠️  回滚不自动恢复资金数据。
--     生产回滚前必须先把 veg_payment_ledger 与 veg_order_revision
--     导出到归档表，否则将永久丢失资金流水。
--     执行前必须已完成数据库备份。
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

-- 建议：先归档再删除（注释保留，按需启用）
-- CREATE TABLE veg_payment_ledger_archive_20261012 AS SELECT * FROM veg_payment_ledger;
-- CREATE TABLE veg_order_revision_archive_20261012 AS SELECT * FROM veg_order_revision;

DROP TABLE IF EXISTS `veg_idempotency`;
DROP TABLE IF EXISTS `veg_merchant_session`;
DROP TABLE IF EXISTS `veg_outbox_event`;
DROP TABLE IF EXISTS `veg_operation_log`;
DROP TABLE IF EXISTS `veg_payment_ledger`;
DROP TABLE IF EXISTS `veg_order_revision`;
DROP TABLE IF EXISTS `veg_order_delivery`;

SET FOREIGN_KEY_CHECKS = 1;
