import path from 'node:path'
import fs from 'node:fs'
import { test, expect } from '@playwright/test'
import { cartSeed, installMockApi, makeOrder } from './mock-api.js'

// A real, scannable test-only QR image. The reserved .test domain ensures this
// fixture cannot point customers at a live payment destination.
const qr = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAUgAAAFIAQAAAAAab1qeAAACV0lEQVR4nO1b246rMAz0WP3/X54jj5OU85ZF2k0MsVqgYVTM1Lc4FLRJ8VmgHeSM+BQq5CBnxKdQIQc5Iz6FejvyExvkMaGXGRgjBMcZW62nV+LTlJQQe8KCxhjA9YyVuSPfAQnIIk0WKkuFkd8ztometZCIN5kub8HpX179eUhCDp+eL5//y6s/KH5a5y4y0TcHxSh/8+r+UD6RH5TXmdkdsR8J3lbr6UWQuHp1MBmxM45Z9Y58PRKGeEX0zMyeqT4zEuLkHnqWQUKRUn4eCT5TkkbjkNvoWQRJZXRRmKOR5oNMBVBuo2cVJFQmaWqU9LUJZ8vx4CZ61kGyWaLesUnrbI6/j55l6iV+s1B3cJX0Pd2v1tOL1fPIurPFy2GUF3p9qZ5eBInWScpd6yaN3SDYVuvpleyTIwUFs/mp2yX20NNL9ZPZC6ZuptfuEtOIq9yRL0Xi2zCWJLNtJp/hE2mpVe7IN+GTPXS2Lkjv0nMLPb0IEi1KjqTeOOyLHllLHX//YT+EWt9QuZntDxVQiqG8951vRlIEJnfN9dVVygaJYRc9yyBxYS8HwlQZrSbEgtIuepaplzCm8EnsxTJ57NPu9udtzJZ6h+n06+7Yp0kyeoZ7q6+k7lKmqJ99p78Y+dH2UtQ3984aqVNsy/X0ms+HGKMnH5ILSOqF2lk/slvMI7eiVQtKUZHi7m/05v7nkCztWzr6b+XYbVb8xchP7rqP6zGG0aVX+cRWBFS5I9/o+RDrbz0cojk89ITYcj29CBLn/wgP+jX9ICfEZ0CSg5wRn0I9E/kPs9nWtCoXA8wAAAAASUVORK5CYII='

async function expectQrVisible(page) {
  const image = page.getByAltText('店主线下收款二维码')
  await expect(image).toBeVisible()
  await expect.poll(() => image.evaluate((element) => element.complete && element.naturalWidth > 0)).toBe(true)
  expect(await image.evaluate((element) => element.naturalWidth)).toBeGreaterThan(100)
}

function evidencePath(name, project) {
  return path.resolve(process.cwd(), '..', 'docs', 'evidence', `web-all-flows-${name}-${project}.png`)
}

test('顾客看到新上架商品、价格库存变化及下架后的旧订单快照', async ({ page }, testInfo) => {
  const api = await installMockApi(page, { token: true, paymentQrUrl: qr })
  const springShoot = {
    id: '4', category_id: 'c3', title: '春笋', image_url: '', simple_desc: '今早新到',
    spec_desc: '500 克 / 份', inventory_unit: '份', inventory: 5, sales_count: 0,
    has_many_spec: false, min_price_fen: 600, max_price_fen: 600, is_out_of_stock: false,
    skus: [{ sku_id: '401', spec_text: '500 克 / 份', price_fen: 600, original_price_fen: 600, inventory: 5, is_out_of_stock: false }],
  }
  api.catalog.push(springShoot)

  await page.goto('search')
  await page.getByPlaceholder('搜索店内商品').fill('春笋')
  await page.getByRole('button', { name: '搜索', exact: true }).click()
  const card = page.locator('.goods-card').filter({ hasText: '春笋' })
  await expect(card).toContainText('¥6.00')
  await card.getByRole('button', { name: '加入购物车：春笋' }).click()
  // Search hides the bottom tab bar; follow the newly-added item to the cart route.
  await page.goto('cart')
  await expect(page.locator('.cart-item')).toContainText('春笋')
  await expect(page.locator('.cart-item')).toContainText('¥6.00')
  await page.screenshot({ path: evidencePath('catalog-published', testInfo.project.name), fullPage: true })

  await page.goto('checkout')
  await page.getByRole('button', { name: '提交订单' }).click()
  await expect(page).toHaveURL(/\/app\/order-result\/9001$/)
  const orderId = '9001'
  expect(api.orders.get(orderId).lines[0]).toMatchObject({ name: '春笋', unit_fen: 600, qty: 1 })

  const liveGoods = api.catalog.find((item) => item.id === '4')
  liveGoods.skus[0].price_fen = 725
  liveGoods.skus[0].original_price_fen = 725
  liveGoods.skus[0].inventory = 1
  liveGoods.inventory = 1
  liveGoods.min_price_fen = 725
  liveGoods.max_price_fen = 725
  await page.goto('goods/4')
  await expect(page.locator('.detail-head__price')).toHaveText('¥7.25')
  await expect(page.getByRole('button', { name: '增加数量' })).toBeDisabled()

  liveGoods.is_active = false
  await page.goto('search')
  await page.getByPlaceholder('搜索店内商品').fill('春笋')
  await page.getByRole('button', { name: '搜索', exact: true }).click()
  await expect(page.getByText('没有找到相关商品', { exact: true })).toBeVisible()
  await page.goto('goods/4')
  await expect(page.getByText('商品已下架或不存在', { exact: true })).toBeVisible()
  await page.goto(`orders/${orderId}`)
  await expect(page.getByText('春笋', { exact: true })).toBeVisible()
  await expect(page.locator('.goods-row').filter({ hasText: '春笋' })).toContainText('¥6.00')
})

test('快速切换订单筛选时，旧的全部订单响应不会覆盖新结果', async ({ page }, testInfo) => {
  await installMockApi(page, { token: true })
  const finished = makeOrder('9101', {
    fulfillment: 'FINISHED', payment_state: 'PAID', can_edit: false, can_cancel: false,
  })
  const cancelled = makeOrder('9102', {
    fulfillment: 'CANCELLED', can_edit: false, can_cancel: false,
  })
  let releaseInitialResponse
  let markInitialRequest
  const initialResponseGate = new Promise((resolve) => { releaseInitialResponse = resolve })
  const initialRequestStarted = new Promise((resolve) => { markInitialRequest = resolve })

  await page.route('**/api/v1/customer/orders*', async (route) => {
    const url = new URL(route.request().url())
    const status = url.searchParams.get('status')
    const list = status === 'FINISHED' ? [finished] : [cancelled, finished]
    if (!status) {
      markInitialRequest()
      await initialResponseGate
    }
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({
        code: 'OK',
        message: 'ok',
        data: { list, total: list.length, page: 1, page_size: 10, has_more: false },
      }),
    })
  })

  try {
    await page.goto('orders')
    await initialRequestStarted
    await page.getByRole('button', { name: '已完成', exact: true }).click()
    await expect(page.locator('.order-card')).toHaveCount(1)
    await expect(page.locator('.order-card')).toContainText(finished.order_no)

    releaseInitialResponse()
    await expect(page.locator('.order-card')).toHaveCount(1)
    await expect(page.locator('.order-card')).not.toContainText(cancelled.order_no)
    await page.screenshot({ path: evidencePath('order-filter-race', testInfo.project.name), fullPage: true })
  } finally {
    releaseInitialResponse()
  }
})

test('顾客登录校验、错误反馈和带原目标恢复登录', async ({ page }, testInfo) => {
  const api = await installMockApi(page, { cart: cartSeed, authFailures: { login: 1 } })
  await page.goto('checkout')
  await expect(page).toHaveURL(/login\?redirect=\/checkout/)
  const submit = page.locator('button.login-submit')
  await expect(submit).toBeDisabled()

  await page.locator('input[type="tel"]').fill('123')
  await page.locator('input[type="password"]').fill('short')
  await page.locator('.agree__box').click()
  await expect(submit).toBeDisabled()

  await page.locator('input[type="tel"]').fill('13912345678')
  await page.locator('input[type="password"]').fill('CustomerQa2026!')
  await expect(submit).toBeEnabled()
  await submit.click()
  await expect(page.getByText('手机号或密码错误')).toBeVisible()
  await page.screenshot({ path: evidencePath('login-error', testInfo.project.name), fullPage: true })
  await submit.click()
  await expect(page.getByRole('heading', { name: '确认订单' })).toBeVisible()
  await expect(page).toHaveURL(/\/app\/checkout$/)
  expect(api.requests.filter((request) => request.path === '/auth/login')).toHaveLength(2)
  expect(api.requests.filter((request) => request.path === '/auth/login').every((request) => !request.headers.authorization)).toBe(true)
})

test('顾客注册遇到重复账号后可改正资料并完成登录', async ({ page }) => {
  const api = await installMockApi(page, { authFailures: { register: 1 } })
  await page.goto('login')
  await page.getByRole('button', { name: '注册', exact: true }).click()
  const submit = page.getByRole('button', { name: '注册并登录' })
  await page.locator('input[type="tel"]').fill('13812345678')
  await page.locator('input[type="password"]').fill('12345')
  await page.getByPlaceholder('选填，例如：张先生').fill('新顾客')
  await page.locator('.agree__box').click()
  await expect(submit).toBeDisabled()
  await page.locator('input[type="password"]').fill('CustomerQa2026!')
  await submit.click()
  await expect(page.getByText('该手机号已注册')).toBeVisible()
  await submit.click()
  await expect(page.locator('.profile-name')).toHaveText('新顾客')
  expect(api.requests.filter((request) => request.path === '/auth/register')).toHaveLength(2)
})

test('空地址结算补建、表单拒绝无效资料、保存失败重试并选择地址', async ({ page }, testInfo) => {
  const api = await installMockApi(page, { token: true, cart: cartSeed, addresses: [], addressSaveFailures: 1 })
  await page.goto('checkout')
  await expect(page.getByRole('heading', { name: '确认订单' })).toBeVisible()
  await expect(page.getByText('还没有收货地址，点击填写')).toBeVisible()
  await expect(page.getByRole('button', { name: '提交订单' })).toBeDisabled()
  await page.screenshot({ path: evidencePath('checkout-without-address', testInfo.project.name), fullPage: true })

  await page.locator('.address-card').click()
  await page.getByRole('heading', { name: '选择收货地址' }).waitFor()
  await page.getByRole('button', { name: '新增地址', exact: true }).click()
  await page.getByLabel('收货人').fill('新地址顾客')
  await page.getByLabel('手机号').fill('123456')
  await page.getByLabel('详细地址').fill('短')
  const save = page.getByRole('button', { name: '保存', exact: true })
  await expect(save).toBeDisabled()
  await page.getByLabel('手机号').fill('13712345678')
  await expect(page.locator('.field__error')).toHaveCount(0)
  await expect(save).toBeDisabled()
  await page.getByLabel('详细地址').fill('秋月小区 5 栋 301 室')
  await expect(save).toBeEnabled()
  await save.click()
  await expect(page.getByText('地址保存失败，请重试')).toBeVisible()
  await save.click()
  await expect(page.getByRole('heading', { name: '选择收货地址' })).toBeVisible()
  const addressCard = page.locator('.addr-card').filter({ hasText: '秋月小区 5 栋 301 室' })
  await expect(addressCard).toBeVisible()
  await addressCard.click()
  await expect(page.getByRole('heading', { name: '确认订单' })).toBeVisible()
  await expect(page.locator('.address-card')).toContainText('秋月小区 5 栋 301 室')
  await expect(page.getByRole('button', { name: '提交订单' })).toBeEnabled()
  await page.getByRole('button', { name: '提交订单' }).click()
  await expect(page).toHaveURL(/\/app\/order-result\/9001$/)
  expect(api.createCalls[0].body.contact.address).toBe('秋月小区 5 栋 301 室')
})

test('预约时间禁用过去时段和不足准备时间，并保存未来本地时区', async ({ page }) => {
  await page.clock.setFixedTime(new Date('2026-10-14T11:59:00+08:00'))
  const api = await installMockApi(page, { token: true, cart: cartSeed })
  await page.goto('checkout')
  await page.getByRole('button', { name: '预约送达' }).click()
  await page.locator('.schedule-picker').click()
  const groups = page.locator('.time-picker__group')
  await groups.nth(0).getByRole('button', { name: /今天/ }).click()
  await expect(groups.nth(1).getByRole('button', { name: '08', exact: true })).toBeDisabled()
  await groups.nth(1).getByRole('button', { name: '12', exact: true }).click()
  await expect(groups.nth(2).getByRole('button', { name: '15', exact: true })).toBeDisabled()
  await expect(groups.nth(2).getByRole('button', { name: '30', exact: true })).toBeEnabled()
  await groups.nth(2).getByRole('button', { name: '30', exact: true }).click()
  await page.getByRole('dialog').getByRole('button', { name: '确定', exact: true }).click()
  await page.getByRole('button', { name: '提交订单' }).click()
  await expect(page).toHaveURL(/\/app\/order-result\/9001$/)
  expect(api.createCalls[0].body.expected_at).toMatch(/^2026-10-14T12:30:00\+08:00$/)
  expect(api.createCalls[0].body.delivery_mode).toBe('SCHEDULED')
})

test('预约时段服务端拒绝后重新选择可用时间并提交', async ({ page }, testInfo) => {
  await page.clock.setFixedTime(new Date('2026-10-14T11:59:00+08:00'))
  const api = await installMockApi(page, { token: true, cart: cartSeed, scheduleSubmitFailures: 1 })
  await page.goto('checkout')
  await page.getByRole('button', { name: '预约送达' }).click()
  await page.locator('.schedule-picker').click()
  let groups = page.locator('.time-picker__group')
  await groups.nth(0).getByRole('button', { name: /今天/ }).click()
  await groups.nth(1).getByRole('button', { name: '12', exact: true }).click()
  await groups.nth(2).getByRole('button', { name: '30', exact: true }).click()
  await page.getByRole('dialog').getByRole('button', { name: '确定', exact: true }).click()
  await page.getByRole('button', { name: '提交订单' }).click()
  await expect(page.getByText('预约时段刚刚被占用，请重新选择时间')).toBeVisible()
  await expect(page).toHaveURL(/\/app\/checkout$/)
  expect(api.createCalls).toHaveLength(1)
  expect(api.orders.has('9001')).toBe(false)

  await page.locator('.schedule-picker').click()
  groups = page.locator('.time-picker__group')
  await groups.nth(0).getByRole('button', { name: /今天/ }).click()
  await groups.nth(1).getByRole('button', { name: '13', exact: true }).click()
  await groups.nth(2).getByRole('button', { name: '00', exact: true }).click()
  await page.getByRole('dialog').getByRole('button', { name: '确定', exact: true }).click()
  await page.getByRole('button', { name: '提交订单' }).click()
  await expect(page).toHaveURL(/\/app\/order-result\/9001$/)
  await expect(page.locator('.result-sub')).toContainText('预约 今天 13:00')
  expect(api.createCalls).toHaveLength(2)
  expect(api.createCalls[0].body.expected_at).toBe('2026-10-14T12:30:00+08:00')
  expect(api.createCalls[1].body.expected_at).toBe('2026-10-14T13:00:00+08:00')
  expect(api.createCalls[0].idem).not.toBe(api.createCalls[1].idem)
  await expect(page.getByText('预约时段刚刚被占用，请重新选择时间')).toBeHidden()
  await expect(page.locator('.sheet-root')).toHaveCount(0)
  await page.screenshot({ path: evidencePath('schedule-server-reject-recovered', testInfo.project.name), fullPage: true, animations: 'disabled' })
})

test('商品库存预览失败后恢复；订单页断网后重试可恢复', async ({ page }, testInfo) => {
  const api = await installMockApi(page, { token: true, cart: cartSeed, failPreview: true })
  await page.goto('checkout')
  await expect(page.getByText('有商品库存不足')).toBeVisible()
  await expect(page.getByRole('button', { name: '提交订单' })).toBeDisabled()
  expect(api.createCalls).toHaveLength(0)
  api.failPreview = false
  await page.getByRole('button', { name: '预约送达' }).click()
  await page.getByRole('button', { name: '尽快配送' }).click()
  await expect(page.getByRole('button', { name: '提交订单' })).toBeEnabled()

  api.networkOffline = true
  await page.goto('orders')
  await expect(page.getByText('加载失败', { exact: true })).toBeVisible()
  await page.screenshot({ path: evidencePath('orders-offline', testInfo.project.name), fullPage: true })
  api.networkOffline = false
  await page.getByRole('button', { name: '重试' }).click()
  await expect(page.locator('.order-card')).toHaveCount(10)
  expect(api.requests.some((request) => request.path === '/orders')).toBe(true)
})

test('订单状态筛选覆盖待付款、待配送、配送中、送达、完成和取消', async ({ page }) => {
  const api = await installMockApi(page, { token: true })
  api.orders.set('2011', makeOrder('2011', {
    fulfillment: 'FINISHED', payment_method: 'OFFLINE_QR', payment_state: 'PAID', paid_fen: 1000,
    can_edit: false, can_cancel: false,
  }))
  await page.goto('orders')
  await expect(page.locator('.order-card')).toHaveCount(10)
  await page.getByRole('button', { name: '加载更多' }).click()
  await expect(page.locator('.order-card')).toHaveCount(20)
  await expect(page.getByRole('button', { name: '加载更多' })).toHaveCount(0)

  const filters = [
    ['待付款', 'UNPAID'], ['待配送', 'PENDING'], ['配送中', 'DELIVERING'],
    ['已送达', 'DELIVERED'], ['已完成', 'FINISHED'], ['已取消', 'CANCELLED'],
  ]
  for (const [label, status] of filters) {
    await page.getByRole('button', { name: label, exact: true }).click()
    await expect.poll(() => api.requests.some((request) => request.path === '/orders' && request.url.searchParams.get('status') === status)).toBe(true)
    await expect(page.locator('.order-card').first()).toBeVisible()
  }
  await page.getByRole('button', { name: '全部', exact: true }).click()
  await expect(page.locator('.order-card')).toHaveCount(10)
  await page.goto('orders/999999')
  await expect(page.getByText('订单不存在或无权查看', { exact: true })).toBeVisible()
})

test('订单搜索支持商品名和订单号，叠加状态筛选并在搜索或清空后回到第一页', async ({ page }, testInfo) => {
  const api = await installMockApi(page, { token: true })
  api.orders.clear()
  const redAppleLine = { sku_id: '301', product_id: '3', name: '红苹果', spec: '500 克 / 份', image_url: '', unit_fen: 500, qty: 1, subtotal_fen: 500 }
  for (let id = 3001; id <= 3012; id += 1) {
    api.orders.set(String(id), makeOrder(String(id), { lines: [redAppleLine] }))
  }
  api.orders.set('3013', makeOrder('3013', {
    fulfillment: 'FINISHED', payment_state: 'PAID', payment_method: 'OFFLINE_QR', can_edit: false, can_cancel: false,
    lines: [redAppleLine],
  }))
  api.orders.set('3014', makeOrder('3014', {
    lines: [{ ...redAppleLine, name: '西兰花' }],
  }))

  await page.goto('orders')
  const search = page.getByRole('search')
  const input = search.getByRole('searchbox', { name: '搜索订单号或商品名' })
  const waitForOrderQuery = async (keyword, status = null, pageNumber = 1) => {
    await expect.poll(() => {
      const request = api.requests.filter((item) => item.path === '/orders').at(-1)
      return request?.url.searchParams.get('keyword') === keyword
        && request.url.searchParams.get('status') === status
        && request.url.searchParams.get('page') === String(pageNumber)
    }).toBe(true)
  }
  await expect(search.getByRole('button', { name: '搜索', exact: true })).toHaveCount(0)
  await input.fill('红苹果')
  await waitForOrderQuery('红苹果')
  await expect(page.locator('.order-card')).toHaveCount(10)
  await expect(page.getByRole('button', { name: '加载更多' })).toBeVisible()
  if (testInfo.project.name === 'chromium-mobile') {
    const samplePath = path.resolve(process.cwd(), 'qa-artifacts', 'customer-order-search.png')
    fs.mkdirSync(path.dirname(samplePath), { recursive: true })
    await page.screenshot({ path: samplePath })
  }
  const productSearch = api.requests.filter((request) => request.path === '/orders').at(-1)
  expect(productSearch.url.searchParams.get('keyword')).toBe('红苹果')
  expect(productSearch.url.searchParams.get('page')).toBe('1')

  await page.getByRole('button', { name: '加载更多' }).click()
  await expect(page.locator('.order-card')).toHaveCount(13)
  const secondPage = api.requests.filter((request) => request.path === '/orders').at(-1)
  expect(secondPage.url.searchParams.get('keyword')).toBe('红苹果')
  expect(secondPage.url.searchParams.get('page')).toBe('2')

  await page.getByRole('button', { name: '已完成', exact: true }).click()
  await waitForOrderQuery('红苹果', 'FINISHED')
  await expect(page.locator('.order-card')).toHaveCount(1)
  const filteredSearch = api.requests.filter((request) => request.path === '/orders').at(-1)
  expect(filteredSearch.url.searchParams.get('keyword')).toBe('红苹果')
  expect(filteredSearch.url.searchParams.get('status')).toBe('FINISHED')
  expect(filteredSearch.url.searchParams.get('page')).toBe('1')

  await page.getByRole('button', { name: '待配送', exact: true }).click()
  await waitForOrderQuery('红苹果', 'PENDING')
  await expect(page.locator('.order-card')).toHaveCount(10)
  await page.getByRole('button', { name: '加载更多' }).click()
  await waitForOrderQuery('红苹果', 'PENDING', 2)
  await expect(page.locator('.order-card')).toHaveCount(12)
  await input.fill('西兰花')
  await waitForOrderQuery('西兰花', 'PENDING')
  await expect(page.locator('.order-card')).toHaveCount(1)
  await input.fill('红苹果')
  await waitForOrderQuery('红苹果', 'PENDING')
  await expect(page.locator('.order-card')).toHaveCount(10)
  await search.getByRole('button', { name: '清空' }).click()
  await waitForOrderQuery(null, 'PENDING')
  await expect(input).toHaveValue('')
  await expect(page.locator('.order-card')).toHaveCount(10)
  const clearedSearch = api.requests.filter((request) => request.path === '/orders').at(-1)
  expect(clearedSearch.url.searchParams.get('keyword')).toBeNull()
  expect(clearedSearch.url.searchParams.get('status')).toBe('PENDING')
  expect(clearedSearch.url.searchParams.get('page')).toBe('1')

  api.orders.delete('3011')
  api.orders.delete('3012')
  await input.fill('红苹果')
  await waitForOrderQuery('红苹果', 'PENDING')
  await expect(page.locator('.order-card')).toHaveCount(10)
  await expect(page.getByRole('button', { name: '加载更多' })).toHaveCount(0)

  await input.fill(api.orders.get('3001').order_no)
  await waitForOrderQuery(api.orders.get('3001').order_no, 'PENDING')
  await expect(page.locator('.order-card')).toHaveCount(1)
  await expect(page.locator('.order-card')).toContainText(api.orders.get('3001').order_no)
  const numberSearch = api.requests.filter((request) => request.path === '/orders').at(-1)
  expect(numberSearch.url.searchParams.get('keyword')).toBe(api.orders.get('3001').order_no)
  expect(numberSearch.url.searchParams.get('status')).toBe('PENDING')

  await input.fill('不存在的商品')
  await waitForOrderQuery('不存在的商品', 'PENDING')
  await expect(page.getByText('没有找到匹配的订单', { exact: true })).toBeVisible()
  api.orders.clear()
  await search.getByRole('button', { name: '清空' }).click()
  await waitForOrderQuery(null, 'PENDING')
  await expect(page.getByText('还没有订单', { exact: true })).toBeVisible()
})

test('未付款 COD 改商品及信息；修改预览失败后重试成功', async ({ page }) => {
  const api = await installMockApi(page, { token: true, revisionPreviewFailures: 1 })
  await page.goto('orders/1001/edit')
  await page.getByLabel('收货人').fill('王小满')
  await page.getByLabel('手机号').fill('13912345678')
  await page.getByLabel('详细地址').fill('春雨路 8 号 202 室')
  await page.getByPlaceholder('例如：放门口').fill('请放门口')
  await page.locator('.edit-row').filter({ hasText: '小青菜' }).getByRole('button', { name: '增加数量' }).click()
  await page.getByRole('button', { name: '+ 加菜' }).click()
  await page.locator('.picker-item').filter({ hasText: '西红柿' }).click()
  await expect(page.locator('.edit-row')).toHaveCount(2)
  await page.getByRole('button', { name: '预览修改' }).click()
  await expect(page.getByText('修改预览暂不可用，请重试')).toBeVisible()
  expect(api.requests.filter((request) => request.path === '/orders/1001/revision' && request.method === 'POST')).toHaveLength(0)
  await page.getByRole('button', { name: '预览修改' }).click()
  await expect(page.getByRole('heading', { name: '确认变更' })).toBeVisible()
  await expect(page.getByText('应收变化', { exact: true })).toBeVisible()
  await page.getByRole('dialog').getByRole('button', { name: '确认变更' }).click()
  await expect(page).toHaveURL(/\/app\/orders\/1001$/)
  await expect(page.getByText('王小满 · 13912345678')).toBeVisible()
  await expect(page.getByText('春雨路 8 号 202 室')).toBeVisible()
  await expect(page.getByText('请放门口')).toBeVisible()
  await expect(page.locator('.goods-row').filter({ hasText: '小青菜' })).toContainText('× 3')
  await expect(page.locator('.goods-row').filter({ hasText: '西红柿' })).toContainText('× 1')
  expect(api.orders.get('1001').payment_state).toBe('UNPAID')
  expect(api.orders.get('1001').total_fen).toBe(1850)
})

test('订单编辑更新预约送达时间后详情与重开保持', async ({ page }, testInfo) => {
  await page.clock.setFixedTime(new Date('2026-10-14T10:00:00+08:00'))
  const api = await installMockApi(page, { token: true })
  api.orders.set('1001', makeOrder('1001', {
    delivery_mode: 'SCHEDULED', expected_at: '2026-10-15T12:00:00+08:00',
  }))

  await page.goto('orders/1001/edit')
  await page.locator('.schedule-picker').click()
  const groups = page.locator('.time-picker__group')
  await groups.nth(0).getByRole('button', { name: /明天/ }).click()
  await groups.nth(1).getByRole('button', { name: '14', exact: true }).click()
  await groups.nth(2).getByRole('button', { name: '45', exact: true }).click()
  await page.getByRole('dialog').getByRole('button', { name: '确定', exact: true }).click()
  await page.getByRole('button', { name: '保存修改' }).click()
  await expect(page).toHaveURL(/\/app\/orders\/1001$/)
  await expect(page.locator('.sheet-root')).toHaveCount(0)
  const expectedText = '预约 明天 14:45'
  await expect(page.locator('.row').filter({ hasText: '期望送达' })).toContainText(expectedText)
  expect(api.orders.get('1001').delivery_mode).toBe('SCHEDULED')
  expect(api.orders.get('1001').expected_at).toBe('2026-10-15T14:45:00+08:00')
  expect(api.requests.find((request) => request.path === '/orders/1001/info' && request.method === 'POST').body.expected_at)
    .toBe('2026-10-15T14:45:00+08:00')

  await page.reload()
  await expect(page.locator('.row').filter({ hasText: '期望送达' })).toContainText(expectedText)
  await expect(page.locator('.sheet-root')).toHaveCount(0)
  await expect(page.locator('.page')).toHaveCSS('opacity', '1')
  await page.screenshot({ path: evidencePath('order-schedule-updated', testInfo.project.name), fullPage: true, animations: 'disabled' })
})

test('已付款扫码订单补款核实后改单生效，减量退款在店主确认前保持处理中', async ({ page }, testInfo) => {
  const api = await installMockApi(page, { token: true, paymentQrUrl: qr })
  api.orders.set('2010', makeOrder('2010', {
    payment_method: 'OFFLINE_QR', payment_state: 'PAID', paid_fen: 1000,
  }))
  await page.goto('orders/2010/edit')
  await page.locator('.edit-row').getByRole('button', { name: '增加数量' }).click()
  await page.getByRole('button', { name: '预览修改' }).click()
  await expect(page.getByRole('dialog').getByText('需补付', { exact: true })).toBeVisible()
  await page.getByRole('dialog').getByRole('button', { name: '确认变更' }).click()
  await expect(page).toHaveURL(/\/app\/orders\/2010$/)
  await expect(page.getByRole('heading', { name: '扫码补款' })).toBeVisible()
  await expect(page.locator('.goods-row').filter({ hasText: '小青菜' })).toContainText('× 2')
  await expect(page.locator('.offline-qr__amount')).toContainText('本次补款 ¥3.50')
  await expect(page.getByAltText('店主线下收款二维码')).toHaveAttribute('src', qr)
  await expectQrVisible(page)
  await page.screenshot({ path: evidencePath('topup-pending', testInfo.project.name), fullPage: true })

  await page.getByRole('button', { name: '刷新核实状态' }).click()
  await expect(page.getByText('付款仍待店主核实；刷新不会自动确认收款')).toBeVisible()
  expect(api.orders.get('2010').lines[0].qty).toBe(2)
  api.confirmTopup('2010') // 店主核实到账的第二角色夹具动作
  await page.getByRole('button', { name: '刷新核实状态' }).click()
  await expect(page.getByText('店主已核实收款，订单状态已更新')).toBeVisible()
  await expect(page.locator('.goods-row').filter({ hasText: '小青菜' })).toContainText('× 3')
  expect(api.orders.get('2010').paid_fen).toBe(1350)

  await page.getByRole('button', { name: '修改订单' }).click()
  await page.locator('.edit-row').getByRole('button', { name: '减少数量' }).click()
  await page.getByRole('button', { name: '预览修改' }).click()
  await expect(page.getByRole('dialog').getByText('将退还', { exact: true })).toBeVisible()
  await page.getByRole('dialog').getByRole('button', { name: '确认变更' }).click()
  await expect(page.getByText('线下退款由店主人工核实和处理')).toBeVisible()
  await expect(page.locator('.goods-row').filter({ hasText: '小青菜' })).toContainText('× 2')
  await expect(page.locator('.row--amount').filter({ hasText: '退款处理中' }).first()).toBeVisible()
  await page.screenshot({ path: evidencePath('refund-pending', testInfo.project.name), fullPage: true })
  await page.getByRole('button', { name: '查看退款进度' }).click()
  await expect(page.getByText('等待店主线下退款并核实')).toBeVisible()
  expect(api.orders.get('2010').refunded_fen).toBe(0)
  expect(api.orders.get('2010').refunding_fen).toBe(350)
  api.confirmRefund('2010') // 店主确认实际退款到账的第二角色夹具动作
  await page.reload()
  await expect(page.getByText('店主已确认退款')).toBeVisible()
  await page.getByRole('button', { name: '返回订单详情' }).click()
  await expect(page.getByText('部分退款', { exact: true })).toBeVisible()
  expect(api.orders.get('2010').refunded_fen).toBe(350)
  expect(api.orders.get('2010').refunding_fen).toBe(0)
})

test('已付款扫码订单取消生成待退款；店主确认后订单仍显示已取消', async ({ page }, testInfo) => {
  const api = await installMockApi(page, { token: true, paymentQrUrl: qr })
  api.orders.set('2012', makeOrder('2012', {
    payment_method: 'OFFLINE_QR', payment_state: 'PAID', paid_fen: 1000,
  }))
  await page.goto('orders/2012')
  await page.getByRole('button', { name: '取消订单' }).click()
  await page.getByRole('dialog').getByRole('button', { name: '下错了' }).click()
  await page.getByRole('dialog').getByRole('button', { name: '确认取消' }).click()
  await expect(page.locator('.detail-status__title')).toHaveText('已取消')
  await expect(page.getByText('款项退款处理中')).toBeVisible()
  await expect(page.locator('.offline-qr')).toHaveCount(0)
  await page.screenshot({ path: evidencePath('cancel-refund-pending', testInfo.project.name), fullPage: true })
  await page.getByRole('button', { name: '查看退款进度' }).click()
  await expect(page.getByText('等待店主线下退款并核实')).toBeVisible()
  expect(api.orders.get('2012').fulfillment).toBe('CANCELLED')
  expect(api.orders.get('2012').payment_state).toBe('REFUNDING')
  api.confirmRefund('2012')
  await page.reload()
  await expect(page.getByText('店主已确认退款')).toBeVisible()
  await page.getByRole('button', { name: '返回订单详情' }).click()
  await expect(page.locator('.detail-status__title')).toHaveText('已取消')
  await expect(page.locator('.status-group')).toContainText('已退款')
})

test('配送开始后不能改/取消；COD 未收款确认收货不改付款状态', async ({ page }, testInfo) => {
  const api = await installMockApi(page, { token: true, paymentQrUrl: qr })
  await page.goto('orders/2001')
  await expect(page.locator('.detail-status__title')).toHaveText('配送中')
  await expect(page.getByRole('button', { name: '修改订单' })).toHaveCount(0)
  await expect(page.getByRole('button', { name: '取消订单' })).toHaveCount(0)
  await page.screenshot({ path: evidencePath('delivery-locked', testInfo.project.name), fullPage: true })
  await page.goto('orders/2001/edit')
  await expect(page.getByText('当前订单不能再自行修改', { exact: true })).toBeVisible()
  await expect(page.getByText('商家已开始配送。如需变更请联系店主。')).toBeVisible()

  await page.goto('orders/2004')
  await expect(page.locator('.offline-qr__amount')).toHaveText('待收货款 ¥10.00')
  await expect(page.locator('.row--amount').filter({ hasText: '待收货款' })).toContainText('¥10.00')
  await page.getByRole('button', { name: '确认收货' }).click()
  await page.getByRole('dialog').getByRole('button', { name: '确认收货' }).click()
  await expect(page.locator('.detail-status__title')).toHaveText('已完成')
  await expect(page.locator('.status-group')).toContainText('货到付款 · 未付款')
  expect(api.orders.get('2004').payment_state).toBe('UNPAID')
  expect(api.orders.get('2004').receivable_fen).toBe(1000)

  api.orders.set('2002', makeOrder('2002', {
    fulfillment: 'DELIVERED', delivered_at: '2026-10-13T10:00:00+08:00',
    payment_method: 'OFFLINE_QR', payment_state: 'PAID', paid_fen: 1000,
    can_edit: false, can_cancel: false, can_receive: true,
  }))
  await page.goto('orders/2002')
  await page.getByRole('button', { name: '确认收货' }).click()
  await page.getByRole('dialog').getByRole('button', { name: '确认收货' }).click()
  await expect(page.locator('.detail-status__title')).toHaveText('已完成')
  await expect(page.locator('.status-group')).toContainText('线下扫码 · 已付款')
  expect(api.orders.get('2002').paid_fen).toBe(1000)
  expect(api.orders.get('2002').receipt_state).toBe('CONFIRMED')
})

test('店主补记已送达 COD 收款后顾客能独立读取履约及付款状态', async ({ page }, testInfo) => {
  const api = await installMockApi(page, { token: true })
  const updated = api.confirmCodPayment('2004')
  expect(updated.fulfillment).toBe('DELIVERED')
  expect(updated.payment_state).toBe('PAID')
  expect(updated.paid_fen).toBe(updated.total_fen)
  expect(updated.receivable_fen).toBe(0)

  const expectPaidAndDelivered = async () => {
    await expect(page.locator('.detail-status__title')).toHaveText('已送达')
    await expect(page.locator('.status-group')).toContainText('货到付款 · 已付款')
    await expect(page.locator('.row').filter({ hasText: '付款状态' })).toContainText('已付款')
    await expect(page.locator('.offline-qr')).toHaveCount(0)
    await expect(page.getByText('待收货款')).toHaveCount(0)
    await expect(page.locator('.sheet-root')).toHaveCount(0)
  }

  await page.goto('orders/2004')
  await expectPaidAndDelivered()
  await page.reload()
  await expectPaidAndDelivered()
  await page.goto('orders')
  await page.locator('.order-card').filter({ hasText: updated.order_no }).click()
  await expect(page).toHaveURL(/\/app\/orders\/2004$/)
  await expectPaidAndDelivered()
  await expect(page.locator('.page')).toHaveCSS('opacity', '1')
  await page.screenshot({ path: evidencePath('cod-paid-delivered', testInfo.project.name), fullPage: true, animations: 'disabled' })
})

test('扫码预付刷新只读状态；模拟店主核款后顾客网页更新为已核实', async ({ page }, testInfo) => {
  const api = await installMockApi(page, { token: true, cart: cartSeed, paymentQrUrl: qr })
  await page.goto('checkout')
  await page.getByRole('button', { name: /扫码预付/ }).click()
  await page.getByRole('button', { name: '提交订单并查看收款码' }).click()
  await expect(page).toHaveURL(/\/app\/order-result\/9001$/)
  await expect(page.getByRole('heading', { name: '订单已提交，待付款' })).toBeVisible()
  await expect(page.getByAltText('店主线下收款二维码')).toHaveAttribute('src', qr)
  await expectQrVisible(page)
  await page.screenshot({ path: evidencePath('qr-waiting', testInfo.project.name), fullPage: true })
  await page.getByRole('button', { name: '刷新核实状态' }).click()
  await expect(page.getByText('付款仍待店主核实；刷新不会自动确认收款')).toBeVisible()
  expect(api.orders.get('9001').payment_state).toBe('UNPAID')

  const unpaid = api.orders.get('9001')
  api.orders.set('9001', makeOrder('9001', {
    ...unpaid, payment_state: 'PAID', paid_fen: unpaid.total_fen,
  })) // 模拟店主核实到账；顾客按钮本身只会读取该状态
  await page.getByRole('button', { name: '刷新核实状态' }).click()
  await expect(page.getByRole('heading', { name: '付款已核实' })).toBeVisible()
  await expect(page.locator('.offline-qr')).toHaveCount(0)
  await expect(page.getByText('店主已核实收款，订单状态已更新。')).toBeVisible()
  expect(api.requests.filter((request) => request.path === '/orders/9001' && request.method === 'POST')).toHaveLength(0)
})

test('店铺暂停时保留购物车，恢复营业后可正常提交', async ({ page }) => {
  const api = await installMockApi(page, { token: true, cart: cartSeed, shopOpen: false })
  await page.goto('checkout')
  await expect(page.getByText('店铺已暂停营业，暂不能提交新订单；购物车会保留。')).toBeVisible()
  await expect(page.getByRole('button', { name: '提交订单' })).toBeDisabled()
  expect(api.createCalls).toHaveLength(0)

  api.shopOpen = true
  await page.reload()
  await expect(page.getByRole('button', { name: '提交订单' })).toBeEnabled()
  await page.getByRole('button', { name: '提交订单' }).click()
  await expect(page).toHaveURL(/\/app\/order-result\/9001$/)
  expect(api.createCalls).toHaveLength(1)
})
