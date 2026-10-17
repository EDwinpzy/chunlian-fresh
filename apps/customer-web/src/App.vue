<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import BottomSheet from '@/components/BottomSheet.vue'
import GoodsImage from '@/components/GoodsImage.vue'
import TabBar from '@/components/TabBar.vue'
import ToastHost from '@/components/ToastHost.vue'
import { useAuthStore } from '@/stores/auth'
import { useShopStore } from '@/stores/shop'

const route = useRoute()
const auth = useAuthStore()
const shop = useShopStore()
const showNotificationPopup = ref(false)
const notificationPopupEvaluated = ref(false)
const notificationPopupTitle = ref('')
const notificationPopupImageUrl = ref('')

const showTabBar = computed(() => route.meta.tabbar === true)

watch(() => shop.setting, (setting) => {
  if (!setting || notificationPopupEvaluated.value) return

  notificationPopupEvaluated.value = true
  const title = setting.notification_popup_title?.trim() || ''
  const imageUrl = setting.notification_popup_image_url?.trim() || ''
  if (!title || !imageUrl) return

  notificationPopupTitle.value = title
  notificationPopupImageUrl.value = imageUrl
  showNotificationPopup.value = true
}, { immediate: true })

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
    <BottomSheet v-model="showNotificationPopup" :title="notificationPopupTitle">
      <div class="notification-popup-image">
        <GoodsImage :src="notificationPopupImageUrl" :alt="notificationPopupTitle" fallback-text="通知" />
      </div>
    </BottomSheet>
  </div>
</template>

<style scoped>
.app-shell {
  min-height: 100vh;
  min-height: 100dvh;
}

.notification-popup-image :deep(.goods-image) {
  aspect-ratio: 4 / 3;
}

.notification-popup-image :deep(.goods-image img) {
  object-fit: contain;
}
</style>
