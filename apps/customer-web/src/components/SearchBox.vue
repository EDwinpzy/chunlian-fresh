<script setup lang="ts">
import AssetIcon from '@/components/AssetIcon.vue'

withDefaults(defineProps<{
  mode?: 'trigger' | 'input'
  modelValue?: string
  placeholder?: string
  inputId?: string
  autocomplete?: string
  enterkeyhint?: 'search' | 'done' | 'go' | 'enter' | 'next' | 'previous' | 'send'
  clearable?: boolean
  submitText?: string
}>(), {
  mode: 'trigger',
  modelValue: '',
  placeholder: '搜索店内商品',
  autocomplete: 'off',
  enterkeyhint: 'search',
  clearable: false,
  submitText: '',
})

const emit = defineEmits<{
  'update:modelValue': [value: string]
  activate: []
  submit: []
}>()

function updateValue(event: Event) {
  emit('update:modelValue', (event.target as HTMLInputElement).value)
}
</script>

<template>
  <button
    v-if="mode === 'trigger'"
    class="search-box search-box--trigger"
    type="button"
    :aria-label="placeholder"
    @click="emit('activate')"
  >
    <AssetIcon name="search" :size="20" />
    <span>{{ placeholder }}</span>
  </button>

  <form v-else class="search-box search-box--input" role="search" @submit.prevent="emit('submit')">
    <AssetIcon name="search" :size="20" />
    <input
      :id="inputId"
      :value="modelValue"
      type="search"
      :placeholder="placeholder"
      :enterkeyhint="enterkeyhint"
      :autocomplete="autocomplete"
      :aria-label="placeholder"
      @input="updateValue"
    />
    <button
      v-if="clearable && modelValue"
      class="search-box__clear"
      type="button"
      aria-label="清空"
      @click.stop="emit('update:modelValue', '')"
    >
      <AssetIcon name="close" :size="16" />
    </button>
    <button v-if="submitText" class="search-box__submit" type="submit">{{ submitText }}</button>
  </form>
</template>

<style scoped>
.search-box {
  width: 100%;
  min-width: 0;
  height: 48px;
  border: 1px solid var(--color-neutral-200);
  border-radius: var(--radius-md);
  background: var(--color-surface);
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 0 6px 0 12px;
  color: var(--color-text-muted);
  font-size: var(--font-body);
  text-align: left;
}

.search-box--trigger {
  cursor: pointer;
}

.search-box--trigger span {
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.search-box--trigger:focus-visible {
  outline: 2px solid var(--color-brand-200);
  outline-offset: 2px;
}

.search-box--input:focus-within {
  border-color: var(--color-brand-600);
  box-shadow: 0 0 0 1px var(--color-brand-600);
}

.search-box input {
  width: auto;
  height: 100%;
  min-width: 0;
  flex: 1;
  padding: 0;
  border: 0;
  outline: 0;
  background: transparent;
  color: var(--color-text-primary);
  font: inherit;
}

.search-box input::placeholder {
  color: var(--color-neutral-400);
}

.search-box input::-webkit-search-cancel-button {
  display: none;
}

.search-box__clear {
  width: 32px;
  height: 32px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex: 0 0 auto;
  border-radius: var(--radius-sm);
  color: var(--color-text-muted);
}

.search-box__submit {
  flex: 0 0 auto;
  height: 36px;
  padding: 0 14px;
  border-radius: var(--radius-sm);
  background: var(--color-brand-700);
  color: var(--color-on-brand);
  font-size: var(--font-product);
  font-weight: var(--weight-semibold);
}
</style>
