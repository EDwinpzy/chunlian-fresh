<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import BottomSheet from '@/components/BottomSheet.vue'
import AssetIcon from '@/components/AssetIcon.vue'
import { api, ApiError } from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { useCartStore } from '@/stores/cart'
import { useShopStore } from '@/stores/shop'
import { useToast } from '@/composables/toast'

const router = useRouter()
const auth = useAuthStore()
const cart = useCartStore()
const shop = useShopStore()
const toast = useToast()

const showLogout = ref(false)
const showProfileEdit = ref(false)
const profileNickname = ref('')
const savingProfile = ref(false)
const orderCount = ref<number | null>(null)

const isLoggedIn = computed(() => auth.isLoggedIn)
const shopPhone = computed(() => shop.setting?.phone || '')

const displayName = computed(() => {
  if (!auth.user) return '未登录'

  return auth.user.nickname || auth.user.mobile_masked
})

async function loadOrderCount() {
  if (!isLoggedIn.value) {
    orderCount.value = null

    return
  }

  try {
    const page = await import('@/api/client').then((m) => m.api.orders({ page: 1, page_size: 1 }))
    orderCount.value = page.total
  } catch {
    orderCount.value = null
  }
}

function callShop() {
  if (shopPhone.value) {
    window.location.href = `tel:${shopPhone.value}`
  } else {
    toast.info('店主暂未留联系电话')
  }
}

async function doLogout() {
  await auth.logout()
  showLogout.value = false
  toast.success('已退出登录')
  router.replace('/')
}

function openProfileEdit() {
  profileNickname.value = auth.user?.nickname || ''
  showProfileEdit.value = true
}

async function saveProfile() {
  const nickname = profileNickname.value.trim()
  if (!nickname || savingProfile.value) return

  savingProfile.value = true
  try {
    auth.user = await api.updateProfile({ nickname })
    showProfileEdit.value = false
    toast.success('昵称已更新')
  } catch (error) {
    toast.error(error instanceof ApiError ? error.message : '保存失败，请重试')
  } finally {
    savingProfile.value = false
  }
}

onMounted(async () => {
  await Promise.all([
    shop.loadSetting().catch(() => null),
    auth.isLoggedIn ? auth.fetchProfile() : Promise.resolve(null),
    loadOrderCount(),
  ])
})
</script>

<template>
  <div class="page">
    <div class="page-body page-body--tabbar profile-body">
      <h1 class="profile-title">我的</h1>
      <section class="profile-head" aria-label="账户信息">
        <div class="profile-avatar" aria-hidden="true">{{ isLoggedIn ? displayName.slice(0, 1) : '你' }}</div>
        <div class="profile-info">
          <p class="profile-name">{{ isLoggedIn ? displayName : '登录 / 注册' }}</p>
          <p class="profile-sub">{{ isLoggedIn ? auth.user?.mobile_masked : '登录后可查看订单与地址' }}</p>
        </div>
        <button v-if="!isLoggedIn" class="profile-edit-trigger" type="button"
          @click="router.push({ name: 'login', query: { redirect: '/me' } })">去登录 <span aria-hidden="true">›</span></button>
        <button v-else class="profile-edit-trigger" type="button" @click="openProfileEdit">修改昵称 <span aria-hidden="true">›</span></button>
      </section>

      <section class="card group" aria-label="订单与地址">
        <button class="group__row" type="button" @click="router.push('/orders')">
          <AssetIcon class="group__icon" name="orders" :size="22" />
          <span class="group__content"><span class="group__label">我的订单</span></span>
          <span v-if="orderCount !== null" class="group__value">{{ orderCount }} 单</span>
          <span class="group__chevron" aria-hidden="true" />
        </button>
        <button class="group__row" type="button" @click="router.push('/addresses')">
          <AssetIcon class="group__icon" name="address" :size="22" />
          <span class="group__content"><span class="group__label">收货地址</span></span>
          <span class="group__chevron" aria-hidden="true" />
        </button>
      </section>

      <h2 class="profile-section-title">服务与设置</h2>
      <section class="card group" aria-label="服务与设置">
        <button class="group__row" type="button" @click="callShop">
          <AssetIcon class="group__icon" name="phone" :size="22" />
          <span class="group__content"><span class="group__label">联系店主</span></span><span class="group__value">{{ shopPhone || '暂未设置' }}</span>
          <span class="group__chevron" aria-hidden="true" />
        </button>
        <button class="group__row" type="button" @click="router.push('/cart')">
          <AssetIcon class="group__icon" name="cart" :size="22" />
          <span class="group__content"><span class="group__label">购物车</span></span>
          <span class="group__value">{{ cart.count ? `${cart.count} 件` : '' }}</span>
          <span class="group__chevron" aria-hidden="true" />
        </button>
        <button class="group__row" type="button" @click="router.push('/about')">
          <AssetIcon class="group__icon" name="notification" :size="22" />
          <span class="group__content"><span class="group__label">用户协议与隐私政策</span></span>
          <span class="group__chevron" aria-hidden="true" />
        </button>
      </section>
      <button v-if="isLoggedIn" class="logout-btn" type="button" @click="showLogout = true">退出登录</button>
      <p class="mini profile-foot">{{ shop.setting?.shop_name || '店铺' }}<template v-if="shop.setting?.shop_slogan"> · {{ shop.setting.shop_slogan }}</template></p>
    </div>

    <BottomSheet v-model="showLogout" title="退出登录" :mask-closable="false">
      <p class="sheet-text">退出后需要重新输入手机号和密码。购物车会保留在本机。</p>
      <template #footer>
        <button class="btn btn--ghost" type="button" @click="showLogout = false">取消</button>
        <button class="btn btn--danger" type="button" @click="doLogout">退出登录</button>
      </template>
    </BottomSheet>

    <BottomSheet v-model="showProfileEdit" title="修改昵称">
      <label class="field profile-edit-field">
        <span class="field__label">昵称</span>
        <input
          v-model="profileNickname"
          type="text"
          maxlength="30"
          placeholder="请输入昵称"
          aria-label="昵称"
          @keydown.enter.prevent="saveProfile"
        />
      </label>
      <template #footer>
        <button class="btn btn--ghost" type="button" :disabled="savingProfile" @click="showProfileEdit = false">取消</button>
        <button class="btn btn--primary" type="button" :disabled="!profileNickname.trim() || savingProfile" @click="saveProfile">
          {{ savingProfile ? '保存中…' : '保存' }}
        </button>
      </template>
    </BottomSheet>
  </div>
</template>

<style scoped>
.profile-body { padding-top: 0; }
.profile-title { margin: 0; padding-top: calc(env(safe-area-inset-top) + 12px); font-size: 22px; line-height: 1.5; font-weight: 700; }
.profile-head { display: flex; align-items: center; gap: 14px; padding: 16px 0 20px; }
.profile-avatar { width: 56px; height: 56px; flex: 0 0 56px; border-radius: 50%; background: var(--color-brand-100); color: var(--color-brand-700); display: flex; align-items: center; justify-content: center; font-size: 26px; font-weight: 600; }
.profile-info { flex: 1; min-width: 0; }
.profile-name { margin: 0; font-size: 21px; font-weight: 700; overflow-wrap: anywhere; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.profile-sub { margin: 6px 0 0; font-size: 13px; color: var(--color-text-secondary); }
.profile-edit-trigger { flex: 0 0 auto; min-height: 48px; color: var(--color-brand-600); font-size: 13px; }
.profile-edit-trigger span { padding-left: 5px; font-size: 18px; }
.profile-section-title { margin: 16px 8px 8px; font-size: 13px; font-weight: 400; color: var(--color-text-secondary); }
.group { padding: 0 16px; border: 0; border-radius: 16px; overflow: hidden; }
.group__row { position: relative; width: 100%; min-height: 56px; padding: 12px 0; display: flex; align-items: center; gap: 12px; text-align: left; }
.group__row + .group__row::before { content: ''; position: absolute; top: 0; left: 34px; right: 0; border-top: 1px solid var(--color-divider); }
.group__icon { flex: 0 0 22px; }
.group__content { display: flex; flex: 1; min-width: 0; flex-direction: column; gap: 4px; }
.group__label { font-size: 15px; font-weight: 500; }
.group__hint { font-size: 12px; color: var(--color-text-secondary); }
.group__value { flex-shrink: 0; max-width: 45%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 12px; color: var(--color-text-secondary); }
.group__chevron {
  width: 7px;
  height: 7px;
  flex: 0 0 7px;
  border-top: 1.5px solid var(--color-text-secondary);
  border-right: 1.5px solid var(--color-text-secondary);
  transform: rotate(45deg);
}
.logout-btn { margin-top: 16px; width: 100%; min-height: 48px; border-radius: 16px; background: var(--color-surface); color: var(--color-danger); font-size: 15px; }
.profile-foot { margin: 20px 0 0; text-align: center; }
.profile-edit-field { margin: 8px 0 4px; }
.sheet-text { margin: 0; font-size: var(--font-body); color: var(--color-text-secondary); line-height: 1.6; }
</style>
