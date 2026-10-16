<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import GoodsImage from '@/components/GoodsImage.vue'
import AssetIcon from '@/components/AssetIcon.vue'
import QuantityStepper from '@/components/QuantityStepper.vue'
import EmptyState from '@/components/EmptyState.vue'
import { api, ApiError } from '@/api/client'
import { useCartStore } from '@/stores/cart'
import { useToast } from '@/composables/toast'
import { formatFen } from '@/utils/format'
import type { GoodsDetail } from '@/api/types'

/**
 * 商品详情（方案 §5.2 UI-C02）。
 *
 * 交互要点（逐条对应方案）：
 *  - 商品照片顶部 1:1，圆角只在下边缘
 *  - SKU 选择为胶囊按钮，选中绿描边 + 浅绿底；无货不可选并提示「已售罄」
 *  - 数量加减有即时反馈，服务端下单时做最终库存校验
 *  - 底部左侧购物车入口（带角标），右侧深绿主按钮「加入购物车」
 *  - 成功用非阻塞 toast，不强制跳转
 */
const route = useRoute()
const router = useRouter()
const cart = useCartStore()
const toast = useToast()

const goods = ref<GoodsDetail | null>(null)
const loading = ref(true)
const failed = ref(false)
const activeSkuId = ref('')
const qty = ref(1)

const activeSku = computed(() => goods.value?.skus.find((s) => s.sku_id === activeSkuId.value) ?? null)
const maxQty = computed(() => Math.min(activeSku.value?.inventory ?? 0, 99))
const canBuy = computed(() => !!activeSku.value && !activeSku.value.is_out_of_stock)

async function load() {
  loading.value = true
  failed.value = false

  try {
    const detail = await api.goodsDetail(String(route.params.id))
    goods.value = detail

    // 默认选中第一个有货的规格；全都没货时选第一个，让顾客看到「已售罄」
    const firstAvailable = detail.skus.find((s) => !s.is_out_of_stock) ?? detail.skus[0]
    activeSkuId.value = firstAvailable?.sku_id ?? ''
    qty.value = Math.max(1, detail.buy_min_number || 1)
  } catch (error) {
    failed.value = true
    if (error instanceof ApiError && error.code === 'NOT_FOUND') {
      toast.warning(error.message)
    }
  } finally {
    loading.value = false
  }
}

function selectSku(skuId: string) {
  const sku = goods.value?.skus.find((s) => s.sku_id === skuId)
  if (!sku) return

  if (sku.is_out_of_stock) {
    toast.warning('该规格已售罄，请选择其他规格')

    return
  }

  activeSkuId.value = skuId
  // 换规格后数量要重新收敛，否则会出现「选了库存 1 的规格但数量还是 5」
  qty.value = Math.min(Math.max(1, qty.value), Math.min(sku.inventory, 99))
}

function addToCart() {
  if (!goods.value || !activeSku.value) {
    toast.warning('请先选择规格')

    return
  }

  const result = cart.add(goods.value, activeSku.value, qty.value)
  toast[result.ok ? 'success' : 'warning'](result.message)
}

function buyNow() {
  if (!goods.value || !activeSku.value) {
    toast.warning('请先选择规格')

    return
  }

  const result = cart.add(goods.value, activeSku.value, qty.value)
  if (!result.ok) {
    toast.warning(result.message)

    return
  }

  router.push('/checkout')
}

onMounted(load)
</script>

<template>
  <div class="page">
    <header class="detail-nav">
      <button class="detail-nav__btn" type="button" aria-label="返回" @click="router.back()">
        <span class="detail-nav__chevron" aria-hidden="true" />
      </button>
      <span class="detail-nav__title ellipsis">{{ goods?.title || '商品详情' }}</span>
      <button class="detail-nav__btn" type="button" aria-label="购物车" @click="router.push('/cart')">
        <AssetIcon name="cart" :size="22" />
        <span v-if="cart.count > 0" class="detail-nav__badge">{{ cart.count > 99 ? '99+' : cart.count }}</span>
      </button>
    </header>

    <div v-if="loading" class="page-body page-body--actionbar">
      <div class="skeleton detail-skeleton__img" />
      <div class="skeleton detail-skeleton__line" />
      <div class="skeleton detail-skeleton__line detail-skeleton__line--short" />
    </div>

    <EmptyState
      v-else-if="failed || !goods"
      icon="error"
      title="商品已下架或不存在"
      description="它可能刚刚卖完了，看看别的菜吧。"
      action-text="返回首页"
      @action="router.replace('/')"
    />

    <div v-else class="page-body page-body--actionbar">
      <!-- 主图 -->
      <div class="detail-media">
        <GoodsImage :src="goods.image_url" :alt="goods.title" :fallback-text="goods.title" radius="0" />
      </div>

      <!-- 价格与名称 -->
      <section class="detail-head">
        <div class="detail-head__row">
          <span class="detail-head__price price num">{{ formatFen(activeSku?.price_fen ?? goods.min_price_fen) }}</span>
          <span class="detail-head__stock caption">
            {{ goods.inventory > 0 ? `库存充足（${goods.inventory}${goods.inventory_unit || '份'}）` : '暂时缺货' }}
          </span>
        </div>
        <h1 class="detail-head__title">{{ goods.title }}</h1>
        <p v-if="goods.simple_desc" class="detail-head__desc caption">{{ goods.simple_desc }}</p>
      </section>

      <!-- 规格 -->
      <section class="detail-block">
        <h2 class="detail-block__title">规格</h2>
        <div class="sku-list">
          <button
            v-for="sku in goods.skus"
            :key="sku.sku_id"
            class="sku-chip"
            :class="{ 'is-active': sku.sku_id === activeSkuId, 'is-disabled': sku.is_out_of_stock }"
            type="button"
            @click="selectSku(sku.sku_id)"
          >
            <span>{{ sku.spec_text }}</span>
            <span class="sku-chip__price num">{{ formatFen(sku.price_fen) }}</span>
            <span v-if="sku.is_out_of_stock" class="sku-chip__sold">已售罄</span>
          </button>
        </div>
      </section>

      <!-- 数量 -->
      <section class="detail-block detail-block--row">
        <h2 class="detail-block__title">数量</h2>
        <QuantityStepper
          v-model="qty"
          :min="1"
          :max="maxQty || 1"
          :disabled="!canBuy"
          @limit="(msg: string) => toast.warning(msg)"
        />
      </section>

      <!-- 说明 -->
      <section v-if="goods.content || goods.origin" class="detail-block">
        <h2 class="detail-block__title">
          <span class="asset-heading"><AssetIcon name="product_detail" :size="20" />商品说明</span>
        </h2>
        <p v-if="goods.origin" class="detail-block__text caption">产地：{{ goods.origin }}</p>
        <p v-if="goods.content" class="detail-block__text">{{ goods.content }}</p>
      </section>

      <p class="detail-tip mini">图片与文字由店主维护；实际收到商品以实物为准。</p>
    </div>

    <!-- 底部固定操作 -->
    <div v-if="!loading && goods" class="action-bar">
      <button class="detail-cart-entry" type="button" @click="router.push('/cart')">
        <AssetIcon name="cart" :size="22" />
        <span>购物车</span>
        <span v-if="cart.count > 0" class="detail-cart-entry__badge">{{ cart.count > 99 ? '99+' : cart.count }}</span>
      </button>

      <button class="btn btn--secondary detail-action" type="button" :disabled="!canBuy" @click="addToCart">
        加入购物车
      </button>
      <button class="btn btn--primary detail-action" type="button" :disabled="!canBuy" @click="buyNow">
        立即下单
      </button>
    </div>
  </div>
</template>

<style scoped>
.detail-nav {
  position: sticky;
  top: 0;
  z-index: 15;
  padding-top: env(safe-area-inset-top);
  height: calc(var(--header-height) + env(safe-area-inset-top));
  display: flex;
  align-items: center;
  gap: var(--space-2);
  padding-left: var(--space-2);
  padding-right: var(--space-2);
  background: var(--color-canvas-translucent);
  backdrop-filter: blur(12px);
}

.detail-nav__btn {
  position: relative;
  width: 36px;
  height: 36px;
  flex: 0 0 36px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
}

.detail-nav__chevron {
  width: 8px;
  height: 8px;
  border-top: 1.75px solid currentColor;
  border-right: 1.75px solid currentColor;
  transform: rotate(225deg);
  color: var(--color-text-secondary);
}

.detail-nav__title {
  flex: 1;
  font-size: var(--font-product);
  font-weight: var(--weight-semibold);
  text-align: center;
}

.detail-nav__badge {
  position: absolute;
  top: 0;
  right: -2px;
  min-width: 15px;
  height: 15px;
  padding: 0 3px;
  border-radius: var(--radius-pill);
  background: var(--color-price);
  color: var(--color-on-brand);
  font-size: var(--font-mini);
  line-height: 15px;
  text-align: center;
}

.detail-media {
  margin: 0 calc(var(--page-padding) * -1);
  border-radius: 0 0 var(--radius-sheet) var(--radius-sheet);
  overflow: hidden;
}

.detail-head {
  padding: var(--space-4) 0 var(--space-2);
}

.detail-head__row {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: var(--space-3);
}

.detail-head__price {
  font-size: var(--font-display);
  line-height: 1.15;
}

.detail-head__title {
  margin: 8px 0 0;
  font-size: var(--font-headline);
  font-weight: var(--weight-semibold);
  line-height: 1.4;
}

.detail-head__desc {
  margin: 6px 0 0;
}

.detail-block {
  margin-top: var(--space-5);
}

.detail-block--row {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.detail-block__title {
  margin: 0 0 var(--space-3);
  font-size: var(--font-product);
  font-weight: var(--weight-semibold);
}

.detail-block--row .detail-block__title {
  margin: 0;
}

.detail-block__text {
  margin: 0 0 6px;
  font-size: var(--font-body);
  line-height: 1.7;
  color: var(--color-text-secondary);
  white-space: pre-wrap;
}

.sku-list {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
}

.sku-chip {
  position: relative;
  padding: 9px 14px;
  border-radius: var(--radius-pill);
  border: 1px solid var(--color-divider);
  background: var(--color-surface);
  display: inline-flex;
  align-items: center;
  gap: 8px;
  font-size: var(--font-caption);
  transition: all var(--motion-fast) var(--motion-ease);
}

.sku-chip.is-active {
  border-color: var(--color-brand-600);
  background: var(--color-brand-100);
  color: var(--color-brand-700);
  font-weight: var(--weight-semibold);
}

.sku-chip.is-disabled {
  opacity: 0.55;
}

.sku-chip__price {
  color: var(--color-price);
  font-weight: var(--weight-semibold);
}

.sku-chip__sold {
  font-size: var(--font-mini);
  color: var(--color-text-muted);
}

.detail-tip {
  margin: var(--space-5) 0 0;
  text-align: center;
}

.detail-cart-entry {
  position: relative;
  flex: 0 0 auto;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 1px;
  font-size: var(--font-mini);
  color: var(--color-text-secondary);
  padding: 0 6px;
}

.detail-cart-entry__badge {
  position: absolute;
  top: -4px;
  right: 0;
  min-width: 15px;
  height: 15px;
  padding: 0 3px;
  border-radius: var(--radius-pill);
  background: var(--color-price);
  color: var(--color-on-brand);
  font-size: var(--font-mini);
  line-height: 15px;
  text-align: center;
}

.detail-action {
  flex: 1;
}

.detail-skeleton__img {
  width: calc(100% + var(--page-padding) * 2);
  margin-left: calc(var(--page-padding) * -1);
  aspect-ratio: 1 / 1;
  border-radius: 0;
}

.detail-skeleton__line {
  height: 16px;
  margin-top: 16px;
}

.detail-skeleton__line--short {
  width: 45%;
}
</style>
