# 春连鲜（chunlian-fresh）交接文档

> 更新时间：2026-10-13 00:30
> 状态：**仓库已收窄为「仅后端」**；服务器后端经历一次误删并已完整恢复

## 一、当前项目范围

2026-10-13 按用户要求「只清前端，留后端」，工作区已删除两个前端：

| 目录 | 状态 |
|---|---|
| `customer-miniapp/` | ❌ 已从工作区删除（133M） |
| `merchant-android/` | ❌ 已从工作区删除（191M） |
| `backend-extension/` | ✅ 保留 |
| `infrastructure/` | ✅ 保留（nginx 配置、插件源码、部署/校验脚本） |
| `docs/`、`design-assets/` | ✅ 保留 |

前端共 327 个文件**已从 git 历史中彻底清除**（见下方说明）。
含前端的完整历史已离线归档为 bundle，需要时可用它开一个旧仓库取回：

```bash
git clone D:\MyProjects\_archive\chunlian-full-history-20261013.bundle chunlian-old
```

项目体积：338M → **16M**（`.git` 2.1M → 434K）。

## 二、服务器后端（磐石云）

**当前状态：已恢复并验证通过。**

- 公网 `http://103.236.97.137:8095/` 可访问（HTTP 200）
- 后台 `http://103.236.97.137:8095/admin.php`
- 数据库 `chunlian_shop`：93 张表 / 28 商品 / 3 订单 / 9 张 `veg_*` 表
- 插件 `chunlianfresh` 已启用，钩子已注册到 `shopxo/app/event.php`
- outbox worker 由 `/etc/cron.d/chunlian` 每分钟调度，可正常运行

### 凭据（均在服务器，chmod 600）

| 文件 | 内容 |
|---|---|
| `/root/.chunlian-admin-cred` | `admin` / `Cl50f5b26581@26` / 入口 `admin.php` |
| `/root/.chunlian-db-cred` | `GENERATED_PASS=ByeKKvohBeHvOJRnqn1hRcnv` |
| `/etc/chunlian/chunlian.env` | DB、业务参数、`MERCHANT_API_SECRET` |

> ⚠️ **管理员密码是 2026-10-13 重置的**：原始明文随凭据文件一起丢失
> （散列仍在库里）。ShopXO 的算法是 `md5($salt . trim($pwd))`，
> 见 `shopxo/app/common.php` 的 `LoginPwdEncryption`。
> `MERCHANT_API_SECRET` 也是新生成的，旧令牌需重新登录。

### 验收记录

| 项 | 结果 |
|---|---|
| 订单修订 E2E（`php /root/api-e2e-revision.php`） | ✅ **32/32 通过** |
| 前端 `/` | ✅ 200 |
| 后台 `/admin.php` | ✅ 200 |
| 商家 API `/api/v1/merchant/goods` | ✅ 返回 `FORBIDDEN`（路由通、鉴权生效） |
| 顾客 API `/api/v1/customer/orders/1/revisions` | ✅ 返回 `FORBIDDEN` |
| ShopXO 原生 API `/api.php?s=/goods/category` | ✅ 返回分类数据 |
| 他人服务（10 个 `selfhosted-*` 容器、nginx、php-fpm） | ✅ 全程未受影响 |

## 三、一次误删与恢复的经过（重要经验）

**经过**：用户要求「清空这个项目，包括 GitHub 上的」。我列出风险后，用户确认
范围为「本地 + GitHub + 服务器」且「不用备份，直接删」，于是**我清空了服务器后端**。
随后用户说「云服务器上的后端留着」—— 但那时已经删完了。

**教训**：破坏性操作要**分步确认**。用户确认「范围」不等于确认「执行到哪一步」；
涉及线上数据时，应先做只读盘点、给出可回滚方案，再逐项执行。

**恢复是怎么做到的**：

1. **数据库**：MySQL 是专用实例（无其他库）且 `log_bin=ON`，
   binlog 覆盖了从建库到删库的全部历史。
   `mysqlbinlog --stop-position=834576`（DROP DATABASE 事件的位置）回放，
   完整恢复 93 张表 / 28 商品 / 3 订单 / 9 张 `veg_*` 表。
2. **代码**：本地仓库是权威源。服务器 `/opt/chunlian/` 里的压缩包是旧快照
   （缺 `CatalogService` / `MerchantInsightService` / `RevisionService`），
   所以用本地仓库重新打包上传，而不是用旧快照。
3. **系统配置**：nginx 站点、env、日志目录、cron + worker
   （worker 脚本从 `/opt/chunlian/run-outbox.sh` 恢复）、php-fpm drop-in、凭据。

**踩到的坑**：解包后站点 500 —— 文件属主是 root，www-data 写不了 `runtime/`。
`chown -R www-data:www-data shopxo/runtime shopxo/public/static/upload` 后恢复。

## 四、待办

### 已完成

1. **文档已按「仅后端」重写**（2026-10-13）：
   - 删除 `docs/UI-SPEC.md`、`docs/design/`（纯前端）
   - 重写 `README.md`、`docs/ARCHITECTURE.md`、`docs/TESTING.md`、`docs/ROADMAP.md`
   - 外科式清理 `docs/PRD.md`（移除 C01–C25 / A01–A25 两张前端功能清单）、
     `docs/DEPLOYMENT.md`（移除前端构建步骤与 UI/Android 发布门槛）、
     `docs/API.md`、`docs/SHOPXO-AUDIT.md`
   - `docs/DEPLOYMENT-REPORT` / `NETWORK-DIAGNOSIS` 顶部加「结论已过时」提示
   - 各文档均注明「前端已移出仓库，且已从 git 历史清除（归档于
     `_archive/chunlian-full-history-20261013.bundle`）」

2. **本地收窄已提交，且前端已从 git 历史彻底清除并推送**（见下方说明）。

### 说明：前端已从 git 历史彻底清除（已完成）

2026-10-13 用 `git filter-repo` 重写历史，把 `customer-miniapp` 与
`merchant-android` 从**所有提交**中移除，然后强制推送：

```bash
git filter-repo --path customer-miniapp --path merchant-android --invert-paths --force
git remote add origin git@github.com:EDwinpzy/chunlian-fresh.git   # filter-repo 会移除 origin
git push --force-with-lease=main:<推送前远端值> origin main
```

结果：

- 提交数 **10 → 8**（两个只改前端的提交因变空被剪掉）
- **所有提交哈希都变了**：原 `d75d6c2`…`ebea3ec` → 现 `1e828a5`…`3224a48`
- 远端 HEAD `3224a48`，本地与远端同步；远端无任何前端文件
- 本地 `.git` 2.1M → **434K**，`git rev-list --objects --all` 已查不到前端条目

⚠️ **GitHub 服务端可能仍暂留旧对象**，直到其内部 GC 执行 —— 短时间内
用旧哈希在网页上可能还能打开。要立即清掉需联系 GitHub 支持。

⚠️ 同时删除了两个 `refs/codex/turn-diffs/checkpoints/*` 过期检查点 ref
（它们仍指向改写前、含前端的对象）。**Codex 在本仓库的历史检查点因此失效。**

**含前端的完整历史已归档**：
`D:\MyProjects\_archive\chunlian-full-history-20261013.bundle`（1.8M，已 `git bundle verify`）
需要的话告诉我，我来执行。

### 仍需你操作

3. **小程序 AppID `wxddab1d4233fb0e96` 与备案域名**：如果要恢复小程序前端，
   需在微信公众平台配置服务器域名（HTTPS + 备案），当前后端只有
   `http://103.236.97.137:8095`，体验版连不上。

### 建议

4. `infrastructure/backup/` 是空目录，但 `docs/DEPLOYMENT.md` 引用了
   `backup_db.sh` —— 这个备份脚本**从未实现**。既然这次是靠 binlog 才救回来的，
   建议把它补上（定时 `mysqldump`）。
5. 后端三个新 Service（`CatalogService` / `MerchantInsightService` /
   `RevisionService`）没有单元测试覆盖。本机无 PHP，可在服务器上装 composer
   后跑 phpunit 补测。

## 五、环境与运维要点

- 本机**没有 PHP**，后端改动无法本地 lint/测试 —— 但可用 cloud-server-control
  连接器在服务器上实测，比本地 lint 更可信。
- 服务器出网受限（GitHub / Docker Hub 超时，走 mirrors.aliyun / gitee）。
- 服务器上还有他人服务（10 个 `selfhosted-*` 容器、`/opt/quiz`），改动前先查。
- 恢复素材都在 `/opt/chunlian/`：ShopXO 发行版 zip、backend-extension 与
  infrastructure 压缩包、`install-plugin.php`、`run-outbox.sh`、E2E 脚本。
- 重建流程：解包 → 放插件到 `shopxo/app/plugins/chunlianfresh` →
  `php infrastructure/scripts/install-shopxo-cli.php --write-config` →
  `php infrastructure/scripts/install-plugin.php --apply` → 修属主。
