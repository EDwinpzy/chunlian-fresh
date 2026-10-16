<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import PageHeader from '@/components/PageHeader.vue'
import GoodsImage from '@/components/GoodsImage.vue'
import AssetIcon from '@/components/AssetIcon.vue'
import StatusPill from '@/components/StatusPill.vue'
import BottomSheet from '@/components/BottomSheet.vue'
import EmptyState from '@/components/EmptyState.vue'
import OfflineQrPanel from '@/components/OfflineQrPanel.vue'
import { api, ApiError, uuid } from '@/api/client'
import { useShopStore } from '@/stores/shop'
import { useToast } from '@/composables/toast'
import { deliveryText, formatDateTime, formatFen } from '@/utils/format'
import type { Order } from '@/api/types'

/**
 * 订单详情（方案 §5.3 UI-C10 + §3.6）。
 *
 * 关键区别（方案 §5.3）：
 *  - 「待付款」「待配送」展示「修改订单」「取消订单」，是否可点由**服务端**决定
 *  - 「配送中」「已送达」「已完成」隐藏修改/取消，只提供联系店主/售后
 *  - 已取消且退款中的订单并行显示「履约已取消 + 款项退款处理中」
 */
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
  if (!order.value || order.value.fulfillment === 'CANCELLED') return false
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
    toast.success('已确认收货')
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

onMounted(async () => {
  await Promise.all([load(), shop.loadSetting().catch(() => null)])
})
</script>

<template>
  <div class="page">
    <PageHeader title="订单详情" />

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

    <div v-else class="page-body" :class="{ 'page-body--actionbar': order.can_cancel || order.can_edit || order.can_receive }">
      <!-- 状态 -->
      <section class="detail-status">
        <h1 class="detail-status__title">{{ order.fulfillment_text }}</h1>
        <StatusPill :order="order" />

        <p v-if="order.fulfillment === 'DELIVERED' && order.receipt_state === 'UNCONFIRMED'" class="detail-status__hint">
          商家已标记送达，收到商品后请确认收货。
        </p>
        <p v-else-if="order.fulfillment === 'CANCELLED' && order.payment_state === 'REFUNDING'" class="detail-status__hint">
          订单已取消，款项退款处理中，请留意退款进度。
        </p>
        <p v-else-if="order.fulfillment === 'CANCELLED' && order.payment_state === 'UNPAID'" class="detail-status__hint">
          订单已取消，无需付款。
        </p>
        <p v-else-if="order.receivable_fen > 0 && order.fulfillment === 'DELIVERED'" class="detail-status__hint">
          待收货款 {{ formatFen(order.receivable_fen) }}，请与店主确认。
        </p>
      </section>

      <OfflineQrPanel
        v-if="showOfflineQr"
        :image-url="paymentQrUrl"
        :amount-fen="qrDueFen"
        :topup="order.revision_state === 'PENDING_TOPUP'"
        :cod="order.payment_method === 'COD'"
        :cod-delivered="order.fulfillment === 'DELIVERED' || order.fulfillment === 'FINISHED'"
        :refreshing="refreshingQr"
        @refresh="refreshQrState"
      />
      <p
        v-if="order.payment_method === 'OFFLINE_QR' && (order.revision_state === 'APPLYING' || order.payment_state === 'REFUNDING' || order.payment_state === 'PARTIAL_REFUNDED')"
        class="manual-refund-note"
        role="status"
      >
        线下退款由店主人工核实和处理，到账前不会标记为退款成功。请联系店主确认进度。
      </p>

      <!-- 收货信息 -->
      <section class="card">
        <div class="row">
          <span class="row__label">收货人</span>
          <span class="row__value">{{ order.contact.name }} · {{ order.contact.phone }}</span>
        </div>
        <div class="row row--block">
          <span class="row__label">收货地址</span>
          <span class="row__value row__value--wrap">{{ order.contact.address }}</span>
        </div>
        <div class="row">
          <span class="row__label">期望送达</span>
          <span class="row__value">{{ deliveryText(order.delivery_mode, order.expected_at) }}</span>
        </div>
        <div v-if="order.remark" class="row row--block">
          <span class="row__label">备注</span>
          <span class="row__value row__value--wrap">{{ order.remark }}</span>
        </div>

        <div class="row-ops">
          <button class="btn btn--text" type="button" @click="copyAddress">复制收货信息</button>
          <button class="btn btn--text" type="button" @click="callShop">联系店主</button>
        </div>
      </section>

      <!-- 商品 -->
      <section class="section">
        <h2 class="section-title">商品清单</h2>
        <div class="card">
          <div v-for="(line, index) in order.lines" :key="`${line.sku_id}-${index}`" class="goods-row">
            <GoodsImage :src="line.image_url" :alt="line.name" :fallback-text="line.name" radius="var(--radius-sm)" />
            <div class="goods-row__body">
              <p class="goods-row__name clamp-2">{{ line.name }}</p>
              <p class="caption">{{ line.spec }} × {{ line.qty }}</p>
            </div>
            <span class="price num">{{ formatFen(line.subtotal_fen) }}</span>
          </div>

          <hr class="divider" />

          <div class="row row--amount"><span>商品小计</span><span class="num">{{ formatFen(order.goods_total_fen) }}</span></div>
          <div class="row row--amount"><span>配送费</span><span class="num">{{ formatFen(order.delivery_fee_fen) }}</span></div>
          <div class="row row--amount row--total">
            <span>订单金额</span>
            <span class="price num">{{ formatFen(order.total_fen) }}</span>
          </div>
          <div v-if="order.paid_fen > 0" class="row row--amount"><span>已实付</span><span class="num">{{ formatFen(order.paid_fen) }}</span></div>
          <div v-if="order.refunded_fen > 0" class="row row--amount"><span>已退款</span><span class="num">{{ formatFen(order.refunded_fen) }}</span></div>
          <div v-if="order.refunding_fen > 0" class="row row--amount"><span>退款处理中</span><span class="num">{{ formatFen(order.refunding_fen) }}</span></div>
          <div v-if="order.receivable_fen > 0" class="row row--amount"><span>待收货款</span><span class="price num">{{ formatFen(order.receivable_fen) }}</span></div>
        </div>
      </section>

      <!-- 订单信息 -->
      <section class="section">
        <h2 class="section-title">订单信息</h2>
        <div class="card">
          <div class="row"><span class="row__label">订单号</span><span class="num">{{ order.order_no }}</span></div>
          <div class="row"><span class="row__label">支付方式</span><span>{{ order.payment_method_text }}</span></div>
          <div class="row"><span class="row__label">付款状态</span><span>{{ order.payment_state_text }}</span></div>
          <div v-if="order.delivered_at" class="row">
            <span class="row__label">送达时间</span><span>{{ formatDateTime(order.delivered_at) }}</span>
          </div>
          <div class="row-ops">
            <button
              v-if="order.refunded_fen > 0 || order.refunding_fen > 0"
              class="btn btn--text"
              type="button"
              @click="router.push(`/orders/${order.id}/refunds`)"
            >
              <AssetIcon name="refund" :size="18" />
              查看退款进度
            </button>
          </div>
        </div>
      </section>

      <div v-if="order.can_switch_to_cod" class="switch-cod">
        <p class="switch-cod__text">在线支付尚未开通，你可以改为货到付款让订单立即生效。</p>
        <button class="btn btn--secondary btn--sm" type="button" :disabled="acting" @click="switchToCod">
          {{ acting ? '处理中…' : '改为货到付款' }}
        </button>
      </div>
    </div>

    <!-- 底部操作 -->
    <div v-if="!loading && order && (order.can_cancel || order.can_edit || order.can_receive)" class="action-bar">
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
      <p class="sheet-text">确认已经收到全部商品？确认后订单将进入已完成。</p>
      <div v-if="order" class="receive-summary">
        <div class="row row--amount"><span>商品</span><span>{{ order.lines.length }} 种</span></div>
        <div class="row row--amount"><span>金额</span><span class="price num">{{ formatFen(order.total_fen) }}</span></div>
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
.detail-status {
  padding: calc(var(--space-4) + 4px) 0 var(--space-2);
}

.detail-status__title {
  margin: 0 0 8px;
  font-size: var(--font-title);
  font-weight: var(--weight-bold);
  color: var(--color-brand-700);
}

.detail-status__hint {
  margin: 10px 0 0;
  padding: 8px 12px;
  border-radius: var(--radius-sm);
  background: var(--color-warning-bg);
  color: var(--color-warning);
  font-size: var(--font-caption);
  line-height: 1.6;
}

.manual-refund-note {
  margin: var(--space-3) 0 0;
  padding: 10px 12px;
  border-radius: var(--radius-md);
  background: var(--color-warning-bg);
  color: var(--color-warning);
  font-size: var(--font-caption);
  line-height: 1.6;
}

.row {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: var(--space-4);
  padding: 5px 0;
  font-size: var(--font-caption);
}

.row--block {
  flex-direction: column;
  gap: 2px;
}

.row__label {
  flex: 0 0 auto;
  color: var(--color-text-secondary);
}

.row__value {
  text-align: right;
}

.row__value--wrap {
  text-align: left;
  line-height: 1.6;
  font-weight: var(--weight-semibold);
}

.row--amount {
  font-size: var(--font-caption);
  color: var(--color-text-secondary);
}

.row--total {
  font-size: var(--font-body);
  color: var(--color-text-primary);
  font-weight: var(--weight-semibold);
  padding-top: 8px;
}

.row-ops {
  margin-top: 8px;
  padding-top: 8px;
  border-top: 1px solid var(--color-divider);
  display: flex;
  justify-content: flex-end;
  gap: var(--space-5);
  font-size: var(--font-caption);
}

.goods-row {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 8px 0;
}

.goods-row :deep(.goods-image) {
  flex: 0 0 52px;
  width: 52px;
}

.goods-row__body {
  flex: 1;
  min-width: 0;
}

.goods-row__name {
  margin: 0;
  font-size: var(--font-caption);
  font-weight: var(--weight-semibold);
  line-height: 1.4;
}

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

.detail-op {
  flex: 1;
}

.detail-op--wide {
  flex: 1.4;
}

.detail-line {
  height: 18px;
  margin-top: 14px;
}

.detail-line--short {
  width: 50%;
}

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
