<script setup lang="ts">
import { computed } from 'vue'
import { formatFen } from '@/utils/format'

const props = defineProps<{
  imageUrl: string
  amountFen: number | null
  topup?: boolean
  cod?: boolean
  codDelivered?: boolean
  refreshing?: boolean
}>()

const emit = defineEmits<{ (event: 'refresh'): void }>()
const hasQr = computed(() => props.imageUrl.trim() !== '')
</script>

<template>
  <section class="offline-qr card" aria-labelledby="offline-qr-title">
    <h2 id="offline-qr-title" class="offline-qr__title">{{ topup ? '扫码补款' : cod ? '货到扫码' : '扫码预付' }}</h2>

    <template v-if="hasQr && amountFen !== null">
      <img class="offline-qr__image" :src="imageUrl" alt="店主线下收款二维码" />
      <p class="offline-qr__amount">
        {{ topup ? '本次补款' : cod && codDelivered ? '待收货款' : '订单金额' }} {{ formatFen(amountFen) }}
      </p>
      <p class="offline-qr__hint">
        <template v-if="cod">
          {{ codDelivered
            ? '请与店主核对待收金额后扫码或现金付款。到账由店主核实，系统不会自动确认。'
            : '请在送达时核对金额后扫码或现金付款，送达前请勿转账。到账由店主核实，系统不会自动确认。'
          }}
          可截图保存二维码，在付款应用的“扫一扫”中从相册识别。
        </template>
        <template v-else>
          请核对金额后扫码转账。付款后由店主核实到账，系统不会自动确认；状态更新前请勿重复转账。可截图保存二维码，在付款应用的“扫一扫”中从相册识别。
        </template>
      </p>
      <button class="btn btn--secondary btn--sm" type="button" :disabled="refreshing" @click="emit('refresh')">
        {{ refreshing ? '查询中…' : '刷新核实状态' }}
      </button>
    </template>

    <p v-else class="offline-qr__missing" role="status">
      {{ hasQr ? '正在读取应付金额，请稍后刷新订单。' : '店主尚未上传收款码，请联系店主确认付款方式。' }}
    </p>
  </section>
</template>

<style scoped>
.offline-qr {
  margin-top: var(--space-4);
  padding: var(--space-4);
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
}

.offline-qr__title {
  margin: 0;
  font-size: var(--font-product);
  font-weight: var(--weight-semibold);
}

.offline-qr__image {
  width: min(62vw, 240px);
  aspect-ratio: 1;
  margin-top: var(--space-3);
  object-fit: contain;
  border: 1px solid var(--color-divider);
  border-radius: var(--radius-md);
  background: var(--color-surface);
}

.offline-qr__amount {
  margin: var(--space-3) 0 0;
  color: var(--color-price);
  font-size: var(--font-section);
  font-weight: var(--weight-bold);
}

.offline-qr__hint,
.offline-qr__missing {
  margin: var(--space-2) 0 var(--space-3);
  color: var(--color-text-secondary);
  font-size: var(--font-caption);
  line-height: 1.65;
}
</style>
