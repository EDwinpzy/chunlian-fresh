-- ============================================================
-- 春连鲜选 (chunlian-fresh) 扩展表迁移 —— 第 3 号
-- 版本：2026_10_12_000003
-- 目的：商家端通知中心的「已读游标」
--
-- 【为什么只需要一张游标表，而不是一张通知表】
-- 通知的内容（新单 / 改单 / 取消 / 退款异常）**已经存在**于
-- `veg_outbox_event` 与 `veg_operation_log` 中 —— 它们是业务事件的
-- 真实记录，本来就要写。
--
-- 若再建一张 `veg_notification` 把同样的事件抄一份，就会有两份真相：
-- 事件写失败但通知写成功（或反之）时，商家看到的通知与实际业务状态不符。
-- 对于「有一单改了但没收到通知」这类问题，排查成本高且没有价值。
--
-- 因此通知中心是**视图**：读取既有事件表 + 一张记录「读到哪儿了」的游标。
-- 未读数 = 游标之后的事件数。
-- ============================================================

SET NAMES utf8mb4;

-- ------------------------------------------------------------
-- 通知已读游标（按操作人）
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `veg_notification_read` (
    `actor_id`      BIGINT UNSIGNED NOT NULL COMMENT '商家账号 ID',
    `last_event_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '已读到的 outbox 事件 ID',
    `updated_at`    DATETIME        NOT NULL,
    PRIMARY KEY (`actor_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='商家端通知已读游标';
