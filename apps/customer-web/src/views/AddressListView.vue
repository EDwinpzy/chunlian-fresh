<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import PageHeader from '@/components/PageHeader.vue'
import EmptyState from '@/components/EmptyState.vue'
import BottomSheet from '@/components/BottomSheet.vue'
import { useShopStore } from '@/stores/shop'
import { useToast } from '@/composables/toast'

/**
 * 收货地址列表（方案 §5.3 UI-C06）。
 *
 * 方案要求：删除默认地址需选新默认 —— 这条规则在**服务端**实现
 * （删掉默认地址后自动把剩下最新的一条顶上来），
 * 客户端只负责提示，避免两端各写一套规则而分叉。
 */
const route = useRoute()
const router = useRouter()
const shop = useShopStore()
const toast = useToast()

const loading = ref(true)
const failed = ref(false)
const pendingDelete = ref<{ id: string; name: string } | null>(null)
const acting = ref(false)

/** 从结算页跳过来时，选完地址要自动返回 */
const pickMode = route.query.pick === '1'

async function load() {
  loading.value = true
  failed.value = false
  try {
    await shop.loadAddresses(true)
  } catch {
    failed.value = true
    toast.error('读取地址失败，请重试')
  } finally {
    loading.value = false
  }
}

function edit(id: string) {
  router.push({ path: '/addresses/edit', query: { id, pick: pickMode ? '1' : '' } })
}

async function choose(id: string) {
  if (!pickMode) return

  acting.value = true
  try {
    await shop.setDefault(id)
    router.back()
  } catch {
    toast.error('选择地址失败，请重试')
  } finally {
    acting.value = false
  }
}

async function setDefault(id: string) {
  acting.value = true
  try {
    await shop.setDefault(id)
    toast.success('默认地址已更新')
    if (pickMode) router.back()
  } catch {
    toast.error('设置默认地址失败，请重试')
  } finally {
    acting.value = false
  }
}

async function confirmDelete() {
  if (!pendingDelete.value) return

  acting.value = true
  try {
    await shop.removeAddress(pendingDelete.value.id)
    toast.success('地址已删除')
    pendingDelete.value = null
  } catch {
    toast.error('删除失败，请重试')
  } finally {
    acting.value = false
  }
}

onMounted(load)
</script>

<template>
  <div class="page">
    <PageHeader :title="pickMode ? '选择收货地址' : '收货地址'" />

    <div class="page-body page-body--actionbar">
      <div v-if="loading" class="skeleton detail-line" />

      <EmptyState
        v-else-if="failed"
        icon="error"
        title="读取地址失败"
        description="请检查网络后重试。"
        action-text="重试"
        @action="load"
      />

      <EmptyState
        v-else-if="shop.addresses.length === 0"
        icon="empty"
        title="还没有收货地址"
        description="填写一次即可复用，下单时直接选择。"
        action-text="新增地址"
        @action="router.push('/addresses/edit')"
      />

      <ul v-else class="addr-list">
        <li
          v-for="addr in shop.addresses"
          :key="addr.id"
          class="addr-card"
          :class="{ 'is-selected': pickMode }"
          :role="pickMode ? 'button' : undefined"
          :tabindex="pickMode ? 0 : undefined"
          @click="choose(addr.id)"
          @keydown.enter.prevent="choose(addr.id)"
          @keydown.space.prevent="choose(addr.id)"
        >
          <div class="addr-card__head">
            <span class="addr-card__name">{{ addr.name }}</span>
            <span class="caption">{{ addr.phone }}</span>
            <span v-if="addr.is_default" class="pill pill--info">默认</span>
          </div>
          <p class="addr-card__text">{{ addr.address }}</p>

          <div class="addr-card__ops" @click.stop>
            <button
              v-if="!addr.is_default"
              class="btn btn--text"
              type="button"
              :disabled="acting"
              @click="setDefault(addr.id)"
            >
              设为默认
            </button>
            <button class="btn btn--text" type="button" @click="edit(addr.id)">编辑</button>
            <button
              class="btn btn--text addr-card__danger"
              type="button"
              @click="pendingDelete = { id: addr.id, name: addr.name }"
            >
              删除
            </button>
          </div>
        </li>
      </ul>
    </div>

    <div class="action-bar">
      <button class="btn btn--primary btn--block" type="button" @click="router.push('/addresses/edit')">
        新增收货地址
      </button>
    </div>

    <BottomSheet :model-value="pendingDelete !== null" title="删除地址" :mask-closable="false" @update:model-value="pendingDelete = null">
      <p class="sheet-text">删除「{{ pendingDelete?.name }}」的收货地址？删除后无法恢复。</p>
      <template #footer>
        <button class="btn btn--ghost" type="button" @click="pendingDelete = null">返回</button>
        <button class="btn btn--danger" type="button" :disabled="acting" @click="confirmDelete">
          {{ acting ? '删除中…' : '确认删除' }}
        </button>
      </template>
    </BottomSheet>
  </div>
</template>

<style scoped>
.addr-list {
  list-style: none;
  margin: 12px 0 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: var(--space-3);
}

.addr-card {
  background: var(--color-surface);
  border: 1px solid var(--color-divider);
  border-radius: var(--radius-lg);
  padding: 14px;
}

.addr-card__head {
  display: flex;
  align-items: center;
  gap: 8px;
}

.addr-card__name {
  font-size: var(--font-product);
  font-weight: var(--weight-semibold);
}

.addr-card__text {
  margin: 6px 0 0;
  font-size: var(--font-body);
  line-height: 1.55;
}

.addr-card__ops {
  margin-top: 10px;
  padding-top: 8px;
  border-top: 1px solid var(--color-divider);
  display: flex;
  justify-content: flex-end;
  gap: var(--space-5);
  font-size: var(--font-caption);
}

.addr-card__danger {
  color: var(--color-danger);
}

.detail-line {
  height: 18px;
  margin-top: 14px;
}

.sheet-text {
  margin: 0;
  font-size: var(--font-body);
  color: var(--color-text-secondary);
  line-height: 1.6;
}
</style>
