# 部署与恢复｜春连鲜选

## 1. 当前生产

2026-10-15 已发布 `chunlian-20261015-shop-config-v1`：店铺资料、展示文案和生鲜分类由店家 App 配置，网页与顾客 App 读取统一配置。保留既有商家公开免登录设置。恢复点为 `/opt/chunlian/backups/chunlian-fresh/20261015T101121Z/`；发布健康检查和订单/流水数量恢复核验通过。

2026-10-16 已发布 `chunlian-20261016-customer-ui-v1`：顾客网页和顾客 App 更新订单与个人中心界面，订单搜索自动触发；顾客 App 升级至 1.0.4（versionCode 5）。店主 App 1.0.4（versionCode 5）沿用已发布包。下载清单、网页资源及顾客/店主 APK 均经线上 HTTPS 复核；两份 APK 的完整下载大小与清单 SHA-256 一致。

2026-10-17 已发布 `chunlian-20261017-all-clients-v1`：顾客网页和后端同步更新，顾客 App 与店主 App 均升级至 1.0.5（versionCode 6）。店主分类管理按一级分类展示商品数、状态筛选、排序和下架提示；分类状态文案统一为“已下架”。部署备份恢复检查通过，公网网页、静态资源和只读 API 检查 28/28 通过；两款 APK、下载清单与下载页均经项目 CA 的 HTTPS 完整下载复核，大小和 SHA-256 一致。备份点为 `/opt/chunlian/backups/chunlian-fresh/20261016T202653Z/`；服务与 APK 回退文件位于 `/opt/chunlian/releases/chunlian-20261017-all-clients-v1/rollback/`。

2026-10-17 已发布 `chunlian-20261017-farm-products-v1`：店主端新增 13 类、150 种农产品预设及配图，分类和商品资料均可修改；新商品默认下架，店主确认名称、价格和库存后再上架。顾客网页与 App 展示已配置的分类图，商品图在店主导入预设并保存商品后由顾客端读取。预设随 App 提供，未自动写入生产商品/分类数据。顾客与店主 Android 均升级至 1.0.6（versionCode 7）。网页构建、Playwright 70/70、顾客 Android 21/21、店主 Android 26/26、APK 核验 29/29、公网只读 28/28、下载页和 APK 公网完整校验 15/15 通过；商家 Lint 有 71 条 warning、0 error。服务包 193 个载荷文件，SHA-256 `5b470b589d0b3035ba8d4b80987d8e3da2fab824812630356fef2097bbf90a23`。备份恢复核验通过，备份点 `/opt/chunlian/backups/chunlian-fresh/20261017T022019Z/`；代码和旧下载文件回退目录 `/opt/chunlian/releases/chunlian-20261017-farm-products-v1/rollback/`。

| 项目 | 当前状态 |
|---|---|
| 主机 | panshi-cloud，103.236.97.137，共享服务器 |
| 项目路径 | /var/www/chunlian |
| 网页 | https://103.236.97.137:8096/app/ |
| Android 下载 | https://103.236.97.137:8096/downloads/ ；顾客1.0.6/code7，店主1.0.6/code7 |
| API | https://103.236.97.137:8096/api/v1/ |
| 网络 | NAT 8096；私有CA；Android内置限定IP信任，网页需安装公开CA |
| 服务 | nginx、php8.3-fpm active，维护标记已解除 |
| 商家管理 | `CHUNLIAN_MERCHANT_OPEN_ACCESS=1`；任何安装者都可取得完整商家管理会话 |
| 新结构 | payment_qr_url、veg_notification_seen、veg_shop_profile、veg_fresh_category_root、veg_fresh_category_state 已存在 |
| 资金方式 | OFFLINE_QR人工扫码核款/补款/退款和COD，无微信商户API |
| 正式二维码 | 空；店主在App上传真实收款码 |
| 容量快照 | 本次发布前后约18GiB可用 |

## 2. 本次验证

店铺配置发布：服务包189文件，SHA-256 `9a215eb227fb68c7ba4acd8fa73ae5bcdc4db8551f8b17555cd600dea153c678`；独立 MySQL 迁移及重复执行通过，配置服务隔离检查37断言、网页配置回归12/12，公网严格CA只读28/28，生产首页截图通过。原有订单、修订、资金流水及店铺设置指纹一致；未写入测试业务数据。新增分类完成一次性旧分类兼容登记（6个根类）。

备份已下载到受控 `dist/private/backup-20261015-shop-config/`，数据库、代码配置、上传文件的完整 SHA-256 均核对一致。真实 MySQL 分类并发锁回归尚未补充，本次验证了迁移及生产只读分类接口。

两款正式 APK 沿用原签名，单测16+15项、包体25项通过；公网重新下载核对完整SHA一致，下载页截图通过。下载文件位于 `shopxo/public/downloads/`，独立于网页切换目录。

农产品预设发布验证：顾客 Web `npm run build` 与 Playwright 70/70 通过；顾客 Android Release 单测 21/21、店主 Android Release 单测 26/26，`lintRelease` 与 `assembleRelease` 均通过；店主 Lint 报告 71 warnings、0 errors。APK 的包名、签名、生产 HTTPS 地址、版本与清单核验 29/29；公网清单、顾客/店主 APK 完整下载大小与 SHA-256、下载页及其 6 张图片核验 15/15；生产网页、静态资源、API 与匿名权限只读检查 28/28。远端 dry-check 对 193 个包文件校验，并对 PHP 源码执行语法检查。生产订单/资金数据计数恢复核验通过，未写入预设商品或分类。

本机未安装 PHP/Composer，且生产 PHP 未安装 PDO SQLite，因此未执行后端 PHPUnit/SQLite 隔离测试；分类图片创建、编辑和清除的数据库流程没有在生产业务数据上做写入测试。

以下为历史验证记录：

后端165项/490条断言、自检46/46；隔离业务144/144、边界并发192/192、通知竞态18/18；网页mock20/20、真实隔离流程2/2；顾客安卓Debug/Release各13项、店主各8项单测，lint及正式构建通过；APK核验25/25、生产只读18/18。

2026-10-15 免登录增量：PHP 包语法检查与策略 10 项断言通过；商家 API 拒绝缺少设备标识，开放会话可访问工作台，注销后会话失效；店主 Android Debug/Release 单测各14/14，Release lint/签名构建通过，APK核验25/25；emulator-5558 冷启动直达工作台，设置页显示公开免登录模式；生产只读复验18/18。未写入订单、商品、资金或收款码。

首次 apply 因 512 MiB 空间守卫拒绝且未切换；核对缓存引用后清理超过12小时且无活跃引用的 Docker 构建缓存约538.3MB，重试成功。没有删除运行镜像、容器、数据卷、数据库或 MyTV 发布/回滚资产。生产只执行 GET 验证、免登录会话烟测及获准的代码发布，没有订单、资金或商品测试写入。

服务包184载荷文件，SHA-256 `8fcd35b8104d316d495032901c420cdd733d0e1ca831685e620525d955edf63a`；远端 dry-check 和发布健康检查通过。公开网页/资产/API 从公网严格校验证书和 IP，18/18 通过；订单与资金流水计数恢复核验通过。

## 3. 备份与文件回滚

本次恢复点：`/opt/chunlian/backups/chunlian-fresh/20261015T101121Z/`。发布脚本恢复检查核对订单和流水数量；完整逐字段恢复演练未在本次重复执行。2026-10-14 与 2026-10-13 恢复点仍保留。

文件回滚目录：`/opt/chunlian/releases/chunlian-20261015-shop-config-v1/rollback/`。该目录含代码、nginx 和应用配置；PHP-FPM 池配置本次未改变。先只读检查：

```text
python3 /opt/chunlian/rollback-existing.py --release chunlian-20261015-shop-config-v1
```

维护者获准恢复后才加 `--apply`。恢复期间暂停请求并保留被替换版本；失败保持维护状态。该脚本不恢复数据库，保留兼容新增二维码列、通知与店铺配置表和发布后订单。切回旧通知代码可能重复提醒，但不会因此删除事件或资金记录。

禁止直接拿旧数据库备份覆盖发布后的订单。数据库恢复须另行审核数据范围、维护窗口和业务损失。

## 4. 后续发布流程

1. 更新源码后在隔离QA验证业务、边界、并发、客户端和包体；记录脚本及源文件SHA。
2. 重新核对磁盘，准备备份及恢复演练空间；生产不构建客户端。
3. 本机 `python infrastructure/scripts/build-delivery.py --release <版本>` 构建确定性包，上传已审查的部署、备份和回滚脚本。
4. 先运行release-existing.py默认dry-check，通过后在获准窗口加 `--apply`；脚本维护门禁、排空旧请求、备份恢复核验，再执行新增结构和文件切换。
5. `python infrastructure/scripts/production-readonly.py` 公网只读复验，核对业务指纹和服务状态；下载受限备份并核SHA。

### Android App 在线更新

顾客端已签名构建为 1.0.6（versionCode 7），店主端为 1.0.6（versionCode 7）。两端支持启动时检查更新和“我的”页手动检查；发现新版本后，用户可选择下载，App 校验文件大小与 SHA-256 后打开 Android 系统安装器，由用户确认安装。更新检查只在启动或手动触发时进行，不包含后台推送或静默安装。2026-10-16 已发布 `chunlian-20261016-category-products-v1`：店主商品管理按生鲜分类展示商品，并提供“全部 / 在售中 / 已下架”筛选；APK、清单和下载页已通过严格 TLS 公网完整性复核。随后发布 `chunlian-20261016-customer-ui-v1`，更新顾客网页和顾客 App。2026-10-17 发布 `chunlian-20261017-all-clients-v1`，顾客、店主 Android 同步升级至 1.0.5（code6），后端和顾客网页同步上线；APK、清单、下载页和网页资源均通过项目 CA HTTPS 与 SHA-256 复核。 2026-10-17 发布 `chunlian-20261017-farm-products-v1`，顾客与店主 Android 升至 1.0.6（code7），同步发布农产品预设、分类图片支持、顾客端分类图和新版下载页；线上完整性检查通过。

早期已安装版本若不具备更新检查能力，需先从现有 `/downloads/` 下载页手动安装 1.0.6 版本；1.0.6 起，顾客端和店主端均支持启动时检查及“我的”页手动检查更新。更新包必须沿用已发布 App 的签名证书，否则 Android 会拒绝覆盖安装。

发布 Android 更新时：

1. 顾客端和店主端分别执行 `assembleRelease -PSIGNING_PROPERTIES=<签名配置路径>`，使用同一项目签名证书；将签名产物更新到 `dist/release/customer-release.apk` 和 `dist/release/merchant-release.apk`。本地保留当前 APK 各一份；需要回退时，仅在 `dist/release/archive/previous-public/` 保留上一轮已发布的顾客端和店主端 APK 各一份。
2. 在 Windows Android SDK Build Tools 34.0.0 上运行 `python infrastructure/scripts/verify-delivery.py --build-tools <Build Tools目录> --release <发布标识> --release-notes <更新说明>`。通过签名、包名、版本和网络配置核验后，脚本生成引用固定名 APK 的 `dist/release/android-release.json` 和核验记录；不要额外生成带版本号的 APK 副本。
3. 将 `customer-release.apk`、`merchant-release.apk` 上传到 `/var/www/chunlian/shopxo/public/downloads/`，核对文件完整后更新 `download-index.html`，让首次安装入口显示并下载当前版本；最后再上传 `android-release.json`。下载页和 App 更新器都使用相同的固定文件名；清单必须包含 `application_id`、版本号、SHA-256 和文件大小。清单的公开 URL 为 `https://103.236.97.137:8096/downloads/android-release.json`；必须最后替换清单。新清单通过线上核验后，清除服务器上不再引用的版本号 APK；本地最多保留当前包和最近一次已发布的回退包。
4. 发布后分别检查清单、两个 APK 和 `download-index.html` 可通过 HTTPS 获取；核对 APK 的 SHA-256 与清单一致，并确认下载页的手动安装入口能下载新版本。

关闭 `CHUNLIAN_MERCHANT_OPEN_ACCESS` 可阻止新公开会话，但不会自动撤销已签发的30天会话；如需立即收回权限，必须同时撤销相关会话。后续清理outbox与seen标记须采用协调的保留策略；当前未启用自动清理或每日异地备份计划。

## 5. 文件权限与交付边界

服务器包文件0644、目录0755，root归属、时间归一；切换后的扩展代码、插件和网页root:www-data，文件0640/目录0750，服务只能读代码。上传和ShopXO运行目录保留必要写权限。相同源码和版本重复构包SHA一致。

源码ZIP排除所有层级docs/evidence、私钥/JKS/口令、本机配置、缓存和构建输出，保留QA脚本及明确标注不可付款的合成图片。公开CA可交付；签名JKS、CA/服务器私钥和生产备份仅保存在受控dist/private/。

共享磁盘余量有限，图片/日志/备份增长可能影响其他服务。任何清理先核对当前引用；不得删除其他项目发布和回滚资产。证书到期2027-10-13，需提前续签。
