-- ============================================================
-- 春连鲜选 · 演示数据（生鲜品类与商品）
--
-- 【为什么需要它】
-- ShopXO 发行版自带的 28 个示例商品是数码/服饰/箱包，
-- 用来验证「生鲜到家」的商品卡、分类、SKU、下单流程会非常别扭
-- （「笔记本 ×2 货到付款」看不出任何真实感）。
--
-- 【为什么不删掉原有示例商品】
-- 那是 ShopXO 自带的演示数据，属于用户环境的一部分。
-- 这里只**新增**生鲜品类与商品，并把它们的 sort_level 设高，
-- 让它们在顾客端排在最前；原有商品仍在，需要时可自行下架。
--
-- 幂等：按 title 判重，重复执行不会产生重复商品。
-- ============================================================

SET @t := UNIX_TIMESTAMP();

-- ---------------- 分类 ----------------
INSERT INTO `shopxo_goods_category`
    (`pid`, `name`, `vice_name`, `icon`, `icon_active`, `describe`, `bg_color`,
     `is_home_recommended`, `sort`, `is_enable`, `add_time`, `upd_time`)
SELECT 0, '叶菜类', '', '', '', '当日采摘的绿叶菜', '#EAF3E9', 1, 100, 1, @t, @t
WHERE NOT EXISTS (SELECT 1 FROM `shopxo_goods_category` WHERE `name` = '叶菜类' AND `pid` = 0);

INSERT INTO `shopxo_goods_category`
    (`pid`, `name`, `vice_name`, `icon`, `icon_active`, `describe`, `bg_color`,
     `is_home_recommended`, `sort`, `is_enable`, `add_time`, `upd_time`)
SELECT 0, '瓜果类', '', '', '', '本地大棚瓜果', '#FDF3E7', 1, 90, 1, @t, @t
WHERE NOT EXISTS (SELECT 1 FROM `shopxo_goods_category` WHERE `name` = '瓜果类' AND `pid` = 0);

INSERT INTO `shopxo_goods_category`
    (`pid`, `name`, `vice_name`, `icon`, `icon_active`, `describe`, `bg_color`,
     `is_home_recommended`, `sort`, `is_enable`, `add_time`, `upd_time`)
SELECT 0, '根茎类', '', '', '', '耐放的根茎菜', '#F3EFE7', 1, 80, 1, @t, @t
WHERE NOT EXISTS (SELECT 1 FROM `shopxo_goods_category` WHERE `name` = '根茎类' AND `pid` = 0);

INSERT INTO `shopxo_goods_category`
    (`pid`, `name`, `vice_name`, `icon`, `icon_active`, `describe`, `bg_color`,
     `is_home_recommended`, `sort`, `is_enable`, `add_time`, `upd_time`)
SELECT 0, '蛋豆类', '', '', '', '鸡蛋与豆制品', '#FBF6E9', 1, 70, 1, @t, @t
WHERE NOT EXISTS (SELECT 1 FROM `shopxo_goods_category` WHERE `name` = '蛋豆类' AND `pid` = 0);

INSERT INTO `shopxo_goods_category`
    (`pid`, `name`, `vice_name`, `icon`, `icon_active`, `describe`, `bg_color`,
     `is_home_recommended`, `sort`, `is_enable`, `add_time`, `upd_time`)
SELECT 0, '菌菇类', '', '', '', '当日鲜菇', '#EEF3EC', 1, 60, 1, @t, @t
WHERE NOT EXISTS (SELECT 1 FROM `shopxo_goods_category` WHERE `name` = '菌菇类' AND `pid` = 0);

INSERT INTO `shopxo_goods_category`
    (`pid`, `name`, `vice_name`, `icon`, `icon_active`, `describe`, `bg_color`,
     `is_home_recommended`, `sort`, `is_enable`, `add_time`, `upd_time`)
SELECT 0, '调味配菜', '', '', '', '葱姜蒜等配菜', '#F0F2EC', 0, 50, 1, @t, @t
WHERE NOT EXISTS (SELECT 1 FROM `shopxo_goods_category` WHERE `name` = '调味配菜' AND `pid` = 0);

-- ---------------- 商品 ----------------
-- 商品名 / 单价(元) / 库存 / 单位 / 规格文本 / 所属分类
DROP TEMPORARY TABLE IF EXISTS `tmp_seed_goods`;
CREATE TEMPORARY TABLE `tmp_seed_goods` (
    `title`   varchar(120) NOT NULL,
    `price`   decimal(10,2) NOT NULL,
    `inv`     int NOT NULL,
    `unit`    varchar(10) NOT NULL,
    `spec`    varchar(40) NOT NULL,
    `cat`     varchar(20) NOT NULL
);

INSERT INTO `tmp_seed_goods` VALUES
 ('本地小青菜',        3.50,  80, '份', '约500g/份',  '叶菜类'),
 ('有机菠菜',          4.50,  60, '份', '约400g/份',  '叶菜类'),
 ('上海青',            3.20,  90, '份', '约500g/份',  '叶菜类'),
 ('油麦菜',            3.80,  70, '份', '约500g/份',  '叶菜类'),
 ('沙瓤西红柿',        5.60, 100, '份', '约500g/份',  '瓜果类'),
 ('水果黄瓜',          6.80,  60, '份', '约500g/份',  '瓜果类'),
 ('紫皮长茄子',        5.20,  50, '份', '约500g/份',  '瓜果类'),
 ('黄心土豆',          2.80, 150, '份', '约500g/份',  '根茎类'),
 ('山东胡萝卜',        3.00, 120, '份', '约500g/份',  '根茎类'),
 ('山东大葱',          2.60, 100, '把', '1把(约300g)', '调味配菜'),
 ('本地土鸡蛋',       12.80,  60, '盒', '10枚/盒',     '蛋豆类'),
 ('卤水老豆腐',        4.50,  40, '块', '1块(约400g)', '蛋豆类'),
 ('新鲜香菇',          6.90,  40, '盒', '约200g/盒',   '菌菇类'),
 ('金针菇',            3.20,  80, '袋', '约200g/袋',   '菌菇类');

-- 主表：只插入尚不存在的商品
INSERT INTO `shopxo_goods`
    (`title`, `title_color`, `simple_desc`, `spec_desc`, `images`,
     `original_price`, `min_original_price`, `max_original_price`,
     `price`, `min_price`, `max_price`, `inventory`, `inventory_unit`,
     `give_integral`, `buy_min_number`, `buy_max_number`, `is_deduction_inventory`,
     `is_shelves`, `content_web`, `photo_count`, `sales_count`, `access_count`,
     `video`, `is_exist_many_spec`, `spec_base`, `fictitious_goods_value`,
     `use_guide`, `sort_level`, `share_images`, `seo_title`, `seo_keywords`, `seo_desc`,
     `is_delete_time`, `add_time`, `upd_time`)
SELECT s.`title`, '', '', s.`spec`, '',
       '0.00', '0.00', '0.00',
       s.`price`, s.`price`, s.`price`, s.`inv`, s.`unit`,
       0, 1, 0, 1,
       1, '', 0, 0, 0,
       '', 0, '', '',
       '', 500, '', '', '', '',
       0, @t, @t
FROM `tmp_seed_goods` s
WHERE NOT EXISTS (SELECT 1 FROM `shopxo_goods` g WHERE g.`title` = s.`title` AND g.`is_delete_time` = 0);

-- SKU：每个商品一个默认规格（本项目按固定规格定价，见方案 §1.3）
INSERT INTO `shopxo_goods_spec_base`
    (`goods_id`, `price`, `original_price`, `inventory`, `buy_min_number`, `buy_max_number`,
     `weight`, `volume`, `coding`, `barcode`, `inventory_unit`, `extends`, `add_time`)
SELECT g.`id`, s.`price`, '0.00', s.`inv`, 1, 0,
       0, 0, '', '', s.`unit`, '[]', @t
FROM `tmp_seed_goods` s
INNER JOIN `shopxo_goods` g ON g.`title` = s.`title` AND g.`is_delete_time` = 0
WHERE NOT EXISTS (
    SELECT 1 FROM `shopxo_goods_spec_base` b WHERE b.`goods_id` = g.`id`
);

-- 规格值（顾客端显示「约500g/份」）
INSERT INTO `shopxo_goods_spec_value` (`goods_id`, `goods_spec_base_id`, `value`)
SELECT g.`id`, b.`id`, s.`spec`
FROM `tmp_seed_goods` s
INNER JOIN `shopxo_goods` g ON g.`title` = s.`title` AND g.`is_delete_time` = 0
INNER JOIN `shopxo_goods_spec_base` b ON b.`goods_id` = g.`id`
WHERE NOT EXISTS (
    SELECT 1 FROM `shopxo_goods_spec_value` v
    WHERE v.`goods_spec_base_id` = b.`id` AND v.`value` = s.`spec`
);

-- 分类关联
INSERT INTO `shopxo_goods_category_join` (`goods_id`, `category_id`, `add_time`)
SELECT g.`id`, c.`id`, @t
FROM `tmp_seed_goods` s
INNER JOIN `shopxo_goods` g ON g.`title` = s.`title` AND g.`is_delete_time` = 0
INNER JOIN `shopxo_goods_category` c ON c.`name` = s.`cat` AND c.`pid` = 0
WHERE NOT EXISTS (
    SELECT 1 FROM `shopxo_goods_category_join` j
    WHERE j.`goods_id` = g.`id` AND j.`category_id` = c.`id`
);

DROP TEMPORARY TABLE `tmp_seed_goods`;
