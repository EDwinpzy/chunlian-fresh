<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import GoodsImage from '@/components/GoodsImage.vue'
import QuantityStepper from '@/components/QuantityStepper.vue'
import EmptyState from '@/components/EmptyState.vue'
import BottomSheet from '@/components/BottomSheet.vue'
import AssetIcon from '@/components/AssetIcon.vue'
import { useCartStore } from '@/stores/cart'
import { useShopStore } from '@/stores/shop'
import { useToast } from '@/composables/toast'
import { formatFen } from '@/utils/format'

/**
 * 购物车（方案 §5.2 UI-C03 上半页）。
 *
 * 【为什么不做「选中/未选中」勾选】
 * 本店是「买菜」场景：顾客加进购物车的基本都是本次要买的，
 * 逐个勾选只会增加操作步骤。整单结算更符合实际。
 * （方案里画了勾选框，但同时也要求「少入口、少步骤」，
 *   这里取后者 —— 若后续确实需要部分结算，再加也不影响数据模型。）
 */
const router = useRouter()
const cart = useCartStore()
const shop = useShopStore()
const toast = useToast()

const confirmClear = ref(false)

const deliveryFeeFen = computed(() => shop.setting?.delivery_fee_fen ?? 0)
const totalFen = computed(() => cart.totalFen + deliveryFeeFen.value)
const hasSoldOut = computed(() => cart.items.some((it) => it.inventory <= 0))

function changeQty(skuId: string, value: number) {
  const result = cart.setQty(skuId, value)
  if (!result.ok) toast.warning(result.message)
}

function removeItem(skuId: string, name: string) {
  cart.remove(skuId)
  toast.info(`已移除「${name}」`)
}

function doClear() {
  cart.clear()
  confirmClear.value = false
  toast.info('购物车已清空')
}

function goCheckout() {
  if (cart.isEmpty) return
  if (hasSoldOut.value) {
    toast.warning('购物车中有已售罄的商品，请先移除')

    return
  }
  router.push('/checkout')
}
</script>

<template>
  <div class="page">
    <header class="cart-head">
      <h1 class="cart-head__title">购物车</h1>
      <button v-if="!cart.isEmpty" class="btn btn--text" type="button" @click="confirmClear = true">
        清空
      </button>
    </header>

    <div class="page-body page-body--tabbar">
      <EmptyState
        v-if="cart.isEmpty"
        icon="cart"
        title="购物车还是空的"
        description="去首页挑几样今天要吃的菜吧。"
        action-text="去逛逛"
        @action="router.push('/')"
      />

      <template v-else>
        <p v-if="shop.setting && !shop.setting.is_open" class="cart-closed">
          店铺当前已打烊，可以先挑菜，营业后再下单。
        </p>

        <ul class="cart-list">
          <li v-for="item in cart.items" :key="item.sku_id" class="cart-item">
            <GoodsImage
              :src="item.image_url"
              :alt="item.name"
              :fallback-text="item.name"
              radius="var(--radius-md)"
            />

            <div class="cart-item__body">
              <h3 class="cart-item__name clamp-2">{{ item.name }}</h3>
              <p class="cart-item__spec caption">{{ item.spec }}</p>

              <p v-if="item.inventory <= 0" class="cart-item__warn">该商品已售罄，请移除</p>

              <div class="cart-item__foot">
                <span class="price num">{{ formatFen(item.unit_fen) }}</span>
                <QuantityStepper
                  :model-value="item.qty"
                  :min="0"
                  :max="Math.min(item.inventory, 99)"
                  @update:model-value="(v: number) => changeQty(item.sku_id, v)"
                  @limit="(msg: string) => toast.warning(msg)"
                />
              </div>
            </div>

            <button class="cart-item__remove" type="button" aria-label="移除" @click="removeItem(item.sku_id, item.name)">
              <AssetIcon name="close" :size="16" />
            </button>
          </li>
        </ul>

        <p class="cart-note mini">
          商品小计 {{ formatFen(cart.totalFen) }}，配送费 {{ formatFen(deliveryFeeFen) }}，以结算页为准。
        </p>
      </template>
    </div>

    <!-- 底部结算条 -->
    <div v-if="!cart.isEmpty" class="action-bar cart-bar">
      <div class="cart-bar__sum">
        <span class="caption">合计</span>
        <span class="price num cart-bar__amount">{{ formatFen(totalFen) }}</span>
      </div>
      <button class="btn btn--primary cart-bar__go" type="button" @click="goCheckout">
        去结算（{{ cart.count }}）
      </button>
    </div>

    <BottomSheet v-model="confirmClear" title="清空购物车" :mask-closable="false">
      <p class="sheet-text">购物车里的 {{ cart.count }} 件商品会被全部移除，此操作不可撤销。</p>
      <template #footer>
        <button class="btn btn--ghost" type="button" @click="confirmClear = false">再想想</button>
        <button class="btn btn--danger" type="button" @click="doClear">确认清空</button>
      </template>
    </BottomSheet>
  </div>
</template>

<style scoped>
.cart-head {
  padding: calc(env(safe-area-inset-top) + 14px) var(--page-padding) 8px;
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.cart-head__title {
  margin: 0;
  font-size: var(--font-title);
  font-weight: var(--weight-bold);
  color: var(--color-brand-700);
}

.cart-closed {
  margin: 0 0 var(--space-3);
  padding: 8px 12px;
  border-radius: var(--radius-sm);
  background: var(--color-warning-bg);
  color: var(--color-warning);
  font-size: var(--font-caption);
}

.cart-list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: var(--space-3);
}

.cart-item {
  position: relative;
  background: var(--color-surface);
  border: 1px solid var(--color-divider);
  border-radius: var(--radius-lg);
  padding: 12px;
  display: flex;
  gap: 12px;
}

.cart-item :deep(.goods-image) {
  flex: 0 0 78px;
  width: 78px;
}

.cart-item__body {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
}

.cart-item__name {
  margin: 0;
  font-size: var(--font-product);
  font-weight: var(--weight-semibold);
  line-height: 1.4;
  padding-right: 22px;
}

.cart-item__spec {
  margin: 2px 0 0;
}

.cart-item__warn {
  margin: 4px 0 0;
  font-size: var(--font-mini);
  color: var(--color-danger);
}

.cart-item__foot {
  margin-top: auto;
  padding-top: 8px;
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.cart-item__remove {
  position: absolute;
  top: 8px;
  right: 8px;
  width: 26px;
  height: 26px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  color: var(--color-text-muted);
}

.cart-note {
  margin: var(--space-4) 0 0;
  text-align: center;
}

.cart-bar__sum {
  flex: 1;
  display: flex;
  flex-direction: column;
  line-height: 1.25;
}

.cart-bar__amount {
  font-size: var(--font-headline);
}

.cart-bar__go {
  flex: 0 0 auto;
  min-width: 140px;
}

.sheet-text {
  margin: 0;
  font-size: var(--font-body);
  color: var(--color-text-secondary);
  line-height: 1.6;
}
</style>
