<script setup lang="ts">
import { watch } from 'vue'
import AssetIcon from '@/components/AssetIcon.vue'

/**
 * 底部弹层（方案 §5.4 GDialog 的底部变体）。
 *
 * 【为什么取消订单、确认收货都用底部弹层而不是居中弹窗】
 * 手机上居中弹窗的手指行程更远、误触关闭率更高；
 * 底部弹层贴近拇指、可下滑关闭，是移动端确认动作的默认形态。
 */
const props = withDefaults(
  defineProps<{
    modelValue: boolean
    title?: string
    /** 是否显示右上角关闭按钮 */
    closable?: boolean
    /** 点遮罩是否关闭（破坏性操作应设为 false，避免误触关闭丢失输入） */
    maskClosable?: boolean
  }>(),
  { title: '', closable: true, maskClosable: true },
)

const emit = defineEmits<{
  (e: 'update:modelValue', value: boolean): void
}>()

function close() {
  emit('update:modelValue', false)
}

function onMask() {
  if (props.maskClosable) close()
}

// 弹层打开时锁住页面滚动，否则背景会跟着手指滑动
watch(
  () => props.modelValue,
  (open) => {
    document.body.style.overflow = open ? 'hidden' : ''
  },
)
</script>

<template>
  <teleport to="body">
    <transition name="sheet">
      <div v-if="modelValue" class="sheet-root">
        <div class="sheet-mask" @click="onMask" />
        <div class="sheet-panel" role="dialog" aria-modal="true">
          <header v-if="title || closable" class="sheet-header">
            <h2 class="sheet-title">{{ title }}</h2>
            <button v-if="closable" class="sheet-close" type="button" aria-label="关闭" @click="close">
              <AssetIcon name="close" :size="20" />
            </button>
          </header>

          <div class="sheet-body">
            <slot />
          </div>

          <footer v-if="$slots.footer" class="sheet-footer">
            <slot name="footer" />
          </footer>
        </div>
      </div>
    </transition>
  </teleport>
</template>

<style scoped>
.sheet-root {
  position: fixed;
  inset: 0;
  z-index: 60;
}

.sheet-mask {
  position: absolute;
  inset: 0;
  background: var(--color-scrim);
}

.sheet-panel {
  position: absolute;
  left: 0;
  right: 0;
  bottom: 0;
  background: var(--color-surface);
  border-radius: var(--radius-sheet) var(--radius-sheet) 0 0;
  box-shadow: var(--shadow-sheet);
  padding-bottom: env(safe-area-inset-bottom);
  max-height: 86vh;
  display: flex;
  flex-direction: column;
}

.sheet-header {
  position: relative;
  padding: 18px var(--space-4) 10px;
  display: flex;
  align-items: center;
  justify-content: center;
}

.sheet-title {
  margin: 0;
  font-size: var(--font-product);
  font-weight: var(--weight-semibold);
}

.sheet-close {
  position: absolute;
  right: var(--space-3);
  top: 14px;
  width: 32px;
  height: 32px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  color: var(--color-text-muted);
}

.sheet-body {
  padding: 0 var(--space-4);
  overflow-y: auto;
}

.sheet-footer {
  padding: var(--space-4);
  display: flex;
  gap: var(--space-3);
}

.sheet-footer :deep(.btn) {
  flex: 1;
}

.sheet-enter-active,
.sheet-leave-active {
  transition: opacity var(--motion-base) var(--motion-ease);
}

.sheet-enter-active .sheet-panel,
.sheet-leave-active .sheet-panel {
  transition: transform var(--motion-base) var(--motion-ease);
}

.sheet-enter-from,
.sheet-leave-to {
  opacity: 0;
}

.sheet-enter-from .sheet-panel,
.sheet-leave-to .sheet-panel {
  transform: translateY(100%);
}
</style>
