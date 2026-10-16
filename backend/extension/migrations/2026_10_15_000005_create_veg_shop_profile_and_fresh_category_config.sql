-- 店铺展示资料与自有生鲜分类登记。
-- 店铺运营展示文案独立保存，分类表只登记允许进入春连鲜选目录的根类 ID。

CREATE TABLE IF NOT EXISTS `veg_shop_profile` (
    `id`                    tinyint unsigned NOT NULL DEFAULT 1,
    `shop_logo_url`         varchar(500) NOT NULL DEFAULT '',
    `shop_address`          varchar(255) NOT NULL DEFAULT '',
    `business_hours`        varchar(120) NOT NULL DEFAULT '',
    `shop_description`      varchar(500) NOT NULL DEFAULT '',
    `delivery_description`  varchar(500) NOT NULL DEFAULT '',
    `home_banner_title`     varchar(80)  NOT NULL DEFAULT '',
    `home_banner_subtitle`  varchar(120) NOT NULL DEFAULT '',
    `shop_slogan`           varchar(120) NOT NULL DEFAULT '',
    `search_hot_words`      text         NOT NULL COMMENT 'JSON string array',
    `home_section_title`    varchar(80)  NOT NULL DEFAULT '',
    `updated_at`            datetime     NOT NULL,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='店铺对外展示资料';

INSERT IGNORE INTO `veg_shop_profile`
    (`id`, `search_hot_words`, `updated_at`)
VALUES
    (1, '[]', NOW());

CREATE TABLE IF NOT EXISTS `veg_fresh_category_root` (
    `category_id` bigint unsigned NOT NULL COMMENT 'ShopXO goods_category root ID',
    `created_at`  datetime NOT NULL,
    PRIMARY KEY (`category_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='春连鲜选目录允许的根分类';

CREATE TABLE IF NOT EXISTS `veg_fresh_category_state` (
    `id`             tinyint unsigned NOT NULL DEFAULT 1,
    `legacy_seeded`  tinyint NOT NULL DEFAULT 0 COMMENT '是否已完成一次性旧分类兼容导入',
    `updated_at`     datetime NOT NULL,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='春连鲜选分类配置状态';

INSERT IGNORE INTO `veg_fresh_category_state` (`id`, `legacy_seeded`, `updated_at`)
VALUES (1, 0, NOW());
