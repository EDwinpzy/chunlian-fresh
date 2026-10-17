import { test, expect } from '@playwright/test'
import { installMockApi } from './mock-api.js'

test('每日鲜选默认取前五个商品，分类筛选不改变推荐轮播，点击直达详情', async ({ page }, testInfo) => {
  const api = await installMockApi(page)
  await page.goto('./')

  const carousel = page.getByRole('region', { name: '每日鲜选' })
  await expect(carousel).toBeVisible()
  await expect(carousel.getByRole('link', { name: '去挑选好菜：小青菜' })).toBeVisible()
  await expect(carousel.locator('.featured-slide')).toHaveCount(3)
  await page.screenshot({ path: `qa-artifacts/home-featured-carousel-${testInfo.project.name}.png`, fullPage: true })

  expect(api.requests.some((request) => (
    request.path === '/catalog/goods'
      && request.url.searchParams.get('ids') === null
      && request.url.searchParams.get('page_size') === '5'
  ))).toBe(true)

  const categories = page.getByRole('navigation', { name: '按分类找好菜' })
  await categories.getByRole('button', { name: '瓜果类' }).click()
  await expect(page.locator('.section-title').first()).toContainText('今日鲜选 · 瓜果类')
  await expect(page.locator('.goods-card')).toHaveCount(1)
  await expect(carousel.getByRole('link', { name: '去挑选好菜：小青菜' })).toBeVisible()

  await carousel.getByRole('link', { name: '去挑选好菜：小青菜' }).click()
  await expect(page).toHaveURL(/\/goods\/1$/)
  await expect(page.getByRole('heading', { name: '小青菜' })).toBeVisible()
})

test('店家配置顺序决定轮播顺序，指示点切换后点击进入对应商品', async ({ page }) => {
  const api = await installMockApi(page, { featuredProductIds: ['2', '1'] })
  await page.goto('./')

  const carousel = page.getByRole('region', { name: '每日鲜选' })
  const links = carousel.locator('.featured-slide__link')
  await expect(links).toHaveCount(2)
  await expect(links.nth(0)).toHaveAttribute('href', /\/goods\/2$/)
  await expect(links.nth(1)).toHaveAttribute('href', /\/goods\/1$/)
  expect(api.requests.some((request) => (
    request.path === '/catalog/goods' && request.url.searchParams.get('ids') === '2,1'
  ))).toBe(true)

  const secondSlide = carousel.getByRole('button', { name: '第 2 个推荐商品：小青菜' })
  await secondSlide.click()
  await expect(secondSlide).toHaveAttribute('aria-current', 'true')
  await links.nth(1).click()
  await expect(page).toHaveURL(/\/goods\/1$/)
})
