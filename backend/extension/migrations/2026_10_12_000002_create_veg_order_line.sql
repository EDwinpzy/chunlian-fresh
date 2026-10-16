-- ============================================================
-- 春连鲜选 (chunlian-fresh) 扩展表迁移 —— 第 2 号
-- 版本：2026_10_12_000002
-- 目的：补上 ShopXO 订单明细缺失的 SKU 身份
--
-- 背景（见 docs/SHOPXO-AUDIT.md §5）：
--   ShopXO 的下单链路里 `spec_base_id`（= shopxo_goods_spec_base.id）
--   是存在的，但**没有落到 shopxo_order_detail**。该表只有
--   goods_id + spec(JSON 文本) + price + buy_number。
--
--   后果：订单落库后无法稳定还原"这一行是哪个 SKU"。
--   而改单引擎（ADR-004）必须按 SKU 比对旧单与新单才能算出差价，
--   且定价规则要求"已有 SKU 维持原单价、新增 SKU 按现价"。
--   靠 spec 文本反推会在商品改规格模板后失效，并可能产生歧义 → 算错钱。
--
-- 对策：在下单瞬间用钩子捕获 SKU 身份，写入本表。
--   钩子名：plugins_service_buy_order_detail_insert_begin
--   解析方法：GoodsService::GoodsSpecDetail()（ShopXO 官方口径）
--
-- ⚠️  执行前核对：表前缀、字符集、order_id 类型
-- ============================================================

SET NAMES utf8mb4;

-- ------------------------------------------------------------
-- 订单行快照（含稳定 SKU 身份）
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `veg_order_line` (
    `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `order_id`        BIGINT UNSIGNED NOT NULL COMMENT 'ShopXO shopxo_order.id',
    `order_detail_id` BIGINT UNSIGNED NOT NULL COMMENT 'ShopXO shopxo_order_detail.id',
    `sku_id`          BIGINT UNSIGNED NOT NULL COMMENT 'ShopXO shopxo_goods_spec_base.id —— 稳定 SKU 身份',
    `goods_id`        BIGINT UNSIGNED NOT NULL COMMENT 'ShopXO shopxo_goods.id',
    `name_snapshot`   VARCHAR(160)    NOT NULL COMMENT '下单时商品名（冻结，不随商品改名而变）',
    `spec_snapshot`   VARCHAR(255)    NOT NULL DEFAULT '' COMMENT '下单时规格描述（仅展示用，不作身份标识）',
    `image_snapshot`  VARCHAR(255)    NOT NULL DEFAULT '' COMMENT '下单时主图',
    `unit_fen`        BIGINT          NOT NULL COMMENT '下单时单价（整数分，冻结）',
    `qty`             INT UNSIGNED    NOT NULL COMMENT '下单数量',
    `created_at`      DATETIME        NOT NULL,
    PRIMARY KEY (`id`),
    -- 一个订单明细只允许捕获一次：钩子若被重复触发，第二次会因唯一约束失败而非写入重复行
    UNIQUE KEY `uk_detail` (`order_detail_id`),
    KEY `idx_order_sku` (`order_id`, `sku_id`),
    KEY `idx_order` (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='订单行快照（含稳定 SKU 身份）';
