ALTER TABLE `veg_shop_setting`
    ADD COLUMN `payment_qr_url` varchar(500) NOT NULL DEFAULT '' COMMENT '店主实际收款二维码图片';
