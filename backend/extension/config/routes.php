<?php

declare(strict_types=1);

/**
 * 春连鲜选 扩展层路由声明（**契约文档，非实际注册代码**）。
 *
 * ✅ 审计已完成 —— 实际路由方式见 docs/SHOPXO-AUDIT.md §2，结论：
 *
 *   1. **不需要手写路由文件**。ShopXO 的 `config/route.php` 里
 *      `url_route_must = false`，插件走 catch-all 分发：
 *        前台：`/{插件名}-{控制器}-{方法}`
 *        API ：`/api.php?s=/plugins/index&pluginsname=…&pluginscontrol=…&pluginsaction=…`
 *
 *   2. 商家端 API 由 `app/plugins/chunlianfresh/api/Merchant.php` 的
 *      `dispatch` 单入口内部路由，Nginx 把 `/api/v1/<path>` 重写成插件调用
 *      （见 infrastructure/nginx/chunlian.conf）。
 *
 *   3. 本文件保留为**接口清单与契约对照**：所有 URI 与 docs/API.md、
 *      api-contracts/openapi.yaml 保持一致，便于评审与生成文档。
 *      它不是被加载的代码 —— 修改它不会改变运行时行为。
 *
 * ⚠️ 因此：**不要把本文件的内容复制进插件当作路由注册**。
 *    新增接口应当改 `api/Merchant.php` 的 dispatch 分支 + 本文件 + OpenAPI，
 *    三处保持同步。
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
            ['POST',   '/api/v1/customer/push-devices',                          'Customer\\PushDeviceController@store'],
            ['DELETE', '/api/v1/customer/push-devices/{installation_id}',        'Customer\\PushDeviceController@destroy'],

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

            ['POST',   '/api/v1/merchant/push-devices',                          'Merchant\\PushDeviceController@store'],
            ['DELETE', '/api/v1/merchant/push-devices/{installation_id}',        'Merchant\\PushDeviceController@destroy'],

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
