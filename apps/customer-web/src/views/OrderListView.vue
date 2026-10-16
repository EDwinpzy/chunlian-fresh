<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import PageHeader from '@/components/PageHeader.vue'
import GoodsImage from '@/components/GoodsImage.vue'
import StatusPill from '@/components/StatusPill.vue'
import EmptyState from '@/components/EmptyState.vue'
import SearchBox from '@/components/SearchBox.vue'
import { api, ApiError } from '@/api/client'
import { useToast } from '@/composables/toast'
import { deliveryText, formatFen } from '@/utils/format'
import type { Order } from '@/api/types'

/**
 * 我的订单（方案 §5.3 UI-C09）。
 *
 * 顶部筛选胶囊用「展示口径」（待付款/待配送/配送中/已送达/已取消），
 * 由**服务端**映射到四维状态 —— 客户端不拼装状态条件，
 * 否则一旦后端状态机调整，前端就会静默显示错的数据。
 */
const router = useRouter()
const toast = useToast()

const FILTERS = [
  { key: '', label: '全部' },
  { key: 'UNPAID', label: '待付款' },
  { key: 'PENDING', label: '待配送' },
  { key: 'DELIVERING', label: '配送中' },
  { key: 'DELIVERED', label: '已送达' },
  { key: 'FINISHED', label: '已完成' },
  { key: 'CANCELLED', label: '已取消' },
]

const active = ref('')
const keyword = ref('')
const activeKeyword = ref('')
const orders = ref<Order[]>([])
const loading = ref(true)
const failed = ref(false)
const hasMore = ref(false)
const page = ref(1)
const loadingMore = ref(false)
let latestLoadId = 0
let keywordSearchTimer: ReturnType<typeof window.setTimeout> | undefined

async function load(reset = true) {
  const loadId = ++latestLoadId
  if (reset) {
    page.value = 1
    loading.value = true
    loadingMore.value = false
    failed.value = false
  } else {
    loadingMore.value = true
  }
  const requestPage = page.value
  const requestStatus = active.value || undefined
  const requestKeyword = activeKeyword.value || undefined

  try {
    const data = await api.orders({
      keyword: requestKeyword,
      status: requestStatus,
      page: requestPage,
      page_size: 10,
    })

    if (loadId !== latestLoadId) return
    orders.value = reset ? data.list : [...orders.value, ...data.list]
    hasMore.value = data.has_more ?? (data.list.length >= 10)
  } catch (error) {
    if (loadId !== latestLoadId) return
    if (reset) failed.value = true
    toast.error(error instanceof ApiError ? error.message : '加载失败')
  } finally {
    if (loadId === latestLoadId) {
      loading.value = false
      loadingMore.value = false
    }
  }
}

function loadMore() {
  if (!hasMore.value || loadingMore.value) return
  page.value += 1
  load(false)
}

function selectFilter(key: string) {
  if (active.value === key) return
  active.value = key
}

function updateKeyword(value: string) {
  keyword.value = value
  if (keywordSearchTimer !== undefined) {
    window.clearTimeout(keywordSearchTimer)
    keywordSearchTimer = undefined
  }

  const nextKeyword = value.trim()
  if (!nextKeyword) {
    if (activeKeyword.value) activeKeyword.value = ''
    return
  }

  keywordSearchTimer = window.setTimeout(() => {
    keywordSearchTimer = undefined
    if (activeKeyword.value !== nextKeyword) activeKeyword.value = nextKeyword
  }, 300)
}

function submitSearch() {
  if (keywordSearchTimer !== undefined) {
    window.clearTimeout(keywordSearchTimer)
    keywordSearchTimer = undefined
  }

  const nextKeyword = keyword.value.trim()
  keyword.value = nextKeyword

  if (activeKeyword.value === nextKeyword) {
    load(true)
    return
  }

  activeKeyword.value = nextKeyword
}

function itemCount(order: Order) {
  return order.lines.reduce((count, line) => count + line.qty, 0)
}

watch([active, activeKeyword], () => load(true))
onMounted(() => load(true))
onBeforeUnmount(() => {
  if (keywordSearchTimer !== undefined) window.clearTimeout(keywordSearchTimer)
})
</script>

<template>
  <div class="page">
    <PageHeader title="我的订单" />

    <div class="orders-controls">
      <div class="orders-search">
        <SearchBox
          mode="input"
          :model-value="keyword"
          placeholder="搜索订单号或商品名"
          input-id="order-search-input"
          autocomplete="off"
          :clearable="true"
          @update:model-value="updateKeyword"
          @submit="submitSearch"
        />
      </div>

      <div class="filter-bar scroll-x" role="group" aria-label="订单状态筛选">
        <button
          v-for="f in FILTERS"
          :key="f.key"
          class="filter-chip"
          :class="{ 'is-active': active === f.key }"
          type="button"
          @click="selectFilter(f.key)"
        >
          {{ f.label }}
        </button>
      </div>
    </div>

    <div class="page-body page-body--tabbar">
      <div v-if="loading" class="order-list">
        <div v-for="i in 3" :key="i" class="order-skeleton card">
          <div class="skeleton order-skeleton__line" />
          <div class="skeleton order-skeleton__line order-skeleton__line--short" />
        </div>
      </div>

      <EmptyState
        v-else-if="failed"
        icon="error"
        title="加载失败"
        description="请检查网络后重试。"
        action-text="重试"
        @action="load(true)"
      />

      <EmptyState
        v-else-if="orders.length === 0 && activeKeyword"
        icon="search"
        title="没有找到匹配的订单"
        description="试试其他订单号或商品名。"
      />

      <EmptyState
        v-else-if="orders.length === 0"
        icon="order"
        title="还没有订单"
        description="挑几样今天要吃的菜，下单后可以随时查看进度。"
        action-text="去逛逛"
        @action="router.push('/')"
      />

      <template v-else>
        <ul class="order-list">
          <li
            v-for="order in orders"
            :key="order.id"
            class="order-card"
            @click="router.push(`/orders/${order.id}`)"
          >
            <header class="order-card__head">
              <span class="order-card__no num">订单号&nbsp;{{ order.order_no }}</span>
              <span class="order-card__view">详情 <span aria-hidden="true">›</span></span>
            </header>

            <div class="order-card__status">
              <StatusPill :order="order" />
            </div>

            <div class="order-card__goods">
              <div class="order-card__lines" aria-hidden="true">
                <GoodsImage
                  v-for="line in order.lines.slice(0, 3)"
                  :key="line.sku_id"
                  :src="line.image_url"
                  :alt="line.name"
                  :fallback-text="line.name"
                  radius="var(--radius-sm)"
                />
                <span v-if="order.lines.length > 3" class="order-card__more caption">
                  +{{ order.lines.length - 3 }}
                </span>
              </div>
              <div class="order-card__goods-copy">
                <p class="order-card__summary ellipsis">
                  {{ order.lines.map((l) => `${l.name} × ${l.qty}`).join('、') }}
                </p>
                <span class="order-card__count">共 {{ itemCount(order) }} 件商品</span>
              </div>
            </div>

            <footer class="order-card__foot">
              <span class="order-card__delivery">
                <span class="order-card__delivery-dot" aria-hidden="true" />
                <span>{{ deliveryText(order.delivery_mode, order.expected_at) }}</span>
              </span>
              <span class="order-card__total">
                <span>合计</span>
                <strong class="price num">{{ formatFen(order.total_fen) }}</strong>
              </span>
            </footer>

            <div v-if="order.can_cancel || order.can_edit || order.can_receive" class="order-card__ops" @click.stop>
              <button
                v-if="order.can_edit"
                class="btn btn--ghost btn--sm"
                type="button"
                @click="router.push(`/orders/${order.id}/edit`)"
              >
                修改订单
              </button>
              <button
                v-if="order.can_receive"
                class="btn btn--secondary btn--sm"
                type="button"
                @click="router.push(`/orders/${order.id}`)"
              >
                确认收货
              </button>
              <button
                v-if="order.can_cancel"
                class="btn btn--danger btn--sm"
                type="button"
                @click="router.push(`/orders/${order.id}`)"
              >
                取消订单
              </button>
            </div>
          </li>
        </ul>

        <button v-if="hasMore" class="load-more" type="button" :disabled="loadingMore" @click="loadMore">
          {{ loadingMore ? '加载中…' : '加载更多' }}
        </button>
      </template>
    </div>
  </div>
</template>

<style scoped>
.filter-bar {
  gap: 7px;
  padding: 0 var(--page-padding) 12px;
}

.orders-controls {
  position: sticky;
  top: calc(var(--header-height) + env(safe-area-inset-top));
  z-index: 10;
  background: var(--color-canvas-translucent);
  border-bottom: 1px solid rgba(231, 234, 228, 0.72);
  backdrop-filter: blur(12px);
}

.orders-search {
  padding: 12px var(--page-padding) 10px;
}

.filter-chip {
  flex: 0 0 auto;
  min-height: 38px;
  padding: 0 14px;
  border-radius: var(--radius-pill);
  background: var(--color-surface);
  border: 1px solid var(--color-divider);
  font-size: var(--font-caption);
  color: var(--color-text-secondary);
  white-space: nowrap;
}

.filter-chip.is-active {
  background: var(--color-brand-700);
  border-color: var(--color-brand-700);
  color: var(--color-on-brand);
  font-weight: var(--weight-semibold);
  box-shadow: 0 3px 8px rgba(23, 63, 52, 0.14);
}

.filter-chip:focus-visible {
  outline: 2px solid var(--color-brand-500);
  outline-offset: 2px;
}

.order-list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: var(--space-3);
}

.order-card {
  background: var(--color-surface);
  border: 1px solid var(--color-divider);
  border-radius: 20px;
  padding: 14px 14px 12px;
  box-shadow: var(--elevation-1);
}

.order-card__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  min-height: 24px;
}

.order-card__no {
  font-size: var(--font-caption);
  color: var(--color-text-secondary);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.order-card__view {
  display: inline-flex;
  flex: 0 0 auto;
  align-items: center;
  gap: 3px;
  color: var(--color-text-muted);
  font-size: var(--font-mini);
}

.order-card__view span {
  color: var(--color-brand-600);
  font-size: 18px;
  line-height: 12px;
}

.order-card__status {
  margin-top: 9px;
}

.order-card__lines {
  display: flex;
  align-items: center;
  gap: 6px;
  min-width: 0;
}

.order-card__lines :deep(.goods-image) {
  width: 46px;
  flex: 0 0 46px;
  border: 1px solid var(--color-divider);
  border-radius: 12px;
}

.order-card__more {
  flex: 0 0 auto;
  color: var(--color-text-muted);
  font-size: 10px;
}

.order-card__goods {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr);
  align-items: center;
  gap: 10px;
  margin-top: 12px;
  padding: 11px 0 12px;
  border-bottom: 1px solid var(--color-divider);
}

.order-card__goods-copy {
  min-width: 0;
}

.order-card__summary {
  margin: 0;
  color: var(--color-text-primary);
  font-size: var(--font-body);
  line-height: 1.5;
}

.order-card__count {
  display: block;
  margin-top: 3px;
  color: var(--color-text-muted);
  font-size: var(--font-mini);
}

.order-card__foot {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  min-height: 42px;
}

.order-card__delivery {
  display: inline-flex;
  min-width: 0;
  align-items: center;
  gap: 7px;
  color: var(--color-text-secondary);
  font-size: var(--font-caption);
}

.order-card__delivery-dot {
  width: 7px;
  height: 7px;
  flex: 0 0 7px;
  border-radius: 50%;
  background: var(--color-brand-500);
  box-shadow: 0 0 0 3px var(--color-brand-50);
}

.order-card__total {
  display: inline-flex;
  flex: 0 0 auto;
  align-items: baseline;
  gap: 7px;
  color: var(--color-text-muted);
  font-size: var(--font-mini);
}

.order-card__total .price {
  font-size: 18px;
}

.order-card__ops {
  margin-top: 4px;
  display: flex;
  justify-content: flex-end;
  flex-wrap: wrap;
  gap: 8px;
}

.order-card__ops :deep(.btn--sm) {
  min-height: 40px;
  border-radius: 13px;
}

.order-skeleton {
  height: 120px;
  display: flex;
  flex-direction: column;
  justify-content: center;
  gap: 10px;
}

.order-skeleton__line {
  height: 14px;
}

.order-skeleton__line--short {
  width: 40%;
}

.load-more {
  width: 100%;
  margin-top: var(--space-4);
  height: 44px;
  border-radius: var(--radius-md);
  border: 1px solid var(--color-divider);
  background: var(--color-surface);
  font-size: var(--font-caption);
  color: var(--color-text-secondary);
}
</style>
