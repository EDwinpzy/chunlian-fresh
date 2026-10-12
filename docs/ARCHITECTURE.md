# 架构决策记录（ADR）｜春连鲜选

## ADR-001 顾客端使用 uni-app 编译微信小程序，不嵌入 web-view

- **状态**：已采纳
- **背景**：ShopXO 官方提供 uni-app 客户端源码，可编译到微信小程序。
- **决策**：以 `shopxo-uniapp` 为功能与 API 兼容底座，重做 UI 后**编译为微信小程序原生产物**。
- **后果**：禁止将 ShopXO H5 演示站放入 `web-view` 作为购物主流程；微信支付调用小程序支付能力，不在 `web-view` 内仿制收银台。
- **验收**：源码形式为 uni-app 源工程 + 编译输出；页面审计显示首页/购物车/支付/订单均由小程序页面实现。

## ADR-002 商家端使用 Kotlin + Jetpack Compose 原生工程

- **状态**：已采纳
- **背景**：店主端需拍照上架、通知、拨号、离线缓存等系统能力，且操作频次高。
- **决策**：新建独立 Android Studio Gradle 工程，业务界面全部由 `@Composable` 绘制。
- **后果**：不使用 uni-app App/H5/WebView；不携带 `admin.php` cookie、超级管理员口令或数据库账号，仅通过 HTTPS Merchant API 访问同源服务层。
- **验收**：`./gradlew assembleRelease` 可产出已签名 APK；WebView 检索为零（业务页面）。

## ADR-003 订单状态拆成四维，不使用单一布尔字段

- **状态**：已采纳
- **背景**："已送达""顾客确认收货""已付款"语义不同，合并会导致统计与资金错误。
- **决策**：`fulfillment` / `payment` / `receipt` / `revision` 四个独立维度，页面状态为展示映射。
- **后果**：COD 送达未收款可处于"已送达 + 未付款"；统计保留应收，不自动计入实收。
- **实现**：`backend-extension/src/Domain/*State.php` + `OrderStateMachine.php`。

## ADR-004 订单修改采用版本化 + 幂等 + 差价引擎

- **状态**：已采纳
- **背景**：已在线支付订单在配送前自由增删商品，引起差价补付、部分退款、库存调整与并发。
- **决策**：每次成功修订 `version + 1`，保存 `before_json`/`after_json` 快照；`delta_fen` 正数补款、负数退款；补款成功才原子提交。
- **定价**：旧有 SKU 维持原下单单价，新增 SKU 与增量数量按现价。
- **并发**：行锁 / 乐观锁 `WHERE version=? AND fulfillment='PENDING'`；失败方收到 `409 ORDER_VERSION_CONFLICT`。
- **实现**：`RevisionCalculator.php` + `veg_order_revision` 唯一约束。

## ADR-005 退款不在 MySQL 事务中等待第三方网络

- **状态**：已采纳
- **决策**：先保存退款意图 → 调用微信退款 API → 写 outbox → 由 callbak/查询更新最终结果。
- **后果**：退款受理后只显示"处理中"，不累计成功退款；失败进入 `REFUND_NEEDS_ATTENTION` 人工队列。
- **防超退**：可退 = `总已实付 − 总已成功退款 − 处理中退款`。

## ADR-006 COD 收款并入「确认送达」，默认不预选已收款

- **状态**：已采纳
- **背景**：避免一键误记账，同时保留"送达未收款"这一真实业务状态。
- **决策**：COD 弹窗三选一 —— 已收款并送达 / 仅送达未收款 / 返回；**默认不预选**。
- **后果**：不支持在同一动作中只改"已收款"却不送达；补记收款为独立次级入口，写审计。

## ADR-007 单价与金额一律用「分」的整数

- **状态**：已采纳
- **决策**：PHP 用 `int`，Kotlin 用 `Long`，TypeScript 用 `number`（安全整数范围内）；数据库用 `BIGINT`。
- **后果**：禁止 `float`/`double` 存人民币；界面层用 `NumberFormat` 格式化。

## ADR-008 单机模块化单体，不上微服务

- **状态**：已采纳
- **背景**：单店、低并发、一人运维。
- **决策**：一台服务器承载 Nginx + PHP-FPM + MySQL；扩展以插件/服务层形式挂载；异步用数据库 outbox + cron 单 worker。
- **后果**：不引入 Kubernetes、Redis 集群、消息队列集群、对象存储网关。
