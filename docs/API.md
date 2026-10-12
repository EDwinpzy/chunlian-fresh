# API 契约｜春连鲜选（后端）

> 来源：方案 §10。所有 `/api/v1/...` 均为本项目**拟新增接口**，并非声称 ShopXO 官方已存在同名路由。
> **所有金额使用 `*_fen` 整数（Long）**。机器可读版本见 `api-contracts/openapi.yaml`。
>
> **范围说明**：本仓库仅保留后端。契约中「顾客端接口」一节描述的是面向顾客端的
> API 设计，其中**当前已实现并验证的只有订单改单相关端点**
> （`/api/v1/customer/orders/{id}/revision*`）；其余顾客端能力（首页、商品、
> 购物车、地址、下单、支付）目前仍走 ShopXO 原生 API（`/api.php?s=...`）。

## 1. 通用约定

### 1.1 请求头

| Header | 必填 | 说明 |
|---|---|---|
| `Authorization` | 是 | `Bearer <token>`；顾客为微信身份会话，商家为管理员会话 |
| `Idempotency-Key` | 写请求必填 | UUID v4；同 key 重复请求返回首次结果 |
| `X-Order-Version` | 订单写操作 | 乐观锁版本号，服务端强校验 |
| `X-Client` | 否 | 调用方标识（如 `merchant` / `customer`），用于日志与错误展示策略 |
| `X-Device-Id` | 商家端 | 设备标识，用于会话撤销与限速 |

### 1.2 统一响应

成功：

```json
{
  "code": "OK",
  "message": "success",
  "data": { },
  "request_id": "server-generated-request-id"
}
```

失败：

```json
{
  "code": "ORDER_VERSION_CONFLICT",
  "message": "订单内容刚刚发生变化，请刷新后再试",
  "data": { "current_version": 5 },
  "request_id": "server-generated-request-id"
}
```

> **禁止**在错误响应里返回数据库栈、支付私钥、完整用户隐私等信息。

### 1.3 错误码

| 错误码 | HTTP | 顾客/商家提示 |
|---|---|---|
| `UNAUTHORIZED` | 401 | 登录已过期，请重新登录 |
| `FORBIDDEN` | 403 | 无权访问该资源 |
| `NOT_FOUND` | 404 | 资源不存在 |
| `RATE_LIMITED` | 429 | 操作过于频繁，请稍后再试 |
| `VALIDATION_FAILED` | 422 | 提交内容有误，请检查后重试 |
| `ORDER_NOT_EDITABLE` | 409 | 商家已开始配送，不能再修改或取消 |
| `ORDER_VERSION_CONFLICT` | 409 | 订单发生变化，请刷新后重试 |
| `OUT_OF_STOCK` | 409 | 部分商品库存不足，请调整数量 |
| `TOPUP_REQUIRED` | 409 | 该订单还需补款，暂不能配送 |
| `PAYMENT_CONFIRMING` | 409 | 支付确认中，请稍后刷新 |
| `REFUND_PROCESSING` | 409 | 退款正在处理，请稍后查询 |
| `COD_ALREADY_COLLECTED` | 409 | 此单已登记收款，请勿重复操作 |
| `INVALID_EXPECTED_TIME` | 422 | 预约时间必须晚于当前时间 |
| `SHOP_CLOSED` | 409 | 商家暂停营业，暂不能新下单 |
| `IDEMPOTENCY_CONFLICT` | 409 | 相同请求已处理，结果不同 |

### 1.4 幂等规则

- 所有写操作必须携带 `Idempotency-Key`，服务端在 `veg_payment_ledger.idempotency_key` / 会话级幂等表中去重。
- 重复请求：**返回首次执行结果**（HTTP 200 + 相同 data），不重复执行副作用。
- Key 作用域：`actor_id + endpoint + key`，有效期 24h。

## 2. 顾客端接口

| 方法 | 接口 | 作用 | 权限/幂等要求 |
|---|---|---|---|
| `GET` | `/api/v1/catalog/categories` | 分类 | 公开 |
| `GET` | `/api/v1/catalog/products` | 商品/搜索/库存展示 | 公开 |
| `GET` | `/api/v1/catalog/products/{id}` | 商品详情与 SKU | 公开 |
| `GET` | `/api/v1/customer/addresses` | 我的地址 | 顾客 token |
| `POST` | `/api/v1/customer/addresses` | 保存地址 | 顾客 token |
| `POST` | `/api/v1/customer/orders/preview` | 下单金额预览 | 顾客 token |
| `POST` | `/api/v1/customer/orders` | 下单 | `Idempotency-Key` |
| `GET` | `/api/v1/customer/orders` | 我的订单 | 顾客 token |
| `GET` | `/api/v1/customer/orders/{id}` | 订单详情与权限 | 校验归属 |
| `POST` | `/api/v1/customer/orders/{id}/revisions/preview` | 修改预览 | 订单版本号 |
| `POST` | `/api/v1/customer/orders/{id}/revisions` | 提交修订 | 版本号 + 幂等 |
| `POST` | `/api/v1/customer/orders/{id}/cancel` | 取消订单 | 订单锁 + 幂等 |
| `POST` | `/api/v1/customer/orders/{id}/pay` | 获取支付参数/补差价 | 绑定支付单 |
| `GET` | `/api/v1/customer/orders/{id}/refunds` | 退款进度 | 校验归属 |
| `POST` | `/api/v1/customer/orders/{id}/receive` | 确认收货 | 状态检查、幂等 |

### 2.1 新建订单请求

```json
{
  "client_request_id": "uuid-from-client",
  "items": [{ "sku_id": "SKU_102", "qty": 2 }],
  "contact": { "name": "张先生", "phone": "13800138000", "address": "阳光小区3栋502室" },
  "delivery_mode": "scheduled",
  "expected_delivery_at": "2026-10-13T17:30:00+08:00",
  "payment_method": "COD",
  "remark": "到了电话联系"
}
```

约束：

- `asap` 时不传 `expected_delivery_at`；`scheduled` 时必传且必须晚于当前时间（服务器经营时区）+ 至少 30 分钟缓冲。
- `payment_method` ∈ `WECHAT` | `COD`。COD 下单返回 `fulfillment=PENDING`、`payment=UNPAID`。
- 金额**全部由服务端计算**，请求不接受任何金额字段。

响应 `data`：

```json
{
  "order_id": "10025",
  "order_no": "20261012193000001",
  "order_version": 1,
  "fulfillment": "PENDING",
  "payment_state": "UNPAID",
  "payment_method": "COD",
  "total_fen": 3580,
  "goods_fen": 3580,
  "delivery_fee_fen": 0,
  "pay_params": null
}
```

`payment_method=WECHAT` 时 `pay_params` 返回客户端支付参数（`timeStamp`/`nonceStr`/`package`/`signType`/`paySign`，供微信小程序 `wx.requestPayment` 使用），`payment_state=PAYING`。

### 2.2 修改预览请求

```json
{
  "order_version": 3,
  "items": [{ "sku_id": "SKU_102", "qty": 3 }, { "sku_id": "SKU_205", "qty": 1 }],
  "contact": { "name": "张先生", "phone": "13800138000", "address": "阳光小区3栋502室" },
  "delivery_mode": "asap"
}
```

响应 `data`：

```json
{
  "order_version": 3,
  "old_total_fen": 3580,
  "new_total_fen": 4520,
  "delta_fen": 940,
  "delta_type": "TOPUP",
  "goods_changed": true,
  "address_changed": false,
  "time_changed": true,
  "priced_lines": [
    { "sku_id": "SKU_102", "qty": 3, "unit_fen": 350, "priced_by": "ORIGINAL_ORDER" },
    { "sku_id": "SKU_205", "qty": 1, "unit_fen": 490, "priced_by": "CURRENT_PRICE" }
  ],
  "warnings": [],
  "customer_message": "本次修改需补付 ¥9.40，支付成功后才生效。"
}
```

- `delta_type` ∈ `NONE` | `TOPUP` | `REFUND`。
- 定价规则：**旧有 SKU 维持原下单单价；新增 SKU 按现价；增量数量按现价**。`priced_by` 字段显式说明来源。
- 缺货 SKU 进 `warnings`，并在提交时拒绝。

### 2.3 提交修订请求

```json
{
  "order_version": 3,
  "items": [{ "sku_id": "SKU_102", "qty": 3 }],
  "contact": { "name": "张先生", "phone": "13800138000", "address": "阳光小区3栋502室" },
  "remark": "放门口"
}
```

响应 `data`：

```json
{
  "order_version": 4,
  "revision_id": 88,
  "revision_status": "PENDING_TOPUP",
  "delta_fen": 940,
  "pay_params": { "timeStamp": "...", "nonceStr": "...", "package": "...", "signType": "RSA", "paySign": "..." },
  "effective_immediately": false,
  "customer_message": "本次修改需补付 ¥9.40，支付成功后才生效。"
}
```

- `delta_fen > 0` 且已付款 → `revision_status=PENDING_TOPUP`，`effective_immediately=false`，需调用 `/pay` 补款。
- `delta_fen < 0` 且已付款 → `revision_status=COMMITTED`，同时产生退款任务，`refund_state=PROCESSING`。
- `delta_fen = 0` → `revision_status=COMMITTED`，`effective_immediately=true`。
- 未支付 → `COMMITTED`，旧支付单失效并重新生成应付金额。

### 2.4 取消订单请求

```json
{ "order_version": 4, "reason": "临时有事" }
```

响应 `data`：

```json
{
  "order_id": "10025",
  "fulfillment": "CANCELLED",
  "payment_state": "REFUNDING",
  "refund_fen": 3580,
  "refund_no": "RK2026101219300001",
  "customer_message": "取消成功后将发起原路退款，请留意退款进度。"
}
```

- 未开始配送才允许；COD 未收 / 微信未支付 → 直接 `CANCELLED`，不产生退款。
- 微信已付款 → `REFUNDING`，应退 = `总已实付 − 总已成功退款`，**不得超额**。

## 3. 商家端 API

| 方法 | 接口 | 作用 | 约束 |
|---|---|---|---|
| `POST` | `/api/v1/merchant/auth/login` | 登录 | 限速、设备标识、短令牌 |
| `POST` | `/api/v1/merchant/auth/logout` | 登出及令牌撤销 | 本人 |
| `GET` | `/api/v1/merchant/dashboard` | 工作台统计 | 商家身份 |
| `GET` | `/api/v1/merchant/orders` | 订单列表与筛选 | 分页、按需脱敏 |
| `GET` | `/api/v1/merchant/orders/{id}` | 订单详情 | 商家身份 |
| `POST` | `/api/v1/merchant/orders/{id}/dispatch` | 开始配送 | 版本锁、不可待补款 |
| `POST` | `/api/v1/merchant/orders/{id}/deliver` | 送达并按选择收款 | 事务 + 幂等 |
| `POST` | `/api/v1/merchant/orders/{id}/cod-receipt` | 仅异常补记 COD 收款 | 仅已送达未收款、审计 |
| `GET` | `/api/v1/merchant/orders/{id}/revisions` | 修改历史 | 只读 |
| `GET` | `/api/v1/merchant/products` | 商品列表 | 商家身份 |
| `POST` | `/api/v1/merchant/products` | 新增商品 | 校验图片/SKU |
| `PATCH` | `/api/v1/merchant/products/{id}` | 商品价格库存上下架 | 审计 |
| `POST` | `/api/v1/merchant/uploads` | 上传图片 | 限类型/大小/权限 |
| `GET` | `/api/v1/merchant/reconciliation` | 收款报表 | 商家身份 |
| `GET` / `PATCH` | `/api/v1/merchant/store` | 读取/更新营业与基础设置 | 商家身份 |
| `GET` | `/api/v1/merchant/notifications` | 待办提醒 | 仅本店 |

### 3.1 商家确认送达（合并收款）

```json
{
  "order_version": 4,
  "received_money": true,
  "collection_method": "CASH",
  "collected_fen": 3580,
  "client_request_id": "uuid-from-client"
}
```

- **仅送达未收款**：`"received_money": false`，**不要提供收款金额**；服务器不写 `COD_RECEIPT` 流水。
- `collection_method` ∈ `CASH` | `OFFLINE_QR` | `OTHER`，仅 `received_money=true` 时必填。
- 在线已付款订单送达时 `received_money` 必须为 `false` 或不传。
- 响应：

```json
{
  "order_id": "10025",
  "order_version": 5,
  "fulfillment": "DELIVERED",
  "payment_state": "PAID",
  "receivable_fen": 0,
  "ledger_id": 3012,
  "delivered_at": "2026-10-12T18:40:11+08:00"
}
```

未收款时返回 `"payment_state": "UNPAID", "receivable_fen": 3580, "ledger_id": null`。

### 3.2 开始配送

```json
{ "order_version": 4, "client_request_id": "uuid-from-client" }
```

- 前置校验：`fulfillment=PENDING`、无 `PENDING_TOPUP` 修订、非取消。
- 存在未结补款 → `409 TOPUP_REQUIRED`，提示"顾客有待支付的订单变更，暂不能配送"。
- 响应：`fulfillment=DELIVERING`、`version+1`、`locked_customer_editing=true`。

### 3.3 仅补记 COD 收款（纠错入口）

```json
{
  "order_version": 5,
  "collection_method": "OFFLINE_QR",
  "collected_fen": 3580,
  "note": "顾客微信转账",
  "client_request_id": "uuid-from-client"
}
```

- 仅允许 `fulfillment=DELIVERED` 且 `receivable_fen > 0`。
- 已收 → `409 COD_ALREADY_COLLECTED`。
- 写入流水 + 审计日志，不改变 `fulfillment`。

## 4. 商家工作台响应示例

```json
{
  "business_date": "2026-10-12",
  "store": { "name": "春连鲜选", "open": true, "delivery_fee_fen": 0 },
  "metrics": {
    "received_fen_today": 28650,
    "wechat_received_fen_today": 21000,
    "cod_received_fen_today": 7650,
    "pending_dispatch_count": 5,
    "delivering_count": 2,
    "receivable_count": 3,
    "receivable_fen": 10740,
    "refund_fen_today": 0,
    "order_count_today": 12
  },
  "recent_orders": [
    {
      "order_id": "10025",
      "order_no": "20261012193000001",
      "summary": "青菜×2 / 土豆×1",
      "fulfillment": "PENDING",
      "payment_state": "UNPAID",
      "payment_method": "COD",
      "total_fen": 3580,
      "delivery_mode": "asap",
      "expected_at": null,
      "revision_count": 0,
      "updated_by_customer_at": null
    }
  ]
}
```

**统计口径**：`今日实收 = 当天在线确认入账 + 当天 COD 实收 − 当天已成功退款`。跨日支付/退款按**资金发生日**归集，另提供"按订单日销售额"指标，不混算。

## 5. 对外托管接口（Webhook）

| 接口 | 作用 | 校验 |
|---|---|---|
| `POST /api/v1/webhook/wechatpay/notify` | 微信支付结果通知 | APIv3 签名验签、金额/商户号/订单号比对、防重放 |
| `POST /api/v1/webhook/wechatpay/refund-notify` | 微信退款结果通知 | 同上 |

处理原则：

- 验签失败 → 直接丢弃并记录告警，不做任何业务变更。
- 重复通知 → 幂等更新，`provider_trade_no` 唯一约束兜底。
- 回调处理**不在 HTTP 请求内做长耗时外部调用**；写 outbox 事件由 cron worker 处理查单与重试。

## 6. 分页约定

请求：`?page=1&page_size=20`（`page_size` 上限 100）。

响应：

```json
{
  "code": "OK",
  "data": {
    "list": [],
    "page": 1,
    "page_size": 20,
    "total": 128,
    "has_more": true
  },
  "request_id": "..."
}
```
