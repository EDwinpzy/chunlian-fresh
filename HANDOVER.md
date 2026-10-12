# 春连鲜（chunlian-fresh）交接文档

> 更新时间：2026-10-12 23:41
> 状态：**上一轮中断时的未提交变更已全部审查、修复并提交**（分支 main，3 个 commit）

## 一、项目概览

三端架构（仓库 `D:/MyProjects/chunlian-fresh`，分支 main）：

- **customer-miniapp** — 客户端微信小程序（uni-app），构建产物在 `dist/build/mp-weixin/`
- **merchant-android** — 商家端 Android（Kotlin + Jetpack Compose + Hilt）
- **backend-extension + infrastructure** — ShopXO 插件扩展（`infrastructure/shopxo-plugin/chunlianfresh/`）、nginx 配置、部署/E2E 脚本

## 二、本轮完成的事（2026-10-12）

上一轮中断时遗留的 28 项未提交变更已审查、修复并提交：

| commit | 内容 |
|---|---|
| `da423c3` | Android 商品管理页 + 经营洞察页 + 修正接口响应契约 |
| `b8f81dd` | 后端三个领域服务 + 通知游标迁移 + 顾客端 API（Customer.php） |
| `d170298` | nginx 按 merchant/customer 拆分路由（**修复**）+ 资源同步/E2E 脚本 + 小程序静态资源 |

### 审查中发现并修复的 4 个契约缺陷

这四个缺陷属于同一类：**客户端把响应的 `data` 反序列化为字段完整的 DTO，
而服务端只回了一个精简对象**。kotlinx.serialization 遇到缺失的必填字段会抛
`MissingFieldException`，被 `safeApiCall` 归类为「服务端返回了无法解析的数据」
—— 结果是**操作实际成功了，界面却提示失败**，店主会反复点击。

| 接口 | 服务端返回 | 客户端原声明 | 修复方式 |
|---|---|---|---|
| `POST goods/{id}/shelves` | `{goods_id, is_shelves}` | `GoodsDto` | **服务端**改为回商品详情（与改价接口一致） |
| `POST notifications/read` | `{last_event_id:int}` | `Map<String,String>` | 客户端新增 `MarkReadResponse` |
| `POST orders/{id}/commit-topup` | `{changed, revision_state}` | `OrderDto` | 客户端新增 `CommitTopupResponse` |
| `GET orders/{id}/revisions` | 精简 lineBrief 快照 | `OrderLineDto` | 客户端新增 `RevisionLineDto` |

另：删除 `InsightRepository` 中未被调用、且与顶层 `safeUnwrap` 重复的 `safeCall`。

### 关于 nginx 的改动（是修复，不是重构）

原配置 `location ^~ /api/v1/` 把整段路径塞进 `path=$1`。但 Android 端
baseUrl 是 `.../api/v1/`、Retrofit 路径是 `merchant/orders`，于是
`/api/v1/merchant/orders` 被解析成 `path=merchant/orders`，
插件 dispatch 里没有这个路由 → **商家端所有接口 404**。
拆成 `/api/v1/merchant/` 与 `/api/v1/customer/` 后前缀被吃掉，路由才匹配。

### 验证状态（本轮实际跑过）

| 项目 | 结果 |
|---|---|
| Android `compileDebugKotlin` | ✅ 通过 |
| Android 单元测试 | ✅ 34 个全部通过（0 失败 0 错误） |
| Android `assembleDebug` | ✅ `app-debug.apk`（20M） |
| 小程序 `check:pkg` 架构守卫 | ✅ 通过（扫描 30 个文件） |
| 小程序 `test:money` 金额断言 | ✅ 34/34 通过 |
| 后端 `php -l` / `phpunit` | ⚠️ **未跑**：本机无 PHP 环境 |

## 三、待办

### 必须（部署前）

1. 后端迁移 `2026_10_12_000003_create_veg_notification_read` 尚未在库上执行。
2. `infrastructure/nginx/chunlian.conf` 尚未同步到磐石云服务器。
3. 在服务器上跑 `infrastructure/scripts/api-e2e-revision.php`，验证改单全链路。

### 建议

4. Android goods/insight 页面**真机联调与验收未做**（已编译通过，但未上真机）。
5. 后端新增的 3 个 Service 没有单元测试覆盖。本机装 PHP 后应补：
   `CatalogService`（聚合字段重算、幂等上下架）、
   `MerchantInsightService`（实收/应收分列、退款只计 SUCCESS）、
   `RevisionService`（补款前不切明细）。
6. **小程序 API 前缀与后端路由不一致**（本轮审查发现，**未修**，见下节说明）。

## 四、已知问题：小程序 API 前缀（本轮未处理）

`customer-miniapp/src/api/request.js` 用 `${baseUrl}${apiPrefix}${url}` 拼 URL，
`apiPrefix = '/api/v1'`，而 `src/api/index.js` 里的路径是裸的（`/orders/...`），
因此实际请求是 `/api/v1/orders/{id}/revision`。

但 nginx 与 `Customer.php` 期望的是 `/api/v1/customer/orders/{id}/revision`。
此外小程序其余端点（`/home`、`/products`、`/cart/preview`、`/addresses`、
`/auth/login`）在 `docs/API.md` 里分属 `catalog/` 与 `customer/` 分组，
当前后端都还没有对应实现。

另有文档口径不一致：`docs/API.md` 写 `/customer/orders/{id}/revisions`（复数），
而 `Customer.php` 与小程序都用 `revision`（单数）。

**为什么不在这轮修**：该问题在本轮之前就已存在（旧 nginx 同样对不上，
裸路径会被路由到 merchant 控制器），并非本轮引入；且小程序尚未接真实后端
（`config.js` 里的 baseUrl 仍是占位域名），改动需要先确定
「小程序对齐后端实现」还是「后端对齐 docs/API.md」，属于设计决策。

## 五、部署与环境要点

- 部署脚本：`infrastructure/scripts/deploy-backend.sh`、`install-plugin.php`
- 目标服务器为磐石云（root@103.236.97.137:55560，经 cloud-server-control 连接器），
  **出网受限**（GitHub/Docker Hub 超时，走 mirrors.aliyun/gitee）；
  服务器上有他人服务（selfhosted-* 容器、/opt/quiz），改动前先查
- nginx 配置改动（chunlian.conf）尚未同步到服务器
- 本机（开发机）**没有 PHP**，后端改动无法本地 lint/测试；
  Android 侧有 JDK 21 + Android SDK，可正常构建
