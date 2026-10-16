<script setup lang="ts">
/**
 * 数量器（方案 §5.4 GQuantityStepper）。
 *
 * 所有顾客页面共用同一组真实加减图标和按钮尺寸。
 */
import AssetIcon from '@/components/AssetIcon.vue'

const props = withDefaults(
  defineProps<{
    modelValue: number
    min?: number
    max?: number
    disabled?: boolean
  }>(),
  { min: 1, max: 99, disabled: false },
)

const emit = defineEmits<{
  (e: 'update:modelValue', value: number): void
  (e: 'limit', message: string): void
}>()

function decrease() {
  if (props.disabled) return
  if (props.modelValue <= props.min) {
    emit('limit', '数量不能再少了')

    return
  }
  emit('update:modelValue', props.modelValue - 1)
}

function increase() {
  if (props.disabled) return
  if (props.modelValue >= props.max) {
    emit('limit', `最多 ${props.max} 份`)

    return
  }
  emit('update:modelValue', props.modelValue + 1)
}
</script>

<template>
  <div class="stepper" :class="{ 'is-disabled': disabled }">
    <button
      class="stepper__btn"
      type="button"
      aria-label="减少数量"
      :disabled="disabled || modelValue <= min"
      @click="decrease"
    >
      <AssetIcon name="quantity_minus" :size="28" />
    </button>

    <span class="stepper__value num">{{ modelValue }}</span>

    <button
      class="stepper__btn stepper__btn--plus"
      type="button"
      aria-label="增加数量"
      :disabled="disabled || modelValue >= max"
      @click="increase"
    >
      <AssetIcon name="quantity_plus" :size="28" />
    </button>
  </div>
</template>

<style scoped>
.stepper {
  display: inline-flex;
  align-items: center;
  gap: 0;
}

.stepper__btn {
  width: 32px;
  height: 32px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border: 0;
  background: transparent;
  transition: transform var(--motion-fast) var(--motion-ease);
}

.stepper__btn:disabled {
  opacity: 0.4;
}

.stepper__btn:active:not(:disabled) {
  transform: scale(0.9);
}

.stepper__value {
  min-width: 28px;
  text-align: center;
  font-size: var(--font-body);
  font-weight: var(--weight-semibold);
}

/* 用伪元素把视觉上的 32px 图标按钮扩到 44px 点击范围。 */
.stepper__btn::after {
  content: '';
  position: absolute;
  inset: -6px;
}

.stepper__btn {
  position: relative;
}
</style>
