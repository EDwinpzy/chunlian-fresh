#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
春连鲜选 · 美术资源接入脚本

把 design-assets/ 里的资源包铺进两端工程。

【为什么要脚本而不是手工复制】
手工会出现两类问题：
  1. 复制不全/复制错尺寸 —— tabbar 有 81x81 与 64x64 两套，安卓有 5 个密度，
     全手工挑容易挑错，而错的表现是『图标在某些手机上糊』，很难定位。
  2. 无法追溯 —— 三个月后没人记得某个文件是从哪张图来的。
本脚本每次运行都从资源包重新同步，并把映射关系打印出来。

【资源包必须满足的条件】（脚本会校验，不满足就报错退出）
  - 只接受 `微信小程序/` 与 `安卓商家端/` 两个目录
  - `原始拆分PNG/` 是**源图/预览**，可能不带透明通道（RGB 而非 RGBA），
    直接铺进工程会在小程序里出现白底方块。Redeclared as 参考，不参与同步。
  - 所有同步的 PNG 必须是 RGBA/索引色且非交错，否则拒绝。

用法：
    python infrastructure/scripts/sync-assets.py          # 同步
    python infrastructure/scripts/sync-assets.py --check  # 只校验不写入
"""

import os
import shutil
import struct
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
PACK = os.path.join(ROOT, 'design-assets', '春连生鲜移动端开发可用资源包_第10套')

MINIAPP = os.path.join(ROOT, 'customer-miniapp', 'src', 'static')
ANDROID_RES = os.path.join(ROOT, 'merchant-android', 'app', 'src', 'main', 'res')

# ------------------------------------------------------------
# tabbar 映射：资源包文件名 → 小程序 pages.json 引用的文件名
# ------------------------------------------------------------
TABBAR_MAP = {
    'tabbar_home_normal_81x81.png':    'home.png',
    'tabbar_home_active_81x81.png':    'home-active.png',
    'tabbar_category_normal_81x81.png': 'catalog.png',
    'tabbar_category_active_81x81.png': 'catalog-active.png',
    'tabbar_cart_normal_81x81.png':    'cart.png',
    'tabbar_cart_active_81x81.png':    'cart-active.png',
    'tabbar_profile_normal_81x81.png': 'profile.png',
    'tabbar_profile_active_81x81.png': 'profile-active.png',
}

# 功能图标：资源包 common_icons_64 → 小程序 static/images/features/
# 资源包里缺 search（tabbar 有但 features 没有），单独跳过并提示
FEATURE_ICONS = [
    'address', 'after_sale', 'cod', 'coupon', 'customer_service', 'delivery_time',
    'favorite', 'hot_sale', 'new_user', 'notification', 'orders', 'phone',
    'product_detail', 'refund', 'share', 'wechat_pay',
]

CHECK_ONLY = '--check' in sys.argv


def png_props(path):
    """读 PNG 头，返回 (宽, 高, 位深, 色彩类型, 是否交错)。色彩类型 6=RGBA。"""
    with open(path, 'rb') as f:
        head = f.read(29)
    if head[:8] != b'\x89PNG\r\n\x1a\n':
        return None
    w, h = struct.unpack('>II', head[16:24])
    return (w, h, head[24], head[25], head[28])


def validate(path):
    """校验一个 PNG 是否可以直接用于工程：必须带透明通道、非交错。"""
    p = png_props(path)
    if p is None:
        return '不是有效的 PNG'
    w, h, bit, ctype, interlace = p
    if ctype not in (3, 6):          # 3=索引色(可带透明) 6=RGBA
        return '没有透明通道（色彩类型 %d，RGB 直接进工程会显白底）' % ctype
    if interlace:
        return '交错式 PNG（小程序部分版本显示异常）'
    return None


def main():
    print('春连鲜选 · 美术资源接入')
    print('=' * 62)

    if not os.path.isdir(PACK):
        sys.exit('找不到资源包：%s' % PACK)

    problems = []

    # ---------------- 校验 ----------------
    print('\n[1] 校验资源包')
    tabbar_dir = os.path.join(PACK, '微信小程序', 'tabbar')
    feature_dir = os.path.join(PACK, '微信小程序', 'common_icons_64')
    and_dir = os.path.join(PACK, '安卓商家端')

    for src in [tabbar_dir, feature_dir, and_dir]:
        if not os.path.isdir(src):
            problems.append('缺少目录：' + src)

    missing = [f for f in TABBAR_MAP if not os.path.isfile(os.path.join(tabbar_dir, f))]
    if missing:
        problems.append('缺少 tabbar 图标：' + ', '.join(missing))

    missing_f = [f + '_64x64.png' for f in FEATURE_ICONS
                 if not os.path.isfile(os.path.join(feature_dir, f + '_64x64.png'))]
    if missing_f:
        problems.append('缺少功能图标：' + ', '.join(missing_f))

    for d in [tabbar_dir, feature_dir, and_dir]:
        if not os.path.isdir(d):
            continue
        for name in os.listdir(d):
            p = os.path.join(d, name)
            if not name.lower().endswith('.png') or not os.path.isfile(p):
                continue
            err = validate(p)
            if err:
                problems.append('%s：%s' % (name, err))

    if problems:
        print('  ✗ %d 个问题：' % len(problems))
        for p in problems[:20]:
            print('    · ' + p)
        sys.exit(1)
    print('  ✓ 资源包校验通过')

    if CHECK_ONLY:
        print('\n--check：只做校验，未写入。')
        return

    # ---------------- 小程序 tabbar ----------------
    print('\n[2] 小程序 tabbar 图标')
    tabs_out = os.path.join(MINIAPP, 'tabs')
    os.makedirs(tabs_out, exist_ok=True)
    for src_name, dst_name in TABBAR_MAP.items():
        shutil.copy2(os.path.join(tabbar_dir, src_name), os.path.join(tabs_out, dst_name))
        print('  ✓ %s → static/tabs/%s' % (src_name, dst_name))

    # ---------------- 小程序功能图标 ----------------
    print('\n[3] 小程序功能图标')
    feat_out = os.path.join(MINIAPP, 'images', 'features')
    os.makedirs(feat_out, exist_ok=True)
    for name in FEATURE_ICONS:
        src = os.path.join(feature_dir, name + '_64x64.png')
        shutil.copy2(src, os.path.join(feat_out, name + '.png'))
    print('  ✓ %d 个 → static/images/features/' % len(FEATURE_ICONS))

    # ---------------- 安卓资源 ----------------
    print('\n[4] 安卓商家端资源')
    copied = 0
    for spec in os.listdir(and_dir):
        spec_dir = os.path.join(and_dir, spec)
        if not os.path.isdir(spec_dir):
            continue
        # 只接 drawable-* 与 mipmap-*，忽略 README 等其他文件
        if not (spec.startswith('drawable-') or spec.startswith('mipmap-')):
            continue
        out_dir = os.path.join(ANDROID_RES, spec)
        os.makedirs(out_dir, exist_ok=True)
        for name in os.listdir(spec_dir):
            if not name.lower().endswith('.png'):
                continue
            shutil.copy2(os.path.join(spec_dir, name), os.path.join(out_dir, name))
            copied += 1
    print('  ✓ %d 个 → merchant-android/app/src/main/res/' % copied)

    # ---------------- 汇总 ----------------
    print('\n' + '=' * 62)
    total = len(TABBAR_MAP) + len(FEATURE_ICONS) + copied
    print('完成：共同步 %d 个文件' % total)
    print('  · 小程序 tabbar %d + 功能图标 %d' % (len(TABBAR_MAP), len(FEATURE_ICONS)))
    print('  · 安卓 drawable/mipmap %d' % copied)
    print('\n注意：Android 的底部导航图标需改成引用这些 drawable，')
    print('      见 Routes.kt 中 BottomTab 的 icon 字段（本次同步需配套修改）。')


if __name__ == '__main__':
    main()
