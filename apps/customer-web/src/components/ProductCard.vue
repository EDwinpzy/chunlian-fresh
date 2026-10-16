<script setup lang="ts">
import { computed } from 'vue'
import { useRouter } from 'vue-router'
import GoodsImage from './GoodsImage.vue'
import AssetIcon from '@/components/AssetIcon.vue'
import { formatFen } from '@/utils/format'
import type { GoodsSummary } from '@/api/types'

/**
 * 商品卡（方案 §5.4 GProductCard）。
 * 变体：normal / soldOut。
 *
 * 交互：点卡片进详情，点 `+` 直接加购（由父组件处理，因为「加购哪个 SKU」
 * 取决于商品是单规格还是多规格）。
 */
const props = withDefaults(
  defineProps<{
    goods: GoodsSummary
    /** 显示加购按钮（首页/分类/搜索用 true，订单里的商品行用 false） */
    showAdd?: boolean
    qty?: number
  }>(),
  { showAdd: true, qty: 0 },
)

const emit = defineEmits<{
  (e: 'add', goods: GoodsSummary): void
}>()

const router = useRouter()

const soldOut = computed(() => props.goods.is_out_of_stock)
const priceText = computed(() => {
  const { min_price_fen: min, max_price_fen: max } = props.goods
  if (max > min) return `${formatFen(min)} 起`

  return formatFen(min)
})

function open() {
  router.push(`/goods/${props.goods.id}`)
}

function onAdd(event: Event) {
  event.stopPropagation()
  if (soldOut.value) return
  emit('add', props.goods)
}
</script>

<template>
  <article class="goods-card" :class="{ 'is-soldout': soldOut }" @click="open">
    <div class="goods-card__media">
      <GoodsImage
        :src="goods.image_url"
        :alt="goods.title"
        :fallback-text="goods.title"
        radius="0"
      />
      <span v-if="soldOut" class="goods-card__mask">已售罄</span>
    </div>

    <div class="goods-card__body">
      <h3 class="goods-card__title clamp-2">{{ goods.title }}</h3>
      <p class="goods-card__spec caption ellipsis">
        {{ goods.spec_desc || goods.inventory_unit || '按份销售' }}
      </p>

      <div class="goods-card__footer">
        <span class="goods-card__price price num">{{ priceText }}</span>

        <button
          v-if="showAdd !== false"
          class="goods-card__add"
          :class="{ 'is-disabled': soldOut }"
          type="button"
          :aria-label="`加入购物车：${goods.title}`"
          @click="onAdd"
        >
          <AssetIcon name="add_to_cart" :size="24" />
          <span v-if="qty && qty > 0" class="goods-card__qty">{{ qty }}</span>
        </button>
      </div>
    </div>
  </article>
</template>

<style scoped>
/*
 * 商品卡采用轻边框，减少阴影层级，让商品图片成为视觉重点。
 */
.goods-card {
  background: var(--color-surface);
  border-radius: var(--radius-lg);
  border: 1px solid var(--color-divider);
  padding: 6px;
  display: flex;
  flex-direction: column;
  transition: transform var(--motion-fast) var(--motion-ease);
}

.goods-card:active {
  transform: scale(0.985);
}

.goods-card.is-soldout {
  opacity: 0.72;
}

.goods-card__media {
  position: relative;
  border-radius: var(--radius-md);
  overflow: hidden;
}

.goods-card__mask {
  position: absolute;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  background: var(--color-image-mask);
  color: var(--color-text-secondary);
  font-size: var(--font-caption);
  font-weight: var(--weight-semibold);
  letter-spacing: 1px;
}

.goods-card__body {
  padding: 8px 4px 4px;
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.goods-card__title {
  margin: 0;
  font-size: var(--font-product);
  font-weight: var(--weight-semibold);
  line-height: 1.45;
  min-height: 43px;
}

.goods-card__spec {
  margin: 0;
}

.goods-card__footer {
  margin-top: 2px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
}

.goods-card__price {
  font-size: var(--font-headline);
  line-height: 1.2;
  white-space: nowrap;
}

.goods-card__add {
  position: relative;
  width: 30px;
  height: 30px;
  flex: 0 0 30px;
  border-radius: 50%;
  background: var(--color-brand-700);
  color: var(--color-on-brand);
  display: inline-flex;
  align-items: center;
  justify-content: center;
  transition: transform var(--motion-fast) var(--motion-ease);
}

/* 保持按钮紧凑，同时扩大可点击区域。 */
.goods-card__add::after {
  content: '';
  position: absolute;
  inset: -8px;
}

.goods-card__add:active:not(.is-disabled) {
  transform: scale(0.9);
}

.goods-card__add.is-disabled {
  background: var(--color-divider);
  color: var(--color-text-muted);
}

.goods-card__qty {
  position: absolute;
  top: -6px;
  right: -6px;
  min-width: 16px;
  height: 16px;
  padding: 0 4px;
  border-radius: var(--radius-pill);
  background: var(--color-price);
  color: var(--color-on-brand);
  font-size: var(--font-mini);
  line-height: 16px;
  font-weight: var(--weight-semibold);
}
</style>
