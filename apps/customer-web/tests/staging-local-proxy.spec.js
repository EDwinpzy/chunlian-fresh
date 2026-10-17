import { randomInt, randomUUID } from 'node:crypto'
import { test, expect } from '@playwright/test'

// All writes in this suite are pinned to the isolated local QA tunnel. Never
// accept a target URL from an environment variable or a test parameter.
const stagingOrigin = 'http://127.0.0.1:18099'
const expectedMarker = { environment: 'isolated-staging', database: 'chunlian_qa_20261013' }
let customerToken = ''
let merchantToken = ''
let createdOrderIds = []

async function assertStagingMarker() {
  const markerUrl = new URL('/__qa_environment', stagingOrigin)
  if (markerUrl.origin !== 'http://127.0.0.1:18099') throw new Error('staging marker path escaped loopback')

  const response = await fetch(markerUrl)
  if (!response.ok) throw new Error(`staging marker unavailable: HTTP ${response.status}`)
  const marker = await response.json()
  if (JSON.stringify(marker) !== JSON.stringify(expectedMarker)) {
    throw new Error(`staging marker mismatch; refusing write scenarios: ${JSON.stringify(marker)}`)
  }
}

async function stagingApi(method, path, { body, token, key } = {}) {
  const url = new URL(path, stagingOrigin)
  if (
    url.origin !== 'http://127.0.0.1:18099' ||
    !url.pathname.startsWith('/api/v1/customer/') && !url.pathname.startsWith('/api/v1/merchant/')
  ) {
    throw new Error(`refusing auxiliary API outside the fixed QA path: ${url.origin}${url.pathname}`)
  }
  await assertStagingMarker()

  const headers = { 'X-Client': 'customer-web' }
  if (body !== undefined) headers['Content-Type'] = 'application/json'
  if (token) headers.Authorization = `Bearer ${token}`
  if (key) headers['Idempotency-Key'] = key
  const response = await fetch(url, {
    method,
    headers,
    body: body === undefined ? undefined : JSON.stringify(body),
  })
  let envelope
  try {
    envelope = await response.json()
  } catch {
    throw new Error(`non-JSON staging response for ${method} ${url.pathname}`)
  }
  return { response, envelope }
}

async function requiredStagingApi(method, path, options = {}) {
  const { response, envelope } = await stagingApi(method, path, options)
  if (!response.ok || envelope.code !== 'OK') {
    throw new Error(`staging API ${method} ${new URL(path, stagingOrigin).pathname} failed: ${envelope.code || response.status} ${envelope.message || ''}`)
  }
  return envelope.data
}

async function ensureMerchantToken() {
  if (merchantToken) return merchantToken

  const result = await requiredStagingApi('POST', '/api/v1/merchant/auth/login', {
    body: {
      username: 'qa_admin',
      password: 'QaMerchant2026!',
      device_id: 'qa-web-staging',
    },
  })
  merchantToken = result.token
  if (!merchantToken) throw new Error('staging merchant login returned no token')
  return merchantToken
}

async function customerOrder(orderId) {
  return requiredStagingApi('GET', `/api/v1/customer/orders/${orderId}`, { token: customerToken })
}

async function merchantOrderAction(orderId, action, extra = {}) {
  const current = await customerOrder(orderId)
  const token = await ensureMerchantToken()
  return requiredStagingApi('POST', `/api/v1/merchant/orders/${orderId}/${action}`, {
    token,
    key: randomUUID(),
    body: { version: current.version, ...extra },
  })
}

async function confirmQrAmount(orderId) {
  const current = await customerOrder(orderId)
  let amount = current.receivable_fen
  if (current.revision_state === 'PENDING_TOPUP' || !Number.isInteger(amount) || amount <= 0) {
    const history = await requiredStagingApi('GET', `/api/v1/customer/orders/${orderId}/revisions`, {
      token: customerToken,
    })
    const pending = [...(history.list || [])].reverse().find((revision) => revision.state === 'PENDING_TOPUP')
    amount = pending?.delta_fen
  }
  if (!Number.isInteger(amount) || amount <= 0) {
    throw new Error(`QA order ${orderId} has no positive QR amount to verify`)
  }
  return merchantOrderAction(orderId, 'qr-receipt', { amount_fen: amount })
}

async function confirmPendingRefund(orderId) {
  const current = await customerOrder(orderId)
  if (!Number.isInteger(current.refunding_fen) || current.refunding_fen <= 0) {
    throw new Error(`QA order ${orderId} has no pending refund to verify`)
  }
  return merchantOrderAction(orderId, 'manual-refund', { amount_fen: current.refunding_fen })
}

async function registerCustomer(page) {
  const phone = `139${String(randomInt(0, 100_000_000)).padStart(8, '0')}`
  const password = `Qa${randomInt(10_000, 99_999)}Fresh!`
  await page.goto('login')
  await page.getByRole('button', { name: '注册', exact: true }).click()
  await page.locator('input[type="tel"]').fill(phone)
  await page.locator('input[type="password"]').fill(password)
  await page.getByPlaceholder('选填，例如：张先生').fill('staging 网页顾客')
  await page.locator('.agree__box').click()
  await page.getByRole('button', { name: '注册并登录' }).click()
  await expect(page).toHaveURL(/\/app\/me$/)
  customerToken = await page.evaluate(() => localStorage.getItem('chunlian.customer.token') || '')
  expect(customerToken).not.toBe('')
  return { phone, password }
}

async function stagingGoods(page) {
  const response = await page.request.get('/api/v1/customer/catalog/goods?page=1&page_size=50')
  expect(response.ok()).toBeTruthy()
  const envelope = await response.json()
  expect(envelope.code).toBe('OK')
  const item = (envelope.data?.list || []).find((goods) => !goods.is_out_of_stock && !goods.has_many_spec)
  expect(item, '隔离 staging 应有至少一个在售单规格商品').toBeTruthy()
  return item
}

async function addFromHome(page, goods) {
  await page.goto('./')
  const card = page.locator('.goods-card').filter({ hasText: goods.title })
  await expect(card).toBeVisible()
  await card.getByRole('button', { name: `加入购物车：${goods.title}` }).click()
  await expect(page.getByText('已加入购物车')).toBeVisible()
}

async function displayedOrderQuantity(page, goodsTitle) {
  const texts = await page.locator('.od-gline').filter({ hasText: goodsTitle }).allTextContents()
  return texts.reduce((sum, text) => sum + Number(text.match(/×\s*(\d+)/)?.[1] || 0), 0)
}

test.beforeAll(async () => {
  await assertStagingMarker()
})

test.beforeEach(() => {
  customerToken = ''
  merchantToken = ''
  createdOrderIds = []
})

test.afterEach(async ({}, testInfo) => {
  if (testInfo.status === 'passed' || !createdOrderIds.length || !customerToken) return

  // Keep interrupted runs from leaving test orders in a live or refund-pending
  // state. Cleanup uses the same fixed marker and fixed loopback API guard.
  for (const orderId of createdOrderIds) {
    try {
      let current = await customerOrder(orderId)
      if (current.can_cancel) {
        await requiredStagingApi('POST', `/api/v1/customer/orders/${orderId}/cancel`, {
          token: customerToken,
          key: randomUUID(),
          body: { version: current.version, reason: 'staging web e2e failure cleanup' },
        })
        current = await customerOrder(orderId)
      }
      if (current.refunding_fen > 0) await confirmPendingRefund(orderId)
    } catch {
      // Preserve the original test failure; staging order state remains auditable.
    }
  }
})

test('隔离 staging：分类失败重试、切分类、搜索和访客加购', async ({ page }) => {
  const goods = await stagingGoods(page)
  const categoriesResponse = await page.request.get('/api/v1/customer/catalog/categories')
  expect(categoriesResponse.ok()).toBeTruthy()
  const categoriesEnvelope = await categoriesResponse.json()
  expect(categoriesEnvelope.code).toBe('OK')
  const categories = categoriesEnvelope.data || []
  expect(categories.length).toBeGreaterThan(1)

  let failedFirstCategoryRequest = false
  await page.route('**/api/v1/customer/catalog/goods*', async (route) => {
    if (!failedFirstCategoryRequest && route.request().method() === 'GET') {
      failedFirstCategoryRequest = true
      await route.abort('failed')
      return
    }
    await route.continue()
  })

  await page.goto('category')
  await expect(page.getByText('加载失败', { exact: true })).toBeVisible()
  await page.getByRole('button', { name: '重试', exact: true }).click()
  await expect(page.locator('.goods-card').first()).toBeVisible()
  expect(failedFirstCategoryRequest).toBeTruthy()

  const categoryButton = page.getByRole('button', { name: categories[1].name, exact: true })
  await categoryButton.click()
  await expect(categoryButton).toHaveClass(/is-active/)

  await page.unrouteAll()
  await page.goto('search')
  await page.locator('#search-input').fill(goods.title)
  await page.getByRole('button', { name: '搜索', exact: true }).click()
  const result = page.locator('.goods-card').filter({ hasText: goods.title })
  await expect(result).toBeVisible()
  await result.getByRole('button', { name: `加入购物车：${goods.title}` }).click()
  await expect(page.getByText('已加入购物车')).toBeVisible()
  await page.goto('cart')
  await expect(page.getByText(goods.title, { exact: false })).toBeVisible()
  await page.screenshot({ path: 'test-results/web-catalog-search.png', fullPage: true })
})

test('隔离 staging：顾客账户地址、QR 补款退款、配送收货和 COD 取消完整流程', async ({ page }) => {
  const { phone, password } = await registerCustomer(page)

  // Verify login against the saved credential, then exercise the web profile edit
  // and reload to prove the server value and session both persist.
  await page.goto('login')
  await page.locator('input[type="tel"]').fill(phone)
  await page.locator('input[type="password"]').fill(password)
  await page.locator('.agree__box').click()
  await page.locator('.login-submit').click()
  await expect(page).toHaveURL(/\/app\/me$/)
  await page.getByRole('button', { name: '修改昵称' }).click()
  await page.getByLabel('昵称').fill('staging 网页昵称已修改')
  await page.getByRole('dialog').getByRole('button', { name: '保存', exact: true }).click()
  await expect(page.locator('.profile-name')).toHaveText('staging 网页昵称已修改')
  await page.reload()
  await expect(page.locator('.profile-name')).toHaveText('staging 网页昵称已修改')
  await page.screenshot({ path: 'test-results/web-profile-nickname.png', fullPage: true })

  // Address create, edit, default selection, and deletion are all performed
  // through the customer UI with this synthetic account.
  await page.goto('addresses')
  await page.getByRole('button', { name: '新增收货地址' }).click()
  await page.getByLabel('收货人').fill('QA 首选收货人')
  await page.getByLabel('手机号').fill(phone)
  await page.getByLabel('详细地址').fill('隔离测试街道 18 号')
  await page.getByRole('button', { name: '保存', exact: true }).click()
  await expect(page.locator('.addr-card')).toHaveCount(1)

  await page.getByRole('button', { name: '新增收货地址' }).click()
  await page.getByLabel('收货人').fill('QA 备用收货人')
  await page.getByLabel('手机号').fill(phone)
  await page.getByLabel('详细地址').fill('隔离测试街道 28 号')
  await page.getByRole('button', { name: '保存', exact: true }).click()
  await expect(page.locator('.addr-card')).toHaveCount(2)
  const secondAddress = page.locator('.addr-card').filter({ hasText: 'QA 备用收货人' })
  await secondAddress.getByRole('button', { name: '设为默认' }).click()
  await expect(secondAddress.getByText('默认', { exact: true })).toBeVisible()

  const firstAddress = page.locator('.addr-card').filter({ hasText: 'QA 首选收货人' })
  await firstAddress.getByRole('button', { name: '编辑' }).click()
  await page.getByLabel('详细地址').fill('隔离测试街道 19 号（已修改）')
  await page.getByRole('button', { name: '保存', exact: true }).click()
  await expect(page.locator('.addr-card').filter({ hasText: '隔离测试街道 19 号（已修改）' })).toBeVisible()
  const editedAddress = page.locator('.addr-card').filter({ hasText: 'QA 首选收货人' })
  await editedAddress.getByRole('button', { name: '删除' }).click()
  await page.getByRole('dialog').getByRole('button', { name: '确认删除' }).click()
  await expect(page.locator('.addr-card')).toHaveCount(1)
  await expect(page.locator('.addr-card').filter({ hasText: 'QA 备用收货人' }).getByText('默认', { exact: true })).toBeVisible()

  const goods = await stagingGoods(page)
  await addFromHome(page, goods)
  await page.goto('checkout')
  await expect(page.getByRole('heading', { name: '确认订单' })).toBeVisible()
  await expect(page.locator('.address-card')).toContainText('QA 备用收货人')

  // Select a cross-day slot explicitly and choose offline QR prepayment.
  await page.getByRole('button', { name: /预约送达/ }).click()
  await page.locator('.schedule-picker').click()
  const timeGroups = page.locator('.time-picker__group')
  await timeGroups.nth(0).getByRole('button', { name: /明天/ }).click()
  await timeGroups.nth(1).getByRole('button', { name: '18', exact: true }).click()
  await timeGroups.nth(2).getByRole('button', { name: '30', exact: true }).click()
  await page.getByRole('dialog').getByRole('button', { name: '确定', exact: true }).click()
  await page.getByRole('button', { name: /扫码预付/ }).click()
  await expect(page.getByText(/系统不会自动确认付款/)).toBeVisible()
  await page.getByPlaceholder('例如：到了电话联系、放门口、不要塑料袋').fill('staging 网页 QR 预约测试')
  await page.getByRole('button', { name: '提交订单并查看收款码' }).click()
  await expect(page).toHaveURL(/\/app\/order-result\/\d+$/)
  await expect(page.getByRole('heading', { name: '订单已提交，待付款' })).toBeVisible()
  await expect(page.locator('.offline-qr__image')).toBeVisible()
  await expect.poll(() => page.locator('.offline-qr__image').evaluate((image) => image.complete && image.naturalWidth > 0)).toBe(true)

  const qrOrderId = new URL(page.url()).pathname.split('/').at(-1)
  createdOrderIds.push(qrOrderId)
  await expect.poll(() => page.evaluate(() => localStorage.getItem('chunlian.customer.token') || '')).not.toBe('')
  customerToken = await page.evaluate(() => localStorage.getItem('chunlian.customer.token') || '')
  let qrOrder = await customerOrder(qrOrderId)
  expect(qrOrder.delivery_mode).toBe('SCHEDULED')
  expect(new Date(qrOrder.expected_at).getTime()).toBeGreaterThan(Date.now() + 8 * 60 * 60 * 1000)
  expect(qrOrder.payment_state).toBe('UNPAID')
  expect(qrOrder.remark).toBe('staging 网页 QR 预约测试')
  await page.screenshot({ path: 'test-results/web-qr-unpaid.png', fullPage: true })

  // Viewing or refreshing the QR must not mark the order paid. The merchant
  // confirms the exact amount through the guarded isolated QA API helper.
  await page.getByRole('button', { name: '刷新核实状态' }).click()
  await expect(page.getByText(/付款仍待店主核实/)).toBeVisible()
  await expect(page.getByRole('heading', { name: '订单已提交，待付款' })).toBeVisible()
  await confirmQrAmount(qrOrderId)
  await page.getByRole('button', { name: '刷新核实状态' }).click()
  await expect(page.getByRole('heading', { name: '付款已核实' })).toBeVisible()

  await page.getByRole('button', { name: '查看订单详情' }).click()
  await expect(page.getByText('staging 网页 QR 预约测试')).toBeVisible()
  await expect(page.locator('.od-slot')).toContainText(/预约 .*18:30/)

  // Info-only edit path should save without changing quantity or payment state.
  await page.getByRole('button', { name: '修改订单' }).click()
  await page.getByPlaceholder('例如：放门口').fill('staging 网页备注已修改')
  await page.getByRole('button', { name: '保存修改' }).click()
  await expect(page.getByText('staging 网页备注已修改')).toBeVisible()
  await expect(page.locator('.od-gline').filter({ hasText: goods.title })).toContainText('× 1')

  // Increasing paid QR order quantity creates an unapplied top-up. Existing
  // lines remain unchanged until the merchant confirms the top-up amount.
  await page.getByRole('button', { name: '修改订单' }).click()
  await page.locator('.edit-row').filter({ hasText: goods.title }).getByRole('button', { name: '增加数量' }).click()
  await page.getByRole('button', { name: '预览修改' }).click()
  await expect(page.getByRole('dialog').getByText('需补付', { exact: true })).toBeVisible()
  await page.getByRole('dialog').getByRole('button', { name: '确认变更' }).click()
  await expect(page.getByRole('heading', { name: '扫码补款' })).toBeVisible()
  await expect(page.locator('.od-gline').filter({ hasText: goods.title })).toContainText('× 1')
  qrOrder = await customerOrder(qrOrderId)
  expect(qrOrder.revision_state).toBe('PENDING_TOPUP')
  expect(qrOrder.payment_state).toBe('PAID')
  await confirmQrAmount(qrOrderId)
  await page.getByRole('button', { name: '刷新核实状态' }).click()
  await expect.poll(() => displayedOrderQuantity(page, goods.title)).toBe(2)

  // Decreasing the paid order exposes an in-flight manual refund to the customer.
  await page.getByRole('button', { name: '修改订单' }).click()
  await page.locator('.edit-row').filter({ hasText: goods.title }).getByRole('button', { name: '减少数量' }).click()
  await page.getByRole('button', { name: '预览修改' }).click()
  await expect(page.getByRole('dialog').getByText('将退还', { exact: true })).toBeVisible()
  await page.getByRole('dialog').getByRole('button', { name: '确认变更' }).click()
  await expect(page.getByText(/线下退款由店主人工核实和处理/)).toBeVisible()
  await expect.poll(() => displayedOrderQuantity(page, goods.title)).toBe(1)
  await page.getByRole('button', { name: '查看退款进度' }).click()
  await expect(page.getByRole('heading', { name: '退款进度' })).toBeVisible()
  await expect(page.getByText('等待店主线下退款并核实', { exact: true })).toBeVisible()
  await expect(page.locator('.toast')).toHaveCount(0)
  await page.screenshot({ path: 'test-results/web-refund-pending.png', fullPage: true })
  await confirmPendingRefund(qrOrderId)
  await page.reload()
  await expect(page.getByText('店主已确认退款', { exact: true })).toBeVisible()
  await page.getByRole('button', { name: '返回订单详情' }).click()
  await expect(page.getByText('已退款', { exact: true })).toBeVisible()

  // Merchant delivery transitions and customer receipt confirmation are
  // independently verified on the customer detail page.
  await merchantOrderAction(qrOrderId, 'deliver')
  await page.reload()
  await expect(page.locator('.od-hero__status-text')).toHaveText('配送中')
  await expect(page.getByRole('button', { name: '修改订单' })).toHaveCount(0)
  await merchantOrderAction(qrOrderId, 'confirm-delivery', { collected_on_delivery: false })
  await page.reload()
  await expect(page.locator('.od-hero__status-text')).toHaveText('已送达')
  await page.getByRole('button', { name: '确认收货' }).click()
  await page.getByRole('dialog').getByRole('button', { name: '确认收货' }).click()
  await expect(page.locator('.od-hero__status-text')).toHaveText('已完成')
  await expect(page.getByRole('dialog')).toHaveCount(0)
  await expect(page.locator('.toast')).toHaveCount(0)
  await page.screenshot({ path: 'test-results/web-order-finished.png', fullPage: true })

  // The COD order exercises its distinct checkout and cancellation path.
  await addFromHome(page, goods)
  await page.goto('checkout')
  await expect(page.getByRole('button', { name: '货到付款' })).toHaveClass(/is-active/)
  await page.getByPlaceholder('例如：到了电话联系、放门口、不要塑料袋').fill('staging 网页 COD 取消测试')
  await page.getByRole('button', { name: '提交订单' }).click()
  await expect(page).toHaveURL(/\/app\/order-result\/\d+$/)
  const codOrderId = new URL(page.url()).pathname.split('/').at(-1)
  createdOrderIds.push(codOrderId)
  await expect(page.getByRole('heading', { name: '货到扫码' })).toBeVisible()
  await expect(page.getByText(/送达时扫码或现金付款/)).toBeVisible()
  await expect.poll(() => page.locator('.offline-qr__image').evaluate((image) => image.complete && image.naturalWidth > 0)).toBe(true)
  await page.screenshot({ path: 'test-results/web-cod-qr.png', fullPage: true })
  await page.getByRole('button', { name: '查看订单详情' }).click()
  await expect(page.getByRole('heading', { name: '货到扫码' })).toBeVisible()
  await expect(page.locator('.od-hero__status')).toContainText('货到付款')
  await page.getByRole('button', { name: '取消订单' }).click()
  await page.getByRole('dialog').getByRole('button', { name: '下错了' }).click()
  await page.getByRole('dialog').getByRole('button', { name: '确认取消' }).click()
  await expect(page.locator('.od-hero__status-text')).toHaveText('已取消')
  await expect(page.locator('.offline-qr')).toHaveCount(0)
  const cancelledCodOrder = await customerOrder(codOrderId)
  expect(cancelledCodOrder.order_status).toBe('CANCELLED')
  await page.screenshot({ path: 'test-results/web-cod-cancelled.png', fullPage: true })
  await page.goto(`order-result/${codOrderId}`)
  await expect(page.getByRole('heading', { name: '订单已取消' })).toBeVisible()
  await expect(page.locator('.offline-qr')).toHaveCount(0)

  // Verify customer order filters against the completed QR order and cancelled COD order.
  await page.goto('orders')
  await page.getByRole('button', { name: '已完成', exact: true }).click()
  await expect(page.locator('.order-card')).toHaveCount(1)
  await expect(page.locator('.order-card').first()).toContainText(goods.title)
  await page.getByRole('button', { name: '已取消', exact: true }).click()
  await expect(page.locator('.order-card')).toHaveCount(1)
  await expect(page.locator('.order-card').first()).toContainText(`#${cancelledCodOrder.order_no}`)
  await expect(page.locator('.order-card').first()).toContainText(goods.title)
})
