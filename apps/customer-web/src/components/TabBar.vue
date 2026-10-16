<script setup lang="ts">
import { computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useCartStore } from '@/stores/cart'
import AssetIcon from '@/components/AssetIcon.vue'

/**
 * 底部导航：复用真实图标，选中状态由文字颜色表达，购物车显示数量角标。
 */
const route = useRoute()
const router = useRouter()
const cart = useCartStore()

const tabs = [
  { name: 'home', icon: 'home', label: '首页', path: '/' },
  { name: 'category', icon: 'category', label: '分类', path: '/category' },
  { name: 'cart', icon: 'cart', label: '购物车', path: '/cart' },
  { name: 'me', icon: 'profile', label: '我的', path: '/me' },
] as const

const current = computed(() => (route.name as string) ?? 'home')

function go(path: string) {
  if (route.path !== path) router.push(path)
}
</script>

<template>
  <nav class="tabbar">
    <button
      v-for="tab in tabs"
      :key="tab.name"
      class="tabbar__item"
      :class="{ 'is-active': current === tab.name }"
      type="button"
      @click="go(tab.path)"
    >
      <span class="tabbar__icon">
        <AssetIcon :name="tab.icon" variant="tabbar" :active="current === tab.name" :size="24" />

        <span v-if="tab.name === 'cart' && cart.count > 0" class="tabbar__badge">
          {{ cart.count > 99 ? '99+' : cart.count }}
        </span>
      </span>
      <span class="tabbar__label">{{ tab.label }}</span>
    </button>
  </nav>
</template>

<style scoped>
.tabbar {
  position: fixed;
  left: 0;
  right: 0;
  bottom: 0;
  z-index: 30;
  height: calc(var(--tabbar-height) + env(safe-area-inset-bottom));
  padding-bottom: env(safe-area-inset-bottom);
  background: var(--color-surface);
  border-top: 1px solid var(--color-divider);
  display: flex;
  align-items: stretch;
}

.tabbar__item {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 2px;
  color: var(--color-text-muted);
  transition: color var(--motion-fast) var(--motion-ease);
}

.tabbar__item.is-active {
  color: var(--color-brand-700);
}

.tabbar__icon {
  position: relative;
  display: inline-flex;
  width: 24px;
  height: 24px;
  align-items: center;
  justify-content: center;
  border-radius: var(--radius-xs);
}

.tabbar__label {
  font-size: var(--font-mini);
  line-height: 1.2;
  font-weight: var(--weight-semibold);
}

.tabbar__badge {
  position: absolute;
  top: -5px;
  right: -10px;
  min-width: 16px;
  height: 16px;
  padding: 0 4px;
  border-radius: var(--radius-pill);
  background: var(--color-price);
  color: var(--color-on-brand);
  font-size: var(--font-mini);
  line-height: 16px;
  text-align: center;
  font-weight: var(--weight-semibold);
}
</style>
