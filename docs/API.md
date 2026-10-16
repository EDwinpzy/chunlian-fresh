# API 路由与集成说明｜春连鲜选

> 本文按仓库当前 ShopXO 插件控制器整理。生产入口为 https://103.236.97.137:8096/api/v1/，当前发布为 `chunlian-20261015-open-access-v1`。TLS 使用项目私有 CA；Android 按精确 IP 信任，网页用户须按使用说明手动安装或信任该 CA。商家公开免登录会话已按用户决定在生产启用。

## 1. 通用约定

- 顾客路由前缀：/api/v1/customer/。
- 商家路由前缀：/api/v1/merchant/。
- 插件响应使用 code、message、data。并非每个错误都保证相同 HTTP 状态码。
- 顾客与商家会话通过 Bearer token 使用。不得把管理员口令、服务器凭据或私钥写入客户端。
- 金额字段使用整数分，例如 price_fen、amount_fen、collected_fen。
- 支付方式为 OFFLINE_QR 与 COD。服务端拒绝 WECHAT 下单方式；仓库没有线上微信支付下单或支付通知 webhook 路由。

### 幂等和订单版本

- 顾客创建订单、订单修订、取消、确认收货、切换 COD 均要求 Idempotency-Key（UUID v4）。订单修订还要求请求体中的 client_version 或兼容字段 version。
- 商家开始配送、确认送达、补记 COD 收款、扫码收款核实、人工退款确认和取消均要求 Idempotency-Key；需要并发保护的动作还传订单 version。
- 相同幂等键用于不同请求体时返回 IDEMPOTENCY_CONFLICT；原请求仍在处理时可能返回 IDEMPOTENCY_IN_PROGRESS。
- 其他写接口的幂等范围以对应控制器和服务为准，不应从设计稿推断所有路由行为一致。

## 2. 顾客端路由

公开店铺与目录：

| 方法 | 路径 | 用途 |
|---|---|---|
| GET | /api/v1/customer/shop | 店铺信息，含可用收款二维码设置 |
| GET | /api/v1/customer/catalog/categories | 商品分类 |
| GET | /api/v1/customer/catalog/goods | 商品列表与筛选 |
| GET | /api/v1/customer/catalog/goods/{id} | 商品详情 |

顾客账号、地址与订单：

| 方法 | 路径 | 用途 |
|---|---|---|
| POST | /api/v1/customer/auth/register | 注册 |
| POST | /api/v1/customer/auth/login | 登录 |
| POST | /api/v1/customer/auth/logout | 注销 |
| GET | /api/v1/customer/auth/me | 当前账号 |
| POST 或 PATCH | /api/v1/customer/auth/profile | 更新资料 |
| GET、POST | /api/v1/customer/addresses | 查询或保存地址 |
| POST | /api/v1/customer/addresses/{id}/default | 设置默认地址 |
| DELETE | /api/v1/customer/addresses/{id} | 删除地址 |
| POST | /api/v1/customer/orders/preview | 下单预览 |
| GET、POST | /api/v1/customer/orders | 查询或创建订单 |
| GET | /api/v1/customer/orders/{id} | 订单详情 |
| POST | /api/v1/customer/orders/{id}/revision/preview | 修订预览 |
| POST | /api/v1/customer/orders/{id}/revision | 提交修订 |
| GET | /api/v1/customer/orders/{id}/revisions | 修订记录 |
| POST 或 PATCH | /api/v1/customer/orders/{id}/info | 更新联系信息 |
| POST | /api/v1/customer/orders/{id}/cancel | 取消订单 |
| POST | /api/v1/customer/orders/{id}/receive | 确认收货 |
| POST | /api/v1/customer/orders/{id}/switch-to-cod | 切换为 COD |
| GET | /api/v1/customer/orders/{id}/refunds | 查询退款记录 |

需要登录的顾客路由使用顾客 Bearer token；服务检查订单归属。目录与店铺信息公开读取。

## 3. 商家端路由

商家路由均位于 /api/v1/merchant/。除登录和下述开关控制的 `auth/open-session` 外，需要有效商家 Bearer token。

| 路径（拼接商家前缀） | 用途 |
|---|---|
| auth/login | 商家登录 |
| **POST auth/open-session** | **公开免登录管理会话；仅当服务端环境变量 `CHUNLIAN_MERCHANT_OPEN_ACCESS` 精确等于 `1` 时启用** |
| dashboard | 工作台与店铺概览 |
| receivables | COD 应收 |
| settlement/daily | 每日结算 |
| stats、ledger | 经营统计与资金流水 |
| notifications、notifications/read | 通知与已读状态 |
| store | 读取或更新店铺设置，包括 payment_qr_url |
| uploads | 上传商品或收款码所需图片 |
| goods/categories | 商品分类 |
| goods、goods/{id} | 商品列表、详情、新建、更新或下架 |
| goods/{id}/shelves | 商品上下架 |
| goods/{id}/sku/{skuId} | 更新 SKU 价格或库存 |
| orders、orders/{id} | 订单列表与详情 |
| orders/{id}/revisions | 订单修订记录 |
| orders/{id}/commit-topup | 提交已核实补款的修订 |
| orders/{id}/deliver | 开始配送 |
| orders/{id}/confirm-delivery | 确认送达，可登记 COD 实收 |
| orders/{id}/cod-receipt | 补记 COD 收款 |
| orders/{id}/qr-receipt | 核实并登记扫码预付款或修订补款 |
| orders/{id}/manual-refund | 核实已经实际退回的线下退款 |
| orders/{id}/cancel | 商家取消订单 |

`POST /api/v1/merchant/auth/open-session` 请求体为 `{"device_id":"<设备标识>"}`，成功响应与密码登录的 `LoginResult` 相同：`data` 含 `token`、`expires_at`、`merchant_id`、`shop_name`。服务端选择 ID 最小且 `status=0` 的 ShopXO 管理员作为会话 actor，将 256-bit 随机 token 的 SHA-256 写入现有 `veg_merchant_session` 表，30 天后过期。功能默认关闭；未设置开关或值不是精确的 `1` 时返回 `FORBIDDEN`。

### 商家设置与分类配置

`GET /api/v1/merchant/store` 读取全部店铺设置；`PATCH` 或 `PUT` 接受部分字段更新。字段分成营业/下单设置和顾客端展示资料，保存后顾客端 `GET /api/v1/customer/shop` 返回同一份资料。`updated_at` 只读，更新请求体应省略。

| 字段 | 含义 | 类型 |
|---|---|---|
| `shop_name`、`notice`、`phone` | 店名、公告、联系电话 | 文本 |
| `payment_qr_url` | 线下收款码图片地址 | 文本 |
| `delivery_fee_fen`、`min_lead_minutes` | 配送费（分）、预约提前分钟数 | 整数 |
| `is_open` | 是否营业并允许新下单 | 布尔 |
| `shop_logo_url`、`shop_address`、`business_hours` | 店铺 Logo、地址、营业时间 | 文本 |
| `shop_description`、`delivery_description` | 店铺简介、配送说明 | 文本 |
| `home_banner_title`、`home_banner_subtitle`、`shop_slogan` | 首页主副标题、宣传语 | 文本 |
| `search_hot_words` | 首页热门搜索词，最多 10 项 | 文本数组 |
| `home_section_title` | 首页商品区标题 | 文本 |
| `updated_at` | 最近更新时间 | 只读文本 |

分类由商家 App 的“商品分类”管理：`GET /api/v1/merchant/goods/categories` 返回可配置的根分类及直接子类（含已停用项）；`POST` 新建根类或子类。创建根类传 `{"name":"海鲜水产","sort":100,"is_enabled":true}`；创建子类额外传 `parent_id`。新建请求必须带 UUID v4 `Idempotency-Key`。`PATCH` 或 `PUT /api/v1/merchant/goods/categories/{id}` 可更新 `name`、`sort`、`is_enabled`；`DELETE` 仅允许删除没有子类且没有关联商品的分类。根分类 ID 会单独登记，所以改名不会改变它是否属于春连鲜选目录。顾客端只显示启用的已登记分类；停用分类下既有商品仍可由商家查看和调整。

部署 `2026_10_15_000005_create_veg_shop_profile_and_fresh_category_config.sql` 后，顾客端第一次读取目录会运行旧分类兼容导入检查：发现旧版六个生鲜根类时登记其分类 ID；若旧分类尚不存在，会在后续读取时继续检查。运行时目录过滤依据登记的 ID，不依赖分类名称。

**安全影响：**当前生产已启用此模式。任何能够访问该 API 的人都可以取得完整商家管理权限，包括修改商品和库存、查看订单及顾客联系方式、变更店铺设置和登记收款/退款。无需账号密码、设备审批或其他身份验证；`device_id` 仅用于会话记录，不构成授权。关闭环境变量会阻止新公开会话，但已签发会话仍有效至过期或单独撤销。

商家鉴权后的订单详情返回 `contact.phone`，供拨号及复制履约信息。订单列表、工作台与通知仍只返回脱敏电话；匿名请求不能读取详情。

`GET merchant/orders` 可用 `status` 按履约状态筛选；省略时包含全部订单。`status=DELIVERED` 同时包含 `DELIVERED`（已送达）和 `FINISHED`（已完成），列表将两者合并展示。支持的值为 `PENDING`、`DELIVERING`、`DELIVERED` 和 `CANCELLED`。接口不提供列表专用的待收款筛选，收款与退款业务仍由工作台统计、账本及对应操作接口提供。接口还支持 `sort=DEFAULT|EXPECTED_AT`：默认值 `DEFAULT` 保持待配送、配送中、已送达和终态的履约优先顺序；`EXPECTED_AT` 按预计配送时间从早到晚排序，`expected_at` 为空的即时配送（ASAP）订单排在预约订单之前。两种排序都在服务端应用，因此筛选、关键词搜索和分页共用同一排序结果；无效的 `sort` 值回退为 `DEFAULT`。

通知列表的 `unread_count` 为该商家账号的全部未读事件数，不受 `limit` 限制；`total` 仅为本次列表条数。`POST notifications/read` 返回 `marked_count`，只将本次读取已提交的事件标为已读；随后提交的并发事件仍为未读。已读状态按账号和事件分别保存。

SKU 调价与库存接口使用 `PATCH` 或 `PUT`；`POST` 会被拒绝。经营统计使用 `GET stats?from=YYYY-MM-DD&to=YYYY-MM-DD`，区间含起止日期。店铺业务日按 `Asia/Shanghai` 计算，管理 App 的今天、近 7 天和近 30 天也按北京时间选择，独立于设备时区。

`goods/{id}/sku/{skuId}` 价格和库存更新使用 `PATCH` 或 `PUT`，不接受旧式 `POST`。请求金额字段为 `price_fen`，库存字段为 `inventory`。

`GET ledger` 可用 `order_id`、`kind`、`page`、`page_size` 筛选和分页。响应包含 `list`、`total`、`page`、`page_size` 与 `summary`。金额使用整数分；退款为负数。汇总中的 `inflow_fen`、`outflow_fen`、`net_fen` 仅计算成功流水，`in_flight_count` 单列未完成流水数量。

订单扫码收款核实、人工退款确认与其他资金写操作必须带幂等键和当前订单版本。客户端二维码展示、支付截图或口头反馈不能替代商家核账。

对已付款 OFFLINE_QR 订单提交减量修订时，响应中的修订状态为 `APPLYING`，新明细和总额立即生效，退款流水保持 `PROCESSING`；该状态下禁止配送和再次改单。店主确认实际退款后，退款流水才转为成功，修订状态转为 `COMMITTED`。

## 4. 当前能力边界

- 服务端拒绝 WECHAT 下单方式。没有微信支付商户下单、支付 webhook 或微信退款能力。
- OFFLINE_QR 的收款由店主核实真实到账后，在商家端记录；退款只有在款项已实际退回后才记录为成功。
- 数据库迁移 2026_10_13_000003_add_payment_qr.sql 已随 2026-10-14 发布部署到生产；生产只读验收 18/18 通过。当前生产 `payment_qr_url` 为空，店主可在管理 App 上传真实收款码；QA 合成码只用于隔离测试。
- 当前发布状态、隔离业务与客户端验收范围见 HANDOVER.md、docs/ACCEPTANCE-REPORT-2026-10-13.md 和 docs/TESTING.md。真实资金、实体手机及生产管理员登录后的写入未测试。

## 5. 业务不变量

履约、付款、顾客收货与订单修订状态分开；COD 已送达但未收款保留应收；金额用整数分；顾客只能在开始配送成功前自主修改或取消；所有资金、库存和订单副作用均须防止重复执行。详见 docs/PRD.md 与 docs/ARCHITECTURE.md。
