# 数据模型｜春连鲜选

> 来源：方案 §9。
> **以下SQL为概念性历史草案，不是当前生产迁移清单。** 当前代码库迁移脚本是schema来源。2026-10-14发布已增加二维码字段及逐事件通知已读表。草案中的WX_*/WECHAT属于历史模型；当前采用人工扫码与COD，没有自动支付/退款。

## 1. 设计原则

- 订单、商品和支付**主事实仍以 ShopXO 原生表为准**，只建必要扩展表。
- 商品表**不复制**；`veg_*` 表通过 `order_id` 外键关联到 ShopXO 订单主表。
- 金额一律 `BIGINT`（**分**），不使用 `float`/`decimal` 存人民币。
- `delta_fen` 为**有符号**整数：正数补款、负数退款。
- 所有订单历史明细保持**不可篡改的快照或事件记录**。
- 店铺业务时区固定为 `Asia/Shanghai`。插件数据库连接独立使用 `+08:00` 会话时区，使 `NOW()`、订单日期及资金发生日与 PHP 一致；不改变共享主机的全局数据库时区，也不批量改写既有时间记录。

## 2. 数据字典

| 表名 | 字段摘要 | 目的 |
|---|---|---|
| `veg_order_delivery` | `order_id`、`delivery_mode`、`expected_at`、`fulfillment`、`delivered_at`、`received_at`、`version` | 自配送状态与订单版本（乐观锁） |
| `veg_order_revision` | `id`、`order_id`、`from_version`、`to_version`、`status`、`before_json`、`after_json`、`delta_fen` | 修改前后快照、差价与履约封锁 |
| `veg_payment_ledger` | `id`、`order_id`、`revision_id`、`kind`、`amount_fen`、`provider_trade_no`、`status`、`idempotency_key` | 记录扫码入账、补款、人工退款及 COD 实收 |
| `veg_operation_log` | `id`、`actor_type`、`actor_id`、`order_id`、`action`、`detail_json`、`created_at` | 审计，禁止前台删除 |
| `veg_outbox_event` | `id`、`event_type`、`entity_id`、`payload`、`sent_at`、`retry_count` | 业务事件与 App 提醒重试 |
| `veg_merchant_session` | `token_hash`、`device_id`、`actor_id`、`expires_at`、`revoked_at` | 商家专用 API 登录会话 |
| `veg_notification_seen` | `actor_id`、`event_id`、`read_at` | 按商家账号逐事件保存已读状态；与通知总数分页无关 |

### 2.1 枚举取值

| 字段 | 枚举 |
|---|---|
| `veg_order_delivery.delivery_mode` | `ASAP` / `SCHEDULED` |
| `veg_order_delivery.fulfillment` | `PENDING` / `DELIVERING` / `DELIVERED` / `FINISHED` / `CANCELLED` |
| `veg_order_delivery.payment_state` | `UNPAID` / `PAYING` / `PAID` / `PARTIAL_REFUNDED` / `REFUNDING` / `REFUNDED` |
| `veg_order_delivery.receipt_state` | `UNCONFIRMED` / `CONFIRMED` |
| `veg_order_delivery.revision_state` | `NONE` / `PENDING_TOPUP` / `APPLYING` / `COMMITTED` / `FAILED` |
| `veg_order_delivery.payment_method` | `OFFLINE_QR` / `COD` |
| `veg_order_revision.status` | `PENDING_TOPUP` / `APPLYING` / `COMMITTED` / `FAILED` / `REVERTED` |
| `veg_payment_ledger.kind` | `OFFLINE_RECEIPT` / `TOPUP` / `OFFLINE_REFUND` / `COD_RECEIPT` |
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
    `payment_method`  VARCHAR(16)     NOT NULL COMMENT 'OFFLINE_QR / COD',
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
    `kind`               VARCHAR(24)     NOT NULL COMMENT 'OFFLINE_RECEIPT/TOPUP/OFFLINE_REFUND/COD_RECEIPT',
    `amount_fen`         BIGINT          NOT NULL COMMENT '金额单位为分；资金方向由 kind 决定，退款统计按支出处理',
    `status`             VARCHAR(24)     NOT NULL,
    `business_no`        VARCHAR(80)     NOT NULL COMMENT '我方业务单号',
    `provider_trade_no`  VARCHAR(100)    NULL     COMMENT '可选外部流水号；线下核实不依赖平台交易号',
    `collection_method`  VARCHAR(16)     NULL     COMMENT '实际收款渠道（COD 或 OFFLINE_QR）',
    `collected_fen`      BIGINT          NULL     COMMENT 'COD 实收金额',
    `idempotency_key`    VARCHAR(100)    NOT NULL,
    `operator_id`        BIGINT UNSIGNED NULL COMMENT '商家资金核实操作人',
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
    `event_type`    VARCHAR(64)     NOT NULL COMMENT '业务事件类型由应用代码定义',
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

### 3.3 通知已读迁移 `migrations/2026_10_14_000004_create_veg_notification_seen.sql`

该实际迁移创建 `veg_notification_seen`，使用 `(actor_id, event_id)` 作为主键，并为 `event_id` 建索引。迁移会把旧 `veg_notification_read.last_event_id` 游标覆盖范围内的现有 outbox 事件回填为已读。发布脚本在维护模式并排空写请求后执行迁移，避免并发 outbox 写入与回填交错。

后端按当前事件是否存在该账号的已读标记计算未读数，且未读数不受列表分页大小影响。这可避免低 ID 的 outbox 事务晚于高 ID 事务提交时，被高水位游标错误地视为已读或遗漏。

数据库逆迁移 `migrations/2026_10_14_000004_create_veg_notification_seen_rollback.sql` 会删除整张已读标记表。应用文件回滚不会执行该 SQL，保留 `veg_notification_seen`、兼容的 `payment_qr_url` 新增列及已产生的业务数据；只有单独审核并备份后的数据库回滚才可考虑移除新表。

## 4. 唯一性约束（必须）

| 约束 | 目的 |
|---|---|
| `veg_order_delivery.order_id` 主键 | 每单扩展记录唯一 |
| `veg_payment_ledger.uk_business_no` | 业务流水号唯一 |
| `veg_payment_ledger.uk_idem_key` | `idempotency_key` 全局唯一 |
| `veg_payment_ledger.uk_provider_trade` | 外部流水号若有值则唯一；当前人工核实不依赖平台交易号 |
| `veg_order_revision.uk_order_to_version` | 修订 `order_id + to_version` 唯一 |

> **禁止**同一 COD 收款凭证被重复记账。

## 5. 一致性约束

1. 下单与预占库存需要**本地事务**；OFFLINE_QR 转账在系统外完成，商家核实到账后手工登记，没有自动回调或查单。
2. 线下退款由店主在实际退回后确认，系统不调用支付平台退款 API，也不依赖自动回调。
3. 商品修改涉及**两层库存**：已提交订单预占 + 待补款修订的暂时预占；任意撤销必须**对称归还**。
4. 商家开工、顾客编辑、取消均受**同一订单锁/版本约束**；不能用纯前端"按钮不可点"代替服务端保护。
5. 资金登记由商家核实真实到账或退回后提交；请求仍须幂等，重复操作不能重复记账。
6. COD 与扫码收款流水都记录商家核实的线下资金事实，并保留付款渠道与核销人。
7. 已付款 OFFLINE_QR 订单减量时，新明细和总额立即生效，修订进入 `APPLYING`，退款流水保持 `PROCESSING`；此状态禁止配送和再次改单。实际退款经店主核实后，退款记为 `SUCCESS`、修订转为 `COMMITTED`。
8. 日结账口径：`今日实收 = 当天已核实扫码预付及补款入账 + 当天 COD 实收 − 当天已成功退款`。跨日支付/退款按**资金发生日**显示，另提供"按订单日销售额"指标，不混算。

## 6. 关键查询示例

### 6.1 待收款清单（COD 已送达未收）

```sql
SELECT d.order_id, d.delivered_at, d.version,
       COALESCE(SUM(ABS(l.amount_fen)), 0) AS collected_fen
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
  SUM(CASE WHEN kind IN ('OFFLINE_RECEIPT','TOPUP','COD_RECEIPT') AND status='SUCCESS' THEN ABS(amount_fen) ELSE 0 END)
- SUM(CASE WHEN kind = 'OFFLINE_REFUND' AND status='SUCCESS' THEN ABS(amount_fen) ELSE 0 END) AS received_fen
FROM veg_payment_ledger
WHERE DATE(occurred_at) = :business_date;
```

### 6.3 可退余额（防超退）

```sql
SELECT
  COALESCE(SUM(CASE WHEN kind IN ('OFFLINE_RECEIPT','TOPUP') AND status='SUCCESS' THEN ABS(amount_fen) END), 0) AS paid_fen,
  COALESCE(SUM(CASE WHEN kind = 'OFFLINE_REFUND' AND status IN ('SUCCESS','PROCESSING') THEN ABS(amount_fen) END), 0) AS refunded_or_pending_fen
FROM veg_payment_ledger
WHERE order_id = :order_id;
-- 可退余额 = paid_fen - refunded_or_pending_fen；待处理退款同样占用可退额度
```

## 7. 索引与容量建议

- `veg_payment_ledger` 是增长最快的表；单店日均 < 200 行，`occurred_at` 索引足够。
- **以下是运维建议，不表示当前已启用：**按月将 `veg_operation_log` 归档到 `veg_operation_log_archive`，建议保留 12 个月。
- **以下是运维建议，不表示当前已启用：**`veg_outbox_event.status='SENT'` 的记录可在保留 7 天后清理。`veg_notification_seen.event_id` 引用 outbox 事件 ID，实施清理时必须按同一保留策略协调两表，避免产生孤立已读标记或误删仍需保留的通知状态。当前没有部署自动清理任务。
- **以下是备份目标建议，不表示当前已启用：**数据库每日全量备份并将图片同步到异地，保留 7–30 天。当前没有配置自动每日备份计划。
