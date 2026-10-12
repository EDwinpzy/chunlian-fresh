-- ============================================================
-- 春连鲜选 (chunlian-fresh) 扩展表迁移
-- 版本：2026_10_12_000001
-- 生成来源：docs/DATA-MODEL.md §3.1
--
-- ⚠️  概念性迁移草案，不是已验证可在 ShopXO 6.9.2 执行成功的 SQL。
--     执行前必须核对：
--       1. ShopXO 订单表实际名称与 ID 类型
--       2. 数据库表前缀（默认 shopxo_）
--       3. 字符集与排序规则
--       4. 是否已有同名原生表
--     禁止直接对未知官方表结构执行修改。
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------
-- 1. 订单履约扩展（含四维状态与乐观锁版本）
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `veg_order_delivery` (
    `order_id`        BIGINT UNSIGNED NOT NULL COMMENT 'ShopXO 订单 ID',
    `delivery_mode`   VARCHAR(16)     NOT NULL COMMENT 'ASAP / SCHEDULED',
    `expected_at`     DATETIME        NULL     COMMENT '预约送达时间，ASAP 时为 NULL',
    `fulfillment`     VARCHAR(16)     NOT NULL DEFAULT 'PENDING' COMMENT 'PENDING/DELIVERING/DELIVERED/FINISHED/CANCELLED',
    `payment_state`   VARCHAR(24)     NOT NULL DEFAULT 'UNPAID'  COMMENT 'UNPAID/PAYING/PAID/PARTIAL_REFUNDED/REFUNDING/REFUNDED',
    `payment_method`  VARCHAR(16)     NOT NULL COMMENT 'WECHAT / COD',
    `receipt_state`   VARCHAR(16)     NOT NULL DEFAULT 'UNCONFIRMED' COMMENT 'UNCONFIRMED / CONFIRMED',
    `revision_state`  VARCHAR(24)     NOT NULL DEFAULT 'NONE' COMMENT 'NONE/PENDING_TOPUP/APPLYING/COMMITTED/FAILED',
    `delivered_at`    DATETIME        NULL,
    `received_at`     DATETIME        NULL     COMMENT '顾客确认收货时间',
    `version`         BIGINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '乐观锁版本',
    `created_at`      DATETIME        NOT NULL,
    `updated_at`      DATETIME        NOT NULL,
    PRIMARY KEY (`order_id`),
    KEY `idx_fulfillment_expected` (`fulfillment`, `expected_at`),
    KEY `idx_payment_state` (`payment_method`, `payment_state`),
    KEY `idx_revision_state` (`revision_state`),
    KEY `idx_delivered_receivable` (`fulfillment`, `payment_method`, `payment_state`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='订单履约扩展';

-- ------------------------------------------------------------
-- 2. 订单修订快照（before/after + 差价）
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `veg_order_revision` (
    `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `order_id`     BIGINT UNSIGNED NOT NULL,
    `from_version` BIGINT UNSIGNED NOT NULL,
    `to_version`   BIGINT UNSIGNED NOT NULL,
    `status`       VARCHAR(24)     NOT NULL COMMENT 'PENDING_TOPUP/APPLYING/COMMITTED/FAILED/REVERTED',
    `before_json`  JSON            NOT NULL COMMENT '修订前完整快照',
    `after_json`   JSON            NOT NULL COMMENT '修订后完整快照',
    `delta_fen`    BIGINT          NOT NULL COMMENT '正=补款 负=退款 0=无资金动作',
    `actor_type`   VARCHAR(16)     NOT NULL DEFAULT 'CUSTOMER',
    `actor_id`     BIGINT UNSIGNED NULL,
    `reason`       VARCHAR(255)    NULL,
    `created_at`   DATETIME        NOT NULL,
    `updated_at`   DATETIME        NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_order_to_version` (`order_id`, `to_version`),
    KEY `idx_order_revision` (`order_id`, `id`),
    KEY `idx_status_created` (`status`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='订单修订快照';

-- ------------------------------------------------------------
-- 3. 资金流水（支付 / 补款 / 退款 / COD 实收）
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `veg_payment_ledger` (
    `id`                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `order_id`           BIGINT UNSIGNED NOT NULL,
    `revision_id`        BIGINT UNSIGNED NULL COMMENT '关联修订单，主支付为 NULL',
    `kind`               VARCHAR(24)     NOT NULL COMMENT 'WX_PAY/TOPUP/WX_REFUND/COD_RECEIPT',
    `amount_fen`         BIGINT          NOT NULL COMMENT '正=入账 负=退款',
    `status`             VARCHAR(24)     NOT NULL COMMENT 'PENDING/PROCESSING/SUCCESS/FAILED/CLOSED',
    `business_no`        VARCHAR(80)     NOT NULL COMMENT '我方业务单号',
    `provider_trade_no`  VARCHAR(100)    NULL     COMMENT '微信支付单号',
    `collection_method`  VARCHAR(16)     NULL     COMMENT 'CASH / OFFLINE_QR / OTHER（仅 COD）',
    `collected_fen`      BIGINT          NULL     COMMENT 'COD 实收金额',
    `idempotency_key`    VARCHAR(100)    NOT NULL,
    `operator_id`        BIGINT UNSIGNED NULL COMMENT 'COD 核销操作人',
    `occurred_at`        DATETIME        NOT NULL COMMENT '资金发生时间（统计口径）',
    `created_at`         DATETIME        NOT NULL,
    `updated_at`         DATETIME        NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_business_no` (`business_no`),
    UNIQUE KEY `uk_idem_key` (`idempotency_key`),
    UNIQUE KEY `uk_provider_trade` (`provider_trade_no`),
    KEY `idx_order_kind` (`order_id`, `kind`),
    KEY `idx_occurred` (`occurred_at`, `kind`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='资金流水（支付/退款/COD实收）';

-- ------------------------------------------------------------
-- 4. 操作审计（禁止前台删除）
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `veg_operation_log` (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `actor_type`  VARCHAR(16)     NOT NULL COMMENT 'CUSTOMER/MERCHANT/SYSTEM',
    `actor_id`    BIGINT UNSIGNED NULL,
    `order_id`    BIGINT UNSIGNED NULL,
    `action`      VARCHAR(64)     NOT NULL COMMENT 'DISPATCH/DELIVER/COD_RECEIPT/PRICE_EDIT/REVISION...',
    `detail_json` JSON            NOT NULL,
    `request_id`  VARCHAR(64)     NULL,
    `ip`          VARCHAR(45)     NULL,
    `created_at`  DATETIME        NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_order_action` (`order_id`, `action`, `created_at`),
    KEY `idx_actor` (`actor_type`, `actor_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='操作审计，禁止前台删除';

-- ------------------------------------------------------------
-- 5. 异步任务 outbox（支付核对 / 退款查询 / App 提醒重试）
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `veg_outbox_event` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `event_type`    VARCHAR(64)     NOT NULL COMMENT 'ORDER_CREATED/ORDER_REVISED/ORDER_CANCELLED/REFUND_NEEDS_ATTENTION/PAYMENT_VERIFY/REFUND_QUERY',
    `entity_id`     BIGINT UNSIGNED NULL,
    `payload`       JSON            NOT NULL,
    `status`        VARCHAR(16)     NOT NULL DEFAULT 'PENDING' COMMENT 'PENDING/SENT/FAILED/DEAD',
    `retry_count`   INT UNSIGNED    NOT NULL DEFAULT 0,
    `next_retry_at` DATETIME        NULL,
    `sent_at`       DATETIME        NULL,
    `last_error`    VARCHAR(500)    NULL,
    `created_at`    DATETIME        NOT NULL,
    `updated_at`    DATETIME        NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_pending` (`status`, `next_retry_at`),
    KEY `idx_entity` (`event_type`, `entity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='异步任务 outbox';

-- ------------------------------------------------------------
-- 6. 商家 API 会话
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `veg_merchant_session` (
    `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `actor_id`     BIGINT UNSIGNED NOT NULL,
    `token_hash`   CHAR(64)        NOT NULL COMMENT 'SHA-256 of token',
    `device_id`    VARCHAR(64)     NOT NULL,
    `device_name`  VARCHAR(128)    NULL,
    `last_seen_at` DATETIME        NULL,
    `expires_at`   DATETIME        NOT NULL,
    `revoked_at`   DATETIME        NULL,
    `created_at`   DATETIME        NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_token_hash` (`token_hash`),
    KEY `idx_actor` (`actor_id`, `revoked_at`),
    KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='商家 API 会话';

-- ------------------------------------------------------------
-- 7. 幂等键（生产实现，对应 IdempotencyStore）
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `veg_idempotency` (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `actor_id`    VARCHAR(64)     NOT NULL,
    `endpoint`    VARCHAR(128)    NOT NULL,
    `idem_key`    VARCHAR(100)    NOT NULL,
    `fingerprint` CHAR(64)        NOT NULL COMMENT '请求体 SHA-256',
    `response`    JSON            NULL COMMENT '首次执行结果',
    `reserved_at` DATETIME        NOT NULL,
    `expires_at`  DATETIME        NOT NULL,
    `created_at`  DATETIME        NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_scope_key` (`actor_id`, `endpoint`, `idem_key`),
    KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='幂等键';

SET FOREIGN_KEY_CHECKS = 1;
