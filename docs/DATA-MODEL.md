# 数据模型｜春连鲜选

> 来源：方案 §9。
> **以下 SQL 为概念性迁移草案**，不是已对 ShopXO 6.9.2 执行成功的 SQL。实施前必须核对现有订单 ID 类型、数据库表前缀、字符集及原生外键/约束；**不直接对未知官方表结构执行修改**。

## 1. 设计原则

- 订单、商品和支付**主事实仍以 ShopXO 原生表为准**，只建必要扩展表。
- 商品表**不复制**；`veg_*` 表通过 `order_id` 外键关联到 ShopXO 订单主表。
- 金额一律 `BIGINT`（**分**），不使用 `float`/`decimal` 存人民币。
- `delta_fen` 为**有符号**整数：正数补款、负数退款。
- 所有订单历史明细保持**不可篡改的快照或事件记录**。

## 2. 数据字典

| 表名 | 字段摘要 | 目的 |
|---|---|---|
| `veg_order_delivery` | `order_id`、`delivery_mode`、`expected_at`、`fulfillment`、`delivered_at`、`received_at`、`version` | 自配送状态与订单版本（乐观锁） |
| `veg_order_revision` | `id`、`order_id`、`from_version`、`to_version`、`status`、`before_json`、`after_json`、`delta_fen` | 修改前后快照、差价与履约封锁 |
| `veg_payment_ledger` | `id`、`order_id`、`revision_id`、`kind`、`amount_fen`、`provider_trade_no`、`status`、`idempotency_key` | 追加支付、退款及 COD 实收流水 |
| `veg_operation_log` | `id`、`actor_type`、`actor_id`、`order_id`、`action`、`detail_json`、`created_at` | 审计，禁止前台删除 |
| `veg_outbox_event` | `id`、`event_type`、`entity_id`、`payload`、`sent_at`、`retry_count` | 支付核对、退款查询、App 提醒重试 |
| `veg_merchant_session` | `token_hash`、`device_id`、`actor_id`、`expires_at`、`revoked_at` | 商家专用 API 登录会话 |

### 2.1 枚举取值

| 字段 | 枚举 |
|---|---|
| `veg_order_delivery.delivery_mode` | `ASAP` / `SCHEDULED` |
| `veg_order_delivery.fulfillment` | `PENDING` / `DELIVERING` / `DELIVERED` / `FINISHED` / `CANCELLED` |
| `veg_order_delivery.payment_state` | `UNPAID` / `PAYING` / `PAID` / `PARTIAL_REFUNDED` / `REFUNDING` / `REFUNDED` |
| `veg_order_delivery.receipt_state` | `UNCONFIRMED` / `CONFIRMED` |
| `veg_order_delivery.revision_state` | `NONE` / `PENDING_TOPUP` / `APPLYING` / `COMMITTED` / `FAILED` |
| `veg_order_delivery.payment_method` | `WECHAT` / `COD` |
| `veg_order_revision.status` | `PENDING_TOPUP` / `APPLYING` / `COMMITTED` / `FAILED` / `REVERTED` |
| `veg_payment_ledger.kind` | `WX_PAY` / `TOPUP` / `WX_REFUND` / `COD_RECEIPT` |
| `veg_payment_ledger.status` | `PENDING` / `SUCCESS` / `FAILED` / `PROCESSING` / `CLOSED` |
| `veg_payment_ledger.collection_method` | `CASH` / `OFFLINE_QR` / `OTHER` |

> 四维状态（履约/支付/收货/修订）**拆开存放**，不允许合并成一个布尔字段。

## 3. 迁移脚本

### 3.1 正向迁移 `migrations/2026_10_12_000001_create_veg_tables.sql`

```sql
-- ============================================================
-- 春连鲜选 扩展表迁移（概念草案，执行前须核对 ShopXO 实际结构）
-- 前置：ShopXO 订单表已存在；:ORDER_TABLE: 替换为实际订单表名
-- ============================================================

CREATE TABLE IF NOT EXISTS `veg_order_delivery` (
    `order_id`        BIGINT UNSIGNED NOT NULL COMMENT 'ShopXO 订单 ID',
    `delivery_mode`   VARCHAR(16)     NOT NULL COMMENT 'ASAP / SCHEDULED',
    `expected_at`     DATETIME        NULL     COMMENT '预约送达时间，ASAP 时为 NULL',
    `fulfillment`     VARCHAR(16)     NOT NULL DEFAULT 'PENDING',
    `payment_state`   VARCHAR(24)     NOT NULL DEFAULT 'UNPAID',
    `payment_method`  VARCHAR(16)     NOT NULL COMMENT 'WECHAT / COD',
    `receipt_state`   VARCHAR(16)     NOT NULL DEFAULT 'UNCONFIRMED',
    `revision_state`  VARCHAR(24)     NOT NULL DEFAULT 'NONE',
    `delivered_at`    DATETIME        NULL,
    `received_at`     DATETIME        NULL     COMMENT '顾客确认收货时间',
    `version`         BIGINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '乐观锁版本',
    `created_at`      DATETIME        NOT NULL,
    `updated_at`      DATETIME        NOT NULL,
    PRIMARY KEY (`order_id`),
    KEY `idx_fulfillment_expected` (`fulfillment`, `expected_at`),
    KEY `idx_payment_state` (`payment_method`, `payment_state`),
    KEY `idx_revision_state` (`revision_state`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='订单履约扩展';

CREATE TABLE IF NOT EXISTS `veg_order_revision` (
    `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `order_id`     BIGINT UNSIGNED NOT NULL,
    `from_version` BIGINT UNSIGNED NOT NULL,
    `to_version`   BIGINT UNSIGNED NOT NULL,
    `status`       VARCHAR(24)     NOT NULL,
    `before_json`  JSON            NOT NULL COMMENT '修订前完整快照',
    `after_json`   JSON            NOT NULL COMMENT '修订后完整快照',
    `delta_fen`    BIGINT          NOT NULL COMMENT '正=补款 负=退款',
    `actor_type`   VARCHAR(16)     NOT NULL DEFAULT 'CUSTOMER',
    `actor_id`     BIGINT UNSIGNED NULL,
    `reason`       VARCHAR(255)    NULL,
    `created_at`   DATETIME        NOT NULL,
    `updated_at`   DATETIME        NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_order_to_version` (`order_id`, `to_version`),
    KEY `idx_order_revision` (`order_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='订单修订快照';

CREATE TABLE IF NOT EXISTS `veg_payment_ledger` (
    `id`                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `order_id`           BIGINT UNSIGNED NOT NULL,
    `revision_id`        BIGINT UNSIGNED NULL COMMENT '关联修订单，主支付为 NULL',
    `kind`               VARCHAR(24)     NOT NULL COMMENT 'WX_PAY/TOPUP/WX_REFUND/COD_RECEIPT',
    `amount_fen`         BIGINT          NOT NULL COMMENT '正=入账 负=退款',
    `status`             VARCHAR(24)     NOT NULL,
    `business_no`        VARCHAR(80)     NOT NULL COMMENT '我方业务单号',
    `provider_trade_no`  VARCHAR(100)    NULL     COMMENT '微信支付单号',
    `collection_method`  VARCHAR(16)     NULL     COMMENT 'COD 收款渠道',
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='资金流水（支付/退款/COD实收）';

CREATE TABLE IF NOT EXISTS `veg_operation_log` (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `actor_type`  VARCHAR(16)     NOT NULL COMMENT 'CUSTOMER/MERCHANT/SYSTEM',
    `actor_id`    BIGINT UNSIGNED NULL,
    `order_id`    BIGINT UNSIGNED NULL,
    `action`      VARCHAR(64)     NOT NULL COMMENT 'DISPATCH/DELIVER/COD_RECEIPT/PRICE_EDIT...',
    `detail_json` JSON            NOT NULL,
    `request_id`  VARCHAR(64)     NULL,
    `ip`          VARCHAR(45)     NULL,
    `created_at`  DATETIME        NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_order_action` (`order_id`, `action`, `created_at`),
    KEY `idx_actor` (`actor_type`, `actor_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='操作审计，禁止前台删除';

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='异步任务 outbox';

CREATE TABLE IF NOT EXISTS `veg_merchant_session` (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `actor_id`    BIGINT UNSIGNED NOT NULL,
    `token_hash`  CHAR(64)        NOT NULL COMMENT 'SHA-256 of token',
    `device_id`   VARCHAR(64)     NOT NULL,
    `device_name` VARCHAR(128)    NULL,
    `last_seen_at` DATETIME       NULL,
    `expires_at`  DATETIME        NOT NULL,
    `revoked_at`  DATETIME        NULL,
    `created_at`  DATETIME        NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_token_hash` (`token_hash`),
    KEY `idx_actor` (`actor_id`, `revoked_at`),
    KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='商家 API 会话';
```

### 3.2 回滚脚本 `migrations/2026_10_12_000001_create_veg_tables_rollback.sql`

```sql
-- 回滚顺序与创建顺序相反；执行前必须已备份
DROP TABLE IF EXISTS `veg_merchant_session`;
DROP TABLE IF EXISTS `veg_outbox_event`;
DROP TABLE IF EXISTS `veg_operation_log`;
DROP TABLE IF EXISTS `veg_payment_ledger`;
DROP TABLE IF EXISTS `veg_order_revision`;
DROP TABLE IF EXISTS `veg_order_delivery`;
```

> **回滚不自动恢复资金数据**。生产回滚前必须先导出 `veg_payment_ledger` 与 `veg_order_revision` 到归档表。

## 4. 唯一性约束（必须）

| 约束 | 目的 |
|---|---|
| `veg_order_delivery.order_id` 主键 | 每单扩展记录唯一 |
| `veg_payment_ledger.uk_business_no` | 支付/退款商户单号唯一 |
| `veg_payment_ledger.uk_idem_key` | `idempotency_key` 全局唯一 |
| `veg_payment_ledger.uk_provider_trade` | 微信支付单号唯一，防重复入账 |
| `veg_order_revision.uk_order_to_version` | 修订 `order_id + to_version` 唯一 |

> **禁止**同一 COD 收款凭证被重复记账。

## 5. 一致性约束

1. 下单与预占库存需要**本地事务**；支付行为在事务外调用；支付确认通过独立回调/查单补偿。
2. 线上退款**不在 MySQL 事务中等待第三方网络**：先保存退款意图 → 调用平台 → 用回调/查询更新结果。
3. 商品修改涉及**两层库存**：已提交订单预占 + 待补款修订的暂时预占；任意撤销必须**对称归还**。
4. 商家开工、顾客编辑、取消均受**同一订单锁/版本约束**；不能用纯前端"按钮不可点"代替服务端保护。
5. 支付/退款回调需校验签名、金额、商户号、订单号、原始流水；**重放请求只能更新一次**。
6. COD 收款流水属**线下收款事实记录**，不是微信支付平台回调；必须区分付款渠道和核销人。
7. 日结账口径：`今日实收 = 当天在线确认入账 + 当天 COD 实收 − 当天已成功退款`。跨日支付/退款按**资金发生日**显示，另提供"按订单日销售额"指标，不混算。

## 6. 关键查询示例

### 6.1 待收款清单（COD 已送达未收）

```sql
SELECT d.order_id, d.delivered_at, d.version,
       COALESCE(SUM(l.amount_fen), 0) AS collected_fen
FROM veg_order_delivery d
LEFT JOIN veg_payment_ledger l
       ON l.order_id = d.order_id AND l.kind = 'COD_RECEIPT' AND l.status = 'SUCCESS'
WHERE d.payment_method = 'COD'
  AND d.fulfillment = 'DELIVERED'
GROUP BY d.order_id, d.delivered_at, d.version
HAVING collected_fen < :order_total_fen;
```

### 6.2 今日实收

```sql
SELECT
  SUM(CASE WHEN kind IN ('WX_PAY','TOPUP') AND status='SUCCESS' THEN amount_fen ELSE 0 END)
+ SUM(CASE WHEN kind = 'COD_RECEIPT' AND status='SUCCESS' THEN amount_fen ELSE 0 END)
+ SUM(CASE WHEN kind = 'WX_REFUND'   AND status='SUCCESS' THEN amount_fen ELSE 0 END) AS received_fen
FROM veg_payment_ledger
WHERE DATE(occurred_at) = :business_date;
```

### 6.3 可退余额（防超退）

```sql
SELECT
  COALESCE(SUM(CASE WHEN kind IN ('WX_PAY','TOPUP') AND status='SUCCESS' THEN amount_fen END), 0) AS paid_fen,
  COALESCE(SUM(CASE WHEN kind = 'WX_REFUND' AND status IN ('SUCCESS','PROCESSING') THEN amount_fen END), 0) AS refunded_or_pending_fen
FROM veg_payment_ledger
WHERE order_id = :order_id;
-- 可退 = paid_fen + refunded_or_pending_fen   （refunded 为负数）
```

## 7. 索引与容量建议

- `veg_payment_ledger` 是增长最快的表；单店日均 < 200 行，`occurred_at` 索引足够。
- `veg_operation_log` 按月归档到 `veg_operation_log_archive`，保留 12 个月。
- `veg_outbox_event.status='SENT'` 的记录保留 7 天后清理。
- 备份：数据库每日全量 + 图片同步异地；保留 7–30 天。
