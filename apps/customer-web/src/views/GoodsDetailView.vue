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
 *  - 商品照片从安全区顶部开始，导航按钮浮在主图上
 *  - SKU 选择卡片显示价格与库存；无货时提示「已售罄」
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
const activeImageIndex = ref(0)

const activeSku = computed(() => goods.value?.skus.find((s) => s.sku_id === activeSkuId.value) ?? null)
const maxQty = computed(() => Math.min(activeSku.value?.inventory ?? 0, 99))
const canBuy = computed(() => !!activeSku.value && !activeSku.value.is_out_of_stock)
const detailImages = computed(() => {
  const detail = goods.value
  if (!detail) return []

  return [...new Set([detail.image_url, ...(detail.images ?? [])].filter((src) => src.trim() !== ''))]
})
const activeImage = computed(() => detailImages.value[activeImageIndex.value] ?? '')

async function load() {
  loading.value = true
  failed.value = false

  try {
    const detail = await api.goodsDetail(String(route.params.id))
    goods.value = detail
    activeImageIndex.value = 0

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

function selectImage(index: number) {
  activeImageIndex.value = index
}

function moveImage(offset: number) {
  const imageCount = detailImages.value.length
  if (imageCount < 2) return

  activeImageIndex.value = (activeImageIndex.value + offset + imageCount) % imageCount
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
      <header class="detail-nav" aria-label="商品导航">
        <button class="detail-nav__btn" type="button" aria-label="返回" @click="router.back()">
          <span class="detail-nav__chevron" aria-hidden="true" />
        </button>
        <button class="detail-nav__btn" type="button" aria-label="购物车" @click="router.push('/cart')">
          <AssetIcon name="cart" :size="22" />
          <span v-if="cart.count > 0" class="detail-nav__badge">{{ cart.count > 99 ? '99+' : cart.count }}</span>
        </button>
      </header>

      <!-- 主图 -->
      <section class="detail-media" aria-label="商品图片">
        <GoodsImage :key="activeImage" :src="activeImage" :alt="goods.title" :fallback-text="goods.title" radius="0" />
        <template v-if="detailImages.length > 1">
          <button class="detail-media__arrow detail-media__arrow--previous" type="button" aria-label="上一张商品图片" @click="moveImage(-1)">
            <span aria-hidden="true">‹</span>
          </button>
          <button class="detail-media__arrow detail-media__arrow--next" type="button" aria-label="下一张商品图片" @click="moveImage(1)">
            <span aria-hidden="true">›</span>
          </button>
          <div class="detail-media__pagination" aria-label="选择商品图片">
            <button
              v-for="(image, index) in detailImages"
              :key="image"
              class="detail-media__dot"
              :class="{ 'is-active': index === activeImageIndex }"
              type="button"
              :aria-label="`商品图片 ${index + 1}`"
              :aria-pressed="index === activeImageIndex"
              @click="selectImage(index)"
            />
          </div>
          <span class="detail-media__count">{{ activeImageIndex + 1 }} / {{ detailImages.length }}</span>
        </template>
      </section>

      <div class="detail-panel">
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
          <div class="detail-highlights" aria-label="商品特点">
            <span v-if="goods.origin" class="detail-highlight">产地 {{ goods.origin }}</span>
            <span v-if="goods.has_many_spec" class="detail-highlight">多规格可选</span>
            <span class="detail-highlight">已售 {{ goods.sales_count }} {{ goods.inventory_unit || '份' }}</span>
          </div>
        </section>

        <!-- 规格 -->
        <section class="detail-block">
          <div class="detail-section-head">
            <h2 class="detail-block__title">选择规格</h2>
            <span class="detail-section-note">{{ goods.spec_desc || '按需挑选' }}</span>
          </div>
          <div class="sku-list">
            <button
              v-for="sku in goods.skus"
              :key="sku.sku_id"
              class="sku-chip"
              :class="{ 'is-active': sku.sku_id === activeSkuId, 'is-disabled': sku.is_out_of_stock }"
              type="button"
              @click="selectSku(sku.sku_id)"
            >
              <span class="sku-chip__name">{{ sku.spec_text }}</span>
              <span class="sku-chip__pricing">
                <span class="sku-chip__price num">{{ formatFen(sku.price_fen) }}</span>
                <del v-if="sku.original_price_fen > sku.price_fen" class="sku-chip__original num">{{ formatFen(sku.original_price_fen) }}</del>
              </span>
              <span class="sku-chip__availability">
                {{ sku.is_out_of_stock ? '已售罄' : `库存 ${sku.inventory}${goods.inventory_unit || '份'}` }}
              </span>
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

        <section class="detail-block">
          <h2 class="detail-block__title">商品信息</h2>
          <div class="detail-info">
            <div v-if="goods.origin" class="detail-info__cell">
              <span class="detail-info__label">产地</span>
              <span class="detail-info__value">{{ goods.origin }}</span>
            </div>
            <div v-if="goods.spec_desc" class="detail-info__cell">
              <span class="detail-info__label">商品规格</span>
              <span class="detail-info__value">{{ goods.spec_desc }}</span>
            </div>
            <div class="detail-info__cell">
              <span class="detail-info__label">当前库存</span>
              <span class="detail-info__value">{{ goods.inventory }} {{ goods.inventory_unit || '份' }}</span>
            </div>
            <div class="detail-info__cell">
              <span class="detail-info__label">累计销量</span>
              <span class="detail-info__value">{{ goods.sales_count }} {{ goods.inventory_unit || '份' }}</span>
            </div>
          </div>
        </section>

        <!-- 商品介绍 -->
        <section v-if="goods.content" class="detail-block">
          <h2 class="detail-block__title">商品简介</h2>
          <div class="detail-description">{{ goods.content }}</div>
        </section>

        <p class="detail-tip mini">图片与文字由店主维护；实际收到商品以实物为准。</p>
      </div>
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
  justify-content: space-between;
  margin: 0 calc(var(--page-padding) * -1) calc(0px - var(--header-height) - env(safe-area-inset-top));
  padding-right: var(--page-padding);
  padding-left: var(--page-padding);
  pointer-events: none;
  background: transparent;
}

.detail-nav__btn {
  position: relative;
  width: 42px;
  height: 42px;
  flex: 0 0 42px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: var(--radius-pill);
  background: rgb(20 41 30 / 48%);
  color: #fff;
  backdrop-filter: blur(9px);
  pointer-events: auto;
}

.detail-nav__chevron {
  width: 8px;
  height: 8px;
  border-top: 1.75px solid currentColor;
  border-right: 1.75px solid currentColor;
  transform: rotate(225deg);
  color: #fff;
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
  position: relative;
  margin: 0 calc(var(--page-padding) * -1);
  border-radius: 0 0 var(--radius-sheet) var(--radius-sheet);
  overflow: hidden;
}

.detail-media__arrow {
  position: absolute;
  top: 50%;
  width: 38px;
  height: 38px;
  display: grid;
  place-items: center;
  transform: translateY(-50%);
  border-radius: var(--radius-pill);
  background: rgb(20 41 30 / 42%);
  color: #fff;
  font-size: 28px;
  line-height: 1;
  backdrop-filter: blur(8px);
}

.detail-media__arrow--previous {
  left: var(--space-3);
}

.detail-media__arrow--next {
  right: var(--space-3);
}

.detail-media__pagination {
  position: absolute;
  right: 0;
  bottom: 30px;
  left: 0;
  display: flex;
  justify-content: center;
  gap: 7px;
}

.detail-media__dot {
  width: 7px;
  height: 7px;
  border-radius: var(--radius-pill);
  background: rgb(255 255 255 / 62%);
  box-shadow: 0 1px 4px rgb(0 0 0 / 24%);
}

.detail-media__dot.is-active {
  width: 19px;
  background: #fff;
}

.detail-media__count {
  position: absolute;
  right: var(--space-4);
  bottom: 27px;
  padding: 3px 8px;
  border-radius: var(--radius-pill);
  background: rgb(20 41 30 / 48%);
  color: #fff;
  font-size: var(--font-mini);
}

.detail-panel {
  position: relative;
  z-index: 1;
  margin: -20px calc(var(--page-padding) * -1) 0;
  padding: var(--space-5) var(--page-padding) 0;
  border-radius: var(--radius-sheet) var(--radius-sheet) 0 0;
  background: var(--color-canvas);
}

.detail-head {
  padding: 0 0 var(--space-2);
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

.detail-highlights {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-top: var(--space-3);
}

.detail-highlight {
  padding: 5px 10px;
  border-radius: var(--radius-pill);
  background: var(--color-brand-100);
  color: var(--color-brand-700);
  font-size: var(--font-mini);
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

.detail-section-head {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: var(--space-3);
  margin-bottom: var(--space-3);
}

.detail-section-head .detail-block__title {
  margin: 0;
}

.detail-section-note {
  overflow: hidden;
  color: var(--color-text-muted);
  font-size: var(--font-mini);
  text-overflow: ellipsis;
  white-space: nowrap;
}

.detail-block--row .detail-block__title {
  margin: 0;
}

.sku-list {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 10px;
}

.sku-chip {
  position: relative;
  min-width: 0;
  min-height: 76px;
  padding: 11px 12px;
  border-radius: var(--radius-md);
  border: 1px solid var(--color-divider);
  background: var(--color-surface);
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  justify-content: center;
  gap: 5px;
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

.sku-chip__pricing {
  display: flex;
  align-items: baseline;
  gap: 8px;
}

.sku-chip__name {
  max-width: 100%;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.sku-chip__original {
  color: var(--color-text-muted);
  font-size: var(--font-mini);
}

.sku-chip__availability {
  font-size: var(--font-mini);
  color: var(--color-text-muted);
}

.detail-info {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  overflow: hidden;
  border: 1px solid var(--color-divider);
  border-radius: var(--radius-lg);
  background: var(--color-surface);
}

.detail-info__cell {
  min-width: 0;
  padding: 11px 12px;
  border-bottom: 1px solid var(--color-divider);
}

.detail-info__cell:nth-last-child(-n + 2) {
  border-bottom: 0;
}

.detail-info__label,
.detail-info__value {
  display: block;
}

.detail-info__label {
  color: var(--color-text-muted);
  font-size: var(--font-mini);
}

.detail-info__value {
  margin-top: 4px;
  overflow: hidden;
  font-size: var(--font-caption);
  font-weight: var(--weight-semibold);
  text-overflow: ellipsis;
  white-space: nowrap;
}

.detail-description {
  padding: 14px;
  border: 1px solid var(--color-divider);
  border-radius: var(--radius-lg);
  background: var(--color-surface);
  color: var(--color-text-secondary);
  font-size: var(--font-caption);
  line-height: 1.8;
  white-space: pre-wrap;
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
