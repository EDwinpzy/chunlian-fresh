<script setup lang="ts">
import { computed, onMounted, onUnmounted, watch } from 'vue'
import { useRoute } from 'vue-router'
import TabBar from '@/components/TabBar.vue'
import ToastHost from '@/components/ToastHost.vue'
import { useAuthStore } from '@/stores/auth'
import { useShopStore } from '@/stores/shop'

const route = useRoute()
const auth = useAuthStore()
const shop = useShopStore()

const showTabBar = computed(() => route.meta.tabbar === true)

watch(() => [route.meta.title, shop.setting?.shop_name], () => {
  const name = shop.setting?.shop_name || '店铺'
  document.title = route.meta.title ? `${route.meta.title} · ${name}` : name
}, { immediate: true })

function refreshShop() {
  if (document.visibilityState === 'visible') {
    shop.loadSetting(true).catch(() => null)
    shop.loadCategories(true).catch(() => null)
  }
}
onMounted(() => document.addEventListener('visibilitychange', refreshShop))
onUnmounted(() => document.removeEventListener('visibilitychange', refreshShop))

watch(() => route.fullPath, () => shop.loadSetting(true).catch(() => null))

onMounted(async () => {
  // 恢复登录态：token 在 localStorage 里，用户重新打开时要静默校验一次
  if (auth.isLoggedIn) {
    await auth.fetchProfile()
  }

  // 店铺设置（营业状态/公告/配送费）是全局信息，首屏拉一次即可
  try {
    await shop.loadSetting()
  } catch {
    // 首页自己会处理错误态，这里不打断渲染
  }
})
</script>

<template>
  <div class="app-shell">
    <router-view v-slot="{ Component }">
      <transition name="fade" mode="out-in">
        <component :is="Component" />
      </transition>
    </router-view>

    <TabBar v-if="showTabBar" />
    <ToastHost />
  </div>
</template>

<style scoped>
.app-shell {
  min-height: 100vh;
  min-height: 100dvh;
}
</style>
