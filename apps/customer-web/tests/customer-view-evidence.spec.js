import fs from 'node:fs/promises'
import path from 'node:path'
import { test, expect } from '@playwright/test'
import { cartSeed, installMockApi, makeOrder } from './mock-api.js'

const qr = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p3sAAAAASUVORK5CYII='

function evidencePath(name, project) {
  return path.resolve(process.cwd(), '..', 'docs', 'evidence', `web-all-flows-view-${name}-${project}.png`)
}

async function waitForArtwork(page) {
  await page.evaluate(async () => {
    await document.fonts.ready
    await Promise.all(Array.from(document.images, (image) => image.decode()))
  })
}

async function inspectAssetGeometry(page) {
  return page.locator('.asset-icon').evaluateAll(async (slots) => {
    const round = (value) => Math.round(value * 100) / 100

    const visibleComponents = (alpha, width, height) => {
      const visited = new Uint8Array(width * height)
      const queue = new Int32Array(width * height)
      const components = []

      for (let start = 0; start < width * height; start += 1) {
        if (visited[start] || alpha[start] <= 16) continue
        let head = 0
        let tail = 0
        let area = 0
        let minX = width
        let minY = height
        let maxX = 0
        let maxY = 0
        queue[tail++] = start
        visited[start] = 1

        while (head < tail) {
          const index = queue[head++]
          const x = index % width
          const y = Math.floor(index / width)
          area += 1
          minX = Math.min(minX, x)
          minY = Math.min(minY, y)
          maxX = Math.max(maxX, x + 1)
          maxY = Math.max(maxY, y + 1)

          for (let ny = Math.max(0, y - 1); ny <= Math.min(height - 1, y + 1); ny += 1) {
            for (let nx = Math.max(0, x - 1); nx <= Math.min(width - 1, x + 1); nx += 1) {
              const neighbor = ny * width + nx
              if (!visited[neighbor] && alpha[neighbor] > 16) {
                visited[neighbor] = 1
                queue[tail++] = neighbor
              }
            }
          }
        }
        components.push({ area, minX, minY, maxX, maxY })
      }

      const largest = components.reduce((best, item) => (!best || item.area > best.area ? item : best), null)
      if (!largest) return { visible: null, ignored: [] }
      const noiseLimit = Math.max(15, Math.floor(largest.area * 0.025))
      const gapFromLargest = (item) => Math.max(
        0,
        largest.minX - item.maxX,
        item.minX - largest.maxX,
        largest.minY - item.maxY,
        item.minY - largest.maxY,
      )
      const visible = components.filter((item) => item.area > noiseLimit || gapFromLargest(item) <= 2)
      const ignored = components.filter((item) => !visible.includes(item))
      return {
        visible: {
          minX: Math.min(...visible.map((item) => item.minX)),
          minY: Math.min(...visible.map((item) => item.minY)),
          maxX: Math.max(...visible.map((item) => item.maxX)),
          maxY: Math.max(...visible.map((item) => item.maxY)),
        },
        ignored,
      }
    }

    return Promise.all(slots.map(async (slot) => {
      const image = slot.querySelector('img')
      if (!image) return { source: '(missing image)', error: 'no img element' }
      await image.decode()

      const canvas = document.createElement('canvas')
      canvas.width = image.naturalWidth
      canvas.height = image.naturalHeight
      const context = canvas.getContext('2d', { willReadFrequently: true })
      if (!context) return { source: image.currentSrc, error: 'canvas unavailable' }
      context.drawImage(image, 0, 0)
      const pixelData = context.getImageData(0, 0, canvas.width, canvas.height).data
      const alpha = new Uint8Array(canvas.width * canvas.height)
      for (let index = 0; index < alpha.length; index += 1) alpha[index] = pixelData[index * 4 + 3]
      const components = visibleComponents(alpha, canvas.width, canvas.height)
      const { minX, minY, maxX, maxY } = components.visible || {}
      if (minX === undefined) return { source: image.currentSrc, error: 'empty alpha bounds' }

      const slotWidth = slot.clientWidth
      const slotHeight = slot.clientHeight
      const fit = Math.min(slotWidth / canvas.width, slotHeight / canvas.height)
      const drawWidth = canvas.width * fit
      const drawHeight = canvas.height * fit
      const alphaCenterX = (slotWidth - drawWidth) / 2 + ((minX + maxX) / 2) * fit
      const alphaCenterY = (slotHeight - drawHeight) / 2 + ((minY + maxY) / 2) * fit
      const style = getComputedStyle(image)
      const matrix = new DOMMatrix(style.transform)
      const [originX = '0px', originY = '0px'] = style.transformOrigin.split(' ')
      const ox = Number.parseFloat(originX)
      const oy = Number.parseFloat(originY)
      const centerX = ox + matrix.a * (alphaCenterX - ox) + matrix.c * (alphaCenterY - oy) + matrix.e
      const centerY = oy + matrix.b * (alphaCenterX - ox) + matrix.d * (alphaCenterY - oy) + matrix.f
      const visibleWidth = (maxX - minX) * fit * Math.hypot(matrix.a, matrix.b)
      const visibleHeight = (maxY - minY) * fit * Math.hypot(matrix.c, matrix.d)
      const transformPoint = (x, y) => ({
        x: ox + matrix.a * (x - ox) + matrix.c * (y - oy) + matrix.e,
        y: oy + matrix.b * (x - ox) + matrix.d * (y - oy) + matrix.f,
      })
      const ignoredOutsideSlot = components.ignored.every((item) => {
        const points = [
          transformPoint((slotWidth - drawWidth) / 2 + item.minX * fit, (slotHeight - drawHeight) / 2 + item.minY * fit),
          transformPoint((slotWidth - drawWidth) / 2 + item.maxX * fit, (slotHeight - drawHeight) / 2 + item.maxY * fit),
        ]
        const left = Math.min(...points.map((point) => point.x))
        const top = Math.min(...points.map((point) => point.y))
        const right = Math.max(...points.map((point) => point.x))
        const bottom = Math.max(...points.map((point) => point.y))
        return right <= 0 || bottom <= 0 || left >= slotWidth || top >= slotHeight
      })

      return {
        source: new URL(image.currentSrc).pathname,
        slot: [slotWidth, slotHeight],
        centerErrorPx: [round(centerX - slotWidth / 2), round(centerY - slotHeight / 2)],
        visibleFraction: round(Math.max(visibleWidth / slotWidth, visibleHeight / slotHeight)),
        overflow: getComputedStyle(slot).overflow,
        ignoredComponents: components.ignored.length,
        ignoredOutsideSlot,
      }
    }))
  })
}

test('顾客网页 16 个主视图逐页加载并保存桌面/手机截图', async ({ page }, testInfo) => {
  const api = await installMockApi(page, { token: true, cart: cartSeed, paymentQrUrl: qr })
  api.orders.set('9001', makeOrder('9001', { payment_method: 'OFFLINE_QR' }))

  const views = [
    { name: 'home', path: '', ready: () => page.getByRole('heading', { name: '春连鲜选' }) },
    { name: 'category', path: 'category', ready: () => page.getByText('叶菜类', { exact: true }) },
    { name: 'search', path: 'search', prepare: async () => {
      await page.getByPlaceholder('搜索店内商品').fill('小青菜')
      await page.getByRole('button', { name: '搜索', exact: true }).click()
    }, ready: () => page.locator('.goods-card').filter({ hasText: '小青菜' }) },
    { name: 'goods', path: 'goods/1', ready: () => page.getByRole('heading', { name: '小青菜' }) },
    { name: 'cart', path: 'cart', ready: () => page.locator('.cart-item') },
    { name: 'checkout', path: 'checkout', ready: () => page.getByRole('heading', { name: '确认订单' }) },
    { name: 'order-result', path: 'order-result/9001', ready: () => page.getByRole('heading', { name: '订单已提交，待付款' }) },
    { name: 'orders', path: 'orders', ready: () => page.getByRole('heading', { name: '我的订单' }) },
    { name: 'order-detail', path: 'orders/1001', ready: () => page.locator('.detail-status__title') },
    { name: 'order-edit', path: 'orders/1001/edit', ready: () => page.getByRole('heading', { name: '修改订单' }) },
    { name: 'refund-progress', path: 'orders/2005/refunds', ready: () => page.getByRole('heading', { name: '退款进度' }) },
    { name: 'addresses', path: 'addresses', ready: () => page.getByRole('heading', { name: '收货地址' }) },
    { name: 'address-edit', path: 'addresses/edit', ready: () => page.getByRole('heading', { name: '新增地址' }) },
    { name: 'login', path: 'login', ready: () => page.locator('.login-title') },
    { name: 'profile', path: 'me', ready: () => page.locator('.profile-name') },
    { name: 'about', path: 'about', ready: () => page.getByRole('heading', { name: '关于与政策' }) },
  ]

  const geometryReport = []

  for (const view of views) {
    await page.goto(view.path)
    if (view.prepare) await view.prepare()
    await expect(view.ready()).toBeVisible()
    await expect(page.locator('.page').first()).toBeVisible()
    await waitForArtwork(page)
    const geometry = await inspectAssetGeometry(page)
    for (const item of geometry) {
      expect(item.error, `${view.name}: ${item.source}`).toBeUndefined()
      expect(Math.abs(item.centerErrorPx[0]), `${view.name}: ${item.source} horizontal center`).toBeLessThanOrEqual(0.75)
      expect(Math.abs(item.centerErrorPx[1]), `${view.name}: ${item.source} vertical center`).toBeLessThanOrEqual(0.75)
      expect(item.visibleFraction, `${view.name}: ${item.source} visible size`).toBeGreaterThanOrEqual(0.78)
      expect(item.visibleFraction, `${view.name}: ${item.source} visible size`).toBeLessThanOrEqual(0.86)
      expect(item.overflow, `${view.name}: ${item.source} clips its slot`).toBe('hidden')
      expect(item.ignoredOutsideSlot, `${view.name}: ${item.source} keeps isolated debris outside the slot`).toBe(true)
    }
    geometryReport.push({ view: view.name, icons: geometry })
    await page.screenshot({ path: evidencePath(view.name, testInfo.project.name), fullPage: true })
  }

  const reportPath = path.resolve(process.cwd(), '..', 'docs', 'evidence', `web-asset-geometry-2026-10-14-${testInfo.project.name}.json`)
  await fs.writeFile(reportPath, `${JSON.stringify({ viewport: testInfo.project.name, views: geometryReport }, null, 2)}\n`, 'utf8')
})
