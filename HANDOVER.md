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

## 三、部署核查与测试版（2026-10-12 第二轮）

### 后端：已部署并验证通过 ✅

实际状态比本文档上一版记录的更完整（「nginx 未同步」「迁移未执行」两条已过时）：

| 项 | 状态 |
|---|---|
| nginx `chunlian.conf` 的 merchant/customer 拆分 | ✅ 已生效 |
| 插件 `Merchant.php` + `Customer.php`、领域层三个新 Service | ✅ 已部署 |
| 迁移 `veg_notification_read` | ✅ 已执行（actor_id 主键正确） |
| 公网 `http://103.236.97.137:8095/` | ✅ 可访问（HTTP 200，端口映射已做） |
| 路由 + 鉴权自测 | ✅ `/api/v1/merchant/goods` → FORBIDDEN；`/api/v1/customer/orders/1/revisions` → FORBIDDEN |
| **订单修订 E2E** | ✅ **32/32 通过**（见下） |

E2E 跑法（脚本已上传到服务器 `/root/`）：

```bash
cd /var/www/chunlian && php /root/api-e2e-revision.php
```

覆盖：补款前不切明细、已有 SKU 维持原价、待补款禁止配送、
补款后切明细并重算总额、退款方向立即生效、订单行快照重建、修订历史。
脚本自清理测试数据。

### 小程序测试版：已完成的部分

- `src/manifest.json` 写入真实 appid `wxddab1d4233fb0e96`，`urlCheck: false`
- `src/config.js` 指向 `http://103.236.97.137:8095`，新增 `nativeApiPrefix`
- `src/api/request.js` 支持 ShopXO 原生接口入口（**参数必须用 `&` 拼接**：
  `s=/goods/detail&id=1` 正确，`s=/goods/detail?id=1` 会被整段吞进路由名）
- `src/api/index.js` 改单接口补 `customer/` 前缀
- 构建通过：11 个页面 / 4 个 tab（首页·买菜·购物车·我的）/ 图标齐全；
  架构守卫 30 文件通过、金额断言 34/34 通过

### 阻塞项（需要你操作）

**① 上传体验版：开发者工具的「服务端口」是关闭的**

CLI 无法调用（报 `IDE service port disabled`）。二选一：

- 工具 → 设置 → 安全设置 → 打开「服务端口」，之后我可以用
  `/c/应用/微信web开发者工具/cli.bat upload` 命令行上传；
- 或者你自己在工具里导入 `customer-miniapp/dist/build/mp-weixin` 并点「上传」。

**② 微信登录未配置**

服务器上 `shopxo_config.common_app_mini_weixin_appid` 与
`common_app_mini_weixin_appsecret` **都是空的**。小程序登录
（`User::AppMiniUserAuth`）因此不可用 —— 而登录是购物车/订单/地址的前置。
需要把小程序的 AppSecret 填进 ShopXO 后台（建议在后台填，不要贴到聊天里）。

### 关于「微信体验版能不能连这个后端」

微信官方规定（developers.weixin.qq.com 网络章节）：

- 域名只支持 `https`；**不能用 IP 地址或 localhost**；**必须 ICP 备案**；
- **端口可以自定义**（如 `https://域名:8095`）→ 现有 nginx 监听 8095 无需改；
- 例外：**开发者工具内**、以及**手机开启「调试模式」时**不校验域名。

所以：**后端本身没问题**，测试阶段（工具 / 手机调试模式）可用；
但**正式版与默认的体验版连不上**，必须先有 HTTPS 域名 + 备案。

### 还没做的大件：小程序 api 层

实测 `/api/v1/{home,categories,products,cart/preview,addresses,orders,auth/login}`
**全部 404** —— 11 个页面里只有「改单」有后端。

按项目既有设计（`Customer.php` 注释明确「顾客端其余功能全部走 ShopXO 原生接口」），
应把 `src/api/index.js` 改接到 ShopXO 原生 API：

| 小程序函数 | ShopXO 原生路由 |
|---|---|
| `getHome` | `index/index` |
| `getCategories` | `goods/category` |
| `getProducts` | `search/index` |
| `getProductDetail` | `goods/detail&id=` + `goods/specdetail&id=` |
| `previewCart` | `cart/spec` / `cart/stock` |
| `getAddresses` 等 | `useraddress/index|detail|save|delete|setdefault` |
| `getOrders` / `getOrderDetail` | `order/index` / `order/detail&id=` |
| `cancelOrder` / `confirmReceipt` | `order/cancel` / `order/collect` |
| `login` | `user/appminiuserauth` |
| 改单相关 | **我们的插件** `/api/v1/customer/orders/{id}/revision*` |

### 其它待办

1. Android goods/insight 页面**真机联调与验收未做**（已编译通过，未上真机）。
2. 后端新增的 3 个 Service 没有单元测试覆盖。可在服务器上补跑 phpunit，
   或给 `CatalogService`（聚合字段重算、幂等上下架）、
   `MerchantInsightService`（实收/应收分列、退款只计 SUCCESS）、
   `RevisionService`（补款前不切明细）各写单测。
3. 微信支付商户号未配置 → 下单后的支付流程无法跑通
   （部署报告 §七 步骤 3 已列出）。
4. `docs/API.md` 的复数/单数口径与实现不一致（见下节）。



## 四、文档口径不一致（小问题）

`docs/API.md` 写的是 `/api/v1/customer/orders/{id}/revisions`（复数），
而 `Customer.php` 与小程序都用 `revision`（单数）。实现与代码一致，
是文档需要对齐。改文档即可，不必改代码。

## 五、部署与环境要点

- 部署脚本：`infrastructure/scripts/deploy-backend.sh`、`install-plugin.php`
- 目标服务器为磐石云（root@103.236.97.137:55560，经 cloud-server-control 连接器），
  **出网受限**（GitHub/Docker Hub 超时，走 mirrors.aliyun/gitee）；
  服务器上有他人服务（selfhosted-* 容器、/opt/quiz），改动前先查
- 服务器凭据位置：`/root/.chunlian-db-cred`、`/root/.chunlian-admin-cred`、
  `/etc/chunlian/chunlian.env`（均 chmod 600）
- 本机（开发机）**没有 PHP**，后端改动无法本地 lint/测试 —— 但可以用
  cloud-server-control 连接器在服务器上跑 `php -l` / 实测接口，这比本地更可信
- Android 侧有 JDK 21 + Android SDK，可正常构建
- 微信开发者工具 CLI：`/c/应用/微信web开发者工具/cli.bat`
  （需先在工具里打开「服务端口」才能用）
