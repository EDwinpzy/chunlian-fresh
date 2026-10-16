/**
 * 顾客端 API 类型定义。
 *
 * 【金额约定】所有金额字段一律以 `_fen` 结尾且为**整数分**。
 * 页面上显示时统一走 `formatFen()`，绝不在业务代码里做 `/100` 的裸运算 ——
 * 浮点除法会在累加时产生分位误差（例如 3 × 3.50 元）。
 */

/** 后端统一信封。 */
export interface ApiEnvelope<T> {
  code: string
  message: string | null
  data: T
}

export interface PageData<T> {
  list: T[]
  total: number
  page: number
  page_size: number
  has_more?: boolean
}

/** 配送方式：ASAP = 尽快配送，SCHEDULED = 预约送达。 */
export type DeliveryMode = 'ASAP' | 'SCHEDULED'
export type PaymentMethod = 'WECHAT' | 'OFFLINE_QR' | 'COD'
export type Fulfillment = 'PENDING' | 'DELIVERING' | 'DELIVERED' | 'FINISHED' | 'CANCELLED'
export type PaymentState =
  | 'UNPAID'
  | 'PAYING'
  | 'PAID'
  | 'PARTIAL_REFUNDED'
  | 'REFUNDING'
  | 'REFUNDED'
export type RevisionState = 'NONE' | 'PENDING_TOPUP' | 'APPLYING' | 'COMMITTED' | 'FAILED'

export interface ShopSetting {
  shop_name: string
  shop_logo_url?: string
  shop_slogan?: string
  shop_address?: string
  business_hours?: string
  shop_description?: string
  delivery_description?: string
  home_banner_title?: string
  home_banner_subtitle?: string
  home_section_title?: string
  search_hot_words?: string[]
  notice: string
  phone: string
  payment_qr_url?: string
  delivery_fee_fen: number
  is_open: boolean
  min_lead_minutes: number
  updated_at: string
}

export interface Category {
  id: string
  name: string
  icon_url: string
  children: { id: string; name: string; icon_url: string }[]
}

export interface GoodsSummary {
  id: string
  title: string
  image_url: string
  simple_desc: string
  spec_desc: string
  inventory_unit: string
  inventory: number
  sales_count: number
  has_many_spec: boolean
  min_price_fen: number
  max_price_fen: number
  is_out_of_stock: boolean
}

export interface Sku {
  sku_id: string
  spec_text: string
  price_fen: number
  original_price_fen: number
  inventory: number
  is_out_of_stock: boolean
}

export interface GoodsDetail extends GoodsSummary {
  skus: Sku[]
  images: string[]
  content: string
  origin: string
  buy_min_number: number
  buy_max_number: number
}

export interface Address {
  id: string
  name: string
  phone: string
  address: string
  is_default: boolean
}

export interface OrderLine {
  sku_id: string
  product_id: string
  name: string
  spec: string
  image_url: string
  unit_fen: number
  qty: number
  subtotal_fen: number
}

export interface OrderContact {
  name: string
  phone: string
  address: string
}

export interface Order {
  id: string
  order_no: string
  version: number
  fulfillment: Fulfillment
  fulfillment_text: string
  payment_state: PaymentState
  payment_state_text: string
  receipt_state: string
  revision_state: RevisionState
  payment_method: PaymentMethod
  payment_method_text: string
  delivery_mode: DeliveryMode
  expected_at: string | null
  lines: OrderLine[]
  contact: OrderContact
  goods_total_fen: number
  delivery_fee_fen: number
  total_fen: number
  paid_fen: number
  refunded_fen: number
  refunding_fen: number
  receivable_fen: number
  refundable_fen: number
  delivered_at: string | null
  received_at: string | null
  remark: string
  /** 由服务端裁决，客户端只负责显示 */
  can_edit: boolean
  can_cancel: boolean
  can_receive: boolean
  can_switch_to_cod: boolean
}

export interface PreviewResult {
  lines: OrderLine[]
  goods_total_fen: number
  delivery_fee_fen: number
  total_fen: number
  delivery_mode: DeliveryMode
  shop_open: boolean
  shop_name: string
  shop_logo_url?: string
  shop_slogan?: string
  shop_address?: string
  business_hours?: string
  shop_description?: string
  delivery_description?: string
  home_banner_title?: string
  home_banner_subtitle?: string
  home_section_title?: string
  search_hot_words?: string[]
  notice: string
}

export interface RevisionPreview {
  order_version: number
  old_total_fen: number
  new_total_fen: number
  delta_fen: number
  delta_type: 'NONE' | 'TOPUP' | 'REFUND'
  priced_lines: {
    sku_id: string
    qty: number
    unit_fen: number
    priced_by: 'ORIGINAL_ORDER' | 'CURRENT_PRICE'
  }[]
  warnings: string[]
  customer_message: string
  requires_topup_before_commit?: boolean
}

export interface RevisionRecord {
  id: string
  from_version: number
  to_version: number
  state: string
  delta_fen: number
  created_at: string
  summary?: Record<string, unknown>
}

export interface AuthUser {
  user_id: string
  nickname: string
  mobile_masked: string
  avatar: string
  registered_at: string
}

export interface LoginResult {
  token: string
  expires_at: string
  user: AuthUser
}

export interface CreateOrderResult {
  order: Order | null
  pay_params: Record<string, string> | null
  payment_ready: boolean
  payment_hint: string
}

/** 购物车条目（纯本地，不下发到服务端）。 */
export interface CartItem {
  sku_id: string
  product_id: string
  name: string
  spec: string
  image_url: string
  unit_fen: number
  qty: number
  inventory: number
  added_at: number
}
