<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import PageHeader from '@/components/PageHeader.vue'
import GoodsImage from '@/components/GoodsImage.vue'
import BottomSheet from '@/components/BottomSheet.vue'
import AssetIcon from '@/components/AssetIcon.vue'
import { api, ApiError, UnauthorizedError, uuid } from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { useCartStore } from '@/stores/cart'
import { useShopStore } from '@/stores/shop'
import { useToast } from '@/composables/toast'
import { formatFen, toExpectedIso, shopCalendarDate } from '@/utils/format'
import type { DeliveryMode, PaymentMethod, PreviewResult } from '@/api/types'

/**
 * 确认订单（方案 §5.2 UI-C03 下半页 + §3.3 状态迁移）。
 *
 * 自上而下的顺序是硬要求：**地址 → 期望送达时间 → 商品及金额 → 支付方式 → 备注
 * → 固定结算按钮**（方案 §5.2）。切忌把配送时间藏进三级菜单。
 *
 * 【为什么金额必须调服务端预览接口】
 * 客户端算的金额与服务端下单时算的不一致，是顾客投诉最多的那类问题。
 * 这里在进入页面、改配送时间、改商品时都会重新预览一次，
 * 结算按钮上显示的永远是服务端刚刚算出来的数字。
 */
const router = useRouter()
const cart = useCartStore()
const shop = useShopStore()
const auth = useAuthStore()
const toast = useToast()

const deliveryMode = ref<DeliveryMode>('ASAP')
const paymentMethod = ref<PaymentMethod>('COD')
const remark = ref('')

const preview = ref<PreviewResult | null>(null)
const previewLoading = ref(false)
const submitting = ref(false)

/** 提交幂等键：点击「提交订单」那一刻生成，失败重试时复用同一个 */
const submitKey = ref('')

// 预约时间：默认明天 17:30，符合「买菜」的典型场景
const scheduleDay = ref<Date>(defaultDay())
const scheduleHour = ref(17)
const scheduleMinute = ref(30)
const showTimeSheet = ref(false)
const showAddressSheet = ref(false)

function defaultDay(): Date {
  const d = shopCalendarDate()
  d.setDate(d.getDate() + 1)

  return d
}

const expectedIso = computed(() => toExpectedIso(scheduleDay.value, scheduleHour.value, scheduleMinute.value))
const address = computed(() => shop.defaultAddress())
const isOpen = computed(() => shop.setting?.is_open !== false)
const paymentQrUrl = computed(() => shop.setting?.payment_qr_url?.trim() || '')
const qrPaymentAvailable = computed(() => paymentQrUrl.value !== '')
const minLeadMinutes = computed(() => Math.max(0, shop.setting?.min_lead_minutes ?? 0))
const deliveryFeeFen = computed(() => preview.value?.delivery_fee_fen ?? shop.setting?.delivery_fee_fen ?? 0)
const goodsTotalFen = computed(() => preview.value?.goods_total_fen ?? cart.totalFen)
const totalFen = computed(() => preview.value?.total_fen ?? goodsTotalFen.value + deliveryFeeFen.value)

function isScheduleSlotAvailable(day: Date, hour: number, minute: number): boolean {
  const slot = new Date(toExpectedIso(day, hour, minute))

  return slot.getTime() > Date.now() + minLeadMinutes.value * 60_000
}

const dayOptions = computed(() => {
  const out: { date: Date; label: string }[] = []
  const today = shopCalendarDate()
  for (let i = 0; i < 7; i++) {
    const d = new Date(today)
    d.setDate(today.getDate() + i)
    const week = ['日', '一', '二', '三', '四', '五', '六'][d.getDay()]
    const prefix = i === 0 ? '今天' : i === 1 ? '明天' : i === 2 ? '后天' : `周${week}`
    out.push({ date: d, label: `${prefix} ${d.getMonth() + 1}/${d.getDate()}` })
  }

  return out
})

const hourOptions = Array.from({ length: 13 }, (_, i) => i + 8) // 08:00 – 20:00
const minuteOptions = [0, 15, 30, 45]

const canSubmit = computed(
  () =>
    !submitting.value &&
    !previewLoading.value &&
    !cart.isEmpty &&
    !!address.value &&
    isOpen.value &&
    (paymentMethod.value === 'COD' || (paymentMethod.value === 'OFFLINE_QR' && qrPaymentAvailable.value)) &&
    (deliveryMode.value !== 'SCHEDULED' ||
      isScheduleSlotAvailable(scheduleDay.value, scheduleHour.value, scheduleMinute.value)) &&
    preview.value !== null,
)

async function refreshPreview() {
  if (cart.isEmpty) {
    preview.value = null

    return
  }

  previewLoading.value = true
  // Never leave an earlier preview available for submission while a new price or
  // delivery-time preview is pending or after the request has failed.
  preview.value = null
  try {
    preview.value = await api.preview({
      items: cart.toOrderItems(),
      delivery_mode: deliveryMode.value,
      expected_at: deliveryMode.value === 'SCHEDULED' ? expectedIso.value : null,
    })
  } catch (error) {
    if (error instanceof ApiError) {
      toast.error(error.message)
      // 库存/下架类错误必须让顾客回购物车处理，否则他会一直点提交
      if (['OUT_OF_STOCK', 'NOT_FOUND'].includes(error.code)) {
        preview.value = null
      }
    }
  } finally {
    previewLoading.value = false
  }
}

async function submit() {
  if (paymentMethod.value === 'OFFLINE_QR' && !qrPaymentAvailable.value) {
    toast.warning('店主尚未上传收款码，请选择货到付款')

    return
  }
  if (
    deliveryMode.value === 'SCHEDULED' &&
    !isScheduleSlotAvailable(scheduleDay.value, scheduleHour.value, scheduleMinute.value)
  ) {
    toast.warning('预约时间需晚于当前时间并满足最短配送准备时间')

    return
  }
  if (!address.value) {
    toast.warning('请先填写收货地址')

    return
  }
  if (!isOpen.value) {
    toast.warning('商家暂停营业，暂不能新下单')

    return
  }
  if (cart.isEmpty) {
    toast.warning('购物车是空的')

    return
  }

  // 幂等键只生成一次：网络超时后用户再点，服务端会返回首次结果而不是重复建单
  if (!submitKey.value) submitKey.value = uuid()

  submitting.value = true
  try {
    const result = await api.createOrder(
      {
        items: cart.toOrderItems(),
        contact: {
          name: address.value.name,
          phone: address.value.phone,
          address: address.value.address,
        },
        delivery_mode: deliveryMode.value,
        expected_at: deliveryMode.value === 'SCHEDULED' ? expectedIso.value : null,
        payment_method: paymentMethod.value,
        remark: remark.value.trim(),
      },
      submitKey.value,
    )

    if (!result.order) {
      throw new ApiError('SERVER_ERROR', '下单结果异常，请到订单列表确认')
    }

    // 只清掉本次成交的 SKU，避免把顾客在这期间新加的商品也清掉
    cart.removeMany(result.order.lines.map((l) => l.sku_id))

    // 用 replace：方案要求「提交付款后不再返回可二次提交的旧结算页」
    router.replace(`/order-result/${result.order.id}`)
  } catch (error) {
    if (error instanceof UnauthorizedError) {
      toast.warning('登录已过期，请重新登录')
      router.push({ name: 'login', query: { redirect: '/checkout' } })

      return
    }

    const message = error instanceof ApiError ? error.message : '下单失败，请重试'
    toast.error(message)

    // 订单版本/库存冲突：让顾客看到最新金额再决定
    if (error instanceof ApiError && ['OUT_OF_STOCK', 'VALIDATION_FAILED'].includes(error.code)) {
      submitKey.value = ''
      await refreshPreview()
    }
  } finally {
    submitting.value = false
  }
}

function pickSchedule(day: Date, hour: number, minute: number) {
  if (!isScheduleSlotAvailable(day, hour, minute)) {
    toast.warning('预约时间需晚于当前时间并满足最短配送准备时间')

    return
  }
  scheduleDay.value = day
  scheduleHour.value = hour
  scheduleMinute.value = minute
  showTimeSheet.value = false
  refreshPreview()
}

watch(deliveryMode, refreshPreview)

onMounted(async () => {
  if (cart.isEmpty) {
    router.replace('/cart')

    return
  }

  await Promise.all([
    shop.loadSetting().catch(() => null),
    auth.isLoggedIn ? shop.loadAddresses(true).catch(() => null) : Promise.resolve(null),
  ])

  await refreshPreview()
})
</script>

<template>
  <div class="page">
    <PageHeader title="确认订单" />

    <div class="page-body page-body--actionbar">
      <!-- 1. 地址 -->
      <section class="card address-card" @click="router.push({ path: '/addresses', query: { pick: '1' } })">
        <template v-if="address">
          <div class="address-card__head">
            <span class="address-card__name">{{ address.name }}</span>
            <span class="address-card__phone caption">{{ address.phone }}</span>
            <span v-if="address.is_default" class="pill pill--info">默认</span>
          </div>
          <p class="address-card__text">{{ address.address }}</p>
        </template>
        <template v-else>
          <p class="address-card__empty">还没有收货地址，点击填写</p>
          <p class="caption">需要收货人姓名、手机号和详细地址</p>
        </template>

        <AssetIcon name="back" class="address-card__arrow" :size="18" :rotation="180" />
      </section>

      <!-- 2. 期望送达时间 -->
      <section class="section">
        <h2 class="section-title">期望送达时间</h2>

        <div class="choice-list">
          <button
            class="choice"
            :class="{ 'is-active': deliveryMode === 'ASAP' }"
            type="button"
            @click="deliveryMode = 'ASAP'"
          >
            <span class="choice__radio" />
            <span class="choice__body">
              <span class="choice__title">尽快配送</span>
              <span class="choice__desc caption">下单后我们会尽快安排配送。</span>
            </span>
          </button>

          <button
            class="choice"
            :class="{ 'is-active': deliveryMode === 'SCHEDULED' }"
            type="button"
            @click="deliveryMode = 'SCHEDULED'"
          >
            <span class="choice__radio" />
            <span class="choice__body">
              <span class="choice__title">预约送达</span>
              <span class="choice__desc caption">请选择希望送达的日期和时间，实际以配送情况为准。</span>
            </span>
          </button>
        </div>

        <button
          v-if="deliveryMode === 'SCHEDULED'"
          class="schedule-picker"
          type="button"
          @click="showTimeSheet = true"
        >
          <span>{{ scheduleDay.getMonth() + 1 }}月{{ scheduleDay.getDate() }}日
            {{ String(scheduleHour).padStart(2, '0') }}:{{ String(scheduleMinute).padStart(2, '0') }}</span>
          <span class="btn btn--text">修改</span>
        </button>
      </section>

      <!-- 3. 商品与金额 -->
      <section class="section">
        <h2 class="section-title">商品清单</h2>

        <div class="card goods-list">
          <div v-for="item in cart.items" :key="item.sku_id" class="goods-row">
            <GoodsImage
              :src="item.image_url"
              :alt="item.name"
              :fallback-text="item.name"
              radius="var(--radius-sm)"
            />
            <div class="goods-row__body">
              <p class="goods-row__name clamp-2">{{ item.name }}</p>
              <p class="caption">{{ item.spec }} × {{ item.qty }}</p>
            </div>
            <span class="price num">{{ formatFen(item.unit_fen * item.qty) }}</span>
          </div>

          <hr class="divider" />

          <div class="amount-row">
            <span>商品小计</span>
            <span class="num">{{ formatFen(goodsTotalFen) }}</span>
          </div>
          <div class="amount-row">
            <span>配送费</span>
            <span class="num">{{ formatFen(deliveryFeeFen) }}</span>
          </div>
          <div class="amount-row amount-row--total">
            <span>合计</span>
            <span class="price num">{{ formatFen(totalFen) }}</span>
          </div>

          <p v-if="previewLoading" class="mini">正在按最新价格与库存重新计算…</p>
        </div>
      </section>

      <!-- 4. 支付方式 -->
      <section class="section">
        <h2 class="section-title">支付方式</h2>

        <div class="choice-list">
          <button
            class="choice"
            :class="{ 'is-active': paymentMethod === 'COD' }"
            type="button"
            @click="paymentMethod = 'COD'"
          >
            <span class="choice__radio" />
            <span class="choice__body">
              <span class="choice__title">货到付款</span>
              <span class="choice__desc caption">送达时扫码或现金付款，店主核实后登记实收。</span>
            </span>
          </button>

          <button
            class="choice"
            :class="{ 'is-active': paymentMethod === 'OFFLINE_QR', 'is-disabled': !qrPaymentAvailable }"
            type="button"
            :disabled="!qrPaymentAvailable"
            :aria-disabled="!qrPaymentAvailable"
            @click="paymentMethod = 'OFFLINE_QR'"
          >
            <span class="choice__radio" />
            <span class="choice__body">
              <span class="choice__title">扫码预付</span>
              <span v-if="qrPaymentAvailable" class="choice__desc caption">
                下单后显示店主收款码；到账由店主核实，系统不会自动确认付款。
              </span>
              <span v-else class="choice__desc caption">店主尚未上传收款码，暂不可选。</span>
            </span>
          </button>
        </div>

        <p v-if="paymentMethod === 'OFFLINE_QR' && qrPaymentAvailable" class="qr-payment-note" role="status">
          提交后可在订单详情查看二维码。请按订单金额转账，等待店主核实到账；付款状态不会因扫码自动变更。
        </p>
      </section>

      <!-- 5. 备注 -->
      <section class="section">
        <h2 class="section-title">订单备注（选填）</h2>
        <textarea
          v-model="remark"
          class="remark-input"
          rows="3"
          maxlength="200"
          placeholder="例如：到了电话联系、放门口、不要塑料袋"
        />
      </section>

      <p class="checkout-tip mini">
        提交订单即表示同意「下单前可随时修改或取消，商家开始配送后需联系店主」的规则。
      </p>
      <p v-if="!isOpen" class="checkout-closed" role="status">
        店铺已暂停营业，暂不能提交新订单；购物车会保留。
      </p>
    </div>

    <!-- 固定结算按钮 -->
    <div class="action-bar">
      <div class="cart-bar__sum">
        <span class="caption">合计</span>
        <span class="price num cart-bar__amount">{{ formatFen(totalFen) }}</span>
      </div>
      <button class="btn btn--primary checkout-submit" type="button" :disabled="!canSubmit" @click="submit">
        {{ submitting ? '提交中…' : paymentMethod === 'OFFLINE_QR' ? '提交订单并查看收款码' : '提交订单' }}
      </button>
    </div>

    <!-- 预约时间选择 -->
    <BottomSheet v-model="showTimeSheet" title="选择希望送达的时间" :mask-closable="false">
      <div class="time-picker">
        <div class="time-picker__group">
          <p class="time-picker__label">日期</p>
          <div class="time-picker__options">
            <button
              v-for="opt in dayOptions"
              :key="opt.label"
              class="time-chip"
              :class="{ 'is-active': opt.date.toDateString() === scheduleDay.toDateString() }"
              type="button"
              @click="scheduleDay = opt.date"
            >
              {{ opt.label }}
            </button>
          </div>
        </div>

        <div class="time-picker__group">
          <p class="time-picker__label">小时</p>
          <div class="time-picker__options">
            <button
              v-for="h in hourOptions"
              :key="h"
              class="time-chip"
              :class="{ 'is-active': scheduleHour === h, 'is-disabled': !isScheduleSlotAvailable(scheduleDay, h, scheduleMinute) }"
              :disabled="!isScheduleSlotAvailable(scheduleDay, h, scheduleMinute)"
              type="button"
              @click="scheduleHour = h"
            >
              {{ String(h).padStart(2, '0') }}
            </button>
          </div>
        </div>

        <div class="time-picker__group">
          <p class="time-picker__label">分钟</p>
          <div class="time-picker__options">
            <button
              v-for="m in minuteOptions"
              :key="m"
              class="time-chip"
              :class="{ 'is-active': scheduleMinute === m, 'is-disabled': !isScheduleSlotAvailable(scheduleDay, scheduleHour, m) }"
              :disabled="!isScheduleSlotAvailable(scheduleDay, scheduleHour, m)"
              type="button"
              @click="scheduleMinute = m"
            >
              {{ String(m).padStart(2, '0') }}
            </button>
          </div>
        </div>
      </div>

      <template #footer>
        <button class="btn btn--ghost" type="button" @click="showTimeSheet = false">取消</button>
        <button
          class="btn btn--primary"
          type="button"
          @click="pickSchedule(scheduleDay, scheduleHour, scheduleMinute)"
        >
          确定
        </button>
      </template>
    </BottomSheet>
  </div>
</template>

<style scoped>
.address-card {
  position: relative;
  margin-top: var(--space-3);
  padding-right: 34px;
}

.address-card__head {
  display: flex;
  align-items: center;
  gap: 8px;
}

.address-card__name {
  font-size: var(--font-product);
  font-weight: var(--weight-semibold);
}

.address-card__text {
  margin: 6px 0 0;
  font-size: var(--font-body);
  line-height: 1.5;
}

.address-card__empty {
  margin: 0;
  font-size: var(--font-product);
  font-weight: var(--weight-semibold);
  color: var(--color-brand-700);
}

.address-card__arrow {
  position: absolute;
  right: 12px;
  top: 50%;
  transform: translateY(-50%);
  color: var(--color-text-muted);
}

.choice-list {
  display: flex;
  flex-direction: column;
  gap: var(--space-3);
}

.choice {
  min-height: 56px;
  border-radius: var(--radius-lg);
  border: 1px solid var(--color-divider);
  background: var(--color-surface);
  padding: 12px 14px;
  display: flex;
  align-items: flex-start;
  gap: 10px;
  text-align: left;
  transition: all var(--motion-fast) var(--motion-ease);
}

.choice.is-active {
  border-color: var(--color-brand-600);
  background: var(--color-brand-100);
}

.choice.is-disabled {
  opacity: 0.58;
  cursor: not-allowed;
}

.qr-payment-note {
  margin: var(--space-3) 0 0;
  padding: 10px 12px;
  border-radius: var(--radius-md);
  background: var(--color-warning-bg);
  color: var(--color-warning);
  font-size: var(--font-caption);
  line-height: 1.6;
}

.choice__radio {
  flex: 0 0 18px;
  width: 18px;
  height: 18px;
  margin-top: 2px;
  border-radius: 50%;
  border: 1.6px solid var(--color-divider);
  background: var(--color-surface);
  position: relative;
}

.choice.is-active .choice__radio {
  border-color: var(--color-brand-700);
}

.choice.is-active .choice__radio::after {
  content: '';
  position: absolute;
  inset: 3px;
  border-radius: 50%;
  background: var(--color-brand-700);
}

.choice__body {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.choice__title {
  font-size: var(--font-product);
  font-weight: var(--weight-semibold);
  display: inline-flex;
  align-items: center;
  gap: 6px;
}

.schedule-picker {
  margin-top: var(--space-3);
  width: 100%;
  height: 48px;
  border-radius: var(--radius-md);
  border: 1px dashed var(--color-brand-600);
  background: var(--color-surface);
  padding: 0 14px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  font-size: var(--font-product);
  font-weight: var(--weight-semibold);
  color: var(--color-brand-700);
}

.goods-list {
  padding: var(--space-4) var(--space-4) 14px;
}

.goods-row {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 8px 0;
}

.goods-row :deep(.goods-image) {
  flex: 0 0 52px;
  width: 52px;
}

.goods-row__body {
  flex: 1;
  min-width: 0;
}

.goods-row__name {
  margin: 0;
  font-size: var(--font-caption);
  font-weight: var(--weight-semibold);
  line-height: 1.4;
}

.amount-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  font-size: var(--font-caption);
  color: var(--color-text-secondary);
  padding: 3px 0;
}

.amount-row--total {
  font-size: var(--font-body);
  color: var(--color-text-primary);
  font-weight: var(--weight-semibold);
  padding-top: 8px;
}

.remark-input {
  width: 100%;
  border-radius: var(--radius-lg);
  border: 1px solid var(--color-divider);
  background: var(--color-surface);
  padding: 12px 14px;
  font-size: var(--font-body);
  line-height: 1.6;
  resize: none;
}

.remark-input::placeholder {
  color: var(--color-text-muted);
}

.checkout-tip {
  margin: var(--space-5) 0 0;
  text-align: center;
  line-height: 1.6;
}

.checkout-closed {
  margin: var(--space-3) 0 0;
  padding: 10px 12px;
  border-radius: var(--radius-md);
  background: var(--color-warning-bg);
  color: var(--color-warning);
  font-size: var(--font-caption);
  line-height: 1.5;
}

.cart-bar__sum {
  flex: 1;
  display: flex;
  flex-direction: column;
  line-height: 1.25;
}

.cart-bar__amount {
  font-size: var(--font-headline);
}

.checkout-submit {
  flex: 0 0 auto;
  min-width: 150px;
}

.time-picker__group + .time-picker__group {
  margin-top: var(--space-5);
}

.time-picker__label {
  margin: 0 0 8px;
  font-size: var(--font-caption);
  color: var(--color-text-secondary);
  font-weight: var(--weight-semibold);
}

.time-picker__options {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.time-chip {
  min-width: 56px;
  padding: 8px 12px;
  border-radius: var(--radius-pill);
  border: 1px solid var(--color-divider);
  background: var(--color-surface);
  font-size: var(--font-caption);
  color: var(--color-text-primary);
}

.time-chip:disabled {
  opacity: 0.45;
  cursor: not-allowed;
}

.time-chip.is-active {
  border-color: var(--color-brand-600);
  background: var(--color-brand-100);
  color: var(--color-brand-700);
  font-weight: var(--weight-semibold);
}
</style>
