<?php

declare(strict_types=1);

/**
 * 春连鲜选 扩展层配置。
 *
 * 所有敏感值来自环境变量，**不提交 Git**。
 */

return [
    'business' => [
        /** 经营时区：预约送达时间校验与日结账口径均以此为准。 */
        'timezone' => getenv('BUSINESS_TIMEZONE') ?: 'Asia/Shanghai',

        /** 预约送达的最小前置缓冲（分钟）。 */
        'min_lead_minutes' => (int) (getenv('MIN_LEAD_MINUTES') ?: 30),

        /** 统一配送费（分）。0 = 免运费。 */
        'delivery_fee_fen' => (int) (getenv('DELIVERY_FEE_FEN') ?: 0),
    ],

    'order' => [
        /**
         * 定价规则：旧 SKU 是否维持原下单单价。
         *
         * `true`  = 旧有 SKU 维持原价，新增 SKU 与增量数量按现价（默认，方案 §3.4）
         * `false` = 全部按现价重算
         *
         * 无论取何值，必须**全系统统一**。
         */
        'keep_original_unit_price' => filter_var(
            getenv('KEEP_ORIGINAL_UNIT_PRICE') ?: 'true',
            FILTER_VALIDATE_BOOLEAN
        ),

        /** 待补款修订的过期时间（分钟）；超时自动撤销并归还预占库存。 */
        'pending_topup_ttl_minutes' => (int) (getenv('PENDING_TOPUP_TTL_MINUTES') ?: 30),
    ],

    'idempotency' => [
        /** 幂等键有效期（秒）。 */
        'ttl' => (int) (getenv('IDEMPOTENCY_TTL') ?: 86400),
        'table' => 'veg_idempotency',
    ],

    'outbox' => [
        'max_retry' => (int) (getenv('OUTBOX_MAX_RETRY') ?: 8),
        /** 指数退避基数（秒）：retry_count^n 上限 1 小时。 */
        'backoff_base_seconds' => 30,
        'backoff_cap_seconds' => 3600,
        /** 超过该重试次数标记为 DEAD，进入人工处理。 */
        'dead_after' => 8,
    ],

    'merchant' => [
        /** 访问令牌有效期（秒）：短时令牌 + 刷新。 */
        'access_token_ttl' => (int) (getenv('MERCHANT_TOKEN_TTL') ?: 7200),
        'refresh_token_ttl' => (int) (getenv('MERCHANT_REFRESH_TTL') ?: 2592000),
        /** 登录失败限速：窗口内最大尝试次数。 */
        'login_max_attempts' => 5,
        'login_window_seconds' => 300,
    ],

    'upload' => [
        'max_mb' => (int) (getenv('UPLOAD_MAX_MB') ?: 5),
        'allowed_mime' => ['image/jpeg', 'image/png', 'image/webp'],
        'max_width' => 2400,
        'max_height' => 2400,
    ],

    'privacy' => [
        /**
         * 是否在列表接口返回完整手机号。
         *
         * 首期固定 false；列表/通知只返回脱敏号，完整号仅在
         * 已认证店主的订单详情页返回。见方案 §12.3。
         */
        'expose_full_phone_in_list' => false,
    ],
];
