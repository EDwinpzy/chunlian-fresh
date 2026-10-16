<script setup lang="ts">
import { computed } from 'vue'
import AssetIcon from '@/components/AssetIcon.vue'

/**
 * 空态 / 错误态（方案 §5.4 GFeedback）。
 *
 * 方案 §4.5：空态可以不用插画，用单色小图标 + 说明 + 主按钮即可；
 * 错误态不能只靠颜色区分。
 */
const props = withDefaults(
  defineProps<{
    icon?: 'empty' | 'error' | 'search' | 'cart' | 'order' | 'offline'
    title: string
    description?: string
    actionText?: string
  }>(),
  { icon: 'empty', description: '', actionText: '' },
)

const emit = defineEmits<{ (e: 'action'): void }>()
const iconAsset = computed(() => ({
  empty: 'empty_basket',
  error: 'warning',
  search: 'search',
  cart: 'empty_basket',
  order: 'orders',
  offline: 'offline',
} as const)[props.icon])
</script>

<template>
  <div class="empty-state">
    <div class="empty-state__icon" :class="`is-${props.icon}`">
      <AssetIcon :name="iconAsset" :size="48" />
    </div>

    <p class="empty-state__title">{{ props.title }}</p>
    <p v-if="props.description" class="empty-state__desc">{{ props.description }}</p>

    <button v-if="props.actionText" class="btn btn--primary empty-state__action" type="button" @click="emit('action')">
      {{ props.actionText }}
    </button>
  </div>
</template>

<style scoped>
.empty-state {
  padding: 56px var(--space-6) 40px;
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
}

.empty-state__icon {
  margin-bottom: var(--space-4);
}

.empty-state__title {
  margin: 0;
  font-size: var(--font-product);
  font-weight: var(--weight-semibold);
  color: var(--color-text-primary);
}

.empty-state__desc {
  margin: 6px 0 0;
  font-size: var(--font-caption);
  color: var(--color-text-secondary);
  max-width: 260px;
  line-height: 1.6;
}

.empty-state__action {
  margin-top: var(--space-5);
  min-width: 140px;
}
</style>
