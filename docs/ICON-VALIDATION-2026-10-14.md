# 三端美术素材尺寸与居中验收｜2026-10-14

## 2026-10-15 补充复核

- 重新目视检查最新 QA 构建的顾客 Android 首页、分类、购物车、订单和个人页，以及店主 Android 商品页和店铺设置页。可见素材在按钮/导航槽位内均完整、水平垂直居中，未发现倾斜、裁切、溢出或位置偏移；店主端“上架/下架”、搜索、筛选、导航和二维码设置区域均显示项目美术素材。
- 复核截图位于 `evidence/icon-visual-check-2026-10-14/`，本报告下方已改为最新 Android 页面截图链接。此前指向旧商品页的 `evidence/merchant-icon-final-products.png` 截图已过期，不再作为当前结论依据。
- Android 页面截图使用隔离 QA 数据；店铺设置截图中的二维码明确标注为 `QA ONLY`。
- 两个 Android Gradle 配置中的临时 `.qa` 包名后缀已移除，当前 applicationId 分别为 `com.chunlian.customer` 和 `com.chunlian.merchant`；两份 APK 均用 `aapt` 实查包名。
- 最终配置验证通过：顾客 Android 单测 13/13、店主 Android 单测 8/8，两个 `:app:assembleDebug` 均成功；顾客网页 `npm run build` 成功。APK 为 Debug 验收包，不是签名发布包。

本补充只确认图标素材与布局；它不代表所有业务流程都在每个客户端逐一重跑，也不代表本轮有新版本部署到生产环境。

## 本轮修正

- 商家“下架”图标的透明画布边缘有一个 22 像素红色孤立碎片。旧的 2% 过滤阈值没有排除它，造成图案边界轻微偏移。本轮把网页验收器、顾客 Android 和商家 Android 的孤立碎片阈值统一为 2.5%；该碎片现在不参与显示边界计算，原始 PNG 保持不变。
- 同一规则还剔除 `close`、`orders`、`product_detail`、`success` 等素材里的孤立细线；相邻主体的部分与面积较大的多段图形（例如加载动画）仍保留。
- 三端统一以过滤后的可见图案计算边界，在按钮槽位中水平、垂直居中，最长边占槽位 82%，并裁切在槽位内。

## 全量检查结果

| 范围 | 覆盖与结果 |
|---|---|
| 顾客网页 | PNG 素材预览 51 项；46 条光学边界记录与 PNG 当前可见边界全部匹配。Playwright 对桌面和手机各检查 16 个视图、63 个图标实例：中心误差最大 0 px，显示尺寸均为槽位 82%，未发现容器溢出。 |
| 顾客 Android | 44 项打包 PNG 的素材边界预览通过：中心误差 0 px、显示尺寸 82%。44/44 项与对应网页美术源图逐字节一致。 |
| 商家 Android | 36 项打包 PNG 的素材边界预览通过：中心误差 0 px、显示尺寸 82%。36/36 项与 `assets/design/generated-icons/merchant` 对应源图逐字节一致；五种密度目录中的 190/190 个 PNG 与原始商家资源包一致。 |
| 构建与单测 | 网页 `npm run build` 通过；顾客和商家分别通过 `compileDebugKotlin` 与 `testDebugUnitTest`，单测 13/13、8/8 通过。 |

Android 组件的本轮修正已编译并通过单测；本轮生成的是 Debug 验收 APK，没有制作签名发布包、部署网页或修改生产数据。旧的 SVG 网页报告已在 `docs/evidence/web-icon-final-report.md` 标明过时。此前关于生产网页发布、服务器只读检查和旧版本包的记录不代表本轮修正已经上线。

## 视觉证据

- 网页：[51 项 PNG 素材渲染预览](evidence/icon-visual-check-2026-10-14/customer-web-rendered.png)、[桌面视图几何报告](evidence/web-asset-geometry-2026-10-14-chromium-desktop.json)、[手机视图几何报告](evidence/web-asset-geometry-2026-10-14-chromium-mobile.json)。
- 顾客 Android：[44 项素材渲染预览](evidence/icon-visual-check-2026-10-14/customer-android-rendered.png)、[首页](evidence/icon-visual-check-2026-10-14/customer-home-current.png)、[分类](evidence/icon-visual-check-2026-10-14/customer-category-current.png)、[购物车](evidence/icon-visual-check-2026-10-14/customer-cart-current.png)、[订单](evidence/icon-visual-check-2026-10-14/customer-orders-current.png)、[个人页](evidence/icon-visual-check-2026-10-14/customer-profile-current.png)。
- 店主 Android：[36 项素材渲染预览](evidence/icon-visual-check-2026-10-14/merchant-android-rendered.png)、[商品页按钮](evidence/icon-visual-check-2026-10-14/merchant-products-current.png)、[店铺设置与二维码上传区](evidence/icon-visual-check-2026-10-14/merchant-settings-current.png)。
- 方法和逐素材碎片记录：[素材几何审计 JSON](evidence/icon-visual-check-2026-10-14/asset-file-geometry-audit.json)。

素材渲染预览用于逐项检查实际组件的归一化边界；网页另有当前构建的真实浏览器截图和几何测量。Android 既有页面截图用于核对控件中的显示位置，本轮对全部 Android PNG 重新计算边界并构建验证，但没有重新走完所有 Android 业务页面。完整业务模拟仍按单独验收清单进行，图标验收不能替代业务流程验收。
