const categories = [
  ['c1', '叶菜类'],
  ['c2', '瓜果类'],
  ['c3', '根茎类'],
  ['c4', '蛋豆类'],
  ['c5', '菌菇类'],
  ['c6', '调味配菜'],
].map(([id, name]) => ({ id, name, icon_url: '', children: [] }))

const catalog = [
  {
    id: '1', category_id: 'c1', title: '小青菜', image_url: '', simple_desc: '当天鲜采',
    spec_desc: '500 克 / 份', inventory_unit: '份', inventory: 20, sales_count: 6,
    has_many_spec: false, min_price_fen: 350, max_price_fen: 350, is_out_of_stock: false,
    skus: [{ sku_id: '101', spec_text: '500 克 / 份', price_fen: 350, original_price_fen: 350, inventory: 20, is_out_of_stock: false }],
  },
  {
    id: '2', category_id: 'c2', title: '西红柿', image_url: '', simple_desc: '酸甜多汁',
    spec_desc: '500 克起', inventory_unit: '份', inventory: 15, sales_count: 8,
    has_many_spec: true, min_price_fen: 500, max_price_fen: 900, is_out_of_stock: false,
    skus: [
      { sku_id: '201', spec_text: '500 克 / 份', price_fen: 500, original_price_fen: 500, inventory: 10, is_out_of_stock: false },
      { sku_id: '202', spec_text: '1 千克 / 袋', price_fen: 900, original_price_fen: 900, inventory: 5, is_out_of_stock: false },
    ],
  },
  {
    id: '3', category_id: 'c3', title: '土豆', image_url: '', simple_desc: '',
    spec_desc: '500 克 / 份', inventory_unit: '份', inventory: 0, sales_count: 3,
    has_many_spec: false, min_price_fen: 400, max_price_fen: 400, is_out_of_stock: true,
    skus: [{ sku_id: '301', spec_text: '500 克 / 份', price_fen: 400, original_price_fen: 400, inventory: 0, is_out_of_stock: true }],
  },
]

export function makeOrder(id, overrides = {}) {
  const options = {
    fulfillment: 'PENDING',
    payment_state: 'UNPAID',
    payment_method: 'COD',
    receipt_state: 'UNCONFIRMED',
    revision_state: 'NONE',
    contact: { name: '测试顾客', phone: '13900000001', address: '测试小区 1 栋 101 室' },
    lines: [{ sku_id: '101', product_id: '1', name: '小青菜', spec: '500 克 / 份', image_url: '', unit_fen: 350, qty: 2, subtotal_fen: 700 }],
    delivery_mode: 'ASAP',
    expected_at: null,
    remark: '',
    version: 1,
    delivered_at: null,
    received_at: null,
    paid_fen: 0,
    refunded_fen: 0,
    refunding_fen: 0,
    can_edit: true,
    can_cancel: true,
    can_receive: false,
    can_switch_to_cod: false,
  }
  Object.assign(options, overrides)

  const goodsTotal = options.lines.reduce((sum, line) => sum + line.unit_fen * line.qty, 0)
  const isDelivered = options.fulfillment === 'DELIVERED' || options.fulfillment === 'FINISHED'
  const receivable = options.payment_method === 'COD' && isDelivered && options.payment_state === 'UNPAID' ? goodsTotal + 300 : 0
  const fulfillmentText = {
    PENDING: '待配送', DELIVERING: '配送中', DELIVERED: '已送达', FINISHED: '已完成', CANCELLED: '已取消',
  }[options.fulfillment]
  const paymentText = {
    UNPAID: '未付款', PAYING: '支付确认中', PAID: '已付款', PARTIAL_REFUNDED: '部分退款', REFUNDING: '退款处理中', REFUNDED: '已退款',
  }[options.payment_state]
  const paymentMethodText = options.payment_method === 'COD'
    ? '货到付款'
    : options.payment_method === 'OFFLINE_QR'
      ? '线下扫码'
      : '微信支付'

  return {
    id: String(id), order_no: `CL20261013${String(id).padStart(6, '0')}`,
    version: options.version, fulfillment: options.fulfillment, fulfillment_text: fulfillmentText,
    payment_state: options.payment_state, payment_state_text: paymentText, receipt_state: options.receipt_state,
    revision_state: options.revision_state, payment_method: options.payment_method, payment_method_text: paymentMethodText,
    delivery_mode: options.delivery_mode, expected_at: options.expected_at,
    lines: options.lines, contact: options.contact, goods_total_fen: goodsTotal, delivery_fee_fen: 300,
    total_fen: goodsTotal + 300, paid_fen: options.paid_fen, refunded_fen: options.refunded_fen,
    refunding_fen: options.refunding_fen, receivable_fen: receivable,
    refundable_fen: Math.max(0, options.paid_fen - options.refunded_fen - options.refunding_fen),
    delivered_at: options.delivered_at, received_at: options.received_at, remark: options.remark,
    can_edit: options.can_edit, can_cancel: options.can_cancel, can_receive: options.can_receive,
    can_switch_to_cod: options.can_switch_to_cod,
  }
}

function sampleOrders() {
  const out = new Map()
  for (let i = 1; i <= 12; i++) out.set(String(1000 + i), makeOrder(1000 + i))
  out.set('2001', makeOrder('2001', { fulfillment: 'DELIVERING', can_edit: false, can_cancel: false }))
  out.set('2002', makeOrder('2002', {
    fulfillment: 'DELIVERED', delivered_at: '2026-10-13T10:00:00+08:00', can_edit: false, can_cancel: false, can_receive: true,
  }))
  out.set('2003', makeOrder('2003', { payment_method: 'WECHAT', can_switch_to_cod: true }))
  out.set('2004', makeOrder('2004', {
    fulfillment: 'DELIVERED', payment_method: 'COD', delivered_at: '2026-10-13T10:00:00+08:00',
    can_edit: false, can_cancel: false, can_receive: true,
  }))
  out.set('2005', makeOrder('2005', {
    fulfillment: 'CANCELLED', payment_state: 'REFUNDING', payment_method: 'WECHAT', paid_fen: 1000,
    refunded_fen: 0, refunding_fen: 1000, can_edit: false, can_cancel: false,
  }))
  out.set('2006', makeOrder('2006', { payment_method: 'OFFLINE_QR' }))
  out.set('2007', makeOrder('2007', {
    payment_method: 'OFFLINE_QR', payment_state: 'PAID', paid_fen: 1000, revision_state: 'PENDING_TOPUP',
  }))

  return out
}

export async function installMockApi(page, {
  token = false,
  cart = [],
  shopOpen = true,
  paymentQrUrl = '',
  failPreview = false,
  timeoutFirstOrder = false,
  authFailures = {},
  addresses: initialAddresses,
  addressSaveFailures = 0,
  orderListFailures = 0,
  revisionPreviewFailures = 0,
  scheduleSubmitFailures = 0,
  networkOffline = false,
} = {}) {
  const state = {
    requests: [],
    createCalls: [],
    addresses: initialAddresses || [
      { id: 'a1', name: '林女士', phone: '13900000001', address: '春风小区 1 栋 101 室', is_default: true },
      { id: 'a2', name: '林先生', phone: '13800000002', address: '夏日花园 2 栋 202 室', is_default: false },
    ],
    orders: sampleOrders(),
    catalog: catalog.map((item) => ({ ...item, skus: item.skus.map((sku) => ({ ...sku })) })),
    user: { user_id: 'qa-1', nickname: '测试顾客', mobile_masked: '139****0001', avatar: '', registered_at: '2026-10-13 09:00:00' },
    idempotency: new Map(),
    nextOrderId: 9000,
    shopOpen,
    paymentQrUrl,
    failPreview,
    timeoutFirstOrder,
    authFailures: { login: authFailures.login || 0, register: authFailures.register || 0 },
    addressSaveFailures,
    orderListFailures,
    revisionPreviewFailures,
    scheduleSubmitFailures,
    networkOffline,
    revisions: new Map(),
    refunds: new Map(),
  }

  for (const orderId of state.orders.keys()) {
    state.refunds.set(orderId, { list: orderId === '2005' ? [
      { refund_no: 'RFQA001', amount_fen: 1000, status: 'PROCESSING', created_at: '2026-10-13 10:00:00' },
      { refund_no: 'RFQA000', amount_fen: 500, status: 'SUCCESS', created_at: '2026-10-12 10:00:00' },
    ] : [], total: orderId === '2005' ? 2 : 0 })
  }

  const priceLines = (items) => (items || []).map(({ sku_id, qty }) => {
    const sku = state.catalog.flatMap((goods) => goods.skus).find((entry) => entry.sku_id === sku_id)
    const product = state.catalog.find((goods) => goods.skus.some((entry) => entry.sku_id === sku_id))
    if (!sku || !product || product.is_active === false || sku.is_out_of_stock || qty > sku.inventory) {
      throw new Error(`Mock inventory unavailable for SKU ${sku_id}`)
    }
    return { sku_id, product_id: product.id, name: product.title, spec: sku.spec_text, image_url: '', unit_fen: sku.price_fen, qty, subtotal_fen: sku.price_fen * qty }
  })

  // These helpers model the other role's server-side action. They are only
  // invoked by Playwright tests and are not customer HTTP routes or UI controls.
  state.confirmTopup = (orderId) => {
    const id = String(orderId)
    const revision = state.revisions.get(id)
    const order = state.orders.get(id)
    if (!revision || revision.state !== 'PENDING_TOPUP' || !order) throw new Error('No pending top-up to confirm')
    const updated = makeOrder(id, {
      ...order, lines: priceLines(revision.items), version: order.version + 1,
      revision_state: 'COMMITTED', payment_state: 'PAID',
      paid_fen: order.paid_fen + revision.delta_fen,
    })
    revision.state = 'COMMITTED'
    state.orders.set(id, updated)
    return updated
  }
  state.confirmRefund = (orderId) => {
    const id = String(orderId)
    const revision = state.revisions.get(id)
    const order = state.orders.get(id)
    if (!revision || !['APPLYING', 'CANCEL_REFUND'].includes(revision.state) || !order) throw new Error('No pending refund to confirm')
    const refunds = state.refunds.get(id) || { list: [], total: 0 }
    const refund = refunds.list.find((item) => item.refund_no === revision.refund_no)
    if (!refund || refund.status !== 'PROCESSING') throw new Error('No processing refund to confirm')
    refund.status = 'SUCCESS'
    const remaining = Math.max(0, order.refunding_fen - revision.refund_fen)
    const refunded = order.refunded_fen + revision.refund_fen
    const updated = makeOrder(id, {
      ...order, version: order.version + 1,
      revision_state: revision.state === 'CANCEL_REFUND' ? order.revision_state : (remaining ? 'APPLYING' : 'COMMITTED'),
      payment_state: remaining ? 'REFUNDING' : (refunded >= order.paid_fen ? 'REFUNDED' : 'PARTIAL_REFUNDED'),
      refunded_fen: refunded, refunding_fen: remaining,
    })
    state.orders.set(id, updated)
    return updated
  }
  state.confirmCodPayment = (orderId) => {
    const id = String(orderId)
    const order = state.orders.get(id)
    if (!order || order.payment_method !== 'COD' || order.fulfillment !== 'DELIVERED' || order.payment_state !== 'UNPAID') {
      throw new Error('No delivered unpaid COD order to confirm')
    }
    const updated = makeOrder(id, { ...order, payment_state: 'PAID', paid_fen: order.total_fen })
    state.orders.set(id, updated)
    return updated
  }

  await page.addInitScript(({ hasToken, cartItems }) => {
    if (hasToken) localStorage.setItem('chunlian.customer.token', 'mock-customer-token')
    if (cartItems.length) localStorage.setItem('chunlian.customer.cart', JSON.stringify(cartItems))
  }, { hasToken: token, cartItems: cart })

  await page.route('**/api/v1/customer/**', async (route) => {
    const request = route.request()
    const url = new URL(request.url())
    const path = url.pathname.replace('/api/v1/customer', '')
    const method = request.method()
    const body = method === 'POST' || method === 'PATCH' || method === 'PUT' ? (request.postDataJSON() || {}) : {}
    state.requests.push({ method, path, body, headers: request.headers(), url })

    const ok = (data) => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ code: 'OK', message: null, data }) })
    const fail = (code, message, status = 200) => route.fulfill({ status, contentType: 'application/json', body: JSON.stringify({ code, message, data: null }) })
    // Browser-facing API disconnection simulation; no app/server/database is involved.
    if (state.networkOffline) return route.abort('internetdisconnected')
    const authorized = request.headers().authorization === 'Bearer mock-customer-token'
    const protectedPath = path.startsWith('/addresses') || path.startsWith('/orders') || path.startsWith('/auth/me') || path.startsWith('/auth/logout') || path.startsWith('/auth/profile')
    if (protectedPath && !authorized) return fail('UNAUTHORIZED', '请先登录', 401)

    if (method === 'GET' && path === '/shop') {
      return ok({ shop_name: '春连鲜选测试店', notice: '今日鲜菜到店', phone: '01012345678', ...(state.paymentQrUrl ? { payment_qr_url: state.paymentQrUrl } : {}), delivery_fee_fen: 300, is_open: state.shopOpen, min_lead_minutes: 30, updated_at: '2026-10-13 09:00:00' })
    }
    if (method === 'GET' && path === '/catalog/categories') return ok(categories)
    if (method === 'GET' && path === '/catalog/goods') {
      const keyword = (url.searchParams.get('keyword') || '').trim()
      const categoryId = url.searchParams.get('category_id')
      let list = state.catalog.filter((item) => item.is_active !== false && (!keyword || item.title.includes(keyword)) && (!categoryId || item.category_id === categoryId))
      const pageSize = Number(url.searchParams.get('page_size') || 20)
      const pageNumber = Number(url.searchParams.get('page') || 1)
      const total = list.length
      list = list.slice((pageNumber - 1) * pageSize, pageNumber * pageSize)
      return ok({ list: list.map(({ skus, category_id, ...item }) => item), total, page: pageNumber, page_size: pageSize, has_more: pageNumber * pageSize < total })
    }
    const goodsMatch = path.match(/^\/catalog\/goods\/(\d+)$/)
    if (method === 'GET' && goodsMatch) {
      const item = state.catalog.find((goods) => goods.id === goodsMatch[1] && goods.is_active !== false)
      if (!item) return fail('NOT_FOUND', '商品不存在')
      const { category_id, ...detail } = item
      return ok({ ...detail, images: [], content: '当天采购，售完即止。', origin: '本地', buy_min_number: 1, buy_max_number: 99 })
    }
    if (method === 'POST' && (path === '/auth/login' || path === '/auth/register')) {
      const mode = path.endsWith('/login') ? 'login' : 'register'
      if (state.authFailures[mode] > 0) {
        state.authFailures[mode] -= 1
        return fail(mode === 'login' ? 'INVALID_CREDENTIALS' : 'ACCOUNT_EXISTS', mode === 'login' ? '手机号或密码错误' : '该手机号已注册')
      }
      state.user = { ...state.user, nickname: body.nickname || '测试顾客', mobile_masked: `${String(body.phone).slice(0, 3)}****${String(body.phone).slice(-4)}` }
      return ok({ token: 'mock-customer-token', expires_at: '2027-01-11 09:00:00', user: state.user })
    }
    if (method === 'GET' && path === '/auth/me') return ok(state.user)
    if (method === 'POST' && path === '/auth/logout') return ok(null)
    if (method === 'PATCH' && path === '/auth/profile') {
      state.user = { ...state.user, ...body }
      return ok(state.user)
    }
    if (method === 'GET' && path === '/addresses') return ok(state.addresses)
    if (method === 'POST' && path === '/addresses') {
      if (state.addressSaveFailures > 0) {
        state.addressSaveFailures -= 1
        return fail('ADDRESS_SAVE_FAILED', '地址保存失败，请重试')
      }
      if (body.id) {
        state.addresses = state.addresses.map((address) => address.id === body.id ? { ...address, ...body } : address)
      } else {
        const address = { ...body, id: `a${state.addresses.length + 1}`, is_default: state.addresses.length === 0 || body.is_default === true }
        state.addresses.push(address)
      }
      if (body.is_default) state.addresses = state.addresses.map((address) => ({ ...address, is_default: address.id === (body.id || state.addresses[state.addresses.length - 1].id) }))
      return ok(state.addresses)
    }
    const addressDefaultMatch = path.match(/^\/addresses\/([^/]+)\/default$/)
    if (method === 'POST' && addressDefaultMatch) {
      state.addresses = state.addresses.map((address) => ({ ...address, is_default: address.id === addressDefaultMatch[1] }))
      return ok(state.addresses)
    }
    const addressMatch = path.match(/^\/addresses\/([^/]+)$/)
    if (method === 'DELETE' && addressMatch) {
      state.addresses = state.addresses.filter((address) => address.id !== addressMatch[1])
      if (state.addresses.length && !state.addresses.some((address) => address.is_default)) state.addresses[0].is_default = true
      return ok(state.addresses)
    }
    if (method === 'POST' && path === '/orders/preview') {
      if (state.failPreview) return fail('OUT_OF_STOCK', '有商品库存不足')
      const lines = (body.items || []).map(({ sku_id, qty }) => {
        const line = priceLines([{ sku_id, qty }])[0]
        return line
      })
      const goodsTotal = lines.reduce((sum, line) => sum + line.subtotal_fen, 0)
      return ok({ lines, goods_total_fen: goodsTotal, delivery_fee_fen: 300, total_fen: goodsTotal + 300, delivery_mode: body.delivery_mode, shop_open: state.shopOpen, shop_name: '春连鲜选测试店', notice: '' })
    }
    if (method === 'POST' && path === '/orders') {
      const idem = request.headers()['idempotency-key']
      if (idem && state.idempotency.has(idem)) return ok({ order: state.orders.get(state.idempotency.get(idem)), pay_params: null, payment_ready: false, payment_hint: '在线支付暂未开通' })
      state.createCalls.push({ body, idem })
      if (body.delivery_mode === 'SCHEDULED' && state.scheduleSubmitFailures > 0) {
        state.scheduleSubmitFailures -= 1
        return fail('VALIDATION_FAILED', '预约时段刚刚被占用，请重新选择时间')
      }
      const id = String(++state.nextOrderId)
      let lines
      try {
        lines = priceLines(body.items)
      } catch {
        return fail('OUT_OF_STOCK', '有商品库存不足')
      }
      const order = makeOrder(id, {
        contact: body.contact, lines, delivery_mode: body.delivery_mode, expected_at: body.expected_at || null,
        remark: body.remark || '', payment_method: body.payment_method,
      })
      state.orders.set(id, order)
      if (idem) state.idempotency.set(idem, id)
      if (state.timeoutFirstOrder) {
        state.timeoutFirstOrder = false
        return route.abort('failed')
      }
      return ok({ order, pay_params: null, payment_ready: false, payment_hint: '在线支付暂未开通' })
    }
    if (method === 'GET' && path === '/orders') {
      if (state.orderListFailures > 0) {
        state.orderListFailures -= 1
        return fail('TEMPORARY_UNAVAILABLE', '订单加载暂不可用，请检查网络后重试')
      }
      const status = url.searchParams.get('status') || ''
      const keyword = (url.searchParams.get('keyword') || '').trim().toLocaleLowerCase()
      let list = [...state.orders.values()].sort((a, b) => Number(b.id) - Number(a.id))
      if (status === 'UNPAID') list = list.filter((order) => order.payment_state === 'UNPAID' && order.payment_method === 'WECHAT' && order.fulfillment === 'PENDING')
      else if (status) list = list.filter((order) => order.fulfillment === status && !(status === 'PENDING' && order.payment_state === 'UNPAID' && order.payment_method === 'WECHAT'))
      if (keyword) {
        list = list.filter((order) =>
          order.order_no.toLocaleLowerCase().includes(keyword)
          || order.lines.some((line) => line.name.toLocaleLowerCase().includes(keyword)),
        )
      }
      const pageNumber = Number(url.searchParams.get('page') || 1)
      const pageSize = Number(url.searchParams.get('page_size') || 10)
      const total = list.length
      list = list.slice((pageNumber - 1) * pageSize, pageNumber * pageSize)
      return ok({ list, total, page: pageNumber, page_size: pageSize, has_more: pageNumber * pageSize < total })
    }
    const orderRevisionPreview = path.match(/^\/orders\/(\d+)\/revision\/preview$/)
    if (method === 'POST' && orderRevisionPreview) {
      const order = state.orders.get(orderRevisionPreview[1])
      if (!order) return fail('NOT_FOUND', '订单不存在')
      if (state.revisionPreviewFailures > 0) {
        state.revisionPreviewFailures -= 1
        return fail('REVISION_PREVIEW_FAILED', '修改预览暂不可用，请重试')
      }
      const pricedLines = (body.items || []).map(({ sku_id, qty }) => {
        const sku = state.catalog.flatMap((goods) => goods.skus).find((entry) => entry.sku_id === sku_id)
        return { sku_id, qty, unit_fen: sku?.price_fen || 0, priced_by: 'CURRENT_PRICE' }
      })
      const newTotal = pricedLines.reduce((sum, line) => sum + line.unit_fen * line.qty, 0) + order.delivery_fee_fen
      const delta = newTotal - order.total_fen
      return ok({ order_version: order.version, old_total_fen: order.total_fen, new_total_fen: newTotal, delta_fen: delta, delta_type: delta > 0 ? 'TOPUP' : delta < 0 ? 'REFUND' : 'NONE', priced_lines: pricedLines, warnings: [], customer_message: delta === 0 ? '修改后金额不变。' : delta > 0 ? '需要补款。' : '将发起差额退款。', requires_topup_before_commit: delta > 0 && order.paid_fen > 0 })
    }
    const orderRevisionSubmit = path.match(/^\/orders\/(\d+)\/revision$/)
    if (method === 'POST' && orderRevisionSubmit) {
      const previous = state.orders.get(orderRevisionSubmit[1])
      if (!previous) return fail('NOT_FOUND', '订单不存在')
      let lines
      try {
        lines = priceLines(body.items)
      } catch {
        return fail('OUT_OF_STOCK', '有商品库存不足')
      }
      const changed = JSON.stringify(lines.map(({ sku_id, qty }) => [sku_id, qty])) !== JSON.stringify(previous.lines.map(({ sku_id, qty }) => [sku_id, qty]))
      if (!changed) return ok({ revision_id: 1, changed: false, delta_fen: 0, revision_state: previous.revision_state, applied_immediately: false, requires_topup: false, order: previous })
      const nextTotal = lines.reduce((sum, line) => sum + line.subtotal_fen, 0) + previous.delivery_fee_fen
      const delta = nextTotal - previous.total_fen
      if (previous.paid_fen > 0 && delta > 0) {
        // A paid order keeps its original items until the merchant verifies the top-up.
        const order = makeOrder(previous.id, { ...previous, version: previous.version + 1, revision_state: 'PENDING_TOPUP' })
        state.orders.set(order.id, order)
        state.revisions.set(order.id, { state: 'PENDING_TOPUP', items: body.items, delta_fen: delta, from_version: previous.version, to_version: order.version })
        return ok({ revision_id: 1, changed: true, delta_fen: delta, revision_state: 'PENDING_TOPUP', applied_immediately: false, requires_topup: true, customer_message: '补款成功后修改生效。', order })
      }
      if (previous.paid_fen > 0 && delta < 0) {
        const refundFen = Math.min(-delta, Math.max(0, previous.paid_fen - previous.refunded_fen - previous.refunding_fen))
        const refundNo = `RFQA-${orderRevisionSubmit[1]}-${previous.version + 1}`
        const order = makeOrder(previous.id, {
          ...previous, lines, version: previous.version + 1, revision_state: 'APPLYING',
          payment_state: 'REFUNDING', refunding_fen: previous.refunding_fen + refundFen,
        })
        state.orders.set(order.id, order)
        state.revisions.set(order.id, { state: 'APPLYING', items: body.items, delta_fen: delta, refund_fen: refundFen, refund_no: refundNo, from_version: previous.version, to_version: order.version })
        const refunds = state.refunds.get(order.id) || { list: [], total: 0 }
        refunds.list.unshift({ refund_no: refundNo, amount_fen: refundFen, status: 'PROCESSING', created_at: '2026-10-14 12:00:00' })
        refunds.total += 1
        state.refunds.set(order.id, refunds)
        return ok({ revision_id: 1, changed: true, delta_fen: delta, revision_state: 'APPLYING', applied_immediately: true, requires_topup: false, customer_message: '退款由店主人工核实处理。', order })
      }
      const order = makeOrder(previous.id, { ...previous, lines, version: previous.version + 1, can_edit: true, revision_state: 'COMMITTED' })
      state.orders.set(order.id, order)
      state.revisions.set(order.id, { state: 'COMMITTED', items: body.items, delta_fen: delta, from_version: previous.version, to_version: order.version })
      return ok({ revision_id: 1, changed: true, delta_fen: delta, revision_state: 'COMMITTED', applied_immediately: true, requires_topup: false, order })
    }
    const orderInfoMatch = path.match(/^\/orders\/(\d+)\/info$/)
    if (method === 'POST' && orderInfoMatch) {
      const old = state.orders.get(orderInfoMatch[1])
      if (!old) return fail('NOT_FOUND', '订单不存在')
      const order = makeOrder(old.id, { ...old, contact: body.contact || old.contact, delivery_mode: body.delivery_mode || old.delivery_mode, expected_at: body.expected_at ?? old.expected_at, remark: body.remark ?? old.remark, version: old.version + 1 })
      state.orders.set(order.id, order)
      return ok(order)
    }
    const orderAction = path.match(/^\/orders\/(\d+)\/(cancel|receive|switch-to-cod)$/)
    if (method === 'POST' && orderAction) {
      const old = state.orders.get(orderAction[1])
      if (!old) return fail('NOT_FOUND', '订单不存在')
      const [id, action] = [orderAction[1], orderAction[2]]
      let options = { ...old, version: old.version + 1 }
      if (action === 'cancel') {
        options = { ...options, fulfillment: 'CANCELLED', can_edit: false, can_cancel: false, can_receive: false }
        const refundable = Math.max(0, old.paid_fen - old.refunded_fen - old.refunding_fen)
        if (refundable > 0) {
          const refundNo = `RFQA-CANCEL-${id}-${old.version + 1}`
          options = { ...options, payment_state: 'REFUNDING', refunding_fen: old.refunding_fen + refundable }
          const refunds = state.refunds.get(id) || { list: [], total: 0 }
          refunds.list.unshift({ refund_no: refundNo, amount_fen: refundable, status: 'PROCESSING', created_at: '2026-10-14 12:00:00' })
          refunds.total += 1
          state.refunds.set(id, refunds)
          state.revisions.set(id, { state: 'CANCEL_REFUND', refund_fen: refundable, refund_no: refundNo })
        }
      }
      if (action === 'receive') options = { ...options, fulfillment: 'FINISHED', receipt_state: 'CONFIRMED', can_edit: false, can_cancel: false, can_receive: false, received_at: '2026-10-13T12:00:00+08:00' }
      if (action === 'switch-to-cod') options = { ...options, payment_method: 'COD', can_switch_to_cod: false }
      const order = makeOrder(id, options)
      state.orders.set(id, order)
      return ok(order)
    }
    const orderIdMatch = path.match(/^\/orders\/(\d+)$/)
    if (method === 'GET' && orderIdMatch) {
      const order = state.orders.get(orderIdMatch[1])
      return order ? ok(order) : fail('NOT_FOUND', '订单不存在')
    }
    const refundMatch = path.match(/^\/orders\/(\d+)\/refunds$/)
    if (method === 'GET' && refundMatch) {
      return ok(state.refunds.get(refundMatch[1]) || { list: [], total: 0 })
    }
    const revisionsMatch = path.match(/^\/orders\/(\d+)\/revisions$/)
    if (method === 'GET' && revisionsMatch) {
      const orderId = revisionsMatch[1]
      const revision = state.revisions.get(orderId)
      if (orderId === '2007' && !revision) return ok({ list: [{ id: 'r-2007', from_version: 1, to_version: 2, state: 'PENDING_TOPUP', delta_fen: 250, created_at: '2026-10-13 11:00:00' }], total: 1 })
      return ok({ list: revision ? [{ id: `r-${orderId}`, from_version: revision.from_version || 1, to_version: revision.to_version || 2, state: revision.state, delta_fen: revision.delta_fen || revision.refund_fen || 0, created_at: '2026-10-14 12:00:00' }] : [], total: revision ? 1 : 0 })
    }

    return fail('NOT_FOUND', `Mock API route missing: ${method} ${path}`, 404)
  })

  return state
}

export const cartSeed = [
  { sku_id: '101', product_id: '1', name: '小青菜', spec: '500 克 / 份', image_url: '', unit_fen: 350, qty: 2, inventory: 20, added_at: 1 },
]
