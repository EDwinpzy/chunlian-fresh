-- ============================================================
-- 春连鲜选通知中心 —— 每个操作人、每个事件的已读标记
-- 版本：2026_10_14_000004
-- ============================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `veg_notification_seen` (
    `actor_id` BIGINT UNSIGNED NOT NULL COMMENT '商家账号 ID',
    `event_id` BIGINT UNSIGNED NOT NULL COMMENT '已读的 outbox 事件 ID',
    `read_at`  DATETIME NOT NULL COMMENT '标记已读时间',
    PRIMARY KEY (`actor_id`, `event_id`),
    KEY `idx_veg_notification_seen_event_id` (`event_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='商家端逐事件已读标记';

-- 迁移旧的高水位游标。发布时先进入维护模式并排空写请求，避免迁移期间并发写入 outbox。
INSERT IGNORE INTO `veg_notification_seen` (`actor_id`, `event_id`, `read_at`)
SELECT r.`actor_id`, e.`id`, r.`updated_at`
FROM `veg_notification_read` r
INNER JOIN `veg_outbox_event` e ON e.`id` <= r.`last_event_id`;
