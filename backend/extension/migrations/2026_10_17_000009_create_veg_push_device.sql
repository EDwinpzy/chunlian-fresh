-- Getui CID binding for authenticated customer and merchant app installations.
-- Device ownership is derived from the authenticated API session, never request data.
CREATE TABLE IF NOT EXISTS `veg_push_device` (
    `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `actor_type`      VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'CUSTOMER / MERCHANT',
    `actor_id`        VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'Authenticated ShopXO user/admin ID',
    `app_id`          VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'Configured Getui App ID',
    `installation_id` VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'Stable per-installation client identifier',
    `cid`             VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'Getui Client ID',
    `revoked_at`      DATETIME        NULL,
    `created_at`      DATETIME        NOT NULL,
    `updated_at`      DATETIME        NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_app_installation` (`app_id`, `installation_id`),
    KEY `idx_actor_active` (`actor_type`, `actor_id`, `revoked_at`),
    KEY `idx_app_cid_active` (`app_id`, `cid`, `revoked_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='推送设备 CID 绑定';
