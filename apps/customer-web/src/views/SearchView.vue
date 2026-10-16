<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import PageHeader from '@/components/PageHeader.vue'
import ProductCard from '@/components/ProductCard.vue'
import EmptyState from '@/components/EmptyState.vue'
import SearchBox from '@/components/SearchBox.vue'
import { api, ApiError } from '@/api/client'
import { useShopStore } from '@/stores/shop'
import { useCartStore } from '@/stores/cart'
import { useToast } from '@/composables/toast'
import type { GoodsSummary } from '@/api/types'

/**
 * 搜索页（方案 §5.3 UI-C05）。
 *
 * 历史搜索存本地：搜索词是**顾客自己的行为数据**，
 * 放服务端既无必要（不跨设备用）也增加隐私面。方案 §2.1 C21 要求
 * 「隐私授权与数据最小化」，本地存储是这里最省数据的做法。
 */
const router = useRouter()
const cart = useCartStore()
const toast = useToast()

const HISTORY_KEY = 'chunlian.customer.search-history'
const shop = useShopStore()
const hotWords = computed(() => shop.setting?.search_hot_words ?? [])

const keyword = ref('')
const history = ref<string[]>(loadHistory())
const results = ref<GoodsSummary[]>([])
const searched = ref(false)
const loading = ref(false)

function loadHistory(): string[] {
  try {
    const raw = localStorage.getItem(HISTORY_KEY)
    const parsed = raw ? JSON.parse(raw) : []

    return Array.isArray(parsed) ? parsed.slice(0, 10) : []
  } catch {
    return []
  }
}

function saveHistory(word: string) {
  const next = [word, ...history.value.filter((w) => w !== word)].slice(0, 10)
  history.value = next
  try {
    localStorage.setItem(HISTORY_KEY, JSON.stringify(next))
  } catch {
    /* 忽略 */
  }
}

async function doSearch(word?: string) {
  const text = (word ?? keyword.value).trim()
  if (!text) {
    toast.info('请输入要搜索的商品')

    return
  }

  keyword.value = text
  saveHistory(text)
  loading.value = true
  searched.value = true

  try {
    const page = await api.goodsList({ keyword: text, page: 1, page_size: 40 })
    results.value = page.list
  } catch (error) {
    results.value = []
    toast.error(error instanceof ApiError ? error.message : '搜索失败，请重试')
  } finally {
    loading.value = false
  }
}

function clearHistory() {
  history.value = []
  try {
    localStorage.removeItem(HISTORY_KEY)
  } catch {
    /* 忽略 */
  }
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

onMounted(() => {
  shop.loadSetting(true).catch(() => null)
  // 输入框自动聚焦：方案 §5.2 要求「点击进入搜索页，输入法自动聚焦」
  window.setTimeout(() => {
    ;(document.getElementById('search-input') as HTMLInputElement | null)?.focus()
  }, 220)
})
</script>

<template>
  <div class="page">
    <PageHeader title="搜索">
      <template #extra>
        <button v-if="searched" class="btn btn--text" type="button" @click="searched = false">返回</button>
      </template>
    </PageHeader>

    <div class="page-body page-body--tabbar">
      <div class="search-page-field">
        <SearchBox
          v-model="keyword"
          mode="input"
          input-id="search-input"
          placeholder="搜索店内商品"
          :clearable="true"
          submit-text="搜索"
          @submit="doSearch()"
        />
      </div>

      <!-- 未搜索：历史 + 热搜 -->
      <template v-if="!searched">
        <section v-if="history.length" class="section">
          <div class="section-title">
            <span>历史搜索</span>
            <button class="section-title__more" type="button" @click="clearHistory">清空</button>
          </div>
          <div class="tag-list">
            <button v-for="word in history" :key="word" class="tag" type="button" @click="doSearch(word)">
              {{ word }}
            </button>
          </div>
        </section>

        <section v-if="hotWords.length" class="section">
          <div class="section-title"><span>店主推荐</span></div>
          <div class="tag-list">
            <button v-for="word in hotWords" :key="word" class="tag" type="button" @click="doSearch(word)">
              {{ word }}
            </button>
          </div>
        </section>
      </template>

      <!-- 已搜索 -->
      <template v-else>
        <div v-if="loading" class="goods-grid">
          <div v-for="i in 4" :key="i" class="goods-skeleton">
            <div class="skeleton goods-skeleton__img" />
            <div class="skeleton goods-skeleton__line" />
          </div>
        </div>

        <EmptyState
          v-else-if="results.length === 0"
          icon="search"
          title="没有找到相关商品"
          description="换个关键词试试，比如「青菜」「土豆」；也可以去分类里逛逛。"
          action-text="去分类看看"
          @action="router.push('/category')"
        />

        <div v-else class="goods-grid">
          <ProductCard v-for="item in results" :key="item.id" :goods="item" @add="quickAdd" />
        </div>
      </template>
    </div>
  </div>
</template>

<style scoped>
.search-page-field {
  margin-top: 4px;
}

.tag-list {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.tag {
  padding: 7px 14px;
  border-radius: var(--radius-pill);
  background: var(--color-surface);
  border: 1px solid var(--color-divider);
  font-size: var(--font-caption);
  color: var(--color-text-secondary);
}

.goods-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: var(--space-3);
  margin-top: var(--space-4);
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
