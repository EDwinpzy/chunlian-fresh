<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import PageHeader from '@/components/PageHeader.vue'
import AssetIcon from '@/components/AssetIcon.vue'
import EmptyState from '@/components/EmptyState.vue'
import { api, ApiError } from '@/api/client'
import { useToast } from '@/composables/toast'
import { formatDateTime, formatFen } from '@/utils/format'
import type { Order } from '@/api/types'

/**
 * 退款进度（方案 §5.3 UI-C13）。
 *
 * 方案 §3.7 明确「部分退款已申请但未到账 → 显示退款处理中，不记为退款成功」。
 * 因此这里的步骤必须把「受理」与「到账」分成两步，
 * 而不是一个二值的「退款中/已退款」。
 */
const route = useRoute()
const router = useRouter()
const toast = useToast()

const order = ref<Order | null>(null)
const refunds = ref<{ refund_no: string; amount_fen: number; status: string; created_at: string }[]>([])
const loading = ref(true)

const STATUS_TEXT: Record<string, string> = {
  PENDING: '已受理，等待提交',
  PROCESSING: '支付平台处理中',
  SUCCESS: '已退款到账',
  FAILED: '退款失败，将人工处理',
  CLOSED: '已关闭',
}

function statusText(status: string): string {
  if (order.value?.payment_method === 'OFFLINE_QR') {
    if (status === 'PENDING' || status === 'PROCESSING') return '等待店主线下退款并核实'
    if (status === 'SUCCESS') return '店主已确认退款'
  }
  return STATUS_TEXT[status] || status
}

function statusTone(status: string): string {
  if (status === 'SUCCESS') return 'success'
  if (status === 'FAILED') return 'danger'

  return 'warning'
}

async function load() {
  loading.value = true
  try {
    const id = String(route.params.id)
    const [o, r] = await Promise.all([api.orderDetail(id), api.refunds(id)])
    order.value = o
    refunds.value = r.list
  } catch (error) {
    toast.error(error instanceof ApiError ? error.message : '读取退款进度失败')
  } finally {
    loading.value = false
  }
}

onMounted(load)
</script>

<template>
  <div class="page">
    <PageHeader title="退款进度" />

    <div class="page-body">
      <div v-if="loading" class="skeleton detail-line" />

      <template v-else-if="order">
        <section class="card refund-head">
          <div class="refund-head__row">
            <span class="caption">订单号</span>
            <span class="num">{{ order.order_no }}</span>
          </div>
          <div class="refund-head__row">
            <span class="caption">订单金额</span>
            <span class="num">{{ formatFen(order.total_fen) }}</span>
          </div>
          <div class="refund-head__row">
            <span class="caption">已退款</span>
            <span class="num">{{ formatFen(order.refunded_fen) }}</span>
          </div>
          <div class="refund-head__row">
            <span class="caption">处理中</span>
            <span class="num">{{ formatFen(order.refunding_fen) }}</span>
          </div>
        </section>

        <EmptyState
          v-if="refunds.length === 0"
          icon="empty"
          title="暂无退款记录"
          description="如果刚刚发起退款，记录可能需要几秒钟才出现，稍后下拉刷新。"
          action-text="刷新"
          @action="load"
        />

        <section v-else class="section">
          <h2 class="section-title">
            <span class="asset-heading"><AssetIcon name="refund" :size="22" />退款记录</span>
          </h2>

          <ul class="refund-list">
            <li v-for="item in refunds" :key="item.refund_no" class="refund-item">
              <div class="refund-item__head">
                <span class="pill" :class="`pill--${statusTone(item.status)}`">
                  {{ statusText(item.status) }}
                </span>
                <span class="price num">{{ formatFen(item.amount_fen) }}</span>
              </div>
              <p class="refund-item__no num mini">退款单号 {{ item.refund_no }}</p>
              <p class="refund-item__time mini">{{ formatDateTime(item.created_at) }}</p>
            </li>
          </ul>

          <p v-if="order.payment_method === 'OFFLINE_QR'" class="refund-note mini">
            退款由店主线下处理。店主核实实际退回后才登记完成；处理中金额不会计为已退款。
            若长时间未收到退款，请联系店主。
          </p>
          <p v-else class="refund-note mini">
            退款结果以支付平台为准。「处理中」表示申请已受理但款项尚未到账，
            不会计为已退款。若长时间未到账，请联系店主。
          </p>
        </section>

        <button class="btn btn--ghost btn--block refund-back" type="button" @click="router.replace(`/orders/${order.id}`)">
          返回订单详情
        </button>
      </template>

      <EmptyState
        v-else
        icon="error"
        title="订单不存在或无权查看"
        action-text="返回订单列表"
        @action="router.replace('/orders')"
      />
    </div>
  </div>
</template>

<style scoped>
.refund-head {
  margin-top: var(--space-3);
}

.refund-head__row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 4px 0;
  font-size: var(--font-caption);
}

.refund-list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: var(--space-3);
}

.refund-item {
  background: var(--color-surface);
  border: 1px solid var(--color-divider);
  border-radius: var(--radius-lg);
  padding: 14px;
}

.refund-item__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.refund-item__no,
.refund-item__time {
  margin: 6px 0 0;
}

.refund-note {
  margin: var(--space-4) 0 0;
  line-height: 1.7;
}

.refund-back {
  margin-top: var(--space-5);
}

.detail-line {
  height: 18px;
  margin-top: 14px;
}
</style>
