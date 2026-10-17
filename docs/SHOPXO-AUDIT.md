# ShopXO 6.9.2 源码审计报告｜春连鲜选
> 历史源码审计：2026-10-12。本文的服务器和源码观察仅对应审计当日，不代表当前新业务已部署。生产 HTTPS 后续已切换到 8096 并验证；生产仍运行旧业务版本，新业务尚未部署。当前状态见 HANDOVER.md 与 docs/DEPLOYMENT.md。


> 审计对象：服务器 `/var/www/chunlian/shopxo`（官方 v6.9.2，20260928 release）
> 审计日期：2026-10-12
> 路径注记：本文中的 `backend-extension/` 按审计时的布局记录；当前仓库源码位于 `backend/extension/`，生产部署目录仍为 `/var/www/chunlian/backend-extension/`。
> 目的：为 `backend-extension` 确定**接入方式**、**数据映射**与**必须补的缺口**。
>
> 本文回答 `docs/ROADMAP.md` §5「源码审计必须先做的 12 件事」中的全部问题。

---

## 1. 结论摘要

| 问题 | 结论 |
|---|---|
| 扩展怎么挂载？ | **插件**。`app/plugins/{name}/` + `shopxo_plugins` 表登记 |
| 路由怎么注册？ | **不用手写路由**。插件走 catch-all：`/{插件名}-{控制器}-{方法}` |
| 订单金额什么类型？ | `decimal(10,2)` —— **不是整数分**，与我们的红线冲突，需适配层转换 |
| 订单状态什么模型？ | **单一维度 tinyint** —— 正是我们要避免的，绝不能作为履约真源 |
| 订单明细存 SKU 吗？ | **不存**。只有 `goods_id` + `spec`(JSON 文本) —— **这是必须补的缺口** |
| 有钩子可用吗？ | 有。`MyEventTrigger` + `plugins_service_*` 命名约定 |
| 能用 `extension_data` 存扩展吗？ | **不能**，已被商品扩展占用 |
| 服务器有 composer 吗？ | **没有 CLI**，无法 `dump-autoload`，需 `spl_autoload_register` 自举 |

---

## 2. 扩展机制：插件

### 2.1 目录结构

```
app/plugins/{plugins}/
├── index/{Control}.php     →  \app\plugins\{plugins}\index\{Control}
├── api/{Control}.php       →  \app\plugins\{plugins}\api\{Control}
├── admin/{Control}.php     →  \app\plugins\{plugins}\admin\{Control}
├── service/BaseService.php →  可选：声明配置字段
└── Event.php               →  \app\plugins\{plugins}\Event（生命周期回调）
```

### 2.2 路由分发（`app/route/route.config`）

```php
$ds = MyC('common_route_separator', '-', true);
Route::rule(':pluginsname'.$ds.'[:pluginscontrol]'.$ds.'[:pluginsaction]'.$ds.'[:id]','plugins/index')->completeMatch();
Route::rule(':pluginsname'.$ds.':pluginscontrol'.$ds.':pluginsaction'.$ds.'[:id]','plugins/index');
```

- **前台**：`/{插件名}-{控制器}-{方法}` → `app/index/controller/Plugins::Index()`
- **API**：`/api.php?s=/plugins/index&pluginsname={名}&pluginscontrol={控制器}&pluginsaction={方法}` → `app/api/controller/Plugins::Index()`
- 两者最终都走 `PluginsService::PluginsControlCall($plugins, $control, $action, $group, $params)`

**关键点**：`config/route.php` 里 `url_route_must = false`，所以**不需要为插件手写路由文件**。原 `backend-extension/config/routes.php` 中「需确定实际注册方式」的疑问到此解决。

### 2.3 插件发现与启用

`PluginsService::PluginsBaseList()` 从 **数据库**读取，不扫描文件系统：

```php
Db::name('Plugins')->where(['is_enable'=>1])->order(...)->field('id,plugins,data')->select()
```

`PluginsCheck()` 的判定链：
1. `PluginsField($plugins, 'is_enable')` 返回 `null` → 插件未安装（DB 无记录）
2. `is_enable != 1` → 已安装但未启用
3. 否则通过

因此**装插件 = 放文件 + 在 `shopxo_plugins` 插一行 `is_enable=1`**。

`shopxo_plugins` 表结构：

| 字段 | 类型 | 说明 |
|---|---|---|
| `id` | int unsigned PK | |
| `name` | char(60) | 显示名 |
| `plugins` | char(60) **UNIQUE** | 标识（= 目录名 = 路由前缀） |
| `plugins_category_id` | int unsigned | 分类（1管理增强/2营销活动/3其他工具/4扩展模块） |
| `plugins_menu_control` | char(120) | 后台菜单控制器 |
| `plugins_is_second_domain` | tinyint | 独立域名，默认 0 |
| `data` | longtext | 配置 JSON（`PluginsLegalCheck` 要求含 `base` 键） |
| `is_enable` | tinyint | **必须为 1** |
| `sort` / `add_time` / `upd_time` | int unsigned | |

### 2.4 生命周期事件（`Event.php`）

`PluginsService::PluginsEventCall()` 支持的方法名：

`Upload` / `BeginInstall` / `Install` / `Uninstall` / `Download` / `BeginUpgrade` / `Upgrade` / `Delete`

我们的插件用 `Install` 建 `veg_*` 表，`Uninstall` **保留数据**（只标记，不删表）。

### 2.5 安全校验

`PluginsLegalCheck()` 仅在 `RequestModule() == 'admin'` 时校验，会向 ShopXO 商店服务发请求验证授权。**私有插件走 API/前台时不受此限制**，因此商家端 API 不会因未在商店上架而被拦。

---

## 3. 钩子机制

调用形式：

```php
$hook_name = 'plugins_service_buy_order_detail_insert_begin';
$ret = EventReturnHandle(MyEventTrigger($hook_name, [
    'hook_name' => $hook_name,
    'is_backend' => true,
    'order_id'  => $order_id,
    'data'      => &$data,      // 引用，可改
    'params'    => $params,
]));
```

命名约定 `plugins_service_{模块}_{动作}_{时机}`。

已确认可用（本次审计实际定位到）的钩子：

| 钩子 | 位置 | 时机 | 对我们的用途 |
|---|---|---|---|
| `plugins_service_buy_order_detail_insert_begin` | `BuyService.php:1260` | 订单明细插入前，`&$data` 可改 | **捕获 SKU 身份**（见 §5） |

> 注：`&$data` 是引用，但 `shopxo_order_detail` **没有** SKU 列，往里塞新字段会导致 INSERT 失败。
> 正确做法是**只读 `$data`**，把结果写进我们自己的 `veg_*` 表。

---

## 4. 数据模型映射

### 4.1 `shopxo_order`（订单主表）

| 字段 | 类型 | 说明 |
|---|---|---|
| `id` / `order_no` | int / char(60) | 主键 / 业务单号 |
| `user_id` | int | 顾客 |
| `payment_id` | int | 支付方式 id（对应 `shopxo_payment`） |
| `status` | tinyint | **单一维度状态** |
| `pay_status` | tinyint | 支付状态 |
| `is_under_line` | tinyint | 线下支付标志（**COD 相关**） |
| `price` / `total_price` / `pay_price` / `refund_price` / `increase_price` / `preferential_price` | **decimal(10,2)** | 金额 |
| `pay_time` / `confirm_time` / `delivery_time` / `cancel_time` / `collect_time` / `close_time` | int | 生命周期时间戳 |
| `extension_data` | longtext | **已被商品扩展占用，不可挪用** |

**`status` 取值**（`app/lang/zh.php:1094`）：

| 值 | 含义 |
|---|---|
| 0 | 待确认 |
| 1 | 待付款 |
| 2 | 待发货 |
| 3 | 待收货 |
| 4 | 已完成 |
| 5 | 已取消 |
| 6 | 已关闭 |

> ⚠️ **`4 = 已完成` 把「已送达」与「已完成」合并了** —— 这正是本项目 ADR-003 要解决的问题。
> 若以 ShopXO `status` 作为配送阶段唯一来源，COD 未收款会被显示成「已完成」，直接漏账。
> **结论：`veg_order_delivery.order_status` 是唯一订单阶段真源，`shopxo_order.status` 仅作展示。**

### 4.2 `shopxo_order_detail`（订单明细）

字段：`order_id, goods_id, title, images, price, total_price, spec, buy_number, spec_desc, ...`

> ⚠️ **没有 `spec_base_id`（SKU id）**。`spec` 是 JSON 文本，仅描述规格组合。
> 详见 §5 —— 这是本项目改单引擎的**头号数据缺口**。

### 4.3 `shopxo_goods_spec_base`（真正的 SKU 表）

`id, goods_id, price, original_price, inventory, buy_min_number, buy_max_number, weight, volume, extends, ...`

**`id` 就是 SKU 主键**，我们的 `veg_*` 表中的 `sku_id` 应引用它。

### 4.4 `shopxo_pay_log`（支付流水）

`id, log_no, user_id, business_type, status, payment, payment_name, subject, total_price, pay_price, trade_no, buyer_user, pay_time, close_time`

- 金额仍是 `decimal(10,2)`
- `trade_no` = 第三方交易号（微信支付单号），对应我们 `veg_payment_ledger.provider_trade_no` 的唯一索引
- `shopxo_payment` 当前 **0 行**（尚未配置任何支付方式）

### 4.5 其他相关表

| 表 | 用途 |
|---|---|
| `shopxo_order_address` | 收货地址 |
| `shopxo_order_status_history` | 状态变更历史（可用于审计对账） |
| `shopxo_order_goods_inventory_log` | 库存变更日志 |
| `shopxo_order_delivery_sync_weixin` | 微信发货信息同步 |
| `shopxo_order_aftersale` | 售后 |

---

## 5. 必须补的缺口：SKU 身份丢失

### 5.1 问题

ShopXO 的下单链路里 **`spec_base_id` 是存在的**：

```php
// BuyService.php:143-147
$goods_base = GoodsService::GoodsSpecDetail($spec_params);
$goods['spec_base_id'] = $goods_base['data']['spec_base']['id'];
```

但构造订单明细时**被丢弃了**：

```php
// BuyService.php:861-865 —— detail_data 里没有 spec_base_id
$order['detail_data'][] = [
    'user_id'  => ...,
    'goods_id' => $vs['goods_id'],
    'spec'     => json_encode($vs['spec']),   // ← 只有规格文本
    'buy_number' => intval($vs['stock']),
    ...
];
```

结果：订单落库后，**无法从 `shopxo_order_detail` 稳定还原「这一行是哪个 SKU」**。

### 5.2 为什么这对本项目是致命的

改单引擎（ADR-004）的核心逻辑是**按 SKU 比对旧单与新单**，才能算出差价：

```
delta_fen = Σ(新单行金额) − Σ(旧单行金额)
```

定价规则还要求「已有 SKU 维持原订单单价，新增 SKU 按现价」——**这需要稳定的 SKU 身份**。

若只能靠 `spec` 文本反推：
- 商品改了规格模板 → 文本变了 → 反推失败
- 同一规格文本理论上可对应不同 `spec_base` → 歧义
- 差价算错 → 直接造成少收或多收钱

### 5.3 解决方案

用钩子在下单瞬间捕获 SKU 身份，落到自建表：

```php
// 插件钩子 plugins_service_buy_order_detail_insert_begin
$spec_base = \app\service\GoodsService::GoodsSpecDetail([
    'goods_id' => $data['goods_id'],
    'spec'     => json_decode($data['spec'], true),
    'stock'    => $data['buy_number'],
]);
$skuId = $spec_base['data']['spec_base']['id'];   // ← 稳定的 SKU 主键
```

`GoodsSpecDetail()` 正是 ShopXO 自己用来解析规格的方法（`BuyService.php:143`），
用它保证与官方口径**完全一致**。

新增表 `veg_order_line` 承载行快照（含 `sku_id` / `unit_fen` / `qty`），
`veg_*` 表之间无外键，与 ShopXO 原生表也不加外键 —— 保持扩展层独立可升级。

---

## 6. 接入设计

### 6.1 分层

```
ShopXO 运行时
    │
    ├── app/plugins/chunlianfresh/          ← 薄适配层（本次新建）
    │   ├── api/Merchant.php                   商家端 API（客户端调用）
    │   ├── index/Order.php                    前台入口（支付回调等）
    │   ├── service/BaseService.php            配置字段声明
    │   ├── Event.php                          安装建表 / 钩子注册
    │   └── Bootstrap.php                      PSR-4 自举（无 composer 依赖）
    │
    └── /var/www/chunlian/backend-extension/   ← 领域层（已有，不依赖 ShopXO）
        ├── src/Domain/                        纯领域，零框架依赖
        ├── src/Service/                       状态机、差价引擎、幂等
        └── src/Repository/                    持久化（本次实现）
```

**原则**：领域层不 import 任何 `app\` 命名空间，保证可单测、可替换。ShopXO 的耦合全部收敛在插件适配层。

### 6.2 无 composer 的自举

服务器 `vendor/bin` 只有 `pinyin` 和 `var-dump-server`，**没有 composer CLI**，无法 `dump-autoload`。
因此插件用 `Bootstrap.php` 注册 PSR-4：

```php
spl_autoload_register(function (string $class): void {
    $prefix = 'Chunlian\\';
    if (!str_starts_with($class, $prefix)) return;
    $rel  = substr($class, strlen($prefix));
    $file = BACKEND_EXTENSION_ROOT . '/src/' . str_replace('\\', '/', $rel) . '.php';
    if (is_file($file)) require_once $file;
});
```

### 6.3 金额转换

ShopXO 存 `decimal(10,2)`，我们存整数分。适配层边界处转换：

```php
// decimal → 分（四舍五入，避免 0.1+0.2 类误差）
function decimal_to_fen(string|float $v): int { return (int) round(((float) $v) * 100); }

// 分 → decimal（写回 ShopXO 时）
function fen_to_decimal(int $fen): string { return number_format($fen / 100, 2, '.', ''); }
```

**约束**：转换只允许发生在插件适配层，领域层内部一律整数分。

---

## 7. 对既有代码的影响

| 文件 | 处理 |
|---|---|
| `backend-extension/config/routes.php` | 头部注释「需审计后确定注册方式」→ 更新为审计结论（插件 catch-all） |
| `backend-extension/src/Repository/OrderRepository.php` | 桩 → 真实实现（本次） |
| `backend-extension/src/Http/Controller/Merchant/OrderController.php` | 参考实现 → 保留，作为插件控制器的调用目标 |
| 新增 `veg_order_line` 表 | 补 SKU 身份缺口 |
| 新增 `app/plugins/chunlianfresh/` | 插件适配层 |

---

## 8. 未决与风险

| 项 | 说明 |
|---|---|
| `shopxo_payment` 为空 | 未配置任何支付方式；COD 需在后台开启线下支付，或由插件接管 |
| 钩子 `&$data` 的字段集 | 实测确认 `$data` 在钩子点**不含** `spec_base_id`，故采用 §5.3 的重新解析方案 |
| 库存回退 | `shopxo_order_goods_inventory_log` 记录变更，但改单的库存差额逻辑需我方自行保证一致性（`RevisionCalculator` 已实现差额计算） |
| 插件商店校验 | 仅 admin 模块触发；商家 API 不受影响。但若日后要在后台插件列表操作，需保证 `data.base` 字段完整 |
| ShopXO 升级 | `veg_*` 表与原生表无外键，插件目录独立，升级 ShopXO 只需确认钩子名未变 |

---

## 9. 审计方法（可复现）

```bash
# 扩展机制
ls /var/www/chunlian/shopxo/app/plugins/
cat /var/www/chunlian/shopxo/app/route/route.config
sed -n '394,470p' /var/www/chunlian/shopxo/app/service/PluginsService.php

# 插件登记表
mysql -e "DESC shopxo_plugins;" chunlian_shop

# 订单模型
mysql -e "SELECT column_name,column_type FROM information_schema.columns
          WHERE table_schema='chunlian_shop' AND table_name='shopxo_order';" 
mysql -e "SELECT column_name,column_type FROM information_schema.columns
          WHERE table_schema='chunlian_shop' AND table_name='shopxo_order_detail';"

# 状态枚举
sed -n '1094,1102p' /var/www/chunlian/shopxo/app/lang/zh.php

# SKU 缺口证据
sed -n '858,880p' /var/www/chunlian/shopxo/app/service/BuyService.php   # detail_data 无 spec_base_id
sed -n '1260,1276p' /var/www/chunlian/shopxo/app/service/BuyService.php # 钩子点
grep -n 'spec_base_id' /var/www/chunlian/shopxo/app/service/BuyService.php
```
