<script setup lang="ts">
import { computed } from 'vue'
import type { Order } from '@/api/types'

/**
 * 订单状态标签。
 *
 * 【为什么不直接把 fulfillment 画成一个彩色点】
 * 方案 §1.2 第 9 条：不允许把「已送达」「顾客确认收货」「已付款」
 * 混成一个布尔字段。页面上必须**同时**看到履约与付款两个状态，
 * 所以这里用两个独立标签，并且都带文字（不只靠颜色）。
 */
const props = defineProps<{
  order: Pick<
    Order,
    'fulfillment' | 'fulfillment_text' | 'payment_state' | 'payment_state_text' | 'payment_method_text'
  >
  /** 只显示付款状态 */
  paymentOnly?: boolean
}>()

const fulfillmentTone = computed(() => {
  switch (props.order.fulfillment) {
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
    <span v-if="!paymentOnly" class="pill" :class="`pill--${fulfillmentTone}`">
      {{ order.fulfillment_text }}
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
