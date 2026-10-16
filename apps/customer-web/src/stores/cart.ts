import { defineStore } from 'pinia'
import { computed, ref, watch } from 'vue'
import type { CartItem, GoodsDetail, Sku } from '@/api/types'

const CART_KEY = 'chunlian.customer.cart'

/**
 * 购物车（纯本地）。
 *
 * 【为什么购物车不放服务端】
 * 本店是单店自营、顾客一次买几样菜，购物车不需要跨设备同步，
 * 也不需要后台看到「谁的车里有什么」。放本地有两个实际好处：
 *   1. 加减数量零延迟，不会因为一次网络抖动丢掉操作；
 *   2. 服务端不需要为「僵尸购物车」做清理与库存占用，
 *      而库存预占恰恰是最容易出错的环节（见方案 §3.4）。
 * 真正的库存校验在下单那一刻由服务端在事务内做。
 */
export const useCartStore = defineStore('cart', () => {
  const items = ref<CartItem[]>(load())

  const count = computed(() => items.value.reduce((sum, it) => sum + it.qty, 0))
  const totalFen = computed(() => items.value.reduce((sum, it) => sum + it.unit_fen * it.qty, 0))
  const isEmpty = computed(() => items.value.length === 0)

  function load(): CartItem[] {
    try {
      const raw = localStorage.getItem(CART_KEY)
      if (!raw) return []
      const parsed = JSON.parse(raw)
      if (!Array.isArray(parsed)) return []

      // 兼容旧数据：字段缺失的条目直接丢弃，避免渲染时到处判空
      return parsed.filter(
        (it) => it && typeof it.sku_id === 'string' && typeof it.qty === 'number' && it.qty > 0,
      ) as CartItem[]
    } catch {
      return []
    }
  }

  watch(
    items,
    (value) => {
      try {
        localStorage.setItem(CART_KEY, JSON.stringify(value))
      } catch {
        /* 存储不可用时静默降级为内存购物车 */
      }
    },
    { deep: true },
  )

  function add(goods: GoodsDetail, sku: Sku, qty = 1): { ok: boolean; message: string } {
    const limit = Math.min(sku.inventory, 99)
    if (limit <= 0) {
      return { ok: false, message: '该规格已售罄' }
    }

    const existing = items.value.find((it) => it.sku_id === sku.sku_id)
    const nextQty = (existing?.qty ?? 0) + qty
    if (nextQty > limit) {
      return { ok: false, message: `最多可购买 ${limit} 份` }
    }

    if (existing) {
      existing.qty = nextQty
      // 价格与库存可能已经变了，顺手刷新，避免用旧价结算
      existing.unit_fen = sku.price_fen
      existing.inventory = sku.inventory
    } else {
      items.value.push({
        sku_id: sku.sku_id,
        product_id: goods.id,
        name: goods.title,
        spec: sku.spec_text,
        image_url: goods.image_url,
        unit_fen: sku.price_fen,
        qty,
        inventory: sku.inventory,
        added_at: Date.now(),
      })
    }

    return { ok: true, message: '已加入购物车' }
  }

  function setQty(skuId: string, qty: number): { ok: boolean; message: string } {
    const item = items.value.find((it) => it.sku_id === skuId)
    if (!item) return { ok: false, message: '商品不在购物车中' }

    if (qty <= 0) {
      remove(skuId)

      return { ok: true, message: '已移除' }
    }
    if (qty > Math.min(item.inventory, 99)) {
      return { ok: false, message: `最多可购买 ${Math.min(item.inventory, 99)} 份` }
    }

    item.qty = qty

    return { ok: true, message: '' }
  }

  function remove(skuId: string) {
    items.value = items.value.filter((it) => it.sku_id !== skuId)
  }

  function clear() {
    items.value = []
  }

  function qtyOf(skuId: string): number {
    return items.value.find((it) => it.sku_id === skuId)?.qty ?? 0
  }

  /** 下单用的精简结构。 */
  function toOrderItems(): { sku_id: string; qty: number }[] {
    return items.value.map((it) => ({ sku_id: it.sku_id, qty: it.qty }))
  }

  /** 下单成功后按实际成交的 SKU 清空（而不是整车清空，避免误删刚加的东西）。 */
  function removeMany(skuIds: string[]) {
    const set = new Set(skuIds)
    items.value = items.value.filter((it) => !set.has(it.sku_id))
  }

  return {
    items,
    count,
    totalFen,
    isEmpty,
    add,
    setQty,
    remove,
    clear,
    qtyOf,
    toOrderItems,
    removeMany,
  }
})
