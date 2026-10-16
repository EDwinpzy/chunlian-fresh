<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import PageHeader from '@/components/PageHeader.vue'
import AssetIcon from '@/components/AssetIcon.vue'
import { useShopStore } from '@/stores/shop'
import { useToast } from '@/composables/toast'

/**
 * 地址编辑（方案 §5.3 UI-C07）。
 * 三个必填项：姓名 / 手机号 / 详细地址。手机格式在客户端与服务端都校验。
 *
 * 【为什么不做省市区三级联动】
 * 方案 §1.2 第 1 条：顾客手工填写并复用收货人、手机号、详细地址，
 * **无需地图授权**。三级联动会引入区域数据与地图 SDK，
 * 而本店是单店、配送范围就是店主熟悉的几条街 —— 手工填更快也更准。
 */
const route = useRoute()
const router = useRouter()
const shop = useShopStore()
const toast = useToast()

const editingId = computed(() => (route.query.id as string) || '')
const isEdit = computed(() => editingId.value !== '')

const form = ref({ name: '', phone: '', address: '', is_default: false })
const saving = ref(false)

const phoneValid = computed(() => /^1[3-9]\d{9}$/.test(form.value.phone))
const canSave = computed(
  () => form.value.name.trim() !== '' && phoneValid.value && form.value.address.trim().length >= 4,
)

async function save() {
  if (!canSave.value) {
    if (!phoneValid.value) toast.warning('请填写正确的 11 位手机号')
    else if (form.value.address.trim().length < 4) toast.warning('请填写详细地址（小区/楼栋/门牌）')
    else toast.warning('请填写收货人姓名')

    return
  }

  saving.value = true
  try {
    await shop.saveAddress({
      id: editingId.value || undefined,
      name: form.value.name.trim(),
      phone: form.value.phone.trim(),
      address: form.value.address.trim(),
      is_default: form.value.is_default,
    })
    toast.success(isEdit.value ? '地址已更新' : '地址已保存')
    router.back()
  } catch (error) {
    toast.error((error as Error).message || '保存失败，请重试')
  } finally {
    saving.value = false
  }
}

onMounted(async () => {
  await shop.loadAddresses().catch(() => null)

  if (isEdit.value) {
    const found = shop.addresses.find((a) => a.id === editingId.value)
    if (found) {
      form.value = {
        name: found.name,
        phone: found.phone,
        address: found.address,
        is_default: found.is_default,
      }
    } else {
      toast.warning('地址不存在')
      router.replace('/addresses')

      return
    }
  } else {
    // 第一条地址自动设为默认（服务端也会兜底，这里只是让开关状态与结果一致）
    form.value.is_default = shop.addresses.length === 0
  }
})
</script>

<template>
  <div class="page">
    <PageHeader :title="isEdit ? '编辑地址' : '新增地址'" />

    <div class="page-body page-body--actionbar">
      <div class="card form-card">
        <label class="field">
          <span class="field__label">收货人</span>
          <input v-model="form.name" type="text" maxlength="30" placeholder="请填写姓名" />
        </label>

        <label class="field">
          <span class="field__label">手机号</span>
          <input
            v-model="form.phone"
            type="tel"
            maxlength="11"
            inputmode="numeric"
            placeholder="11 位手机号"
          />
        </label>

        <label class="field field--block">
          <span class="field__label">详细地址</span>
          <textarea
            v-model="form.address"
            rows="3"
            maxlength="200"
            placeholder="例如：阳光小区 3 栋 502 室"
          />
        </label>

        <p v-if="form.phone && !phoneValid" class="field__error">手机号格式不正确</p>
      </div>

      <label class="default-toggle">
        <input v-model="form.is_default" type="checkbox" />
        <span class="default-toggle__box">
          <AssetIcon v-if="form.is_default" name="success" :size="20" />
        </span>
        <span class="default-toggle__text">设为默认地址</span>
      </label>

      <p class="mini form-tip">
        地址仅用于本次配送，不会用于其他用途。下单前可随时修改。
      </p>
    </div>

    <div class="action-bar">
      <button class="btn btn--primary btn--block" type="button" :disabled="!canSave || saving" @click="save">
        {{ saving ? '保存中…' : '保存' }}
      </button>
    </div>
  </div>
</template>

<style scoped>
.form-card {
  margin-top: 12px;
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.field {
  display: flex;
  align-items: center;
  gap: 12px;
  min-height: 48px;
  border-bottom: 1px solid var(--color-divider);
}

.field:last-of-type {
  border-bottom: none;
}

.field--block {
  flex-direction: column;
  align-items: stretch;
  gap: 4px;
  padding-top: 8px;
}

.field__label {
  flex: 0 0 64px;
  font-size: var(--font-caption);
  color: var(--color-text-secondary);
}

.field textarea {
  resize: none;
  line-height: 1.6;
}

.field__error {
  margin: 0;
  font-size: var(--font-mini);
  color: var(--color-danger);
}

.default-toggle {
  margin-top: var(--space-4);
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 0 4px;
}

.default-toggle input {
  display: none;
}

.default-toggle__box {
  width: 20px;
  height: 20px;
  border-radius: var(--radius-xs);
  border: 1.6px solid var(--color-divider);
  background: var(--color-surface);
  position: relative;
  flex: 0 0 20px;
}

.default-toggle input:checked + .default-toggle__box {
  border-color: transparent;
  background: transparent;
}

.default-toggle__text {
  font-size: var(--font-body);
}

.form-tip {
  margin: var(--space-4) 0 0;
  line-height: 1.6;
}
</style>
