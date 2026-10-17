import { test, expect } from '@playwright/test'
import { installMockApi } from './mock-api.js'

test('首页分类筛选只更新今日鲜选，并可恢复全部商品', async ({ page }, testInfo) => {
  const api = await installMockApi(page)
  await page.goto('./')
  const homeUrl = page.url()

  const categories = page.getByRole('navigation', { name: '按分类找好菜' })
  await expect(categories.getByRole('button', { name: '瓜果类' })).toBeVisible()
  await expect(page.locator('.goods-card')).toHaveCount(3)
  await expect(categories.getByRole('button', { name: '全部', exact: true })).toHaveAttribute('aria-pressed', 'true')

  await categories.getByRole('button', { name: '瓜果类' }).click()

  await expect.poll(() => page.url()).toBe(homeUrl)
  await expect(page.locator('.section-title').first()).toContainText('今日鲜选 · 瓜果类')
  await expect(categories.getByRole('button', { name: '瓜果类' })).toHaveAttribute('aria-pressed', 'true')
  await expect(categories.getByRole('button', { name: '全部', exact: true })).toHaveAttribute('aria-pressed', 'false')
  const goodsGrid = page.locator('.goods-grid')
  await expect(goodsGrid.locator('.goods-card')).toHaveCount(1)
  await expect(goodsGrid.getByRole('heading', { name: '西红柿' })).toBeVisible()
  await expect(goodsGrid.getByRole('heading', { name: '小青菜' })).toHaveCount(0)
  await page.screenshot({ path: `qa-artifacts/home-category-filter-${testInfo.project.name}.png`, fullPage: true })
  expect(api.requests.some((request) => (
    request.path === '/catalog/goods' && request.url.searchParams.get('category_id') === 'c2'
  ))).toBe(true)

  await categories.getByRole('button', { name: '全部', exact: true }).click()
  await expect(page.locator('.section-title').first().locator('span').first()).toHaveText('今日鲜选')
  await expect(page.locator('.goods-card')).toHaveCount(3)

  await page.locator('.section-title__more').click()
  await expect.poll(() => new URL(page.url()).pathname).toMatch(/\/app\/category$/)
})
