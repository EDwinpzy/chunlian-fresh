<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import ProductCard from '@/components/ProductCard.vue'
import EmptyState from '@/components/EmptyState.vue'
import AssetIcon from '@/components/AssetIcon.vue'
import SearchBox from '@/components/SearchBox.vue'
import { api, ApiError } from '@/api/client'
import { useCartStore } from '@/stores/cart'
import { useShopStore } from '@/stores/shop'
import { useToast } from '@/composables/toast'
import type { Category, GoodsSummary } from '@/api/types'

/**
 * 分类页（方案 §5.3 UI-C04）。
 * 左侧展示一级分类，右侧展示该分类下的二级分类筛选与双列商品卡。
 */
const route = useRoute()
const router = useRouter()
const shop = useShopStore()
const cart = useCartStore()
const toast = useToast()

const categories = ref<Category[]>([])
const activeId = ref('')
const goods = ref<GoodsSummary[]>([])
const loading = ref(false)
const failed = ref(false)
const unavailableCategoryIcons = ref(new Set<string>())

const activeCategory = computed(() => categories.value.find((category) => (
  category.id === activeId.value || category.children.some((child) => child.id === activeId.value)
)) ?? null)
const activeChildren = computed(() => activeCategory.value?.children ?? [])

function markCategoryIconUnavailable(id: string) {
  unavailableCategoryIcons.value = new Set(unavailableCategoryIcons.value).add(id)
}

async function loadCategories() {
  const list = await api.categories()
  categories.value = list

  const queryId = (route.query.id as string) || ''
  const allIds = list.flatMap((category) => [category.id, ...category.children.map((child) => child.id)])
  if (queryId && allIds.includes(queryId)) {
    activeId.value = queryId
  } else if (!allIds.includes(activeId.value) && list.length > 0) {
    activeId.value = list[0].id
  }
}

async function loadGoods() {
  if (!activeId.value) {
    goods.value = []

    return
  }

  loading.value = true
  failed.value = false

  try {
    const page = await api.goodsList({ category_id: activeId.value, page: 1, page_size: 50 })
    goods.value = page.list
  } catch {
    failed.value = true
  } finally {
    loading.value = false
  }
}

function selectCategory(id: string) {
  if (activeId.value === id) return
  activeId.value = id
  router.replace({ path: '/category', query: { id } })
}

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

watch(() => route.query.id, (id) => {
  if (typeof id === 'string' && id && id !== activeId.value) {
    activeId.value = id
  }
})

watch(activeId, loadGoods)

onMounted(async () => {
  await Promise.all([shop.loadSetting().catch(() => null), loadCategories()])
  await loadGoods()
})
</script>

<template>
  <div class="page">
    <div class="category-layout">
      <!-- 左侧分类条 -->
      <aside class="cat-rail">
        <button
          v-for="cat in categories"
          :key="cat.id"
          class="cat-rail__item"
          :class="{ 'is-active': activeCategory?.id === cat.id }"
          type="button"
          @click="selectCategory(cat.id)"
        >
          <span class="cat-rail__icon" aria-hidden="true">
            <AssetIcon name="category" :size="20" />
            <img
              v-if="cat.icon_url && !unavailableCategoryIcons.has(cat.id)"
              class="cat-rail__image"
              :src="cat.icon_url"
              alt=""
              loading="lazy"
              @error="markCategoryIconUnavailable(cat.id)"
            />
          </span>
          <span class="cat-rail__name">{{ cat.name }}</span>
        </button>
      </aside>

      <!-- 右侧商品 -->
      <section class="cat-main">
        <header class="cat-main__head">
          <SearchBox placeholder="搜索商品" @activate="router.push('/search')" />
        </header>

        <nav v-if="activeCategory && activeChildren.length" class="cat-subcategories" aria-label="二级分类">
          <button
            class="cat-subcategory"
            :class="{ 'is-active': activeId === activeCategory.id }"
            type="button"
            :aria-pressed="activeId === activeCategory.id"
            @click="selectCategory(activeCategory.id)"
          >
            <AssetIcon name="category" :size="16" />
            <span>全部{{ activeCategory.name }}</span>
          </button>
          <button
            v-for="child in activeChildren"
            :key="child.id"
            class="cat-subcategory"
            :class="{ 'is-active': activeId === child.id }"
            type="button"
            :aria-pressed="activeId === child.id"
            @click="selectCategory(child.id)"
          >
            <span class="cat-subcategory__icon" aria-hidden="true">
              <AssetIcon name="category" :size="14" />
              <img
                v-if="child.icon_url && !unavailableCategoryIcons.has(child.id)"
                class="cat-subcategory__image"
                :src="child.icon_url"
                alt=""
                loading="lazy"
                @error="markCategoryIconUnavailable(child.id)"
              />
            </span>
            <span>{{ child.name }}</span>
          </button>
        </nav>

        <div v-if="loading" class="goods-grid">
          <div v-for="i in 4" :key="i" class="goods-skeleton">
            <div class="skeleton goods-skeleton__img" />
            <div class="skeleton goods-skeleton__line" />
          </div>
        </div>

        <EmptyState
          v-else-if="failed"
          icon="error"
          title="加载失败"
          description="请检查网络后重试。"
          action-text="重试"
          @action="loadGoods"
        />

        <EmptyState
          v-else-if="goods.length === 0"
          icon="empty"
          title="该分类暂无商品"
          description="换个分类看看，或者去首页逛逛。"
          action-text="去首页"
          @action="router.push('/')"
        />

        <div v-else class="goods-grid">
          <ProductCard v-for="item in goods" :key="item.id" :goods="item" @add="quickAdd" />
        </div>
      </section>
    </div>
  </div>
</template>

<style scoped>
/* 分类页不用普通 page-body：左侧要固定、右侧独立滚动 */
.category-layout {
  display: flex;
  min-height: 100vh;
  min-height: 100dvh;
  padding-bottom: calc(var(--tabbar-height) + env(safe-area-inset-bottom));
}

.cat-rail {
  flex: 0 0 84px;
  background: var(--color-canvas);
  padding-top: calc(env(safe-area-inset-top) + 10px);
  border-right: 1px solid var(--color-divider);
  overflow-y: auto;
}

.cat-rail__item {
  width: 100%;
  min-height: 72px;
  padding: 10px 6px;
  font-size: var(--font-caption);
  color: var(--color-text-secondary);
  text-align: center;
  line-height: 1.35;
  position: relative;
  transition: color var(--motion-fast) var(--motion-ease);
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 5px;
}

.cat-rail__icon {
  position: relative;
  display: inline-flex;
  width: 28px;
  height: 28px;
  flex: 0 0 28px;
  align-items: center;
  justify-content: center;
  color: var(--color-brand-700);
}

.cat-rail__image {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  border-radius: 50%;
  background: var(--color-canvas);
  object-fit: contain;
}

.cat-rail__name {
  max-width: 100%;
  overflow-wrap: anywhere;
}

.cat-rail__item.is-active {
  color: var(--color-brand-700);
  font-weight: var(--weight-semibold);
  background: var(--color-surface);
}

.cat-rail__item.is-active::before {
  content: '';
  position: absolute;
  left: 0;
  top: 50%;
  transform: translateY(-50%);
  width: 3px;
  height: 20px;
  border-radius: 0 3px 3px 0;
  background: var(--color-brand-700);
}

.cat-main {
  flex: 1;
  min-width: 0;
  padding: 0 var(--space-3);
  padding-top: calc(env(safe-area-inset-top) + 10px);
}

.cat-main__head {
  margin-bottom: var(--space-3);
}

.cat-subcategories {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin: 0 0 var(--space-3);
}

.cat-subcategory {
  max-width: 100%;
  min-height: 32px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 5px;
  padding: 6px 10px;
  border: 1px solid var(--color-divider);
  border-radius: var(--radius-pill);
  background: var(--color-surface);
  color: var(--color-text-secondary);
  font-size: var(--font-mini);
  line-height: 1.4;
  overflow-wrap: anywhere;
  text-align: center;
}

.cat-subcategory__icon {
  position: relative;
  display: inline-flex;
  width: 16px;
  height: 16px;
  flex: 0 0 16px;
  align-items: center;
  justify-content: center;
  color: var(--color-brand-700);
}

.cat-subcategory__image {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  border-radius: 50%;
  background: var(--color-surface);
  object-fit: contain;
}

.cat-subcategory.is-active {
  border-color: var(--color-brand-600);
  background: var(--color-brand-100);
  color: var(--color-brand-700);
  font-weight: var(--weight-semibold);
}

.goods-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 10px;
  padding-bottom: var(--space-5);
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
</style>
