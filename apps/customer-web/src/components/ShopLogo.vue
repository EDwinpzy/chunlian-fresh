<script setup lang="ts">
import { ref, watch } from 'vue'
import { useShopStore } from '@/stores/shop'
import AssetIcon from '@/components/AssetIcon.vue'
const props = withDefaults(defineProps<{ size?: number }>(), { size: 40 })
const shop = useShopStore()
const failed = ref(false)
watch(() => shop.setting?.shop_logo_url, () => { failed.value = false })
</script>

<template>
  <span
    class="shop-logo"
    role="img"
    :aria-label="`${shop.setting?.shop_name || '店铺'}标志`"
    :style="{ width: `${props.size}px`, height: `${props.size}px` }"
  >
    <img v-if="shop.setting?.shop_logo_url && !failed" :src="shop.setting.shop_logo_url" :alt="`${shop.setting.shop_name}标志`" @error="failed = true" />
    <AssetIcon v-else name="brand" :size="props.size" />
  </span>
</template>

<style scoped>
.shop-logo {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  overflow: hidden;
}

.shop-logo img {
  width: 100%;
  height: 100%;
  object-fit: contain;
}
</style>
