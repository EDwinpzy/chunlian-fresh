<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import GoodsImage from '@/components/GoodsImage.vue'
import BottomSheet from '@/components/BottomSheet.vue'
import EmptyState from '@/components/EmptyState.vue'
import OfflineQrPanel from '@/components/OfflineQrPanel.vue'
import { api, ApiError, uuid } from '@/api/client'
import { useShopStore } from '@/stores/shop'
import { useToast } from '@/composables/toast'
import { deliveryText, fenToYuan, formatDateTime, formatFen } from '@/utils/format'
import type { Order } from '@/api/types'

/**
 * 订单详情（重设计 2026-10-17，样板 docs/design/order-detail-redesign.html）。
 *
 * 结构（自上而下）：
 *  1. 深绿状态主卡：页头反白 + 订单状态 + 付款胶囊 + 四步进度条（规范 §2.5 允许订单状态卡用深绿底）
 *  2. 收货信息 / 商品清单 / 订单信息三张卡，卡头自带图标标题
 *  3. 扫码付款卡放页面最底部（2026-10-17 定稿），紧贴底部操作条
 *  4. 底部操作条按 can_* 显隐；取消/确认收货走 BottomSheet
 *
 * 关键区别（方案 §5.3 不变）：
 *  - 「待付款」「待配送」展示「修改订单」「取消订单」，是否可点由**服务端**决定
 *  - 「配送中」「已送达」「已完成」隐藏修改/取消，只提供联系店主/售后
 *  - 已取消且退款中的订单并行显示订单取消阶段与退款进度
 *
 * 图标用内联描边 SVG（currentColor 跟随文字色），注入方式为 v-html：
 * v-html 内容不带 scoped data 属性，所以样式只依赖继承色，不写后代选择器。
 */

/** 卡头与操作的内联描边图标 */
const ICONS = {
  chevLeft:
    '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg>',
  chevRight:
    '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 6l6 6-6 6"/></svg>',
  pin: '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s-7-5.2-7-11a7 7 0 1 1 14 0c0 5.8-7 11-7 11z"/><circle cx="12" cy="10" r="2.6"/></svg>',
  clock: '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/></svg>',
  copy: '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="11" height="11" rx="2.5"/><path d="M5 15V6a2 2 0 0 1 2-2h9"/></svg>',
  phone:
    '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M5 4h4l1.5 4L8 10.5a12.5 12.5 0 0 0 5.5 5.5l2.5-2.5 4 1.5v4a2 2 0 0 1-2 2A16.5 16.5 0 0 1 3 6a2 2 0 0 1 2-2z"/></svg>',
  bag: '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 8h12l-1.1 11a1.8 1.8 0 0 1-1.8 1.6H8.9A1.8 1.8 0 0 1 7.1 19L6 8z"/><path d="M9 10V6a3 3 0 0 1 6 0v4"/></svg>',
  receipt:
    '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 3h12v18l-3-2-3 2-3-2-3 2V3z"/><path d="M9.5 8.5h5M9.5 12.5h5"/></svg>',
  check:
    '<svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>',
} as const

const STEP_LABELS = ['已下单', '配送中', '已送达', '已完成']

const route = useRoute()
const router = useRouter()
const shop = useShopStore()
const toast = useToast()

const order = ref<Order | null>(null)
const loading = ref(true)
const failed = ref(false)

const showCancel = ref(false)
const showReceive = ref(false)
const cancelReason = ref('')
const acting = ref(false)
const refreshingQr = ref(false)
const pendingTopupFen = ref<number | null>(null)

const cancelReasons = ['临时有事', '想换别的时间', '下错了', '商品选错了', '其他']

const shopPhone = computed(() => shop.setting?.phone || '')
const paymentQrUrl = computed(() => shop.setting?.payment_qr_url?.trim() || '')
const showOfflineQr = computed(() => {
  if (!order.value || order.value.order_status === 'CANCELLED') return false
  if (order.value.payment_method === 'COD') {
    return order.value.payment_state === 'UNPAID' && paymentQrUrl.value !== ''
  }
  return order.value.payment_method === 'OFFLINE_QR'
    && (order.value.payment_state === 'UNPAID' || order.value.revision_state === 'PENDING_TOPUP')
})
const qrDueFen = computed(() => {
  if (!order.value) return null
  if (order.value.revision_state === 'PENDING_TOPUP') return pendingTopupFen.value
  if (order.value.payment_method === 'COD' && order.value.receivable_fen > 0) return order.value.receivable_fen
  return order.value.payment_state === 'UNPAID' ? order.value.total_fen : null
})

/** 手动退款说明：线下退款由店主人工核实，与扫码卡是否展示无关，放在页首提示区 */
const showManualRefundNote = computed(() => {
  if (!order.value || order.value.payment_method !== 'OFFLINE_QR') return false
  return order.value.revision_state === 'APPLYING'
    || order.value.payment_state === 'REFUNDING'
    || order.value.payment_state === 'PARTIAL_REFUNDED'
})

/* ---------- 深绿状态卡 ---------- */

/**
 * 进度步：0-3 = 当前步下标，4 = 全部完成，-1 = 已取消（不显示进度条）。
 * 只反映履约维度；付款是独立维度，由胶囊与金额分列表达（与 StatusPill 同一原则）。
 */
const activeStep = computed(() => {
  switch (order.value?.order_status) {
    case 'PENDING': return 0
    case 'DELIVERING': return 1
    case 'DELIVERED': return 2
    case 'FINISHED': return STEP_LABELS.length
    default: return -1
  }
})

function stepState(i: number): 'done' | 'cur' | 'todo' {
  const step = activeStep.value
  if (step >= STEP_LABELS.length || i < step) return 'done'
  if (i === step) return 'cur'
  return 'todo'
}

function lineOn(i: number): boolean {
  return i <= activeStep.value
}

/** 付款胶囊的圆点：未付/处理中用金色提示，已定局用白色 */
const heroDotGold = computed(() => {
  const state = order.value?.payment_state
  return state === 'UNPAID' || state === 'PAYING' || state === 'REFUNDING'
})

const heroHint = computed(() => {
  const o = order.value
  if (!o) return ''
  if (o.order_status === 'PENDING') {
    return o.payment_state === 'UNPAID'
      ? '请在店主备货前完成付款，付款后由店主核实到账。'
      : '订单已提交，店主确认后备货配送。'
  }
  if (o.order_status === 'DELIVERING') return '商品正在配送途中，请保持电话畅通。'
  if (o.order_status === 'DELIVERED') {
    return o.receivable_fen > 0
      ? '商家已标记送达，核对商品与金额后请确认收货。'
      : '商家已标记送达，收到商品后请确认收货。'
  }
  if (o.order_status === 'FINISHED') {
    return o.refunding_fen > 0 ? '订单已完成，部分退款正在处理，请留意退款进度。' : '订单已完成，感谢您的信任。'
  }
  if (o.order_status === 'CANCELLED') {
    if (o.payment_state === 'REFUNDING') return '订单已取消，款项退款处理中，请留意退款进度。'
    if (o.payment_state === 'UNPAID') return '订单已取消，无需付款。'
    return '订单已取消。'
  }
  return ''
})

/** 已送达且还有 COD 待收金额时，状态卡内用金色条强调（应收的可视面，与实收分列） */
const showHeroReceivable = computed(() => {
  const o = order.value
  return !!o && o.order_status === 'DELIVERED' && o.receivable_fen > 0
})

/* ---------- 金额区 ---------- */

const goodsCount = computed(() => (order.value ? order.value.lines.reduce((sum, line) => sum + line.qty, 0) : 0))

/**
 * 强调行：只有「已全额到账且无任何退款动作」才配叫「实付」，
 * 其余一律「应付 = 订单金额」，已实付/待收/退款在下方分行列出、绝不相加（方案原则 5）。
 */
const payEm = computed<{ label: string; fen: number }>(() => {
  const o = order.value
  if (!o) return { label: '应付', fen: 0 }
  const settled = o.paid_fen >= o.total_fen
    && o.receivable_fen === 0 && o.refunding_fen === 0 && o.refunded_fen === 0
  return settled ? { label: '实付', fen: o.total_fen } : { label: '应付', fen: o.total_fen }
})

/** 付款状态胶囊色调（与 StatusPill 的 paymentTone 同一映射） */
const paymentPillTone = computed(() => {
  switch (order.value?.payment_state) {
    case 'PAID': return 'success'
    case 'UNPAID':
    case 'PAYING':
    case 'REFUNDING': return 'warning'
    case 'REFUNDED':
    case 'PARTIAL_REFUNDED': return 'danger'
    default: return 'muted'
  }
})

const showRefundLink = computed(() => {
  const o = order.value
  return !!o && (o.refunded_fen > 0 || o.refunding_fen > 0)
})

const hasActionBar = computed(() => {
  const o = order.value
  return !!o && (o.can_cancel || o.can_edit || o.can_receive)
})

async function refreshPendingTopup(orderId: string, revisionState: string) {
  pendingTopupFen.value = null
  if (revisionState !== 'PENDING_TOPUP') return

  try {
    const response = await api.revisions(orderId)
    const pending = [...response.list].reverse().find((revision) => revision.state === 'PENDING_TOPUP')
    if (pending && pending.delta_fen > 0) pendingTopupFen.value = pending.delta_fen
  } catch {
    // Without the server's revision amount, never show the full order amount as the top-up.
  }
}

async function load() {
  loading.value = true
  failed.value = false
  try {
    const id = String(route.params.id)
    order.value = await api.orderDetail(id)
    await refreshPendingTopup(id, order.value.revision_state)
  } catch (error) {
    failed.value = true
    toast.error(error instanceof ApiError ? error.message : '读取订单失败')
  } finally {
    loading.value = false
  }
}

async function refreshQrState() {
  if (!order.value) return

  refreshingQr.value = true
  try {
    const fresh = await api.orderDetail(order.value.id)
    order.value = fresh
    await refreshPendingTopup(fresh.id, fresh.revision_state)
    if (fresh.payment_state === 'PAID' && fresh.revision_state !== 'PENDING_TOPUP') {
      toast.success('店主已核实收款，订单状态已更新')
    } else {
      toast.info('付款仍待店主核实；刷新不会自动确认收款')
    }
  } catch (error) {
    toast.error(error instanceof ApiError ? error.message : '刷新付款状态失败')
  } finally {
    refreshingQr.value = false
  }
}

async function doCancel() {
  if (!order.value) return

  acting.value = true
  try {
    order.value = await api.cancelOrder(order.value.id, order.value.version, cancelReason.value, uuid())
    showCancel.value = false
    toast.success('订单已取消')
  } catch (error) {
    toast.error(error instanceof ApiError ? error.message : '取消失败，请重试')
  } finally {
    acting.value = false
  }
}

async function doReceive() {
  if (!order.value) return

  acting.value = true
  try {
    order.value = await api.receiveOrder(order.value.id, order.value.version, uuid())
    showReceive.value = false
    toast.success('已确认收货，剩余应付金额已自动记为已付款')
  } catch (error) {
    toast.error(error instanceof ApiError ? error.message : '确认收货失败，请重试')
  } finally {
    acting.value = false
  }
}

async function switchToCod() {
  if (!order.value) return

  acting.value = true
  try {
    order.value = await api.switchToCod(order.value.id, order.value.version, uuid())
    toast.success('已改为货到付款')
  } catch (error) {
    toast.error(error instanceof ApiError ? error.message : '操作失败，请重试')
  } finally {
    acting.value = false
  }
}

function goBack() {
  if (window.history.length > 1) {
    router.back()
  } else {
    router.replace('/')
  }
}

function callShop() {
  if (shopPhone.value) {
    window.location.href = `tel:${shopPhone.value}`
  } else {
    toast.info('店主暂未留电话，请到「我的」页查看联系方式')
  }
}

async function copyAddress() {
  if (!order.value) return
  try {
    await navigator.clipboard.writeText(
      `${order.value.contact.name} ${order.value.contact.phone} ${order.value.contact.address}`,
    )
    toast.success('收货信息已复制')
  } catch {
    toast.warning('复制失败，请长按选择')
  }
}

async function copyOrderNo() {
  if (!order.value) return
  try {
    await navigator.clipboard.writeText(order.value.order_no)
    toast.success('订单号已复制')
  } catch {
    toast.warning('复制失败，请长按选择')
  }
}

onMounted(async () => {
  await Promise.all([load(), shop.loadSetting().catch(() => null)])
})
</script>

<template>
  <div class="page">
    <div v-if="loading" class="page-body">
      <div class="skeleton detail-line" />
      <div class="skeleton detail-line detail-line--short" />
    </div>

    <EmptyState
      v-else-if="failed || !order"
      icon="error"
      title="订单不存在或无权查看"
      action-text="返回订单列表"
      @action="router.replace('/orders')"
    />

    <template v-else>
      <!-- 深绿状态主卡（含页头，规范 §2.5：订单状态卡允许深绿底） -->
      <section class="od-hero">
        <svg
          class="od-hero__deco"
          viewBox="0 0 24 24"
          fill="none"
          stroke="rgba(255,255,255,.07)"
          stroke-width="1.4"
          stroke-linecap="round"
          stroke-linejoin="round"
          aria-hidden="true"
        >
          <path d="M12 21v-7" />
          <path d="M12 14C12 10 8.9 8 5 8c0 4 3.1 6 7 6z" />
          <path d="M12 12c0-3.3 2.4-5.2 6-5.2 0 3.3-2.4 5.2-6 5.2z" />
        </svg>

        <div class="od-hero__top">
          <button class="od-hero__back" type="button" aria-label="返回" @click="goBack" v-html="ICONS.chevLeft" />
          <span class="od-hero__title">订单详情</span>
          <span class="od-hero__spacer" />
        </div>

        <div class="od-hero__status">
          <h2 class="od-hero__status-text">{{ order.order_status_text }}</h2>
          <span class="od-hero__pill">
            <i class="od-hero__dot" :class="{ 'od-hero__dot--gold': heroDotGold }" />
            {{ order.payment_method_text }} · {{ order.payment_state_text }}
          </span>
        </div>

        <div v-if="activeStep >= 0" class="od-steps">
          <template v-for="(label, i) in STEP_LABELS" :key="label">
            <span v-if="i > 0" class="od-steps__line" :class="{ 'od-steps__line--on': lineOn(i) }" />
            <span class="od-steps__step" :class="`od-steps__step--${stepState(i)}`">
              <span class="od-steps__dot">
                <span v-if="stepState(i) === 'done'" class="od-steps__check" v-html="ICONS.check" />
              </span>
              <span class="od-steps__label">{{ label }}</span>
            </span>
          </template>
        </div>

        <p class="od-hero__hint">{{ heroHint }}</p>

        <div v-if="showHeroReceivable" class="od-hero__recv">
          <span>待收货款（货到付款）</span>
          <b class="num">{{ formatFen(order.receivable_fen) }}</b>
        </div>
      </section>

      <div class="od-body" :class="{ 'od-body--bar': hasActionBar }">
        <!-- 手动退款说明（线下退款由店主人工核实） -->
        <p v-if="showManualRefundNote" class="notice notice--warning" role="status">
          线下退款由店主人工核实和处理，到账前不会标记为退款成功。请联系店主确认进度。
        </p>

        <!-- 收货信息 -->
        <section class="card od-card">
          <div class="od-card__head">
            <span class="od-card__ico" aria-hidden="true" v-html="ICONS.pin" />
            <h3>收货信息</h3>
          </div>
          <p class="od-addr__name">
            {{ order.contact.name }}
            <span class="num">{{ order.contact.phone }}</span>
          </p>
          <p class="od-addr__text">{{ order.contact.address }}</p>
          <div class="od-slot">
            <span class="od-slot__ico" aria-hidden="true" v-html="ICONS.clock" />
            <span class="num">期望送达 {{ deliveryText(order.delivery_mode, order.expected_at) }}</span>
          </div>
          <p v-if="order.remark" class="od-remark"><b>备注</b>{{ order.remark }}</p>
          <div class="od-card__ops">
            <button class="od-op" type="button" @click="copyAddress">
              <span aria-hidden="true" v-html="ICONS.copy" />复制收货信息
            </button>
            <button class="od-op" type="button" @click="callShop">
              <span aria-hidden="true" v-html="ICONS.phone" />联系店主
            </button>
          </div>
        </section>

        <!-- 商品清单 -->
        <section class="card od-card">
          <div class="od-card__head">
            <span class="od-card__ico" aria-hidden="true" v-html="ICONS.bag" />
            <h3>商品清单</h3>
            <span class="od-card__extra">共 {{ goodsCount }} 件</span>
          </div>

          <div v-for="(line, index) in order.lines" :key="`${line.sku_id}-${index}`" class="od-gline">
            <GoodsImage :src="line.image_url" :alt="line.name" :fallback-text="line.name" radius="var(--radius-sm)" />
            <div class="od-gline__main">
              <p class="od-gline__name clamp-2">{{ line.name }}</p>
              <p class="od-gline__spec">{{ line.spec }} × {{ line.qty }}</p>
            </div>
            <div class="od-gline__right">
              <span class="od-gline__sub num">{{ formatFen(line.subtotal_fen) }}</span>
              <span class="od-gline__unit num">{{ formatFen(line.unit_fen) }}/份</span>
            </div>
          </div>

          <hr class="divider od-amount-divider" />

          <div class="od-amt"><span>商品小计</span><span class="num">{{ formatFen(order.goods_total_fen) }}</span></div>
          <div class="od-amt">
            <span>配送费</span>
            <span v-if="order.delivery_fee_fen === 0" class="num num--muted">免配送费</span>
            <span v-else class="num">{{ formatFen(order.delivery_fee_fen) }}</span>
          </div>
          <div class="od-amt-em">
            <span>{{ payEm.label }}</span>
            <span class="od-amt-em__big num"><i>¥</i>{{ fenToYuan(payEm.fen) }}</span>
          </div>
          <!-- 实收与应收分列，绝不相加（方案原则 5） -->
          <div v-if="payEm.label === '应付' && order.paid_fen > 0" class="od-amt">
            <span>已实付</span><span class="num">{{ formatFen(order.paid_fen) }}</span>
          </div>
          <div v-if="order.receivable_fen > 0" class="od-amt">
            <span>待收货款</span><span class="num num--price">{{ formatFen(order.receivable_fen) }}</span>
          </div>
          <div v-if="order.refunding_fen > 0" class="od-amt">
            <span>退款处理中</span><span class="num num--warn">{{ formatFen(order.refunding_fen) }}</span>
          </div>
          <div v-if="order.refunded_fen > 0" class="od-amt">
            <span>已退款</span><span class="num num--danger">{{ formatFen(order.refunded_fen) }}</span>
          </div>
        </section>

        <!-- 订单信息 -->
        <section class="card od-card">
          <div class="od-card__head">
            <span class="od-card__ico" aria-hidden="true" v-html="ICONS.receipt" />
            <h3>订单信息</h3>
          </div>
          <div class="od-kv">
            <span class="od-kv__k">订单号</span>
            <span class="od-kv__v num od-kv__order">
              {{ order.order_no }}
              <button class="od-copy" type="button" aria-label="复制订单号" @click="copyOrderNo" v-html="ICONS.copy" />
            </span>
          </div>
          <div class="od-kv">
            <span class="od-kv__k">支付方式</span>
            <span class="od-kv__v">{{ order.payment_method_text }}</span>
          </div>
          <div class="od-kv">
            <span class="od-kv__k">付款状态</span>
            <span class="od-kv__v"><span class="pill" :class="`pill--${paymentPillTone}`">{{ order.payment_state_text }}</span></span>
          </div>
          <div v-if="order.delivered_at" class="od-kv">
            <span class="od-kv__k">送达时间</span><span class="od-kv__v num">{{ formatDateTime(order.delivered_at) }}</span>
          </div>
          <div v-if="order.received_at" class="od-kv">
            <span class="od-kv__k">完成时间</span><span class="od-kv__v num">{{ formatDateTime(order.received_at) }}</span>
          </div>
          <button v-if="showRefundLink" class="od-link" type="button" @click="router.push(`/orders/${order.id}/refunds`)">
            查看退款进度
            <span aria-hidden="true" v-html="ICONS.chevRight" />
          </button>
        </section>

        <!-- 扫码付款：按 2026-10-17 定稿放页面最底部，紧贴操作条 -->
        <OfflineQrPanel
          v-if="showOfflineQr"
          :image-url="paymentQrUrl"
          :amount-fen="qrDueFen"
          :topup="order.revision_state === 'PENDING_TOPUP'"
          :cod="order.payment_method === 'COD'"
          :cod-delivered="order.order_status === 'DELIVERED' || order.order_status === 'FINISHED'"
          :refreshing="refreshingQr"
          @refresh="refreshQrState"
        />

        <div v-if="order.can_switch_to_cod" class="switch-cod">
          <p class="switch-cod__text">在线支付尚未开通，你可以改为货到付款让订单立即生效。</p>
          <button class="btn btn--secondary btn--sm" type="button" :disabled="acting" @click="switchToCod">
            {{ acting ? '处理中…' : '改为货到付款' }}
          </button>
        </div>
      </div>
    </template>

    <!-- 底部操作 -->
    <div v-if="!loading && order && hasActionBar" class="action-bar">
      <button
        v-if="order.can_cancel"
        class="btn btn--danger detail-op"
        type="button"
        @click="showCancel = true"
      >
        取消订单
      </button>
      <button
        v-if="order.can_edit"
        class="btn btn--ghost detail-op"
        type="button"
        @click="router.push(`/orders/${order.id}/edit`)"
      >
        修改订单
      </button>
      <button
        v-if="order.can_receive"
        class="btn btn--primary detail-op detail-op--wide"
        type="button"
        @click="showReceive = true"
      >
        确认收货
      </button>
    </div>

    <!-- 取消确认 -->
    <BottomSheet v-model="showCancel" title="取消订单" :mask-closable="false">
      <p class="sheet-text">
        {{ order?.paid_fen ? '取消后由店主实际退还款项并登记退款，请留意处理进度。' : '取消后库存会释放，商品需要重新下单。' }}
      </p>

      <p class="sheet-label">取消原因（选填）</p>
      <div class="reason-list">
        <button
          v-for="reason in cancelReasons"
          :key="reason"
          class="reason-chip"
          :class="{ 'is-active': cancelReason === reason }"
          type="button"
          @click="cancelReason = cancelReason === reason ? '' : reason"
        >
          {{ reason }}
        </button>
      </div>

      <template #footer>
        <button class="btn btn--ghost" type="button" @click="showCancel = false">返回</button>
        <button class="btn btn--danger" type="button" :disabled="acting" @click="doCancel">
          {{ acting ? '处理中…' : '确认取消' }}
        </button>
      </template>
    </BottomSheet>

    <!-- 确认收货 -->
    <BottomSheet v-model="showReceive" title="确认收货" :mask-closable="false">
      <p class="sheet-text">确认已经收到全部商品？确认后订单将进入已完成，剩余应付金额会自动记为已付款，适用于所有付款方式。</p>
      <div v-if="order" class="receive-summary">
        <div class="od-amt"><span>商品</span><span>{{ order.lines.length }} 种</span></div>
        <div class="od-amt"><span>金额</span><span class="num num--price">{{ formatFen(order.total_fen) }}</span></div>
      </div>

      <template #footer>
        <button class="btn btn--ghost" type="button" @click="showReceive = false">再看看</button>
        <button class="btn btn--primary" type="button" :disabled="acting" @click="doReceive">
          {{ acting ? '处理中…' : '确认收货' }}
        </button>
      </template>
    </BottomSheet>
  </div>
</template>

<style scoped>
/* ---------- 深绿状态主卡 ---------- */

.od-hero {
  position: relative;
  overflow: hidden;
  background: var(--color-brand-700);
  color: var(--color-on-brand);
  /* 页头并入状态卡后，状态栏安全区由卡片自己兜住（方案 §4.4：用运行时安全区） */
  padding: calc(env(safe-area-inset-top) + 12px) var(--page-padding) 20px;
  border-radius: 0 0 24px 24px;
}

.od-hero__deco {
  position: absolute;
  right: -16px;
  bottom: -18px;
  width: 150px;
  height: 150px;
  pointer-events: none;
}

.od-hero__top {
  position: relative;
  display: flex;
  align-items: center;
  justify-content: space-between;
  height: 40px;
}

.od-hero__back {
  width: 36px;
  height: 36px;
  border-radius: 50%;
  background: var(--color-on-brand-translucent);
  color: var(--color-on-brand);
  display: inline-flex;
  align-items: center;
  justify-content: center;
}

.od-hero__back:active {
  opacity: 0.85;
}

.od-hero__spacer {
  width: 36px;
}

.od-hero__title {
  font-size: var(--font-section);
  font-weight: var(--weight-semibold);
}

.od-hero__status {
  position: relative;
  margin-top: 10px;
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 9px;
}

.od-hero__status-text {
  margin: 0;
  font-size: var(--font-title);
  font-weight: var(--weight-bold);
  letter-spacing: 0.02em;
}

.od-hero__pill {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  background: var(--color-on-brand-translucent);
  border-radius: var(--radius-pill);
  padding: 3px 10px;
  font-size: var(--font-mini);
  color: var(--color-on-brand);
}

.od-hero__dot {
  width: 6px;
  height: 6px;
  border-radius: 50%;
  background: var(--color-on-brand);
}

.od-hero__dot--gold {
  background: var(--color-on-brand-accent);
}

.od-hero__hint {
  position: relative;
  margin: 12px 0 0;
  font-size: var(--font-caption);
  line-height: 1.7;
  color: var(--color-on-brand-muted);
}

.od-hero__recv {
  position: relative;
  margin-top: 14px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  background: var(--color-on-brand-translucent-muted);
  border-radius: var(--radius-sm);
  padding: 10px 12px;
}

.od-hero__recv span {
  font-size: var(--font-caption);
  color: var(--color-on-brand-muted);
}

/* 深绿底上的金色金额必须用 gold-300（on-brand-accent），gold-400 对比度不够 */
.od-hero__recv b {
  font-size: var(--font-product);
  font-weight: var(--weight-bold);
  color: var(--color-on-brand-accent);
}

/* ---------- 订单进度条 ---------- */

.od-steps {
  position: relative;
  margin-top: 18px;
  display: flex;
  align-items: flex-start;
}

.od-steps__step {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 6px;
  min-width: 40px;
}

.od-steps__dot {
  width: 22px;
  height: 22px;
  border-radius: 50%;
  background: var(--color-on-brand-translucent);
  display: flex;
  align-items: center;
  justify-content: center;
  color: var(--color-brand-700);
}

.od-steps__step--done .od-steps__dot {
  background: var(--color-on-brand);
}

.od-steps__step--cur .od-steps__dot {
  background: var(--color-on-brand-accent);
}

.od-steps__step--cur .od-steps__dot::after {
  content: '';
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: var(--color-brand-700);
}

.od-steps__label {
  font-size: var(--font-mini);
  line-height: 15px;
  color: var(--color-on-brand-muted);
  white-space: nowrap;
}

.od-steps__step--cur .od-steps__label {
  color: var(--color-on-brand);
  font-weight: var(--weight-semibold);
}

.od-steps__line {
  flex: 1;
  height: 2px;
  border-radius: 1px;
  background: var(--color-on-brand-translucent);
  margin-top: 10px;
  min-width: 10px;
}

.od-steps__line--on {
  background: var(--color-on-brand-muted);
}

/* ---------- 内容区 ---------- */

.od-body {
  flex: 1;
  padding: 0 var(--page-padding) 28px;
}

.od-body--bar {
  padding-bottom: calc(84px + env(safe-area-inset-bottom));
}

.od-body .notice {
  margin: 14px 0 0;
}

.od-card {
  margin-top: 14px;
}

.od-card__head {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 8px;
}

.od-card__head h3 {
  flex: 1;
  margin: 0;
  font-size: var(--font-product);
  font-weight: var(--weight-semibold);
  color: var(--color-text-primary);
}

.od-card__extra {
  font-size: var(--font-caption);
  color: var(--color-text-muted);
}

.od-card__ico {
  width: 28px;
  height: 28px;
  border-radius: var(--radius-sm);
  background: var(--color-brand-100);
  color: var(--color-brand-700);
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex: none;
}

/* 收货信息 */

.od-addr__name {
  margin: 0;
  font-size: var(--font-body);
  font-weight: var(--weight-semibold);
  color: var(--color-text-primary);
}

.od-addr__name span {
  margin-left: 8px;
  font-size: var(--font-caption);
  color: var(--color-text-secondary);
  font-weight: var(--weight-normal);
}

.od-addr__text {
  margin: 3px 0 0;
  font-size: var(--font-caption);
  line-height: 1.65;
  color: var(--color-neutral-800);
}

.od-slot {
  margin-top: 10px;
  display: flex;
  align-items: center;
  gap: 7px;
  background: var(--color-brand-50);
  color: var(--color-brand-700);
  border-radius: var(--radius-sm);
  padding: 9px 12px;
  font-size: var(--font-body);
  font-weight: var(--weight-semibold);
}

/* v-html 注入的 svg 不带 scoped 属性，必须用 :deep 才能命中 */
.od-slot :deep(svg) {
  color: var(--color-brand-600);
  flex: none;
}

.od-remark {
  margin: 8px 0 0;
  font-size: var(--font-caption);
  color: var(--color-text-secondary);
}

.od-remark b {
  font-weight: var(--weight-semibold);
  margin-right: 6px;
}

.od-card__ops {
  margin-top: 10px;
  padding-top: 10px;
  border-top: 1px solid var(--color-divider);
  display: flex;
  justify-content: flex-end;
  gap: 18px;
}

.od-op {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  color: var(--color-brand-600);
  font-size: var(--font-caption);
  font-weight: var(--weight-semibold);
}

.od-op:active {
  opacity: 0.7;
}

/* 商品清单 */

.od-gline {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 10px 0;
}

.od-gline + .od-gline {
  border-top: 1px solid var(--color-neutral-100);
}

.od-gline :deep(.goods-image) {
  flex: 0 0 52px;
  width: 52px;
}

.od-gline__main {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 3px;
}

.od-gline__name {
  margin: 0;
  font-size: var(--font-body);
  font-weight: var(--weight-semibold);
  line-height: 1.45;
}

.od-gline__spec {
  margin: 0;
  font-size: var(--font-caption);
  color: var(--color-text-muted);
}

.od-gline__right {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 3px;
}

.od-gline__sub {
  font-size: var(--font-body);
  font-weight: var(--weight-semibold);
  color: var(--color-text-primary);
}

.od-gline__unit {
  font-size: var(--font-mini);
  color: var(--color-text-muted);
}

/* 金额区 */

.od-amount-divider {
  margin: 10px 0 6px;
}

.od-amt {
  display: flex;
  justify-content: space-between;
  align-items: baseline;
  padding: 3px 0;
  font-size: var(--font-caption);
  color: var(--color-text-secondary);
}

.od-amt .num {
  color: var(--color-neutral-800);
}

/* 语义色分列：待收货款用价格色，退款中用警示色，已退款用错误色（原则 5） */
.od-amt .num--price {
  color: var(--color-price);
  font-weight: var(--weight-semibold);
}

.od-amt .num--warn {
  color: var(--color-warning);
}

.od-amt .num--danger {
  color: var(--color-danger);
}

.od-amt .num--muted {
  color: var(--color-text-muted);
}

.od-amt-em {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-top: 8px;
  padding-top: 10px;
  border-top: 1px solid var(--color-divider);
}

.od-amt-em > span:first-child {
  font-size: var(--font-body);
  font-weight: var(--weight-semibold);
  color: var(--color-text-primary);
}

.od-amt-em__big {
  display: inline-flex;
  align-items: baseline;
  font-size: var(--font-headline);
  font-weight: var(--weight-bold);
  color: var(--color-price);
}

.od-amt-em__big i {
  font-style: normal;
  font-size: var(--font-caption);
  font-weight: var(--weight-semibold);
  margin-right: 1px;
}

/* 订单信息 */

.od-kv {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: var(--space-4);
  padding: 4px 0;
  font-size: var(--font-caption);
}

.od-kv__k {
  flex: none;
  color: var(--color-text-secondary);
}

.od-kv__v {
  color: var(--color-neutral-800);
  text-align: right;
}

.od-kv__order {
  display: inline-flex;
  align-items: center;
  gap: 4px;
}

.od-copy {
  width: 22px;
  height: 22px;
  border-radius: var(--radius-xs);
  color: var(--color-brand-600);
  display: inline-flex;
  align-items: center;
  justify-content: center;
}

.od-copy:active {
  background: var(--color-brand-50);
}

.od-link {
  width: 100%;
  margin-top: 10px;
  padding-top: 10px;
  border-top: 1px solid var(--color-divider);
  display: flex;
  align-items: center;
  justify-content: space-between;
  color: var(--color-brand-600);
  font-size: var(--font-caption);
  font-weight: var(--weight-semibold);
}

/* 改为货到付款（原样保留） */

.switch-cod {
  margin-top: var(--space-4);
  padding: 12px 14px;
  border-radius: var(--radius-lg);
  background: var(--color-warning-bg);
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-3);
}

.switch-cod__text {
  margin: 0;
  font-size: var(--font-caption);
  color: var(--color-warning);
  line-height: 1.5;
}

/* 底部操作条按钮配比（原样保留） */

.detail-op {
  flex: 1;
}

.detail-op--wide {
  flex: 1.4;
}

/* 骨架屏（原样保留） */

.detail-line {
  height: 18px;
  margin-top: 14px;
}

.detail-line--short {
  width: 50%;
}

/* BottomSheet 内容（原样保留） */

.sheet-text {
  margin: 0;
  font-size: var(--font-body);
  color: var(--color-text-secondary);
  line-height: 1.6;
}

.sheet-label {
  margin: var(--space-5) 0 8px;
  font-size: var(--font-caption);
  color: var(--color-text-secondary);
  font-weight: var(--weight-semibold);
}

.reason-list {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.reason-chip {
  padding: 8px 14px;
  border-radius: var(--radius-pill);
  border: 1px solid var(--color-divider);
  background: var(--color-surface);
  font-size: var(--font-caption);
}

.reason-chip.is-active {
  border-color: var(--color-danger);
  color: var(--color-danger);
  background: var(--color-danger-bg);
}

.receive-summary {
  margin-top: var(--space-4);
}
</style>
