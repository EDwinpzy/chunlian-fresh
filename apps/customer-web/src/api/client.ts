import type {
  Address,
  ApiEnvelope,
  AuthUser,
  Category,
  CreateOrderResult,
  DeliveryMode,
  GoodsDetail,
  GoodsSummary,
  LoginResult,
  Order,
  PageData,
  PaymentMethod,
  PreviewResult,
  RevisionPreview,
  RevisionRecord,
  ShopSetting,
} from './types'

/**
 * 顾客端 API 客户端。
 *
 * 【为什么不用 axios】
 * 需要的只有四件事：拼 URL、带 token、带幂等键、把信封拆开。
 * 引入一个 HTTP 库换来的体积与依赖，不如自己写 100 行。
 *
 * 【幂等键为什么必须在客户端生成】
 * 服务端只认 UUID v4，且同 key 重复请求会返回**首次结果**。
 * 因此「提交订单」这类按钮必须在**点击那一刻**生成 key 并在重试时复用，
 * 而不是每次请求都新生成 —— 后者等于没有幂等。
 */

const API_PREFIX = '/api/v1/customer'

/** 认证失效时抛出的专用错误，路由守卫据此跳登录页。 */
export class UnauthorizedError extends Error {
  constructor(message = '登录已过期，请重新登录') {
    super(message)
    this.name = 'UnauthorizedError'
  }
}

/** 带业务错误码的异常，页面可以直接展示 `message`。 */
export class ApiError extends Error {
  constructor(
    public readonly code: string,
    message: string,
    public readonly payload?: unknown,
  ) {
    super(message)
    this.name = 'ApiError'
  }
}

const TOKEN_KEY = 'chunlian.customer.token'

export function getToken(): string {
  try {
    return localStorage.getItem(TOKEN_KEY) || ''
  } catch {
    return ''
  }
}

export function setToken(token: string): void {
  try {
    if (token) {
      localStorage.setItem(TOKEN_KEY, token)
    } else {
      localStorage.removeItem(TOKEN_KEY)
    }
  } catch {
    /* 隐私模式下 localStorage 不可用，退化为仅本次会话有效 */
  }
}

/** UUID v4。优先用原生实现，旧浏览器退化为随机拼装。 */
export function uuid(): string {
  const cryptoObj = globalThis.crypto as Crypto | undefined
  if (cryptoObj && typeof cryptoObj.randomUUID === 'function') {
    return cryptoObj.randomUUID()
  }

  const bytes = new Uint8Array(16)
  if (cryptoObj && typeof cryptoObj.getRandomValues === 'function') {
    cryptoObj.getRandomValues(bytes)
  } else {
    for (let i = 0; i < 16; i++) bytes[i] = Math.floor(Math.random() * 256)
  }
  bytes[6] = (bytes[6] & 0x0f) | 0x40
  bytes[8] = (bytes[8] & 0x3f) | 0x80

  const hex = Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('')
  return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`
}

interface RequestOptions {
  method?: 'GET' | 'POST' | 'PATCH' | 'PUT' | 'DELETE'
  query?: Record<string, string | number | boolean | null | undefined>
  body?: unknown
  /** 写操作的幂等键；不传则不带头（服务端会拒绝需要它的端点） */
  idempotencyKey?: string
  /** 跳过认证头（登录/注册） */
  anonymous?: boolean
  timeoutMs?: number
}

async function request<T>(path: string, options: RequestOptions = {}): Promise<T> {
  const { method = 'GET', query, body, idempotencyKey, anonymous, timeoutMs = 20000 } = options

  const url = new URL(API_PREFIX + (path.startsWith('/') ? path : `/${path}`), window.location.origin)
  if (query) {
    for (const [key, value] of Object.entries(query)) {
      if (value !== undefined && value !== null && value !== '') {
        url.searchParams.set(key, String(value))
      }
    }
  }

  const headers: Record<string, string> = { Accept: 'application/json' }
  if (body !== undefined) headers['Content-Type'] = 'application/json'
  if (idempotencyKey) headers['Idempotency-Key'] = idempotencyKey
  headers['X-Client'] = 'customer-web'

  if (!anonymous) {
    const token = getToken()
    if (token) headers.Authorization = `Bearer ${token}`
  }

  const controller = new AbortController()
  const timer = window.setTimeout(() => controller.abort(), timeoutMs)

  let response: Response
  try {
    response = await fetch(url.toString(), {
      method,
      headers,
      body: body === undefined ? undefined : JSON.stringify(body),
      signal: controller.signal,
    })
  } catch (error) {
    window.clearTimeout(timer)
    if ((error as Error)?.name === 'AbortError') {
      throw new ApiError('TIMEOUT', '网络似乎开了小差，请重试。')
    }
    throw new ApiError('NETWORK_ERROR', '网络似乎开了小差，请重试。')
  } finally {
    window.clearTimeout(timer)
  }

  let envelope: ApiEnvelope<T> | null = null
  const text = await response.text()
  if (text) {
    try {
      envelope = JSON.parse(text) as ApiEnvelope<T>
    } catch {
      // 服务端在致命错误时会返回 HTML 错误页，此时不能把 HTML 抛给用户看
      throw new ApiError('SERVER_ERROR', '服务端处理异常，请稍后重试。')
    }
  }

  if (!envelope) {
    throw new ApiError('SERVER_ERROR', '服务端没有返回内容，请稍后重试。')
  }

  if (envelope.code === 'OK') {
    return envelope.data
  }

  // 401/403 且未登录：交给上层跳登录页
  if ((envelope.code === 'FORBIDDEN' || envelope.code === 'UNAUTHORIZED') && !anonymous) {
    if (getToken()) {
      setToken('')
      throw new UnauthorizedError(envelope.message || '登录已过期，请重新登录')
    }
    throw new UnauthorizedError(envelope.message || '请先登录')
  }

  throw new ApiError(envelope.code, envelope.message || '操作失败，请重试', envelope.data)
}

export const api = {
  // ---------------- 店铺与目录（公开） ----------------
  shop: () => request<ShopSetting>('/shop'),
  categories: () => request<Category[]>('/catalog/categories'),
  goodsList: (params: {
    category_id?: string
    keyword?: string
    sort?: string
    page?: number
    page_size?: number
  }) => request<PageData<GoodsSummary>>('/catalog/goods', { query: params }),
  goodsDetail: (id: string) => request<GoodsDetail>(`/catalog/goods/${id}`),

  // ---------------- 账号 ----------------
  register: (phone: string, password: string, nickname?: string) =>
    request<LoginResult>('/auth/register', {
      method: 'POST',
      body: { phone, password, nickname: nickname || '' },
      anonymous: true,
    }),
  login: (phone: string, password: string) =>
    request<LoginResult>('/auth/login', { method: 'POST', body: { phone, password }, anonymous: true }),
  logout: () => request<null>('/auth/logout', { method: 'POST' }),
  me: () => request<AuthUser>('/auth/me'),
  updateProfile: (patch: { nickname?: string; avatar?: string }) =>
    request<AuthUser>('/auth/profile', { method: 'PATCH', body: patch }),

  // ---------------- 地址 ----------------
  addresses: () => request<Address[]>('/addresses'),
  saveAddress: (payload: {
    id?: string
    name: string
    phone: string
    address: string
    is_default?: boolean
  }) => request<Address[]>('/addresses', { method: 'POST', body: payload }),
  deleteAddress: (id: string) => request<Address[]>(`/addresses/${id}`, { method: 'DELETE' }),
  setDefaultAddress: (id: string) =>
    request<Address[]>(`/addresses/${id}/default`, { method: 'POST' }),

  // ---------------- 下单 ----------------
  preview: (payload: {
    items: { sku_id: string; qty: number }[]
    delivery_mode: DeliveryMode
    expected_at?: string | null
  }) => request<PreviewResult>('/orders/preview', { method: 'POST', body: payload }),

  createOrder: (
    payload: {
      items: { sku_id: string; qty: number }[]
      contact: { name: string; phone: string; address: string }
      delivery_mode: DeliveryMode
      expected_at?: string | null
      payment_method: PaymentMethod
      remark?: string
      client_type?: string
    },
    idempotencyKey: string,
  ) =>
    request<CreateOrderResult>('/orders', {
      method: 'POST',
      body: { ...payload, client_type: payload.client_type || 'h5' },
      idempotencyKey,
    }),

  // ---------------- 订单 ----------------
  orders: (params: { keyword?: string; status?: string; page?: number; page_size?: number }) =>
    request<PageData<Order>>('/orders', { query: params }),
  orderDetail: (id: string) => request<Order>(`/orders/${id}`),

  /** 修改商品（走修订引擎，涉及补款/退款） */
  revisionPreview: (id: string, items: { sku_id: string; qty: number }[]) =>
    request<RevisionPreview>(`/orders/${id}/revision/preview`, { method: 'POST', body: { items } }),
  revisionSubmit: (id: string, items: { sku_id: string; qty: number }[], idempotencyKey: string, clientVersion: number) =>
    // 注意：这个端点返回的是**修订结果包装**（含新订单快照），不是 Order 本身。
    // 直接当成 Order 解析会让页面拿到 undefined 的 version 而无法继续操作。
    request<{
      revision_id: number
      changed: boolean
      delta_fen: number
      revision_state: string
      applied_immediately: boolean
      requires_topup: boolean
      order: Order | null
    }>(`/orders/${id}/revision`, {
      method: 'POST',
      body: { items, client_version: clientVersion },
      idempotencyKey,
    }),
  revisions: (id: string) => request<{ list: RevisionRecord[]; total: number }>(`/orders/${id}/revisions`),

  /** 修改收货信息/时间/备注（不涉及金额，直接更新） */
  updateOrderInfo: (
    id: string,
    payload: {
      version: number
      contact?: { name: string; phone: string; address: string }
      delivery_mode?: DeliveryMode
      expected_at?: string | null
      remark?: string
    },
  ) => request<Order>(`/orders/${id}/info`, { method: 'POST', body: payload }),

  cancelOrder: (id: string, version: number, reason: string, idempotencyKey: string) =>
    request<Order>(`/orders/${id}/cancel`, {
      method: 'POST',
      body: { version, reason },
      idempotencyKey,
    }),

  receiveOrder: (id: string, version: number, idempotencyKey: string) =>
    request<Order>(`/orders/${id}/receive`, { method: 'POST', body: { version }, idempotencyKey }),

  switchToCod: (id: string, version: number, idempotencyKey: string) =>
    request<Order>(`/orders/${id}/switch-to-cod`, {
      method: 'POST',
      body: { version },
      idempotencyKey,
    }),

  refunds: (id: string) =>
    request<{ list: { refund_no: string; amount_fen: number; status: string; created_at: string }[]; total: number }>(
      `/orders/${id}/refunds`,
    ),
}
