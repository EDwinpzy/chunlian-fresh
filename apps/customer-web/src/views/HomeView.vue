<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import ProductCard from '@/components/ProductCard.vue'
import EmptyState from '@/components/EmptyState.vue'
import ShopLogo from '@/components/ShopLogo.vue'
import AssetIcon from '@/components/AssetIcon.vue'
import SearchBox from '@/components/SearchBox.vue'
import { api, ApiError } from '@/api/client'
import { useCartStore } from '@/stores/cart'
import { useShopStore } from '@/stores/shop'
import { useToast } from '@/composables/toast'
import type { GoodsSummary } from '@/api/types'

/**
 * 首页（方案 §5.2 UI-C01）。
 *
 * 结构固定为：品牌与推荐文案 → 搜索框 → 分类捷径 → 今日上架商品双列网格。
 * 不做自动播放的轮播（方案 §5.2 明确「优先静态，禁止自动声音」），
 * 因为生鲜顾客打开首页的目的是「快速找到今天要买的菜」，
 * 会动的横幅只会抢注意力。
 */
const router = useRouter()
const cart = useCartStore()
const shop = useShopStore()
const toast = useToast()

const goods = ref<GoodsSummary[]>([])
const loading = ref(true)
const failed = ref(false)

const notice = computed(() => shop.setting?.notice || '')
const isOpen = computed(() => shop.setting?.is_open !== false)
const headerHighlights = computed(() => [
  shop.setting?.shop_slogan,
  shop.setting?.home_banner_title,
  shop.setting?.home_banner_subtitle,
].filter((value): value is string => Boolean(value?.trim())).join(' · '))

async function load() {
  loading.value = true
  failed.value = false

  try {
    const [list] = await Promise.all([api.goodsList({ page: 1, page_size: 12 }), shop.loadCategories(true), shop.loadSetting(true)])
    goods.value = list.list
  } catch {
    failed.value = true
  } finally {
    loading.value = false
  }
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

function goCategory(id: string) {
  router.push({ path: '/category', query: { id } })
}

onMounted(load)
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
      <nav v-if="shop.categories.length" class="home-cats scroll-x">
        <button
          v-for="cat in shop.categories"
          :key="cat.id"
          class="home-cat"
          type="button"
          @click="goCategory(cat.id)"
        >
          <span class="home-cat__name">{{ cat.name }}</span>
        </button>
      </nav>

      <!-- 商品 -->
      <section class="section">
        <div class="section-title">
          <span>{{ shop.setting?.home_section_title || '店内商品' }}</span>
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
          title="今天还没有上架商品"
          description="店主正在备货，稍后再来看看。"
          action-text="刷新"
          @action="load"
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
  padding: 0 12px;
  border: 1px solid var(--color-divider);
  border-radius: var(--radius-pill);
  background: var(--color-surface);
  display: flex;
  align-items: center;
  justify-content: center;
}

.home-cat__name {
  font-size: var(--font-caption);
  color: var(--color-text-primary);
  white-space: nowrap;
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
