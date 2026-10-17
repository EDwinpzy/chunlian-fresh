<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api, ApiError, uuid } from '@/api/client'
import OfflineQrPanel from '@/components/OfflineQrPanel.vue'
import AssetIcon from '@/components/AssetIcon.vue'
import { useToast } from '@/composables/toast'
import { useShopStore } from '@/stores/shop'
import { deliveryText, formatFen } from '@/utils/format'
import type { Order } from '@/api/types'

/**
 * 下单结果（方案 §5.3 UI-C08）。
 *
 * 【为什么单独一页而不是弹个 toast 就跳订单详情】
 * 方案要求「在线支付确认中单独状态，不假报成功」。
 * 下单后订单可能处于三种状态（待配送 / 待付款 / 支付确认中），
 * 用一个 toast 糊过去会让顾客不知道钱到底扣了没有。
 * 这一页把「订单号 + 金额 + 付款方式 + 下一步该干什么」讲清楚。
 */
const route = useRoute()
const router = useRouter()
const toast = useToast()
const shop = useShopStore()

const order = ref<Order | null>(null)
const loading = ref(true)
const switching = ref(false)
const refreshingQr = ref(false)
const paymentQrUrl = computed(() => shop.setting?.payment_qr_url?.trim() || '')
const showOfflineQr = computed(() => {
  if (!order.value || order.value.order_status === 'CANCELLED' || order.value.payment_state !== 'UNPAID') return false
  if (order.value.payment_method === 'OFFLINE_QR') return true
  return order.value.payment_method === 'COD' && paymentQrUrl.value !== ''
})
const resultIcon = computed(() => {
  if (!order.value) return 'success'
  return order.value.order_status === 'CANCELLED' || (order.value.payment_method !== 'COD' && order.value.payment_state !== 'PAID')
    ? 'warning'
    : 'success'
})

const resultTitle = computed(() => {
  if (!order.value) return ''
  if (order.value.order_status === 'CANCELLED') return '订单已取消'
  if (order.value.payment_state === 'PAYING') return '支付确认中'
  if (order.value.payment_method === 'OFFLINE_QR' && order.value.payment_state === 'PAID') return '付款已核实'
  if (order.value.payment_method === 'COD' || order.value.payment_state === 'PAID') return '下单成功'

  return '订单已提交，待付款'
})

const resultHint = computed(() => {
  if (!order.value) return ''
  if (order.value.order_status === 'CANCELLED') return '订单已取消，无需付款。'
  if (order.value.payment_method === 'COD') {
    return paymentQrUrl.value
      ? '支持送达时扫码或现金付款；送达前请勿转账，到账后由店主核实。可截图保存二维码，在付款应用的“扫一扫”中从相册识别。'
      : '货到付款，请在收到商品时与店主核对实际收款。'
  }
  if (order.value.payment_method === 'OFFLINE_QR' && order.value.payment_state === 'PAID') return '店主已核实收款，订单状态已更新。'
  if (order.value.payment_method === 'OFFLINE_QR') return '请按订单金额扫码预付，到账后由店主核实；扫码不会自动确认付款。'
  if (order.value.payment_state === 'PAYING') return '正在确认支付结果，请稍后到订单详情查看。'

  return '订单尚未付款，请联系店主处理或改为货到付款。'
})

async function load() {
  loading.value = true
  try {
    order.value = await api.orderDetail(String(route.params.id))
  } catch (error) {
    toast.error(error instanceof ApiError ? error.message : '读取订单失败')
  } finally {
    loading.value = false
  }
}

async function refreshQrState() {
  if (!order.value) return

  refreshingQr.value = true
  try {
    // 只读取服务端订单状态；扫码结果须由店主核实，不在客户端改写付款状态。
    order.value = await api.orderDetail(order.value.id)
    if (order.value.payment_state === 'PAID') {
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

async function switchToCod() {
  if (!order.value) return

  switching.value = true
  try {
    order.value = await api.switchToCod(order.value.id, order.value.version, uuid())
    toast.success('已改为货到付款')
  } catch (error) {
    toast.error(error instanceof ApiError ? error.message : '操作失败，请重试')
  } finally {
    switching.value = false
  }
}

onMounted(() => {
  void Promise.all([load(), shop.loadSetting().catch(() => null)])
})
</script>

<template>
  <div class="page">
    <div class="page-body result-page">
      <div v-if="loading" class="result-loading">
        <div class="skeleton result-loading__icon" />
        <div class="skeleton result-loading__line" />
      </div>

      <template v-else-if="order">
        <div
          class="result-icon"
          :class="{ 'is-pending': order.order_status === 'CANCELLED' || (order.payment_method !== 'COD' && order.payment_state !== 'PAID') }"
        >
          <AssetIcon :name="resultIcon" :size="56" />
        </div>

        <h1 class="result-title">
          {{ resultTitle }}
        </h1>

        <p class="result-sub">
          {{ order.order_status_text }} · {{ order.payment_method_text }} ·
          {{ deliveryText(order.delivery_mode, order.expected_at) }}
        </p>
        <p class="result-hint">{{ resultHint }}</p>

        <OfflineQrPanel
          v-if="showOfflineQr"
          :image-url="paymentQrUrl"
          :amount-fen="order.total_fen"
          :cod="order.payment_method === 'COD'"
          :refreshing="refreshingQr"
          @refresh="refreshQrState"
        />

        <div class="card result-card">
          <div class="result-row">
            <span class="muted">订单号</span>
            <span class="num">{{ order.order_no }}</span>
          </div>
          <div class="result-row">
            <span class="muted">订单金额</span>
            <span class="price num">{{ formatFen(order.total_fen) }}</span>
          </div>
          <div class="result-row">
            <span class="muted">收货人</span>
            <span>{{ order.contact.name }} · {{ order.contact.phone }}</span>
          </div>
          <div class="result-row result-row--block">
            <span class="muted">收货地址</span>
            <span>{{ order.contact.address }}</span>
          </div>
        </div>

        <div v-if="order.can_switch_to_cod" class="result-notice">
          <p class="result-notice__text">
            当前未配置在线支付，订单处于「待付款」。你可以改为货到付款立即生效，或联系店主。
          </p>
          <button class="btn btn--secondary btn--sm" type="button" :disabled="switching" @click="switchToCod">
            {{ switching ? '处理中…' : '改为货到付款' }}
          </button>
        </div>

        <div class="result-actions">
          <button class="btn btn--primary btn--block" type="button" @click="router.replace(`/orders/${order!.id}`)">
            查看订单详情
          </button>
          <button class="btn btn--ghost btn--block" type="button" @click="router.replace('/')">继续买菜</button>
        </div>
      </template>

      <template v-else>
        <p class="muted">没能读取到订单，请到「我的订单」确认。</p>
        <button class="btn btn--primary" type="button" @click="router.replace('/orders')">查看我的订单</button>
      </template>
    </div>
  </div>
</template>

<style scoped>
.result-page {
  padding-top: calc(env(safe-area-inset-top) + 40px);
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
}

.result-icon {
  color: var(--color-success);
}

.result-icon.is-pending {
  color: var(--color-warning);
}

.result-title {
  margin: var(--space-4) 0 0;
  font-size: var(--font-title);
  font-weight: var(--weight-bold);
}

.result-sub {
  margin: 6px 0 var(--space-5);
  font-size: var(--font-caption);
  color: var(--color-text-secondary);
}

.result-hint {
  margin: -10px 0 var(--space-4);
  font-size: var(--font-caption);
  color: var(--color-text-secondary);
  line-height: 1.6;
}

.result-card {
  width: 100%;
  text-align: left;
}

.result-row {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: var(--space-4);
  padding: 6px 0;
  font-size: var(--font-caption);
}

.result-row--block {
  flex-direction: column;
  gap: 2px;
}

.result-row--block span:last-child {
  line-height: 1.6;
}

.result-notice {
  width: 100%;
  margin-top: var(--space-4);
  padding: 12px 14px;
  border-radius: var(--radius-lg);
  background: var(--color-warning-bg);
  text-align: left;
}

.result-notice__text {
  margin: 0 0 10px;
  font-size: var(--font-caption);
  color: var(--color-warning);
  line-height: 1.6;
}

.result-actions {
  width: 100%;
  margin-top: var(--space-6);
  display: flex;
  flex-direction: column;
  gap: var(--space-3);
}

.result-loading {
  width: 100%;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 16px;
}

.result-loading__icon {
  width: 56px;
  height: 56px;
  border-radius: 50%;
}

.result-loading__line {
  width: 60%;
  height: 18px;
}
</style>
