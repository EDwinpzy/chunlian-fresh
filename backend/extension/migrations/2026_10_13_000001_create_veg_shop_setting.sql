-- ============================================================
-- 店铺经营设置（单行表）
--
-- 【为什么不复用 shopxo_config】
-- shopxo_config 是 ShopXO 后台的通用键值表，字段是 (id, value, name,
-- describe, error_tips, type, only_tag, upd_time)，没有类型约束，
-- 也没有「营业开关」这个语义的稳定键名 —— 不同 ShopXO 小版本可能改名。
-- 而本项目的营业开关直接决定顾客端能否下单（硬业务约束），
-- 读到一个拼错或已废弃的键名会静默地让店铺「永远营业」。
-- 因此这里用一张带明确列类型与默认值的单行表，语义由我们自己的代码保证。
--
-- 设计上 id 固定为 1，避免出现多行导致「读哪一行」的歧义。
-- ============================================================

CREATE TABLE IF NOT EXISTS `veg_shop_setting` (
    `id`               tinyint unsigned NOT NULL DEFAULT 1 COMMENT '固定为 1，单行表',
    `shop_name`        varchar(60)  NOT NULL DEFAULT '春连鲜选' COMMENT '店铺名称（顾客端展示）',
    `notice`           varchar(255) NOT NULL DEFAULT '' COMMENT '店铺公告，空则不展示',
    `phone`            varchar(20)  NOT NULL DEFAULT '' COMMENT '店铺联系电话，顾客可一键拨号',
    `delivery_fee_fen` bigint       NOT NULL DEFAULT 0  COMMENT '统一配送费（整数分）',
    `is_open`          tinyint      NOT NULL DEFAULT 1  COMMENT '1=营业中 0=已打烊（打烊禁止新下单）',
    `min_lead_minutes` int          NOT NULL DEFAULT 30 COMMENT '预约送达最少提前分钟数',
    `updated_at`       datetime     NOT NULL COMMENT '最后修改时间',
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='春连鲜选 店铺经营设置';

-- 幂等初始化：重复执行不会覆盖店主已经改过的值
INSERT IGNORE INTO `veg_shop_setting`
    (`id`, `shop_name`, `notice`, `phone`, `delivery_fee_fen`, `is_open`, `min_lead_minutes`, `updated_at`)
VALUES
    (1, '春连鲜选', '新鲜好菜，送到家。', '', 0, 1, 30, NOW());
