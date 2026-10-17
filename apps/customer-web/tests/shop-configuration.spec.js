import { test, expect } from '@playwright/test'
import fs from 'node:fs/promises'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { installMockApi } from './mock-api.js'

const evidenceDirectory = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../../docs/evidence')

async function capture(page, testInfo, name) {
  const screenshotPath = path.join(evidenceDirectory, `shop-config-${name}-${testInfo.project.name}.png`)
  await fs.mkdir(path.dirname(screenshotPath), { recursive: true })
  await page.screenshot({ path: screenshotPath, fullPage: true })
}

async function configuredShop(page) {
  const apiState = await installMockApi(page)
  const setting = {
    shop_name: '邻里鲜果与海鲜店', shop_slogan: '鲜果海味，每日送达',
    shop_logo_url: 'https://shop.example/logo.svg',
    shop_address: '幸福路 88 号', business_hours: '每天 08:00—20:00',
    shop_description: '邻里小店，提供水果与海鲜。', delivery_description: '幸福社区满额配送，远距离请先电话联系。',
    home_banner_title: '今日海鲜新到', home_banner_subtitle: '店主精选 · 按需配送',
    home_section_title: '本周精选', search_hot_words: ['鲜虾', '苹果'],
    phone: '13800138000', notice: '欢迎预订', is_open: true,
    delivery_fee_fen: 500, min_lead_minutes: 45, updated_at: '2026-10-15 10:00:00',
  }
  const categories = [
    { id: 'new1', name: '海鲜水产', icon_url: 'https://shop.example/category.svg', children: [
      { id: 'seafood-fish', name: '鲜鱼', icon_url: 'https://shop.example/category.svg' },
      { id: 'seafood-shrimp', name: '虾蟹', icon_url: 'https://shop.example/category.svg' },
    ] },
    { id: 'new2', name: '时令水果', icon_url: 'https://shop.example/category.svg', children: [
      { id: 'fruit-citrus', name: '柑橘', icon_url: '' },
      { id: 'fruit-apples', name: '苹果', icon_url: '' },
    ] },
  ]
  await page.route('**/api/v1/customer/shop', route => route.fulfill({ json: { code: 'OK', data: setting } }))
  await page.route('**/api/v1/customer/catalog/categories', route => route.fulfill({ json: { code: 'OK', data: categories } }))
  await page.route('https://shop.example/logo.svg', route => route.fulfill({
    status: 200,
    contentType: 'image/svg+xml',
    body: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 40 40"><rect width="40" height="40" rx="10" fill="#245947"/><path d="M10 23h20L20 9z" fill="#f4cf67"/><circle cx="20" cy="28" r="5" fill="#e6f1d5"/></svg>',
  }))
  await page.route('https://shop.example/category.svg', route => route.fulfill({
    status: 200,
    contentType: 'image/svg+xml',
    body: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 40 40"><circle cx="20" cy="20" r="18" fill="#b6d9a8"/><path d="M12 22c7-11 13-11 17-3-4 8-10 10-17 3z" fill="#245947"/></svg>',
  }))
  return { setting, categories, apiState }
}

test('configured store copy, categories and contact details appear across customer pages', async ({ page }, testInfo) => {
  await configuredShop(page)
  await page.goto('./')
  await expect(page.locator('.home-head__brand')).toHaveText('邻里鲜果与海鲜店')
  await expect(page).toHaveTitle('首页 · 邻里鲜果与海鲜店')
  await expect(page.locator('.shop-logo img')).toHaveAttribute('alt', '邻里鲜果与海鲜店标志')
  await expect.poll(() => page.locator('.shop-logo img').evaluate(image => image.naturalWidth)).toBeGreaterThan(0)
  await expect(page.locator('.home-head__highlights')).toHaveText('鲜果海味，每日送达 · 今日海鲜新到 · 店主精选 · 按需配送')
  await expect(page.locator('.section-title').first()).toContainText('本周精选')
  await expect(page.locator('.home-cat__name')).toHaveText(['海鲜水产', '时令水果'])
  await expect(page.locator('.home-cat__image')).toHaveCount(2)
  await expect.poll(() => page.locator('.home-cat__image').first().evaluate(image => image.naturalWidth)).toBeGreaterThan(0)
  await capture(page, testInfo, 'home')
  await page.getByRole('button', { name: '搜索店内商品' }).click()
  await expect(page.getByRole('button', { name: '鲜虾', exact: true })).toBeVisible()
  await expect(page.getByRole('button', { name: '苹果', exact: true })).toBeVisible()
  await expect(page.getByRole('button', { name: '小青菜', exact: true })).toHaveCount(0)
  await page.goto('about')
  await expect(page.getByText('店铺地址：幸福路 88 号')).toBeVisible()
  await expect(page.getByText('营业时间：每天 08:00—20:00')).toBeVisible()
  await expect(page.getByText('邻里小店，提供水果与海鲜。')).toBeVisible()
  await expect(page.getByText('幸福社区满额配送，远距离请先电话联系。')).toBeVisible()
  await expect(page.getByText('扫码预付订单增额需店主核实补款，减额退款由店主实际退款后核实，状态以订单为准。')).toBeVisible()
  await capture(page, testInfo, 'about')
})

test('returning home refreshes changed name and category configuration without reloading the app', async ({ page }) => {
  const { setting, categories } = await configuredShop(page)
  await page.goto('./')
  await expect(page.locator('.home-head__brand')).toHaveText(setting.shop_name)
  await page.getByRole('button', { name: '我的', exact: true }).click()
  await expect(page.locator('.profile-name')).toBeVisible()
  setting.shop_name = '邻里鲜选新店名'
  categories[0].name = '冷鲜水产'
  categories.pop()
  await page.getByRole('button', { name: '首页', exact: true }).click()
  await expect(page.locator('.home-head__brand')).toHaveText('邻里鲜选新店名')
  await expect(page.locator('.home-cat')).toHaveCount(1)
  await expect(page.locator('.home-cat')).toContainText('冷鲜水产')
  await expect(page).toHaveTitle('首页 · 邻里鲜选新店名')
})

test('configured child category filters products and can be opened from its URL', async ({ page }, testInfo) => {
  const { apiState } = await configuredShop(page)
  apiState.catalog[0].category_id = 'seafood-shrimp'

  await page.goto('category')
  await expect(page.getByRole('button', { name: '虾蟹', exact: true })).toBeVisible()
  await page.getByRole('button', { name: '虾蟹', exact: true }).click()
  await expect(page).toHaveURL(/\/category\?id=seafood-shrimp$/)
  await expect(page.getByRole('heading', { name: '小青菜' })).toBeVisible()
  await expect(page.getByRole('button', { name: '虾蟹', exact: true })).toHaveAttribute('aria-pressed', 'true')
  await expect(page.locator('.cat-rail__item').first()).toHaveClass(/is-active/)
  await expect(page.locator('.cat-rail__image')).toHaveCount(2)
  await expect.poll(() => page.locator('.cat-rail__image').first().evaluate(image => image.naturalWidth)).toBeGreaterThan(0)
  await expect(page.locator('.cat-subcategory__image')).toHaveCount(2)
  await capture(page, testInfo, 'subcategory')

  await page.goto('category?id=seafood-shrimp')
  await expect(page.getByRole('button', { name: '虾蟹', exact: true })).toHaveAttribute('aria-pressed', 'true')
  await expect(page.getByRole('heading', { name: '小青菜' })).toBeVisible()
})

test('failed shop logo image falls back to the configured store initial', async ({ page }, testInfo) => {
  const { setting } = await configuredShop(page)
  setting.shop_logo_url = 'https://shop.example/missing-logo.svg'
  await page.route('https://shop.example/missing-logo.svg', route => route.fulfill({ status: 404, body: '' }))

  await page.goto('./')
  await expect(page.locator('.shop-logo')).toHaveAttribute('aria-label', '邻里鲜果与海鲜店标志')
  await expect(page.locator('.shop-logo .asset-icon')).toBeVisible()
  await expect(page.locator('.shop-logo img[alt=""]')).toHaveAttribute('src', /assets\/brand\/spring-fresh\.png/)
  await capture(page, testInfo, 'logo-fallback')
})

test('empty optional shop copy stays hidden and uses concise identity fallbacks', async ({ page }, testInfo) => {
  const { setting } = await configuredShop(page)
  setting.shop_name = ''
  setting.shop_logo_url = ''
  setting.shop_slogan = ''
  setting.notice = ''
  setting.home_banner_title = ''
  setting.home_banner_subtitle = ''
  setting.home_section_title = ''
  setting.search_hot_words = []

  await page.goto('./')
  await expect(page.locator('.home-head__brand')).toHaveText('店铺')
  await expect(page.locator('.shop-logo')).toHaveAttribute('aria-label', '店铺标志')
  await expect(page.locator('.shop-logo .asset-icon')).toBeVisible()
  await expect(page.locator('.home-head__slogan')).toHaveCount(0)
  await expect(page.locator('.home-notice')).toHaveCount(0)
  await expect(page.locator('.home-head__highlights')).toHaveCount(0)
  await expect(page.locator('.section-title > span').first()).toHaveText('店内商品')
  await capture(page, testInfo, 'empty-home')

  await page.goto('search')
  await expect(page.getByText('店主推荐', { exact: true })).toHaveCount(0)
  await capture(page, testInfo, 'empty-search')
})

test('long configured store copy wraps on small screens without clipping or horizontal overflow', async ({ page }, testInfo) => {
  const { setting } = await configuredShop(page)
  setting.shop_name = '邻里鲜果海鲜与冷冻食品配送中心欢迎您到店选购新鲜食材'
  setting.shop_slogan = '每日清晨采购当地时令果蔬与优质水产，按顾客需求精心分装并及时配送到家'
  setting.home_banner_title = '今日新鲜到店推荐：应季水果、海鲜水产和社区精选食材'
  setting.home_banner_subtitle = '可按家庭人数与烹饪需求备注分量，我们会尽量为您挑选合适商品并妥善打包配送'

  await page.goto('./')
  await expect(page.locator('.home-head__brand')).toContainText('邻里鲜果海鲜')
  await expect(page.locator('.home-head__highlights')).toContainText('今日新鲜到店推荐')
  const layout = await page.evaluate(() => ({
    viewportWidth: document.documentElement.clientWidth,
    documentWidth: document.documentElement.scrollWidth,
    highlights: document.querySelector('.home-head__highlights')?.getBoundingClientRect().toJSON(),
    header: document.querySelector('.home-head')?.getBoundingClientRect().toJSON(),
  }))
  expect(layout.documentWidth).toBeLessThanOrEqual(layout.viewportWidth)
  expect(layout.highlights.right).toBeLessThanOrEqual(layout.viewportWidth)
  expect(layout.highlights.bottom).toBeLessThanOrEqual(layout.header.bottom)
  await capture(page, testInfo, 'long-copy')
})
