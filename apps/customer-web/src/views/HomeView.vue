<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import ProductCard from '@/components/ProductCard.vue'
import GoodsImage from '@/components/GoodsImage.vue'
import EmptyState from '@/components/EmptyState.vue'
import ShopLogo from '@/components/ShopLogo.vue'
import AssetIcon from '@/components/AssetIcon.vue'
import SearchBox from '@/components/SearchBox.vue'
import { api, ApiError } from '@/api/client'
import { useCartStore } from '@/stores/cart'
import { useShopStore } from '@/stores/shop'
import { useToast } from '@/composables/toast'
import type { GoodsSummary } from '@/api/types'
import { formatFen } from '@/utils/format'

/**
 * 首页（方案 §5.2 UI-C01）。
 *
 * 结构固定为：品牌与推荐文案 → 搜索框 → 分类捷径 → 每日鲜选轮播 → 商品双列网格。
 * 轮播由顾客手动滑动或点击指示点切换，不自动播放。
 */
const router = useRouter()
const cart = useCartStore()
const shop = useShopStore()
const toast = useToast()

const goods = ref<GoodsSummary[]>([])
const featuredGoods = ref<GoodsSummary[]>([])
const featuredCarousel = ref<HTMLElement | null>(null)
const activeFeaturedIndex = ref(0)
const loading = ref(true)
const failed = ref(false)
const selectedCategoryId = ref('')
const unavailableCategoryIcons = ref(new Set<string>())
let goodsRequestId = 0

const notice = computed(() => shop.setting?.notice || '')
const isOpen = computed(() => shop.setting?.is_open !== false)
const selectedCategory = computed(() => shop.categories.find((category) => category.id === selectedCategoryId.value) ?? null)
const goodsHeading = computed(() => {
  const title = shop.setting?.home_section_title?.trim() || '今日鲜选'

  return selectedCategory.value ? `${title} · ${selectedCategory.value.name}` : title
})
const headerHighlights = computed(() => [
  shop.setting?.shop_slogan,
  shop.setting?.home_banner_title,
  shop.setting?.home_banner_subtitle,
].filter((value): value is string => Boolean(value?.trim())).join(' · '))

async function load(categoryId = selectedCategoryId.value, refreshShopData = false) {
  const requestId = ++goodsRequestId
  loading.value = true
  failed.value = false

  try {
    const params = { ...(categoryId ? { category_id: categoryId } : {}), page: 1, page_size: 12 }
    const [list] = await Promise.all([
      api.goodsList(params),
      ...(refreshShopData ? [shop.loadCategories(true), shop.loadSetting(true)] : []),
    ])
    if (requestId === goodsRequestId) goods.value = list.list
  } catch {
    if (requestId === goodsRequestId) failed.value = true
  } finally {
    if (requestId === goodsRequestId) loading.value = false
  }
}

async function loadFeatured() {
  const ids = (shop.setting?.home_featured_product_ids ?? []).filter(Boolean).slice(0, 10)

  try {
    const page = await api.goodsList(ids.length
      ? { ids: ids.join(','), page: 1, page_size: 10 }
      : { page: 1, page_size: 5 })
    featuredGoods.value = page.list
    activeFeaturedIndex.value = 0
  } catch {
    featuredGoods.value = []
    activeFeaturedIndex.value = 0
  }
}

function updateFeaturedIndex() {
  const element = featuredCarousel.value
  if (!element || element.clientWidth === 0) return

  activeFeaturedIndex.value = Math.min(
    featuredGoods.value.length - 1,
    Math.round(element.scrollLeft / element.clientWidth),
  )
}

function showFeatured(index: number) {
  const element = featuredCarousel.value
  if (!element || index < 0 || index >= featuredGoods.value.length) return

  element.scrollTo({ left: index * element.clientWidth, behavior: 'smooth' })
}

/**
 * 首页的 `+` 直接加购。
 *
 * 单规格商品可以直接加；多规格商品必须先选规格，否则加进购物车的
 * 会是「哪一个 SKU」都不确定 —— 价格与库存都会算错。
 */
async function quickAdd(item: GoodsSummary) {
  if (item.is_out_of_stock) {
    toast.warning('该商品已售罄')

    return
  }

  if (item.has_many_spec) {
    router.push(`/goods/${item.id}`)

    return
  }

  try {
    const detail = await api.goodsDetail(item.id)
    const sku = detail.skus[0]
    if (!sku) {
      toast.warning('该商品暂无可售规格')

      return
    }

    const result = cart.add(detail, sku, 1)
    toast[result.ok ? 'success' : 'warning'](result.message)
  } catch (error) {
    toast.error(error instanceof ApiError ? error.message : '加入购物车失败')
  }
}

function selectCategory(id: string) {
  if (selectedCategoryId.value === id) return

  selectedCategoryId.value = id
  void load(id)
}

function markCategoryIconUnavailable(id: string) {
  unavailableCategoryIcons.value = new Set(unavailableCategoryIcons.value).add(id)
}

onMounted(async () => {
  await load('', true)
  await loadFeatured()
})
</script>

<template>
  <div class="page">
    <div class="page-body page-body--tabbar">
      <!-- 品牌头 -->
      <header class="home-head">
        <div class="home-head__identity">
          <ShopLogo :size="36" />
          <div class="home-head__copy">
            <div class="home-head__title-row">
              <h1 class="home-head__brand">{{ shop.setting?.shop_name || '店铺' }}</h1>
              <span class="home-head__status" :class="isOpen ? 'is-open' : 'is-closed'">
                {{ isOpen ? '营业中' : '已打烊' }}
              </span>
            </div>
            <p v-if="headerHighlights" class="home-head__highlights">{{ headerHighlights }}</p>
          </div>
        </div>
      </header>

      <!-- 搜索 -->
      <SearchBox @activate="router.push('/search')" />

      <!-- 公告 -->
      <p v-if="notice" class="home-notice">
        <span class="home-notice__tag">公告</span>
        {{ notice }}
      </p>

      <!-- 分类捷径 -->
      <!--
        不做 slice：横向滚动 + 服务端已按 sort DESC 排序，生鲜品类自然在前。
        写死「只显示 8 个」会让后加的品类静默消失，而顾客根本不知道有更多。
      -->
      <nav v-if="shop.categories.length" class="home-cats scroll-x" aria-label="按分类找好菜">
        <button
          class="home-cat"
          :class="{ 'is-active': selectedCategoryId === '' }"
          type="button"
          :aria-pressed="selectedCategoryId === ''"
          @click="selectCategory('')"
        >
          <span class="home-cat__icon" aria-hidden="true">
            <AssetIcon name="category" :size="18" />
          </span>
          <span class="home-cat__name">全部</span>
        </button>
        <button
          v-for="cat in shop.categories"
          :key="cat.id"
          class="home-cat"
          :class="{ 'is-active': selectedCategoryId === cat.id }"
          type="button"
          :aria-pressed="selectedCategoryId === cat.id"
          @click="selectCategory(cat.id)"
        >
          <span class="home-cat__icon" aria-hidden="true">
            <AssetIcon name="category" :size="18" />
            <img
              v-if="cat.icon_url && !unavailableCategoryIcons.has(cat.id)"
              class="home-cat__image"
              :src="cat.icon_url"
              alt=""
              loading="lazy"
              @error="markCategoryIconUnavailable(cat.id)"
            />
          </span>
          <span class="home-cat__name">{{ cat.name }}</span>
        </button>
      </nav>

      <!-- 每日鲜选：独立于分类筛选的店主推荐商品轮播 -->
      <section
        v-if="featuredGoods.length"
        class="featured-section"
        role="region"
        aria-roledescription="轮播"
        aria-label="每日鲜选"
      >
        <div class="featured-header">
          <div class="featured-heading">
            <h2 class="featured-heading__title">每日鲜选</h2>
            <p class="featured-heading__subtitle">店主精选 · 新鲜好味道</p>
          </div>
          <div v-if="featuredGoods.length > 1" class="featured-controls" aria-label="切换推荐商品">
            <button
              class="featured-controls__button"
              type="button"
              aria-label="上一个推荐商品"
              :disabled="activeFeaturedIndex === 0"
              @click="showFeatured(activeFeaturedIndex - 1)"
            >
              <AssetIcon name="back" :size="16" />
            </button>
            <button
              class="featured-controls__button"
              type="button"
              aria-label="下一个推荐商品"
              :disabled="activeFeaturedIndex >= featuredGoods.length - 1"
              @click="showFeatured(activeFeaturedIndex + 1)"
            >
              <AssetIcon name="back" :size="16" :rotation="180" />
            </button>
          </div>
        </div>

        <div
          ref="featuredCarousel"
          class="featured-carousel"
          @scroll.passive="updateFeaturedIndex"
        >
          <article
            v-for="(item, index) in featuredGoods"
            :key="item.id"
            class="featured-slide"
            role="group"
            aria-roledescription="幻灯片"
            :aria-label="`${index + 1} / ${featuredGoods.length}：${item.title}`"
          >
            <RouterLink
              class="featured-slide__link"
              :to="`/goods/${item.id}`"
              :aria-label="`去挑选好菜：${item.title}`"
            >
              <div class="featured-slide__media">
                <GoodsImage :src="item.image_url" :alt="item.title" :fallback-text="item.title" radius="0" />
              </div>
              <div class="featured-slide__body">
                <span class="featured-slide__badge">店主推荐</span>
                <h3 class="featured-slide__title">{{ item.title }}</h3>
                <p class="featured-slide__description">{{ item.simple_desc || item.spec_desc || '新鲜上架，欢迎选购' }}</p>
                <div class="featured-slide__footer">
                  <span class="featured-slide__price price num">
                    {{ item.max_price_fen > item.min_price_fen ? `${formatFen(item.min_price_fen)} 起` : formatFen(item.min_price_fen) }}
                  </span>
                  <span class="featured-slide__cta">去挑选好菜 <span aria-hidden="true">→</span></span>
                </div>
              </div>
            </RouterLink>
          </article>
        </div>

        <div v-if="featuredGoods.length > 1" class="featured-dots" aria-label="推荐商品页码">
          <button
            v-for="(item, index) in featuredGoods"
            :key="item.id"
            class="featured-dot"
            :class="{ 'is-active': activeFeaturedIndex === index }"
            type="button"
            :aria-label="`第 ${index + 1} 个推荐商品：${item.title}`"
            :aria-current="activeFeaturedIndex === index ? 'true' : undefined"
            @click="showFeatured(index)"
          />
        </div>
      </section>

      <!-- 商品 -->
      <section class="section">
        <div class="section-title">
          <span>{{ goodsHeading }}</span>
          <button class="section-title__more" type="button" @click="router.push('/category')">
            全部
            <AssetIcon name="back" :size="14" :rotation="180" />
          </button>
        </div>

        <div v-if="loading" class="goods-grid">
          <div v-for="i in 4" :key="i" class="goods-skeleton">
            <div class="skeleton goods-skeleton__img" />
            <div class="skeleton goods-skeleton__line" />
            <div class="skeleton goods-skeleton__line goods-skeleton__line--short" />
          </div>
        </div>

        <EmptyState
          v-else-if="failed"
          icon="error"
          title="没能加载到商品"
          description="网络似乎开了小差，请重试。"
          action-text="重新加载"
          @action="load"
        />

        <EmptyState
          v-else-if="goods.length === 0"
          icon="empty"
          :title="selectedCategory ? `${selectedCategory.name}暂无商品` : '今天还没有上架商品'"
          :description="selectedCategory ? '换个分类看看，或者查看全部商品。' : '店主正在备货，稍后再来看看。'"
          :action-text="selectedCategory ? '查看全部商品' : '刷新'"
          @action="selectedCategory ? selectCategory('') : load()"
        />

        <div v-else class="goods-grid">
          <ProductCard
            v-for="item in goods"
            :key="item.id"
            :goods="item"
            :qty="0"
            @add="quickAdd"
          />
        </div>
      </section>
    </div>
  </div>
</template>

<style scoped>
.home-head__copy { min-width: 0; flex: 1; }
.home-head__brand { overflow-wrap: anywhere; }
.home-head {
  padding: calc(env(safe-area-inset-top) + 8px) 0 8px;
  display: flex;
  align-items: center;
}

.home-head__identity {
  min-width: 0;
  width: 100%;
  display: flex;
  align-items: center;
  gap: 9px;
}

.home-head__title-row {
  min-width: 0;
  display: flex;
  align-items: center;
  gap: 8px;
}

.home-head__brand {
  margin: 0;
  min-width: 0;
  font-size: var(--font-section);
  font-weight: var(--weight-bold);
  color: var(--color-brand-700);
  line-height: 1.25;
}

.home-head__status {
  flex: 0 0 auto;
  display: inline-flex;
  align-items: center;
  gap: 4px;
  font-size: var(--font-mini);
  line-height: 1.2;
}

.home-head__status::before {
  content: '';
  width: 6px;
  height: 6px;
  border-radius: 50%;
  background: currentColor;
}

.home-head__status.is-open { color: var(--color-success); }
.home-head__status.is-closed { color: var(--color-text-muted); }

.home-head__highlights {
  margin: 3px 0 0;
  display: -webkit-box;
  overflow: hidden;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 2;
  font-size: var(--font-mini);
  line-height: 1.35;
  color: var(--color-text-secondary);
  overflow-wrap: anywhere;
}

.home-notice {
  margin: 10px 0 0;
  padding: 8px 12px;
  border-radius: var(--radius-sm);
  background: var(--color-info-bg);
  color: var(--color-info);
  font-size: var(--font-caption);
  line-height: var(--leading-caption);
  display: flex;
  gap: 6px;
}

.home-notice__tag {
  flex: 0 0 auto;
  font-weight: var(--weight-semibold);
}

.home-cats {
  margin-top: var(--space-3);
  gap: 8px;
  padding-bottom: 1px;
}

.home-cat {
  flex: 0 0 auto;
  min-height: 32px;
  gap: 6px;
  padding: 0 10px;
  border: 1px solid var(--color-divider);
  border-radius: var(--radius-pill);
  background: var(--color-surface);
  display: flex;
  align-items: center;
  justify-content: center;
}

.home-cat__icon {
  position: relative;
  display: inline-flex;
  width: 18px;
  height: 18px;
  flex: 0 0 18px;
  align-items: center;
  justify-content: center;
  color: var(--color-brand-700);
}

.home-cat__image {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  border-radius: 50%;
  background: var(--color-surface);
  object-fit: contain;
}

.home-cat.is-active {
  border-color: var(--color-brand-600);
  background: var(--color-brand-100);
}

.home-cat__name {
  font-size: var(--font-caption);
  color: var(--color-text-primary);
  white-space: nowrap;
}

.home-cat.is-active .home-cat__name {
  color: var(--color-brand-700);
  font-weight: var(--weight-semibold);
}

.featured-section {
  margin-top: var(--space-4);
}

.featured-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: var(--space-2);
}

.featured-heading__title {
  margin: 0;
  color: var(--color-text-primary);
  font-size: var(--font-section);
  font-weight: var(--weight-bold);
  line-height: 1.35;
}

.featured-heading__subtitle {
  margin: 2px 0 0;
  color: var(--color-text-secondary);
  font-size: var(--font-mini);
  line-height: 1.4;
}

.featured-controls {
  display: flex;
  gap: 6px;
}

.featured-controls__button {
  width: 32px;
  height: 32px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border: 1px solid var(--color-divider);
  border-radius: 50%;
  background: var(--color-surface);
  color: var(--color-text-primary);
}

.featured-controls__button:disabled {
  cursor: default;
  opacity: 0.35;
}

.featured-carousel {
  display: flex;
  overflow-x: auto;
  overscroll-behavior-x: contain;
  scroll-snap-type: x mandatory;
  scroll-behavior: smooth;
  -webkit-overflow-scrolling: touch;
  scrollbar-width: none;
}

.featured-carousel::-webkit-scrollbar { display: none; }

.featured-slide {
  min-width: 0;
  flex: 0 0 100%;
  scroll-snap-align: start;
  scroll-snap-stop: always;
}

.featured-slide__link {
  min-height: 174px;
  display: flex;
  overflow: hidden;
  border: 1px solid var(--color-divider);
  border-radius: var(--radius-lg);
  background: var(--color-surface);
}

.featured-slide__media {
  width: 40%;
  flex: 0 0 40%;
  min-width: 0;
  align-self: stretch;
  overflow: hidden;
  background: var(--color-brand-100);
}

.featured-slide__media :deep(.goods-image) {
  height: 100%;
  aspect-ratio: auto;
}

.featured-slide__body {
  min-width: 0;
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  padding: 12px 12px 10px;
}

.featured-slide__badge {
  padding: 3px 7px;
  border-radius: var(--radius-pill);
  background: var(--color-brand-100);
  color: var(--color-brand-700);
  font-size: var(--font-mini);
  font-weight: var(--weight-semibold);
  line-height: 1.25;
}

.featured-slide__title {
  margin: 8px 0 0;
  color: var(--color-text-primary);
  font-size: var(--font-product);
  font-weight: var(--weight-bold);
  line-height: 1.35;
  display: -webkit-box;
  overflow: hidden;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 2;
}

.featured-slide__description {
  margin: 4px 0 0;
  color: var(--color-text-secondary);
  font-size: var(--font-mini);
  line-height: 1.4;
  display: -webkit-box;
  overflow: hidden;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 2;
}

.featured-slide__footer {
  width: 100%;
  margin-top: auto;
  padding-top: 8px;
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 8px;
}

.featured-slide__price {
  color: var(--color-price);
  font-size: var(--font-headline);
  font-weight: var(--weight-bold);
  white-space: nowrap;
}

.featured-slide__cta {
  color: var(--color-brand-700);
  font-size: var(--font-mini);
  font-weight: var(--weight-semibold);
  white-space: nowrap;
}

.featured-dots {
  min-height: 28px;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 7px;
}

.featured-dot {
  width: 7px;
  height: 7px;
  flex: 0 0 7px;
  border-radius: 50%;
  background: var(--color-divider);
  transition: width var(--motion-fast) var(--motion-ease), background-color var(--motion-fast) var(--motion-ease);
}

.featured-dot.is-active {
  width: 18px;
  flex-basis: 18px;
  border-radius: var(--radius-pill);
  background: var(--color-brand-600);
}

.featured-controls__button:focus-visible,
.featured-dot:focus-visible,
.featured-slide__link:focus-visible {
  outline: 2px solid var(--color-brand-600);
  outline-offset: 2px;
}

@media (min-width: 700px) {
  .featured-slide__link { min-height: 216px; }
  .featured-slide__media { width: 42%; flex-basis: 42%; }
  .featured-slide__body { padding: 18px 20px 16px; }
  .featured-slide__title { margin-top: 12px; font-size: var(--font-headline); }
  .featured-slide__description { margin-top: 8px; font-size: var(--font-caption); }
  .featured-slide__footer { padding-top: 12px; }
  .featured-slide__cta { font-size: var(--font-caption); }
}

.goods-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: var(--space-3);
}

.goods-skeleton {
  background: var(--color-surface);
  border: 1px solid var(--color-divider);
  border-radius: var(--radius-lg);
  overflow: hidden;
  padding-bottom: 12px;
}

.goods-skeleton__img {
  width: 100%;
  aspect-ratio: 1 / 1;
  border-radius: 0;
}

.goods-skeleton__line {
  height: 12px;
  margin: 10px 12px 0;
}

.goods-skeleton__line--short {
  width: 50%;
}
</style>
