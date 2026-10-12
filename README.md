# 春连鲜选（Chunlian Fresh）· 后端

社区生鲜电商的后端：基于 **ShopXO 6.9.2** 的扩展层，承载**货到付款（COD）**与
**配送前改单**这两件最容易出资金差错的事，并对外提供商家端 / 顾客端 API。

> **范围说明**：本仓库**只保留后端**。原来的顾客端微信小程序（uni-app）与商家端
> Android（Kotlin + Compose）已于 2026-10-13 移出，并**已从 git 历史中彻底清除**
> （历史被重写，所有提交哈希已变化）。
>
> 含前端的完整历史已离线归档为 bundle：
> `D:\MyProjects\_archive\chunlian-full-history-20261013.bundle`
> 需要时用它开一个旧仓库取回：
> ```bash
> git clone chunlian-full-history-20261013.bundle chunlian-old
> ```

---

## 1. 这个项目解决什么问题

社区生鲜的日常是：顾客下单 → 商家备货 → 配送员上门 → 当面收款。

三个真实痛点，直接决定了后端的数据模型：

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

`已送达 + 未付款 + 未确认收货 + 无修订` 是**合法且必须能表达**的组合。
任何把它当成「已完成」的逻辑都是 bug。

### 红线 2：金额一律用整数「分」

| 层 | 类型 | 字段命名 |
|---|---|---|
| PHP | `int` | `*_fen` |
| MySQL | `BIGINT` | `*_fen` |

**禁止** `float` / `double` 存人民币。浮点误差在收款核对场景会直接变成对账差额。
边界转换统一走 `OrderRowMapper::decimalToFen` / `fenToDecimal`（字符串解析，不用浮点）。

### 红线 3：一个用户操作 = 一个幂等键

所有写操作带 `Idempotency-Key`（UUID v4），落在 `veg_idempotency` 表。

- 键在**用户操作开始时**生成，不是每次 HTTP 调用时
- 重试必须**复用同一个键**（上次请求可能已到达服务端并成功，只是响应丢了）
- 成功或确定性失败后**作废键**（下次点击是新操作）

### 红线 4：乐观锁，冲突绝不静默重试

所有写操作带 `version`，SQL 形如 `WHERE version = ?`。

冲突返回 `ORDER_VERSION_CONFLICT`。调用方**必须**提示刷新，**不得**自动重放 ——
自动重放会覆盖他人的操作结果。

### 红线 5：COD 收款并入「确认送达」，默认不预选

确认送达时强制三选一：

1. 已收到货款并送达 → 写资金流水
2. 已送达但未收到货款 → 保持应收，进应收款列表
3. 返回

**默认一个都不选**。另有一条不变量：**在线已付款订单在送达时绝不能产生「收款」记录**
（防重复入账）。

---

## 3. 架构总览

```
        ┌──────────────────────────────┐
        │ 商家端 App  /  顾客端小程序    │   （前端已移出本仓库）
        └───────────────┬──────────────┘
                        │  HTTPS / JSON
                        ▼
        ┌───────────────────────────────────┐
        │   Nginx（/api/v1/ 按端拆分）        │
        └───────────────┬───────────────────┘
                        ▼
        ┌───────────────────────────────────┐
        │   ShopXO 6.9.2 (ThinkPHP 8)       │
        │   ┌─────────────────────────────┐ │
        │   │  chunlianfresh 插件          │ │
        │   │  · api/Merchant.php         │ │  ← 商家端 API
        │   │  · api/Customer.php         │ │  ← 顾客端改单 API
        │   │  · Bootstrap / Hook / Event │ │
        │   └──────────────┬──────────────┘ │
        └──────────────────┼────────────────┘
                           ▼
        ┌───────────────────────────────────┐
        │  backend-extension（零框架依赖）    │
        │  · 四维状态机 OrderStateMachine     │
        │  · 差价引擎 RevisionCalculator      │
        │  · 资金引擎 LedgerCalculator        │
        │  · 商品 / 经营洞察 / 修订 服务       │
        │  · 9 张 veg_* 独立表                │
        └──────────────┬────────────────────┘
                       ▼
              MySQL 8  +  Nginx  +  PHP-FPM
```

**为什么扩展层用独立的 `veg_*` 表**：不侵入 ShopXO 原生表结构，升级 ShopXO 时扩展层
不受影响。扩展表之间无外键，跨表一致性由服务层保证。

**为什么领域层不放进插件目录**：`backend-extension/src/` 是**零框架依赖**的纯领域层，
不 import 任何 `app\` 或 `think\` 命名空间，因此可以脱离 ShopXO 单独测试。
ShopXO 的耦合全部收敛在插件的控制器里。

**为什么不上微服务**（ADR-008）：单店、低并发、一人运维。
一台服务器 + 数据库 outbox + cron 单 worker 就够了。

---

## 4. 目录结构

```
chunlian-fresh/
├── backend-extension/          # ShopXO 扩展层（PHP 8.1+，MIT）
│   ├── src/
│   │   ├── Domain/             # 四维状态枚举、Money 值对象、订单聚合
│   │   ├── Service/            # OrderStateMachine / RevisionCalculator /
│   │   │                       # LedgerCalculator / IdempotencyService /
│   │   │                       # MerchantOrderService / CatalogService /
│   │   │                       # MerchantInsightService / RevisionService
│   │   ├── Repository/         # PDO 仓储、行映射、幂等存储
│   │   └── Http/Controller/    # Merchant API 控制器（参考实现）
│   ├── migrations/             # 9 张 veg_* 表（3 组迁移 + 回滚）
│   ├── api-contracts/          # OpenAPI 3.0 契约
│   ├── config/                 # extension.php（环境变量驱动）
│   ├── scripts/                # health-check / migrate / outbox-worker
│   └── tests/                  # PHPUnit：8 个测试文件
│
├── infrastructure/             # 部署
│   ├── nginx/chunlian.conf     # 站点配置（/api/v1/ 按 merchant/customer 拆分）
│   ├── shopxo-plugin/          # ShopXO 插件源码（版本管理主体）
│   │   └── chunlianfresh/
│   │       ├── Bootstrap.php   # PSR-4 自举（无 composer 依赖）+ PDO
│   │       ├── Event.php       # 安装/卸载生命周期
│   │       ├── Hook.php        # 订单行 SKU 身份捕获
│   │       ├── config.json     # 钩子声明
│   │       ├── api/Merchant.php    # 商家端 API（dispatch 内部路由）
│   │       ├── api/Customer.php    # 顾客端改单 API
│   │       └── service/BaseService.php
│   ├── scripts/
│   │   ├── deploy-backend.sh          # 一键幂等部署
│   │   ├── install-shopxo-cli.php     # 无网页向导安装 ShopXO（写 database.php）
│   │   ├── install-plugin.php         # 插件安装 + 钩子注册 + 建表
│   │   ├── api-e2e-test.php           # 商家 API 端到端测试
│   │   ├── api-e2e-revision.php       # 订单修订端到端测试（32 项）
│   │   ├── verify-sku-capture.php     # SKU 捕获验证
│   │   └── sync-assets.py             # 美术资源铺装（前端已移出，留作参考）
│   ├── backup/                 # 数据库备份脚本落位（尚未实现，见 §9）
│   └── .env.example
│
├── docs/                       # 设计 / 契约 / 部署文档
└── design-assets/              # 美术资源投放区（gitignore；前端已移出，留档）
```

---

## 5. 环境要求

| 组件 | 版本 | 用途 |
|---|---|---|
| PHP | ≥ 8.1（生产用 8.3） | 后端 |
| MySQL | ≥ 5.7（生产用 8.0） | 数据库 |
| Composer | 2.x | 后端依赖（仅开发/测试需要） |
| Nginx | — | 站点与 API 重写 |
| ShopXO | 6.9.2 | 宿主商城系统 |

> **生产服务器不需要 Composer**：插件的 `Bootstrap.php` 手写 `spl_autoload_register`
> 注册 `Chunlian\` 命名空间，因此不依赖 `vendor/`。

---

## 6. 快速开始

### 6.1 扩展层开发与测试

```bash
cd backend-extension
composer install

# 跑测试（不需要数据库）
./vendor/bin/phpunit

# 语法检查
composer lint

# 数据库迁移（先 dry-run）
php scripts/migrate.php --dry-run
php scripts/migrate.php --apply

# 部署后健康检查
php scripts/health-check.php
```

配置全部通过环境变量注入，见 `backend-extension/config/extension.php`。
常用项：`KEEP_ORIGINAL_UNIT_PRICE`（默认 `true`）、`MIN_LEAD_MINUTES`（默认 30）、
`IDEMPOTENCY_TTL`。

### 6.2 部署到服务器

```bash
cd infrastructure
cp .env.example .env      # 填入数据库、微信支付、密钥等
sudo bash scripts/deploy-backend.sh

# 安装 ShopXO（无网页向导，只写 config/database.php）
php scripts/install-shopxo-cli.php --write-config

# 安装插件（注册插件 + 注册钩子 + 建扩展表）
php scripts/install-plugin.php --check   # 先校验环境
php scripts/install-plugin.php --apply   # 再执行
```

两个脚本都是**幂等**的，可重复执行。

> **⚠️ 解包后必须修属主**，否则站点直接 500（www-data 写不了 `runtime/`）：
> ```bash
> chown -R www-data:www-data /var/www/chunlian/shopxo/runtime \
>                            /var/www/chunlian/shopxo/public/static/upload
> ```

> **为什么钩子注册要自己生成 `app/event.php`**
> ShopXO 的钩子部署只在**后台点击「应用管理」**时触发。
> 命令行安装必须做同一件事，否则钩子不生效 ——
> 而表现是「订单行快照没有写入」，直到改单功能算错钱才会被发现。

### 6.3 验证

```bash
# 商家端 API 端到端（登录/配送/送达收款/幂等/乐观锁/应收/汇总）
php scripts/api-e2e-test.php          # 期望 35/35 通过

# 订单修订端到端（补款前不切明细、原价维持、待补款禁止配送、退款方向）
php scripts/api-e2e-revision.php      # 期望 32/32 通过

# 订单行 SKU 身份捕获（改单引擎的前提）
php scripts/verify-sku-capture.php    # 期望 7/7 通过
```

详细步骤与排障见 `docs/DEPLOYMENT.md`。

---

## 7. 文档索引

| 文档 | 内容 |
|---|---|
| [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) | **ADR** —— 每个关键选型的背景与后果 |
| [`docs/DATA-MODEL.md`](docs/DATA-MODEL.md) | 表结构、字段语义、统计口径 |
| [`docs/API.md`](docs/API.md) | 接口契约、错误码、幂等与版本约定 |
| [`docs/SHOPXO-AUDIT.md`](docs/SHOPXO-AUDIT.md) | **ShopXO 6.9.2 源码审计** —— 扩展机制、钩子、数据映射、发现的缺口 |
| [`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md) | 部署手册 |
| [`docs/PRD.md`](docs/PRD.md) | 产品需求、角色、P0 功能清单、隐私要求 |
| [`docs/TESTING.md`](docs/TESTING.md) | 测试策略与验收清单 |
| [`docs/ROADMAP.md`](docs/ROADMAP.md) | 分阶段计划、里程碑、真实完成度声明 |
| [`docs/DEPLOYMENT-REPORT-2026-10-12.md`](docs/DEPLOYMENT-REPORT-2026-10-12.md) | 云服务器部署实录 |
| [`docs/NETWORK-DIAGNOSIS-2026-10-12.md`](docs/NETWORK-DIAGNOSIS-2026-10-12.md) | 服务器网络可达性专项诊断 |
| [`HANDOVER.md`](HANDOVER.md) | 交接文档：当前状态、凭据位置、运维要点 |

---

## 8. 美术资源

`design-assets/` 是美术资源的**投放区**，已从 git 中排除（见 `.gitignore`）。

前端移出仓库后，这里的资源暂时没有消费方，**保留作为留档**。
若将来恢复前端，落位约定如下：

| 资源 | 目标位置 |
|---|---|
| 小程序 tabbar（81×81） | `customer-miniapp/src/static/tabs/` |
| 小程序功能图标（64×64） | `customer-miniapp/src/static/images/features/` |
| Android 启动图标 | `merchant-android/app/src/main/res/mipmap-*dpi/` |
| Android 功能图标（24dp 基线） | `merchant-android/app/src/main/res/drawable-*dpi/` |

> 已收到的资源包（第 10 套）经检查**不可直接用于生产**：小图标全部 100% 不透明
> （是展板截图裁切，带不透明底）、图内烧入了展板标题与图注、部分图标在画框边缘
> 被裁断、且包内无高分辨率原图可重新裁切。
> 需要**透明背景、内容居中、无图注**的干净导出，或提供展板原图。

---

## 9. 当前完成度与已知限制

### 已完成（后端）

| 模块 | 状态 |
|---|---|
| `backend-extension/` | 47 个源文件；8 个 PHPUnit 测试文件；9 张 `veg_*` 表迁移 + 回滚；OpenAPI 3.0 |
| ShopXO 插件 | **已安装并在服务器上跑通**：路由注册、鉴权、四维状态、COD 收款、幂等、乐观锁、商品管理、经营洞察、订单修订 |
| 商家端 API | `/api/v1/merchant/*` 全部端点已实现并验证 |
| 顾客端 API | `/api/v1/customer/orders/{id}/revision*`（改单闭环）已实现并验证 |
| 云服务器 | 磐石云 `103.236.97.137:8095`，ShopXO + 插件已部署，公网可达 |
| 端到端验证 | 订单修订 **32/32 通过**；前端 `/` 与后台 `/admin.php` 均 200 |

### 已完成的关键修复（由审计与实测发现）

| 问题 | 性质 |
|---|---|
| ShopXO `shopxo_order_detail` **不保存 SKU 身份** | 改单引擎无法按 SKU 比对 → 差价会算错。已用钩子捕获到 `veg_order_line` 并验证 |
| `Order::refundable()` **未扣除处理中退款** | 与 ADR-005 及 `LedgerCalculator` 口径不一致 → 存在**超退风险**。已修复并加回归测试 |
| 地址表字段名假设错误 | 实际是 `tel` 而非 `phone`；省市区是**区域 ID**，名称在 `*_name` 字段 → 拼出错误地址。已修正 |
| 4 处接口响应体与调用方 DTO 不匹配 | 成功操作被判为「无法解析的数据」。已逐一对齐（`setShelves` 改回商品详情、新增 `MarkReadResponse` / `CommitTopupResponse` / `RevisionLineDto`） |

### 已知限制

| 项 | 说明 |
|---|---|
| 前端已移出仓库 | 小程序与 Android 代码**已从 git 历史清除**，完整历史归档在 `D:\MyProjects\_archive\chunlian-full-history-20261013.bundle` |
| 顾客端业务接口未实现 | 除改单外，首页/分类/商品/购物车/地址/订单等仍需走 ShopXO 原生 API（`/api.php?s=...`） |
| HTTPS 未配置 | 需域名 + certbot；当前仅 HTTP（微信体验版因此连不上） |
| ICP 备案未办理 | 中国大陆 80/443 合规运营的前置条件 |
| 微信支付未接通 | 需正式商户号、证书与密钥；COD 链路已完整可用 |
| **数据库备份未实现** | `docs/DEPLOYMENT.md` 引用了 `infrastructure/backup/backup_db.sh`，但该脚本**从未编写**。2026-10-13 的误删事故是靠 MySQL binlog 才恢复的，建议尽快补上定时 `mysqldump` |
| 新增 Service 无单元测试 | `CatalogService` / `MerchantInsightService` / `RevisionService` 未覆盖 |

> **`backend-extension/config/routes.php` 已过时**：审计确认 ShopXO 用插件 catch-all 路由，
> 不需要手写路由文件。实际路由见 `docs/SHOPXO-AUDIT.md` §2 与
> `infrastructure/nginx/chunlian.conf`。

---

## 10. 许可

MIT。见 [`LICENSE`](LICENSE)。

> **注意**：MIT 覆盖本仓库自有代码。ShopXO 核心为 MIT，但其**部分插件、素材与第三方图片的商用许可需单独核对**（见 `docs/ROADMAP.md` §5.12）。
