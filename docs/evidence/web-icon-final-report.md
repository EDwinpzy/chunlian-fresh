# 网页图标视觉验收（旧版记录）

> 本文件记录较早一轮 SVG 图标验收，已由 [三端 PNG 美术素材尺寸与居中验收](../ICON-VALIDATION-2026-10-14.md) 取代。请以 `docs/evidence/icon-visual-check-2026-10-14/` 中的当前素材几何与渲染证据为准。

- 范围：顾客端本地网页，使用 Playwright + 本地 Mock API；桌面 1440×1000，手机 412×915。
- 页面：首页、分类、购物车、我的、商品详情、退款进度、搜索；共保存 14 张桌面/手机截图。
- 加载与布局：可见图标均成功解码；0 个图标资源 HTTP 错误，0 个页面运行错误。每个图标图片框与容器尺寸一致，使用 contain；4 个底栏图标在各自按钮水平居中，且 active/normal 文件与所选页面状态匹配。
- 视觉：逐项查看了全部 14 个 SVG 的 24px、32px 白底/浅灰底 contact sheet，以及两端页面截图。轮廓完整、无裁切或拉伸，底栏与商品说明、退款、订单、电话、搜索图标位置正常。
- 限制：页面数据来自本地模拟接口；这次只验收网页图标，没有访问或部署生产站点。

截图：`web-icon-final-desktop-*.png`、`web-icon-final-mobile-*.png`、`web-icon-final-svg-contact-sheet.png`。机器检查明细见 `web-icon-final-report.json`。
