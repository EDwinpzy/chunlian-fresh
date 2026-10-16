-- 回滚会删除店铺展示资料与分类登记；运行前请先归档 `veg_fresh_category_root`。
DROP TABLE IF EXISTS `veg_fresh_category_state`;
DROP TABLE IF EXISTS `veg_fresh_category_root`;
DROP TABLE IF EXISTS `veg_shop_profile`;
