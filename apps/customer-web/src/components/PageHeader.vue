<script setup lang="ts">
import { useRouter } from 'vue-router'

/**
 * 二级页面顶部栏。
 *
 * 高度按 `--header-height` 固定，并叠加 `env(safe-area-inset-top)` ——
 * 方案 §4.4 明确要求使用平台运行时安全区，不得硬编码状态栏高度。
 */
withDefaults(
  defineProps<{
    title?: string
    /** 右侧插槽是否占位（无内容时不占位，标题才能真正居中） */
    back?: boolean
  }>(),
  { title: '', back: true },
)

const router = useRouter()

function goBack() {
  if (window.history.length > 1) {
    router.back()
  } else {
    router.replace('/')
  }
}
</script>

<template>
  <header class="page-header">
    <div class="page-header__bar">
      <button v-if="back" class="page-header__back" type="button" aria-label="返回" @click="goBack">
        <span class="page-header__chevron" aria-hidden="true" />
      </button>
      <span v-else class="page-header__placeholder" />

      <h1 class="page-header__title ellipsis">{{ title }}</h1>

      <div class="page-header__extra">
        <slot name="extra" />
      </div>
    </div>
  </header>
</template>

<style scoped>
.page-header {
  position: sticky;
  top: 0;
  z-index: 15;
  padding-top: env(safe-area-inset-top);
  background: var(--color-canvas-translucent);
  backdrop-filter: blur(12px);
  border-bottom: 1px solid transparent;
}

.page-header__bar {
  height: var(--header-height);
  display: flex;
  align-items: center;
  gap: var(--space-2);
  padding: 0 var(--space-3);
}

.page-header__back,
.page-header__placeholder {
  width: 36px;
  height: 36px;
  flex: 0 0 36px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  color: var(--color-text-secondary);
}

.page-header__chevron {
  width: 8px;
  height: 8px;
  border-top: 1.75px solid currentColor;
  border-right: 1.75px solid currentColor;
  transform: rotate(225deg);
}

.page-header__title {
  flex: 1;
  margin: 0;
  font-size: var(--font-section);
  font-weight: var(--weight-semibold);
  text-align: center;
}

.page-header__extra {
  min-width: 36px;
  flex: 0 0 auto;
  display: inline-flex;
  align-items: center;
  justify-content: flex-end;
  color: var(--color-text-secondary);
  font-size: var(--font-caption);
}
</style>
