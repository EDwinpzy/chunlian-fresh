<script setup lang="ts">
import { computed } from 'vue'

type IconName =
  | 'home'
  | 'category'
  | 'cart'
  | 'profile'
  | 'search'
  | 'orders'
  | 'phone'
  | 'product_detail'
  | 'refund'
  | 'brand'
  | 'address'
  | 'add_to_cart'
  | 'back'
  | 'close'
  | 'cod'
  | 'coupon'
  | 'customer_service'
  | 'delivery'
  | 'delivery_time'
  | 'empty_basket'
  | 'favorite'
  | 'hot_sale'
  | 'loading'
  | 'new_item'
  | 'notification'
  | 'offline'
  | 'payment'
  | 'quantity_minus'
  | 'quantity_plus'
  | 'refresh'
  | 'share'
  | 'success'
  | 'warning'

type TabIconName = 'home' | 'category' | 'cart' | 'profile' | 'search'
type OpticalBounds = readonly [width: number, height: number, left: number, top: number, right: number, bottom: number]

// Bounds of visible alpha (alpha > 16) in each source PNG. The images came from
// different artboards, so using their full 96px canvases made the artwork look
// uneven and, in a few cases, visibly off-center. Keep the slot size stable and
// scale/translate only the pixels inside it.
const opticalBounds: Record<string, OpticalBounds> = {
  brand: [1254, 1254, 174, 164, 1080, 1089],
  'tabbar/cart_active': [96, 96, 5, 7, 91, 89],
  'tabbar/cart_normal': [96, 96, 5, 7, 91, 89],
  'tabbar/category_active': [96, 96, 5, 7, 91, 89],
  'tabbar/category_normal': [96, 96, 5, 7, 91, 89],
  'tabbar/home_active': [96, 96, 5, 7, 91, 89],
  'tabbar/home_normal-v2-aligned': [96, 96, 8, 10, 88, 86],
  'tabbar/profile_active': [96, 96, 5, 7, 91, 89],
  'tabbar/profile_normal': [96, 96, 5, 7, 91, 89],
  'tabbar/search_active': [96, 96, 5, 7, 91, 89],
  'tabbar/search_normal': [96, 96, 5, 7, 91, 89],
  'tabbar/home_art': [96, 96, 25, 27, 91, 90],
  'tabbar/category_art': [96, 96, 16, 15, 69, 66],
  'tabbar/cart_art': [96, 96, 12, 13, 79, 67],
  'tabbar/profile_art': [96, 96, 16, 14, 76, 79],
  'tabbar/search_art': [96, 96, 18, 13, 75, 66],
  'customer/add_to_cart': [96, 96, 10, 23, 79, 78],
  'customer/address': [96, 96, 34, 28, 75, 82],
  'customer/back': [96, 96, 24, 23, 76, 68],
  'customer/cart': [96, 96, 12, 13, 79, 67],
  'customer/category': [96, 96, 16, 15, 69, 66],
  'customer/close': [96, 96, 21, 18, 75, 72],
  'customer/cod': [96, 96, 15, 27, 81, 82],
  'customer/coupon': [96, 96, 10, 27, 80, 80],
  'customer/customer_service': [96, 96, 17, 26, 80, 82],
  'customer/delivery': [96, 96, 9, 33, 76, 79],
  'customer/delivery_time': [96, 96, 27, 22, 83, 78],
  'customer/empty_basket': [96, 96, 9, 17, 76, 72],
  'customer/favorite': [96, 96, 15, 34, 73, 82],
  'customer/hot_sale': [96, 96, 23, 21, 72, 78],
  'customer/loading': [96, 96, 22, 13, 78, 67],
  'customer/new_item': [96, 96, 19, 28, 77, 78],
  'customer/notification': [96, 96, 24, 21, 76, 77],
  'customer/offline': [96, 96, 20, 17, 89, 65],
  'customer/orders': [96, 96, 16, 30, 70, 82],
  'customer/payment': [96, 96, 20, 24, 79, 79],
  'customer/phone': [96, 96, 26, 31, 76, 82],
  'customer/product_detail': [96, 96, 32, 27, 80, 80],
  'customer/quantity_minus': [96, 96, 27, 18, 81, 72],
  'customer/quantity_plus': [96, 96, 15, 22, 69, 77],
  'customer/refresh': [96, 96, 20, 13, 75, 66],
  'customer/refund': [96, 96, 21, 25, 81, 81],
  'customer/search': [96, 96, 18, 13, 75, 66],
  'customer/share': [96, 96, 19, 30, 70, 82],
  'customer/success': [96, 96, 20, 18, 74, 72],
  'customer/warning': [96, 96, 15, 21, 72, 71],
}

const props = withDefaults(
  defineProps<{ name: IconName; active?: boolean; size?: number; rotation?: number; variant?: 'customer' | 'tabbar' }>(),
  { active: false, size: 24, rotation: 0, variant: 'customer' },
)

const asset = computed(() => {
  const base = import.meta.env.BASE_URL
  if (props.name === 'brand') {
    return { source: base + 'assets/brand/spring-fresh.png?v=2', key: 'brand' }
  }
  if (props.variant === 'tabbar' && ['home', 'category', 'cart', 'profile', 'search'].includes(props.name)) {
    const name = props.name as TabIconName
    const file = `${name}_art.png`
    return { source: base + 'assets/user-art/tabbar/' + file, key: `tabbar/${name}_art` }
  }
  return {
    source: base + 'assets/icons/customer/' + props.name + '.png',
    key: 'customer/' + props.name,
  }
})

const containerStyle = computed(() => ({
  width: `${props.size}px`,
  height: `${props.size}px`,
  transform: props.rotation ? `rotate(${props.rotation}deg)` : undefined,
}))

const imageStyle = computed(() => {
  const bounds = opticalBounds[asset.value.key]
  if (!bounds) return undefined

  const [width, height, left, top, right, bottom] = bounds
  const visibleWidth = (right - left) / width
  const visibleHeight = (bottom - top) / height
  const scale = 0.82 / Math.max(visibleWidth, visibleHeight)
  const visibleCenterX = (left + right) / 2 / width
  const visibleCenterY = (top + bottom) / 2 / height
  const offsetX = (0.5 - visibleCenterX) * props.size * scale
  const offsetY = (0.5 - visibleCenterY) * props.size * scale

  return {
    transform: `translate3d(${offsetX.toFixed(2)}px, ${offsetY.toFixed(2)}px, 0) scale(${scale.toFixed(4)})`,
  }
})
</script>
<template>
  <span
    class="asset-icon"
    :style="containerStyle"
    aria-hidden="true"
  >
    <img :src="asset.source" :style="imageStyle" alt="" draggable="false" decoding="async" />
  </span>
</template>
<style scoped>
.asset-icon { display: inline-flex; align-items: center; justify-content: center; flex: 0 0 auto; vertical-align: middle; overflow: hidden; }
.asset-icon img { display: block; width: 100%; height: 100%; object-fit: contain; transform-origin: center; user-select: none; pointer-events: none; }
</style>
