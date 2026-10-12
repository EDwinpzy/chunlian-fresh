# 春连鲜选（Chunlian Fresh）

社区生鲜电商 —— 顾客微信小程序下单 + 商家原生 Android 接单收款，后端基于 **ShopXO 6.9.2** 扩展。

核心场景是**货到付款（COD）**与**配送前改单**这两件最容易出资金差错的事。

---

## 1. 这个项目解决什么问题

社区生鲜的日常是：顾客下单 → 商家备货 → 配送员上门 → 当面收款。

三个真实痛点，直接决定了本项目的架构：

| 痛点 | 后果 | 本项目的应对 |
|---|---|---|
| 「已送达」被当成「已完成」 | COD 未收到的钱被漏掉，月底对不上账 | 订单状态拆成**四维**，`已送达 + 未付款` 是一等公民，持续计入应收 |
| 忙碌中点「送达」顺手默认已收款 | 把没收到的钱记成已收 | 确认送达**强制三选一**，默认不预选 |
| 顾客改单后配送员照旧送货 | 少收差价 | 差价引擎 `delta_fen`，**补款成功前禁止开始配送** |

---

## 2. 五条不可妥协的设计红线

改动代码前请先读这一节。这五条是全项目一致性的基础，破坏任何一条都会直接造成资金差错。

### 红线 1：订单状态是四个独立维度，绝不合并

```
fulfillment : PENDING | DELIVERING | DELIVERED | CANCELLED
payment     : UNPAID | PAID | PARTIAL_REFUNDED | REFUNDED | REFUNDING
receipt     : UNCONFIRMED | CONFIRMED
revision    : NONE | PENDING_TOPUP | PENDING_REFUND | APPLIED
```

**禁止**把它们压成单个 `order_status` 字段或单一布尔值。

`已送达 + 未付款 + 未确认收货 + 无修订` 是**合法且必须能表达**的组合。任何把它显示成「已完成」的 UI 都是 bug。

### 红线 2：金额一律用整数「分」

| 端 | 类型 | 字段命名 |
|---|---|---|
| PHP | `int` | `*_fen` |
| Kotlin | `Long` | `*Fen` |
| JavaScript | `number`（安全整数内） | `*Fen` |
| MySQL | `BIGINT` | `*_fen` |

**禁止** `float` / `double` 存人民币。浮点误差在收款核对场景会直接变成对账差额。

### 红线 3：一个用户操作 = 一个幂等键

所有写操作带 `Idempotency-Key`（UUID v4）。

- 键在**用户操作开始时**生成，不是每次 HTTP 调用时
- 重试必须**复用同一个键**（上次请求可能已到达服务端并成功，只是响应丢了）
- 成功或确定性失败后**作废键**（下次点击是新操作）

客户端由 `SessionViewModel` 持有键（见 `merchant-android/.../ui/base/SessionViewModel.kt`）。

### 红线 4：乐观锁，冲突绝不静默重试

所有写操作带 `version`，SQL 形如 `WHERE version = ?`。

冲突返回 `409 ORDER_VERSION_CONFLICT`。客户端**必须**提示刷新，**不得**自动重放 —— 自动重放会覆盖他人的操作结果。

### 红线 5：COD 收款并入「确认送达」，默认不预选

确认送达时强制三选一：

1. 已收到货款并送达 → 写资金流水
2. 已送达但未收到货款 → 保持应收，进应收款列表
3. 返回

**默认一个都不选**。另有一条不变量：**在线已付款订单在送达时绝不能产生「收款」记录**（防重复入账）。

---

## 3. 架构总览

```
┌─────────────────────┐        ┌──────────────────────┐
│  顾客微信小程序      │        │  商家 Android 原生    │
│  uni-app → 编译产物  │        │  Kotlin + Compose    │
│  (非 web-view 套壳)  │        │  (非 WebView 套壳)    │
└──────────┬──────────┘        └───────────┬──────────┘
           │  HTTPS / JSON                  │  HTTPS / JSON
           └────────────┬──────────────────┘
                        ▼
        ┌───────────────────────────────────┐
        │   ShopXO 6.9.2 (ThinkPHP 8)       │
        │   ┌─────────────────────────────┐ │
        │   │  backend-extension 扩展层    │ │
        │   │  · 四维状态机                │ │
        │   │  · 差价 / 资金引擎           │ │
        │   │  · 幂等 + 乐观锁             │ │
        │   │  · 7 张 veg_* 独立表         │ │
        │   └─────────────────────────────┘ │
        └───────────────┬───────────────────┘
                        ▼
              MySQL 8  +  Nginx  +  PHP-FPM
```

**为什么扩展层用独立的 `veg_*` 表**：不侵入 ShopXO 原生表结构，升级 ShopXO 时扩展层不受影响。扩展表之间无外键，跨表一致性由服务层保证。

**为什么不上微服务**（ADR-008）：单店、低并发、一人运维。一台服务器 + 数据库 outbox + cron 单 worker 就够了。

---

## 4. 目录结构

```
chunlian-fresh/
├── backend-extension/          # ShopXO 扩展层（PHP 8.1+，MIT）
│   ├── src/
│   │   ├── Domain/             # 四维状态枚举、Money 值对象、订单聚合
│   │   ├── Service/            # OrderStateMachine / RevisionCalculator /
│   │   │                       # LedgerCalculator / IdempotencyService
│   │   ├── Repository/         # OrderRepository（待接入 ShopXO 持久化）
│   │   └── Http/Controller/    # Merchant API 控制器（参考实现）
│   ├── migrations/             # 7 张 veg_* 表 + 回滚脚本
│   ├── api-contracts/          # OpenAPI 3.0 契约
│   ├── config/                 # extension.php（环境变量驱动）/ routes.php
│   ├── scripts/                # health-check / migrate / outbox-worker
│   └── tests/                  # PHPUnit：115 测试 / 330 断言
│
├── customer-miniapp/           # 顾客端（uni-app → 微信小程序）
│   ├── src/pages/              # 11 个页面：首页/分类/详情/购物车/结算/
│   │                           # 订单/订单详情/改单/地址/我的
│   ├── src/components/         # 7 个 cl-* 组件
│   ├── src/api/                # 请求层（含错误归一化）
│   ├── src/store/              # Pinia：购物车 / 订单
│   ├── src/utils/money.js      # 整数分金额工具
│   ├── src/styles/tokens.scss  # 设计令牌（rpx）
│   └── scripts/                # 架构守卫 + 金额断言
│
├── merchant-android/           # 商家端（Kotlin + Compose 原生）
│   └── app/src/main/java/com/chunlian/vegetablemerchant/
│       ├── domain/             # 四维状态 + MerchantOrderStateMachine
│       ├── data/api/           # Retrofit 接口 + 拦截器（鉴权/幂等）
│       ├── data/repository/    # OrderRepository（envelope 解包 + 错误分类）
│       ├── data/local/         # EncryptedSharedPreferences 令牌存储
│       ├── ui/base/            # SessionViewModel（幂等键生命周期）
│       ├── ui/components/      # 状态标签、加载/错误/空态等公共件
│       ├── ui/screens/         # dashboard/orders/orderdetail/receivables/
│       │                       # revision/settings/login
│       ├── ui/theme/           # Color.kt / Type.kt / Theme.kt（dp·sp）
│       └── navigation/         # 路由表 + NavHost
│
├── infrastructure/             # 部署
│   ├── nginx/chunlian.conf     # 站点配置（含安全拒绝规则）
│   ├── scripts/deploy-backend.sh        # 一键幂等部署
│   ├── scripts/install-shopxo-cli.php   # 无网页向导安装 ShopXO
│   └── .env.example
│
├── docs/                       # 10 份设计/契约/部署文档
└── design-assets/              # 美术资源投放区（见 §8）
```

---

## 5. 环境要求

| 组件 | 版本 | 用途 |
|---|---|---|
| PHP | ≥ 8.1（生产用 8.3） | 后端 |
| MySQL | ≥ 5.7（生产用 8.0） | 数据库 |
| Node.js | ≥ 18 | 小程序构建 |
| JDK | 17 | Android 构建 |
| Android SDK | compileSdk 34 / minSdk 24 | Android 构建 |
| Composer | 2.x | 后端依赖 |

本机开发另需：微信开发者工具、HBuilderX（可选）、Android Studio（可选，命令行 Gradle 亦可）。

---

## 6. 快速开始

### 6.1 后端扩展层

```bash
cd backend-extension
composer install

# 跑测试（不需要数据库）
./vendor/bin/phpunit
# 期望：OK (115 tests, 330 assertions)

# 语法检查
composer lint

# 数据库迁移（先 dry-run！）
php scripts/migrate.php --dry-run
php scripts/migrate.php --apply

# 部署后健康检查
php scripts/health-check.php
```

配置全部通过环境变量注入，见 `backend-extension/config/extension.php`。
常用项：`KEEP_ORIGINAL_UNIT_PRICE`（默认 `true`）、`MIN_LEAD_MINUTES`（默认 30）、`IDEMPOTENCY_TTL`。

### 6.2 顾客端小程序

```bash
cd customer-miniapp
npm install

# 静态检查：确保没有 web-view 套壳、金额都是整数分
npm run verify
# 期望：30 个文件通过 + 34/34 金额断言通过

# 编译为微信小程序
npm run build:mp-weixin
# 产物：dist/build/mp-weixin —— 用微信开发者工具打开
```

`src/manifest.json` 里的 `mp-weixin.appid` 需替换为你自己的 AppID。

### 6.3 商家端 Android

```bash
cd merchant-android

# 指定 Android SDK 路径
echo "sdk.dir=/path/to/Android/Sdk" > local.properties

# 单元测试（29 个，覆盖状态机与资金规则）
./gradlew :app:testDebugUnitTest

# 打包
./gradlew :app:assembleDebug
# 产物：app/build/outputs/apk/debug/app-debug.apk

# 正式包（需自行配置签名）
./gradlew :app:assembleRelease
```

API 基址在 `app/build.gradle.kts` 的 `buildConfigField("String", "API_BASE_URL", ...)`，
debug / release 各一份，不硬编码在源码里。

> **注意**：`app/src/main/res/` 下当前使用的是**矢量占位图标**（Material Icons + 自绘自适应图标）。
> 美术资源包待替换，见 §8。

### 6.4 部署到服务器

```bash
cd infrastructure
cp .env.example .env      # 填入数据库、微信支付、密钥等
sudo bash scripts/deploy-backend.sh
```

脚本是**幂等**的，可重复执行。它会依次完成：系统依赖 → Nginx/PHP-FPM → 拉取 ShopXO → CLI 安装 → 建 `veg_*` 表 → 配置 cron（outbox worker / 支付对账 / 数据库备份）。

详细步骤与排障见 `docs/DEPLOYMENT.md`。

---

## 7. 文档索引

| 文档 | 内容 |
|---|---|
| [`docs/PRD.md`](docs/PRD.md) | 产品需求、角色、P0 功能清单、隐私要求 |
| [`docs/UI-SPEC.md`](docs/UI-SPEC.md) | 设计令牌、页面规格、全状态说明 |
| [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) | **8 条 ADR** —— 每个关键选型的背景与后果 |
| [`docs/API.md`](docs/API.md) | 接口契约、错误码、幂等与版本约定 |
| [`docs/DATA-MODEL.md`](docs/DATA-MODEL.md) | 表结构、字段语义、统计口径 |
| [`docs/TESTING.md`](docs/TESTING.md) | 测试策略与验收清单 |
| [`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md) | 部署手册 |
| [`docs/ROADMAP.md`](docs/ROADMAP.md) | 分阶段计划、里程碑、真实完成度声明 |
| [`docs/DEPLOYMENT-REPORT-2026-10-12.md`](docs/DEPLOYMENT-REPORT-2026-10-12.md) | 云服务器部署实录与遗留事项 |
| [`docs/NETWORK-DIAGNOSIS-2026-10-12.md`](docs/NETWORK-DIAGNOSIS-2026-10-12.md) | 服务器网络可达性专项诊断 |

---

## 8. 美术资源状态

`design-assets/` 是美术资源的**投放区**，当前已从仓库中排除（见 `.gitignore`）。

**当前状态：等待可用资源。** 已收到的资源包（第 10 套）经检查**不可直接用于生产**：

| 检查项 | 结果 |
|---|---|
| 是否干净抠图 | ✗ 小图标全部 100% 不透明，四角 alpha=255 —— 是展板截图裁切，带不透明底 |
| 是否含展板装饰 | ✗ 图内烧入了展板标题（如「01 应用主图标」「02 底部导航」）与图注（如「未选中」） |
| 图标是否完整 | ✗ 部分图标在画框边缘被裁断（首页图标右侧缺失、微信支付图标左侧缺失） |
| 是否有展板原图可重新裁切 | ✗ 包内最大图仅 310×322，无高分辨率原图 |

**结论**：需要**透明背景、内容居中、无图注**的干净导出，或提供展板原图以便重新裁切。

在拿到可用资源前，代码中使用的是矢量图标方案：

- Android：Material Icons（矢量）+ 自绘自适应启动图标（`res/drawable/ic_launcher_foreground.xml`）
- 小程序：`src/static/` 下的占位路径已按资源包命名预留，替换时直接覆盖同名文件即可

资源到位后的落位约定：

| 资源 | 目标位置 |
|---|---|
| 小程序 tabbar（81×81） | `customer-miniapp/src/static/tabs/` |
| 小程序功能图标（64×64） | `customer-miniapp/src/static/icons/` |
| Android 启动图标 | `merchant-android/app/src/main/res/mipmap-{m,h,xh,xxh,xxxh}dpi/` |
| Android 功能图标（24dp 基线） | `merchant-android/app/src/main/res/drawable-{m,h,xh,xxh,xxxh}dpi/` |

---

## 9. 当前完成度与已知限制

### 已完成

| 模块 | 状态 |
|---|---|
| `docs/` | 10 份文档 |
| `backend-extension/` | 23 个源类；**115 测试 / 330 断言全通过**；7 张 `veg_*` 表迁移 + 回滚；OpenAPI 3.0 |
| `customer-miniapp/` | 11 页面 / 7 组件；架构守卫通过（无 web-view 套壳）；**34/34 金额断言通过** |
| `merchant-android/` | 37 个 Kotlin 文件；**29 测试全通过**；`assembleDebug` 产出 APK |
| `infrastructure/` | 一键幂等部署脚本、ShopXO CLI 安装器、Nginx 配置、环境变量模板 |
| 云服务器 | ShopXO 6.9.2 已装（262 条 SQL / 91 张表），站点名称与 SEO 已配置，公网可访问 |

### 已知限制

| 项 | 说明 |
|---|---|
| 扩展路由未注册进 ShopXO | `backend-extension/config/routes.php` 头部已标注：需先完成 ShopXO 6.9.2 源码审计才能确定注册方式 |
| `OrderRepository` 持久化层是桩 | 需用审计后的真实 Repository 替换 `src/Repository/OrderRepository.php` |
| HTTPS 未配置 | 需域名 + certbot；当前仅 HTTP |
| ICP 备案未办理 | 中国大陆 80/443 合规运营的前置条件 |
| 微信支付未接通 | 需正式商户号、证书与密钥 |
| 未做真机验收 | 需微信开发者工具 + AppID、以及 Android 真机 |
| 美术资源待替换 | 见 §8 |

---

## 10. 许可

MIT。见 [`LICENSE`](LICENSE)。

> **注意**：MIT 覆盖本仓库自有代码。ShopXO 核心为 MIT，但其**部分插件、素材与第三方图片的商用许可需单独核对**（见 `docs/ROADMAP.md` §5.12）。
