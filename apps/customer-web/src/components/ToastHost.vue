<script setup lang="ts">
import { useToast } from '@/composables/toast'

const { items } = useToast()
</script>

<template>
  <div class="toast-host" aria-live="polite">
    <transition-group name="toast">
      <div v-for="item in items" :key="item.id" class="toast" :class="`toast--${item.type}`">
        {{ item.text }}
      </div>
    </transition-group>
  </div>
</template>

<style scoped>
.toast-host {
  position: fixed;
  top: calc(env(safe-area-inset-top) + 16px);
  left: 0;
  right: 0;
  z-index: 100;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 8px;
  pointer-events: none;
}

.toast {
  max-width: 84vw;
  padding: 10px 16px;
  border-radius: var(--radius-md);
  background: var(--color-toast-bg);
  color: var(--color-on-brand);
  font-size: var(--font-caption);
  line-height: 1.5;
  box-shadow: var(--shadow-floating);
}

.toast--success {
  background: var(--color-toast-success);
}

.toast--warning {
  background: var(--color-toast-warning);
}

.toast--error {
  background: var(--color-toast-danger);
}

.toast-enter-active,
.toast-leave-active {
  transition: all var(--motion-base) var(--motion-ease);
}

.toast-enter-from,
.toast-leave-to {
  opacity: 0;
  transform: translateY(-8px);
}
</style>
