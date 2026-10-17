<script setup lang="ts">
import { computed } from 'vue'
import type { Order } from '@/api/types'

/**
 * 订单状态标签。
 *
 * 订单阶段与付款情况是两个独立维度，页面上分别显示，避免把送达、收货和付款合成一个状态。
 */
const props = defineProps<{
  order: Pick<
    Order,
    'order_status' | 'order_status_text' | 'payment_state' | 'payment_state_text' | 'payment_method_text'
  >
  /** 只显示付款状态 */
  paymentOnly?: boolean
}>()

const orderStatusTone = computed(() => {
  switch (props.order.order_status) {
    case 'PENDING':
      return 'info'
    case 'DELIVERING':
      return 'warning'
    case 'DELIVERED':
      return 'success'
    case 'FINISHED':
      return 'muted'
    default:
      return 'muted'
  }
})

const paymentTone = computed(() => {
  switch (props.order.payment_state) {
    case 'PAID':
      return 'success'
    case 'UNPAID':
      return 'warning'
    case 'PAYING':
    case 'REFUNDING':
      return 'warning'
    case 'REFUNDED':
    case 'PARTIAL_REFUNDED':
      return 'danger'
    default:
      return 'muted'
  }
})
</script>

<template>
  <span class="status-group">
    <span v-if="!paymentOnly" class="pill" :class="`pill--${orderStatusTone}`">
      {{ order.order_status_text }}
    </span>
    <span class="pill" :class="`pill--${paymentTone}`">
      {{ order.payment_method_text }} · {{ order.payment_state_text }}
    </span>
  </span>
</template>

<style scoped>
.status-group {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  flex-wrap: wrap;
}
</style>
