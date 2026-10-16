# 春连鲜选（Chunlian Fresh）

社区生鲜电商系统：顾客端（网页版 + 安卓原生 App）、商家端（安卓原生 App）与 ShopXO 扩展后端。

## 1. 项目范围

| 端 | 形态 | 目录 |
|---|---|---|
| 顾客网页版 | Vue 3 + Vite | apps/customer-web/ |
| 顾客 Android | Kotlin + Jetpack Compose 原生 App | apps/customer-android/ |
| 商家 Android | Kotlin + Jetpack Compose 原生 App | apps/merchant-android/ |
| 后端 | ShopXO 插件与领域服务 | backend/extension/、backend/shopxo-plugin/ |

ShopXO Web 后台保留作初始化和维护入口；店主日常改价、库存、订单履约、收款核实、退款核实、经营统计与只读资金流水查询在商家 Android App 内完成。顾客网页和 Android App 分别实现界面，共用后端 API 与业务规则。

## 2. 当前服务入口

| 入口 | 地址 |
|---|---|
| 顾客网页版 | https://103.236.97.137:8096/app/ |
| ShopXO 首页 | https://103.236.97.137:8096/ |
| ShopXO 后台 | https://103.236.97.137:8096/admin.php |
| API | https://103.236.97.137:8096/api/v1/ |

生产 HTTPS 已在 IP 的 8096 端口启用并验证，NAT 映射已创建，无需域名。TLS 使用项目私有 CA：两款 Android App 已内置并按精确 IP 信任该 CA，用户无需安装；网页用户须按 [使用说明.md](使用说明.md) 手动信任项目公开 CA。

## 3. 订单与资金关键约束

订单的履约、支付、顾客确认收货和修订分别维护：

- 履约 fulfillment：PENDING、DELIVERING、DELIVERED、FINISHED、CANCELLED。
- 支付 payment_state：UNPAID、PAYING、PAID、PARTIAL_REFUNDED、REFUNDING、REFUNDED。
- 收货 receipt_state：UNCONFIRMED、CONFIRMED。
- 修订 revision_state：NONE、PENDING_TOPUP、APPLYING、COMMITTED、FAILED。

- 已送达但未收款是有效状态。COD 实收仅在商家确认实际到账后登记；未收金额继续保留为应收。
- 扫码预付款和补款由商家核实实际到账后记录。退款只有在店主确认资金实际退回后才记录为成功；展示二维码、截图或口头反馈不自动证明收款。
- 顾客自主修改或取消的唯一截止点是服务端成功提交“开始配送”。
- API 金额使用整数分；禁止用浮点数累计资金。
- 涉及订单、库存或资金副作用的写操作必须防止重复执行，具体要求见 docs/API.md。

## 4. 支付方式

顾客可选择 OFFLINE_QR 线下扫码预付或 COD 货到付款。收款二维码由商家 App 配置。项目没有接入微信支付商户 API，不具备微信支付下单、回调或自动退款能力。

## 5. 目录

```text
apps/
  customer-web/
  customer-android/
  merchant-android/
backend/
  extension/
  shopxo-plugin/
assets/design/           原始美术资源
infrastructure/          Nginx、脚本、种子数据
docs/                    文档、测试证据、历史归档
dist/                    本机交付包与签名材料
```

根目录只放项目说明、许可证和通用配置文件。

## 6. 本地开发

顾客网页版位于 apps/customer-web/，安装依赖后运行 npm run dev。Android 工程位于 apps/ 下，按各自说明配置 API 地址，再通过 Gradle 构建。后端改动需要在适当的 PHP 环境中做语法与服务验证；是否可以访问服务器或执行部署须遵守 docs/DEPLOYMENT.md 中的操作范围。

## 7. 当前交付说明

2026-10-14 已发布 `chunlian-20261013-v1`，HTTPS、网页与API生产只读验收通过；二维码列与逐事件通知已读表已迁移。现有13订单/5流水的业务指纹不变。

后端165项/490条断言、自检46/46；隔离业务144/144、边界并发192/192、通知竞态18/18；网页mock20/20、真实隔离流程2/2；顾客安卓Debug/Release各13项、店主各8项单测，lint及正式构建通过；APK核验25/25、生产只读18/18。

- 安装包、公开CA和源码ZIP位于 dist/release/；使用方法见 [使用说明](使用说明.md)，详细验证见 [验收报告](docs/ACCEPTANCE-REPORT-2026-10-13.md)。
- 生产收款码为空，店主须在管理App上传实际收款码；二维码和付款状态由店主核实，不会自动收款或退款。
- 发布后磁盘快照约845MiB可用、98%使用率，仍需容量管理。
- 签名JKS、私钥、受限备份保存在Git忽略的dist/private/，不进入源码包。
- 验收使用模拟器、浏览器和隔离QA，未测试实体手机或真实资金；生产只读，不建立测试订单。仅有App内通知，无锁屏推送。
