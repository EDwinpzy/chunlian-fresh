-- ============================================================
-- 顾客会话（网页版 / 安卓版）
--
-- 【为什么顾客端也需要自己的会话表】
-- ShopXO 原生的用户 token 存在 shopxo_user.token 单列上，
-- 一个账号只能有一个有效 token —— 手机上登录会把网页端挤下线。
-- 顾客端的真实场景恰恰是多端并存（手机 + 浏览器），
-- 所以沿用商家端已经验证过的做法：自建会话表，只存 token 的 SHA-256。
--
-- 账号本身仍然落在 shopxo_user 里（密码算法沿用
-- md5(salt . pwd)，见 app/common.php 的 LoginPwdEncryption），
-- 这样 ShopXO 后台依然能看到顾客、订单归属也不会错。
-- ============================================================

CREATE TABLE IF NOT EXISTS `veg_customer_session` (
    `id`           bigint unsigned NOT NULL AUTO_INCREMENT,
    `user_id`      bigint unsigned NOT NULL COMMENT '对应 shopxo_user.id',
    `token_hash`   char(64)    NOT NULL COMMENT 'token 的 SHA-256（不存明文）',
    `device_name`  varchar(128)         DEFAULT NULL COMMENT 'User-Agent 摘要，用于「我的设备」',
    `last_seen_at` datetime             DEFAULT NULL,
    `expires_at`   datetime    NOT NULL,
    `revoked_at`   datetime             DEFAULT NULL COMMENT '非空即已失效（登出/踢下线）',
    `created_at`   datetime    NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_token_hash` (`token_hash`),
    KEY `idx_user` (`user_id`, `revoked_at`),
    KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='顾客 API 会话';
