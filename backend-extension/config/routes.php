<?php

declare(strict_types=1);

/**
 * 春连鲜选 扩展层路由注册。
 *
 * ⚠️  这是**拟新增接口**的路由声明，不是 ShopXO 官方已有路由。
 *     实际注册方式（插件路由 / 独立入口 / 服务层挂载）需在完成
 *     ShopXO 6.9.2 源码审计后确定；见 docs/ROADMAP.md §5。
 *
 * 所有路由的 URI 与 docs/API.md、api-contracts/openapi.yaml 保持一致。
 */

return [
    /*
    |------------------------------------------------------------------
    | 公开目录（无需认证）
    |------------------------------------------------------------------
    */
    'public' => [
        ['GET',  '/api/v1/catalog/categories',      'Catalog\\CategoryController@index'],
        ['GET',  '/api/v1/catalog/products',        'Catalog\\ProductController@index'],
        ['GET',  '/api/v1/catalog/products/{id}',   'Catalog\\ProductController@show'],
        ['POST', '/api/v1/webhook/wechatpay/notify',        'Webhook\\WechatPayController@payNotify'],
        ['POST', '/api/v1/webhook/wechatpay/refund-notify', 'Webhook\\WechatPayController@refundNotify'],
    ],

    /*
    |------------------------------------------------------------------
    | 顾客端（微信小程序）
    |------------------------------------------------------------------
    */
    'customer' => [
        'middleware' => ['auth:customer', 'rate:customer'],
        'routes' => [
            ['GET',  '/api/v1/customer/addresses',              'Customer\\AddressController@index'],
            ['POST', '/api/v1/customer/addresses',              'Customer\\AddressController@store'],

            ['POST', '/api/v1/customer/orders/preview',         'Customer\\OrderController@preview'],
            ['POST', '/api/v1/customer/orders',                 'Customer\\OrderController@store'],
            ['GET',  '/api/v1/customer/orders',                 'Customer\\OrderController@index'],
            ['GET',  '/api/v1/customer/orders/{id}',            'Customer\\OrderController@show'],

            ['POST', '/api/v1/customer/orders/{id}/revisions/preview', 'Customer\\RevisionController@preview'],
            ['POST', '/api/v1/customer/orders/{id}/revisions',         'Customer\\RevisionController@store'],

            ['POST', '/api/v1/customer/orders/{id}/cancel',     'Customer\\OrderController@cancel'],
            ['POST', '/api/v1/customer/orders/{id}/pay',        'Customer\\OrderController@pay'],
            ['GET',  '/api/v1/customer/orders/{id}/refunds',    'Customer\\RefundController@index'],
            ['POST', '/api/v1/customer/orders/{id}/receive',    'Customer\\OrderController@receive'],
        ],
    ],

    /*
    |------------------------------------------------------------------
    | 商家端（Android App）
    |------------------------------------------------------------------
    */
    'merchant' => [
        'middleware' => ['auth:merchant', 'rate:merchant'],
        'routes' => [
            // 认证本身不需前置 token
            ['POST', '/api/v1/merchant/auth/login',   'Merchant\\AuthController@login',  ['public' => true]],
            ['POST', '/api/v1/merchant/auth/logout',  'Merchant\\AuthController@logout'],

            ['GET',  '/api/v1/merchant/dashboard',               'Merchant\\DashboardController@show'],

            ['GET',  '/api/v1/merchant/orders',                  'Merchant\\OrderController@index'],
            ['GET',  '/api/v1/merchant/orders/{id}',             'Merchant\\OrderController@show'],
            ['POST', '/api/v1/merchant/orders/{id}/dispatch',    'Merchant\\OrderController@dispatch'],
            ['POST', '/api/v1/merchant/orders/{id}/deliver',     'Merchant\\OrderController@deliver'],
            ['POST', '/api/v1/merchant/orders/{id}/cod-receipt', 'Merchant\\OrderController@codReceipt'],
            ['GET',  '/api/v1/merchant/orders/{id}/revisions',   'Merchant\\RevisionController@index'],

            ['GET',   '/api/v1/merchant/products',               'Merchant\\ProductController@index'],
            ['POST',  '/api/v1/merchant/products',               'Merchant\\ProductController@store'],
            ['PATCH', '/api/v1/merchant/products/{id}',          'Merchant\\ProductController@update'],

            ['POST', '/api/v1/merchant/uploads',                 'Merchant\\UploadController@store'],

            ['GET',   '/api/v1/merchant/reconciliation',         'Merchant\\ReconciliationController@index'],
            ['GET',   '/api/v1/merchant/store',                  'Merchant\\StoreController@show'],
            ['PATCH', '/api/v1/merchant/store',                  'Merchant\\StoreController@update'],
            ['GET',   '/api/v1/merchant/notifications',          'Merchant\\NotificationController@index'],
        ],
    ],

    /*
    |------------------------------------------------------------------
    | 必须携带 Idempotency-Key 的端点
    |------------------------------------------------------------------
    */
    'idempotent' => [
        '/api/v1/customer/addresses',
        '/api/v1/customer/orders',
        '/api/v1/customer/orders/{id}/revisions',
        '/api/v1/customer/orders/{id}/cancel',
        '/api/v1/customer/orders/{id}/pay',
        '/api/v1/customer/orders/{id}/receive',
        '/api/v1/merchant/orders/{id}/dispatch',
        '/api/v1/merchant/orders/{id}/deliver',
        '/api/v1/merchant/orders/{id}/cod-receipt',
        '/api/v1/merchant/products',
        '/api/v1/merchant/uploads',
        '/api/v1/merchant/store',
    ],

    /*
    |------------------------------------------------------------------
    | 必须校验订单版本（乐观锁）的端点
    |------------------------------------------------------------------
    */
    'version_guarded' => [
        '/api/v1/customer/orders/{id}/revisions',
        '/api/v1/customer/orders/{id}/cancel',
        '/api/v1/customer/orders/{id}/receive',
        '/api/v1/merchant/orders/{id}/dispatch',
        '/api/v1/merchant/orders/{id}/deliver',
        '/api/v1/merchant/orders/{id}/cod-receipt',
    ],
];
