<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import PageHeader from '@/components/PageHeader.vue'
import GoodsImage from '@/components/GoodsImage.vue'
import QuantityStepper from '@/components/QuantityStepper.vue'
import BottomSheet from '@/components/BottomSheet.vue'
import EmptyState from '@/components/EmptyState.vue'
import SearchBox from '@/components/SearchBox.vue'
import { api, ApiError, uuid } from '@/api/client'
import { useShopStore } from '@/stores/shop'
import { useToast } from '@/composables/toast'
import { formatDelta, formatFen, toExpectedIso, shopCalendarDate } from '@/utils/format'
import type { DeliveryMode, GoodsSummary, Order, RevisionPreview } from '@/api/types'

/**
 * 修改订单（方案 §3.4 / §5.3 UI-C11）。
 *
 * 【本项目最核心的自研功能】
 * 已在线支付的订单在发货前增删商品，会牵动「补差价 / 部分退款 / 库存调整 /
 * 订单版本并发」四件事，ShopXO 原生不支持。这里的规则是：
 *
 *   1. 商品变更 → 必须走**服务端预览**（旧 SKU 维持原下单单价，
 *      新增 SKU 与增量数量按现价），客户端只负责展示 `delta_fen`
 *   2. 金额增加 → 提交后进入 `PENDING_TOPUP`，补款成功前**明细不切换**
 *      （商家不会按一个还没付钱的新单备货）
 *   3. 金额减少 → 立即生效并排退款任务
 *   4. 只改地址/时间/备注（不涉及金额）→ 走 `/info`，不产生修订记录，
 *      免得商家端被一堆「顾客修改了订单」的告警淹没
 *
 * 因此这个页面有两条提交路径，而不是一个按钮糊到底。
 */
const route = useRoute()
const router = useRouter()
const shop = useShopStore()
const toast = useToast()

const order = ref<Order | null>(null)
const loading = ref(true)
const failed = ref(false)

/** 编辑中的明细：sku_id → { qty, 快照信息 } */
interface EditLine {
  sku_id: string
  product_id: string
  name: string
  spec: string
  image_url: string
  unit_fen: number
  qty: number
  inventory: number
}

const lines = ref<EditLine[]>([])
const contact = ref({ name: '', phone: '', address: '' })
const deliveryMode = ref<DeliveryMode>('ASAP')
const scheduleDay = ref(shopCalendarDate())
const scheduleHour = ref(17)
const scheduleMinute = ref(30)
const remark = ref('')
let revisionRequestKey = ''
let revisionRequestFingerprint = ''

const preview = ref<RevisionPreview | null>(null)
const previewing = ref(false)
const submitting = ref(false)
const showPreviewSheet = ref(false)
const showPicker = ref(false)
const showTimeSheet = ref(false)

// 商品选择器
const pickerKeyword = ref('')
const pickerGoods = ref<GoodsSummary[]>([])
const pickerLoading = ref(false)

const expectedIso = computed(() => toExpectedIso(scheduleDay.value, scheduleHour.value, scheduleMinute.value))

const itemsChanged = computed(() => {
  if (!order.value) return false
  const original = new Map<string, number>()
  for (const line of order.value.lines) original.set(line.sku_id, (original.get(line.sku_id) || 0) + line.qty)
  if (original.size !== lines.value.length) return true
  for (const line of lines.value) {
    if (original.get(line.sku_id) !== line.qty) return true
  }

  return false
})

const infoChanged = computed(() => {
  if (!order.value) return false
  const o = order.value
  return (
    contact.value.name !== o.contact.name ||
    contact.value.phone !== o.contact.phone ||
    contact.value.address !== o.contact.address ||
    remark.value !== (o.remark || '') ||
    deliveryMode.value !== o.delivery_mode ||
    (deliveryMode.value === 'SCHEDULED' && o.expected_at !== expectedIso.value)
  )
})

const canSave = computed(() => itemsChanged.value || infoChanged.value)

const editTotalFen = computed(() => lines.value.reduce((sum, l) => sum + l.unit_fen * l.qty, 0))

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
const hourOptions = Array.from({ length: 13 }, (_, i) => i + 8)
const minuteOptions = [0, 15, 30, 45]

async function load() {
  loading.value = true
  failed.value = false

  try {
    const o = await api.orderDetail(String(route.params.id))
    order.value = o

    if (!o.can_edit) {
      toast.warning(o.order_status === 'PENDING' ? '当前订单暂不可修改' : '商家已开始配送，当前订单不能再自行修改')

      return
    }

    const grouped = new Map<string, EditLine>()
    for (const l of o.lines) {
      const existing = grouped.get(l.sku_id)
      if (existing) { existing.qty += l.qty; continue }
      grouped.set(l.sku_id, {
      sku_id: l.sku_id,
      product_id: l.product_id,
      name: l.name,
      spec: l.spec,
      image_url: l.image_url,
      unit_fen: l.unit_fen,
      qty: l.qty,
      // 订单快照里没有库存，先给一个宽松上限，真正的校验在预览接口
      inventory: 99,
      })
    }
    lines.value = [...grouped.values()]
    contact.value = { ...o.contact }
    deliveryMode.value = o.delivery_mode
    remark.value = o.remark || ''

    if (o.expected_at) {
      const d = shopCalendarDate(new Date(o.expected_at))
      if (!Number.isNaN(d.getTime())) {
        scheduleDay.value = d
        scheduleHour.value = d.getHours()
        scheduleMinute.value = d.getMinutes()
      }
    }
  } catch (error) {
    failed.value = true
    toast.error(error instanceof ApiError ? error.message : '读取订单失败')
  } finally {
    loading.value = false
  }
}

async function loadPicker() {
  pickerLoading.value = true
  try {
    const page = await api.goodsList({
      keyword: pickerKeyword.value || undefined,
      page: 1,
      page_size: 30,
    })
    pickerGoods.value = page.list
  } catch {
    pickerGoods.value = []
  } finally {
    pickerLoading.value = false
  }
}

async function addFromPicker(item: GoodsSummary) {
  if (item.is_out_of_stock) {
    toast.warning('该商品已售罄')

    return
  }

  try {
    const detail = await api.goodsDetail(item.id)
    const sku = detail.skus.find((s) => !s.is_out_of_stock)
    if (!sku) {
      toast.warning('该商品暂无可售规格')

      return
    }

    const existing = lines.value.find((l) => l.sku_id === sku.sku_id)
    if (existing) {
      existing.qty += 1
      existing.inventory = sku.inventory
      existing.unit_fen = sku.price_fen
    } else {
      lines.value.push({
        sku_id: sku.sku_id,
        product_id: detail.id,
        name: detail.title,
        spec: sku.spec_text,
        image_url: detail.image_url,
        unit_fen: sku.price_fen,
        qty: 1,
        inventory: sku.inventory,
      })
    }

    showPicker.value = false
    toast.success('已加入本次修改')
  } catch (error) {
    toast.error(error instanceof ApiError ? error.message : '加载商品失败')
  }
}

function removeLine(skuId: string) {
  lines.value = lines.value.filter((l) => l.sku_id !== skuId)
}

function changeQty(skuId: string, qty: number) {
  const line = lines.value.find((l) => l.sku_id === skuId)
  if (!line) return

  if (qty <= 0) {
    removeLine(skuId)

    return
  }
  line.qty = qty
}

/** 第一步：预览。商品有变化时必须让服务端算差价。 */
async function openPreview() {
  if (!canSave.value) {
    toast.info('还没有任何改动')

    return
  }

  if (!itemsChanged.value) {
    // 只改了信息：直接提交，不产生修订记录
    await submitInfoOnly()

    return
  }

  previewing.value = true
  try {
    preview.value = await api.revisionPreview(
      order.value!.id,
      lines.value.map((l) => ({ sku_id: l.sku_id, qty: l.qty })),
    )
    showPreviewSheet.value = true
  } catch (error) {
    toast.error(error instanceof ApiError ? error.message : '预览失败，请重试')
  } finally {
    previewing.value = false
  }
}

async function submitInfoOnly() {
  submitting.value = true
  try {
    order.value = await api.updateOrderInfo(order.value!.id, {
      version: order.value!.version,
      contact: { ...contact.value },
      delivery_mode: deliveryMode.value,
      expected_at: deliveryMode.value === 'SCHEDULED' ? expectedIso.value : null,
      remark: remark.value.trim(),
    })
    toast.success('订单信息已更新')
    router.replace(`/orders/${order.value.id}`)
  } catch (error) {
    toast.error(error instanceof ApiError ? error.message : '保存失败，请重试')
  } finally {
    submitting.value = false
  }
}

/** 第二步：确认变更。先提交商品修订，再提交信息变更。 */
async function confirmRevision() {
  submitting.value = true
  try {
    const items = lines.value.map((l) => ({ sku_id: l.sku_id, qty: l.qty }))
    const fingerprint = JSON.stringify({ id: order.value!.id, version: order.value!.version, items })
    if (fingerprint !== revisionRequestFingerprint) {
      revisionRequestFingerprint = fingerprint
      revisionRequestKey = uuid()
    }
    const result = await api.revisionSubmit(
      order.value!.id,
      items,
      revisionRequestKey,
      order.value!.version,
    )

    if (!result.changed) {
      order.value = await api.orderDetail(order.value!.id)
      showPreviewSheet.value = false
      toast.info('订单内容没有变化')
      router.replace(`/orders/${order.value.id}`)

      return
    }

    order.value = await api.orderDetail(order.value!.id)
    revisionRequestKey = ''
    revisionRequestFingerprint = ''

    // 商品修订成功后再更新地址/时间/备注（两者互不影响，分开提交便于定位问题）
    if (infoChanged.value) {
      order.value = await api.updateOrderInfo(order.value.id, {
        version: order.value.version,
        contact: { ...contact.value },
        delivery_mode: deliveryMode.value,
        expected_at: deliveryMode.value === 'SCHEDULED' ? expectedIso.value : null,
        remark: remark.value.trim(),
      })
    }

    showPreviewSheet.value = false
    // 待补款的修订尚未生效，文案要用服务端给的，不能自己拼
    toast.success(
      result.requires_topup
        ? '修改已提交，补款成功后生效'
        : preview.value?.customer_message || '修改已提交',
    )
    router.replace(`/orders/${order.value.id}`)
  } catch (error) {
    toast.error(error instanceof ApiError ? error.message : '提交失败，请重试')
  } finally {
    submitting.value = false
  }
}

onMounted(async () => {
  await Promise.all([load(), shop.loadSetting().catch(() => null), loadPicker()])
})
</script>

<template>
  <div class="page">
    <PageHeader title="修改订单" />

    <div v-if="loading" class="page-body">
      <div class="skeleton detail-line" />
    </div>

    <EmptyState
      v-else-if="failed || !order"
      icon="error"
      title="订单不存在或无权查看"
      action-text="返回订单列表"
      @action="router.replace('/orders')"
    />

    <EmptyState
      v-else-if="!order.can_edit"
      icon="order"
      title="当前订单不能再自行修改"
      description="商家已开始配送。如需变更请联系店主。"
      action-text="返回订单详情"
      @action="router.replace(`/orders/${order.id}`)"
    />

    <div v-else class="page-body page-body--actionbar">
      <p class="edit-tip">
        开始配送前，可随时修改订单。旧商品维持原下单单价，新增商品与增量按现价。
      </p>
      <p class="mini edit-price-note">页面商品小计为估算，提交前请以服务端差价预览为准。</p>

      <!-- 商品 -->
      <section class="section">
        <h2 class="section-title">
          <span>商品</span>
          <button class="section-title__more" type="button" @click="showPicker = true">+ 加菜</button>
        </h2>

        <div class="card">
          <div v-if="lines.length === 0" class="edit-empty">
            已移除全部商品。如需清空订单，请直接取消订单。
          </div>

          <div v-for="line in lines" :key="line.sku_id" class="edit-row">
            <GoodsImage :src="line.image_url" :alt="line.name" :fallback-text="line.name" radius="var(--radius-sm)" />
            <div class="edit-row__body">
              <p class="edit-row__name clamp-2">{{ line.name }}</p>
              <p class="caption">{{ line.spec }}</p>
              <div class="edit-row__foot">
                <span class="price num">{{ formatFen(line.unit_fen) }}</span>
                <QuantityStepper
                  :model-value="line.qty"
                  :min="0"
                  :max="Math.min(line.inventory, 99)"
                  @update:model-value="(v: number) => changeQty(line.sku_id, v)"
                  @limit="(msg: string) => toast.warning(msg)"
                />
              </div>
            </div>
          </div>

          <hr v-if="lines.length" class="divider" />

          <div class="row row--total">
            <span>修改后商品小计</span>
            <span class="price num">{{ formatFen(editTotalFen) }}</span>
          </div>
        </div>
      </section>

      <!-- 收货信息 -->
      <section class="section">
        <h2 class="section-title">收货信息</h2>
        <div class="card form-card">
          <label class="field">
            <span class="field__label">收货人</span>
            <input v-model="contact.name" type="text" maxlength="30" placeholder="请填写姓名" />
          </label>
          <label class="field">
            <span class="field__label">手机号</span>
            <input v-model="contact.phone" type="tel" maxlength="11" inputmode="numeric" placeholder="11 位手机号" />
          </label>
          <label class="field field--block">
            <span class="field__label">详细地址</span>
            <textarea v-model="contact.address" rows="2" maxlength="200" placeholder="小区 / 楼栋 / 门牌" />
          </label>
        </div>
      </section>

      <!-- 送达时间 -->
      <section class="section">
        <h2 class="section-title">期望送达时间</h2>
        <div class="choice-list">
          <button class="choice" :class="{ 'is-active': deliveryMode === 'ASAP' }" type="button" @click="deliveryMode = 'ASAP'">
            <span class="choice__radio" />
            <span class="choice__title">尽快配送</span>
          </button>
          <button
            class="choice"
            :class="{ 'is-active': deliveryMode === 'SCHEDULED' }"
            type="button"
            @click="deliveryMode = 'SCHEDULED'"
          >
            <span class="choice__radio" />
            <span class="choice__title">预约送达</span>
          </button>
        </div>

        <button v-if="deliveryMode === 'SCHEDULED'" class="schedule-picker" type="button" @click="showTimeSheet = true">
          <span>
            {{ scheduleDay.getMonth() + 1 }}月{{ scheduleDay.getDate() }}日
            {{ String(scheduleHour).padStart(2, '0') }}:{{ String(scheduleMinute).padStart(2, '0') }}
          </span>
          <span class="btn btn--text">修改</span>
        </button>
      </section>

      <!-- 备注 -->
      <section class="section">
        <h2 class="section-title">订单备注</h2>
        <textarea v-model="remark" class="remark-input" rows="2" maxlength="200" placeholder="例如：放门口" />
      </section>
    </div>

    <div v-if="!loading && order?.can_edit" class="action-bar">
      <div class="cart-bar__sum">
        <span class="caption">{{ itemsChanged ? '修改后商品小计' : '当前商品小计' }}</span>
        <span class="price num cart-bar__amount">{{ formatFen(itemsChanged ? editTotalFen : order.goods_total_fen) }}</span>
      </div>
      <button class="btn btn--primary edit-submit" type="button" :disabled="!canSave || previewing || submitting" @click="openPreview">
        {{ previewing ? '计算中…' : itemsChanged ? '预览修改' : '保存修改' }}
      </button>
    </div>

    <!-- 预览确认 -->
    <BottomSheet v-model="showPreviewSheet" title="确认变更" :mask-closable="false">
      <template v-if="preview">
        <div class="preview-amount">
          <div class="row row--amount"><span>原金额</span><span class="num">{{ formatFen(preview.old_total_fen) }}</span></div>
          <div class="row row--amount"><span>新金额</span><span class="num">{{ formatFen(preview.new_total_fen) }}</span></div>
          <div class="row row--total">
            <span>{{ order && order.paid_fen === 0 ? '应收变化' : preview.delta_fen >= 0 ? '需补付' : '将退还' }}</span>
            <span class="price num">{{ formatDelta(Math.abs(preview.delta_fen)) }}</span>
          </div>
        </div>

        <p class="preview-message">{{ order && order.paid_fen === 0 ? '修改确认后立即生效，订单应收按新金额更新。' : preview.customer_message }}</p>

        <div v-if="preview.warnings?.length" class="preview-warnings">
          <p v-for="(w, i) in preview.warnings" :key="i" class="preview-warnings__item">{{ w }}</p>
        </div>

        <p class="mini preview-note">
          {{
            order && order.paid_fen === 0
              ? '未收款订单无需补款；送达付款或扫码预付时请按更新后的订单金额支付。'
              : preview.delta_fen > 0
              ? '补款成功前，商家看到的仍是原订单内容，不会按新单备货。'
              : preview.delta_fen < 0
                ? '退款由店主实际退还后核实，可在订单详情查看进度。'
                : '本次修改不涉及金额变化，提交后立即生效。'
          }}
        </p>
      </template>

      <template #footer>
        <button class="btn btn--ghost" type="button" @click="showPreviewSheet = false">返回修改</button>
        <button class="btn btn--primary" type="button" :disabled="submitting" @click="confirmRevision">
          {{ submitting ? '提交中…' : '确认变更' }}
        </button>
      </template>
    </BottomSheet>

    <!-- 加菜 -->
    <BottomSheet v-model="showPicker" title="加菜" :mask-closable="false">
      <div class="picker-search">
        <SearchBox
          v-model="pickerKeyword"
          mode="input"
          placeholder="搜索商品"
          submit-text="搜索"
          @submit="loadPicker"
        />
      </div>

      <div v-if="pickerLoading" class="picker-loading">加载中…</div>

      <ul v-else class="picker-list">
        <li v-for="item in pickerGoods" :key="item.id" class="picker-item" @click="addFromPicker(item)">
          <GoodsImage :src="item.image_url" :alt="item.title" :fallback-text="item.title" radius="var(--radius-sm)" />
          <div class="picker-item__body">
            <p class="picker-item__name clamp-2">{{ item.title }}</p>
            <p class="caption">{{ item.spec_desc || item.inventory_unit }}</p>
          </div>
          <span class="price num">{{ formatFen(item.min_price_fen) }}</span>
        </li>
      </ul>
    </BottomSheet>

    <!-- 时间选择 -->
    <BottomSheet v-model="showTimeSheet" title="选择希望送达的时间" :mask-closable="false">
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
            :class="{ 'is-active': scheduleHour === h }"
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
            :class="{ 'is-active': scheduleMinute === m }"
            type="button"
            @click="scheduleMinute = m"
          >
            {{ String(m).padStart(2, '0') }}
          </button>
        </div>
      </div>

      <template #footer>
        <button class="btn btn--ghost" type="button" @click="showTimeSheet = false">取消</button>
        <button class="btn btn--primary" type="button" @click="showTimeSheet = false">确定</button>
      </template>
    </BottomSheet>
  </div>
</template>

<style scoped>
.edit-tip {
  margin: 12px 0 0;
  padding: 8px 12px;
  border-radius: var(--radius-sm);
  background: var(--color-brand-100);
  color: var(--color-brand-700);
  font-size: var(--font-caption);
  line-height: 1.6;
}

.edit-price-note {
  margin: 8px 2px 0;
  color: var(--color-text-muted);
  line-height: 1.5;
}

.edit-empty {
  padding: 16px 0;
  text-align: center;
  font-size: var(--font-caption);
  color: var(--color-text-secondary);
}

.edit-row {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 8px 0;
}

.edit-row :deep(.goods-image) {
  flex: 0 0 58px;
  width: 58px;
}

.edit-row__body {
  flex: 1;
  min-width: 0;
}

.edit-row__name {
  margin: 0;
  font-size: var(--font-caption);
  font-weight: var(--weight-semibold);
  line-height: 1.4;
}

.edit-row__foot {
  margin-top: 6px;
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 4px 0;
  font-size: var(--font-caption);
}

.row--amount {
  color: var(--color-text-secondary);
}

.row--total {
  font-size: var(--font-body);
  font-weight: var(--weight-semibold);
  color: var(--color-text-primary);
  padding-top: 8px;
}

.form-card {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.field {
  display: flex;
  align-items: center;
  gap: 12px;
  min-height: 44px;
  border-bottom: 1px solid var(--color-divider);
}

.field:last-child {
  border-bottom: none;
}

.field--block {
  flex-direction: column;
  align-items: stretch;
  gap: 4px;
  padding-top: 6px;
}

.field__label {
  flex: 0 0 64px;
  font-size: var(--font-caption);
  color: var(--color-text-secondary);
}

.field input,
.field textarea {
  font-size: var(--font-body);
  resize: none;
  line-height: 1.6;
}

.choice-list {
  display: flex;
  gap: var(--space-3);
}

.choice {
  flex: 1;
  min-height: 48px;
  border-radius: var(--radius-md);
  border: 1px solid var(--color-divider);
  background: var(--color-surface);
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 0 14px;
  font-size: var(--font-caption);
}

.choice.is-active {
  border-color: var(--color-brand-600);
  background: var(--color-brand-100);
  color: var(--color-brand-700);
  font-weight: var(--weight-semibold);
}

.choice__radio {
  width: 16px;
  height: 16px;
  border-radius: 50%;
  border: 1.6px solid var(--color-divider);
  position: relative;
  flex: 0 0 16px;
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
  font-size: var(--font-body);
  font-weight: var(--weight-semibold);
  color: var(--color-brand-700);
}

.remark-input {
  width: 100%;
  border-radius: var(--radius-lg);
  border: 1px solid var(--color-divider);
  background: var(--color-surface);
  padding: 12px 14px;
  font-size: var(--font-body);
  resize: none;
  line-height: 1.6;
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

.edit-submit {
  flex: 0 0 auto;
  min-width: 140px;
}

.preview-amount {
  padding-bottom: 6px;
}

.preview-message {
  margin: var(--space-4) 0 0;
  font-size: var(--font-caption);
  color: var(--color-brand-700);
  line-height: 1.6;
}

.preview-warnings {
  margin-top: var(--space-3);
}

.preview-warnings__item {
  margin: 0 0 4px;
  font-size: var(--font-mini);
  color: var(--color-warning);
}

.preview-note {
  margin-top: var(--space-3);
  line-height: 1.6;
}

.picker-search {
  padding-bottom: 12px;
}

.picker-loading {
  padding: 24px 0;
  text-align: center;
  color: var(--color-text-muted);
  font-size: var(--font-caption);
}

.picker-list {
  list-style: none;
  margin: 0;
  padding: 0;
  max-height: 48vh;
  overflow-y: auto;
}

.picker-item {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 0;
  border-bottom: 1px solid var(--color-divider);
}

.picker-item:last-child {
  border-bottom: none;
}

.picker-item :deep(.goods-image) {
  flex: 0 0 48px;
  width: 48px;
}

.picker-item__body {
  flex: 1;
  min-width: 0;
}

.picker-item__name {
  margin: 0;
  font-size: var(--font-caption);
  font-weight: var(--weight-semibold);
  line-height: 1.4;
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
}

.time-chip.is-active {
  border-color: var(--color-brand-600);
  background: var(--color-brand-100);
  color: var(--color-brand-700);
  font-weight: var(--weight-semibold);
}

.detail-line {
  height: 18px;
  margin-top: 14px;
}
</style>
