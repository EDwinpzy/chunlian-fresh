<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import ShopLogo from '@/components/ShopLogo.vue'
import AssetIcon from '@/components/AssetIcon.vue'
import { useAuthStore } from '@/stores/auth'
import { useShopStore } from '@/stores/shop'
import { useToast } from '@/composables/toast'

/**
 * 登录 / 注册（方案 §2.1 C21 的网页版实现）。
 *
 * 【为什么是手机号 + 密码，而不是微信登录】
 * 微信登录需要小程序或开放平台资质与备案域名（见 HANDOVER.md 的待办），
 * 当前服务器只有 http://IP:8095，两者都不具备。
 * 手机号 + 密码是**立刻可用**的方案，且不依赖任何第三方授权；
 * 后端仍把账号写进 ShopXO 的用户表，将来接微信登录时不会冲突。
 *
 * 【短信验证码为什么没做】
 * 需要短信服务商与实名签名，属于运营侧配置。这里不做假验证码 ——
 * 一个假的「验证码」输入框比没有更糟。
 */
const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const shop = useShopStore()
const toast = useToast()

const mode = ref<'login' | 'register'>('login')
const phone = ref('')
const password = ref('')
const nickname = ref('')
const agreed = ref(false)
const submitting = ref(false)

const canSubmit = computed(
  () => /^1[3-9]\d{9}$/.test(phone.value) && password.value.length >= 6 && agreed.value,
)

async function submit() {
  if (!canSubmit.value) {
    if (!/^1[3-9]\d{9}$/.test(phone.value)) toast.warning('请填写正确的 11 位手机号')
    else if (password.value.length < 6) toast.warning('密码至少 6 位')
    else toast.warning('请先同意用户协议与隐私政策')

    return
  }

  submitting.value = true
  try {
    if (mode.value === 'login') {
      await auth.login(phone.value, password.value)
      toast.success('登录成功')
    } else {
      await auth.register(phone.value, password.value, nickname.value)
      toast.success('注册成功')
    }

    // 登录后立刻拉地址：结算页要用，且避免用户来回跳页面时反复等
    shop.loadAddresses(true).catch(() => null)

    const redirect = (route.query.redirect as string) || '/me'
    router.replace(redirect)
  } catch (error) {
    toast.error((error as Error).message || '操作失败，请重试')
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div class="page">
    <div class="page-body login-page">
      <header class="login-head">
        <div class="login-logo">
          <ShopLogo :size="40" />
        </div>
        <h1 class="login-title">{{ shop.setting?.shop_name || '店铺' }}</h1>
        <p v-if="shop.setting?.shop_slogan" class="login-sub">{{ shop.setting.shop_slogan }}</p>
      </header>

      <div class="login-tabs">
        <button
          class="login-tab"
          :class="{ 'is-active': mode === 'login' }"
          type="button"
          @click="mode = 'login'"
        >
          登录
        </button>
        <button
          class="login-tab"
          :class="{ 'is-active': mode === 'register' }"
          type="button"
          @click="mode = 'register'"
        >
          注册
        </button>
      </div>

      <div class="card login-form">
        <label class="field">
          <span class="field__label">手机号</span>
          <input v-model="phone" type="tel" maxlength="11" inputmode="numeric" placeholder="11 位手机号" />
        </label>

        <label class="field">
          <span class="field__label">密码</span>
          <input
            v-model="password"
            type="password"
            maxlength="32"
            :placeholder="mode === 'register' ? '至少 6 位' : '请输入密码'"
          />
        </label>

        <label v-if="mode === 'register'" class="field">
          <span class="field__label">称呼</span>
          <input v-model="nickname" type="text" maxlength="20" placeholder="选填，例如：张先生" />
        </label>
      </div>

      <label class="agree">
        <input v-model="agreed" type="checkbox" />
        <span class="agree__box" />
        <span class="agree__text">
          我已阅读并同意
          <button class="agree__link" type="button" @click.prevent="router.push('/about')">用户协议与隐私政策</button>
        </span>
      </label>

      <button class="btn btn--primary btn--block login-submit" type="button" :disabled="!canSubmit || submitting" @click="submit">
        {{ submitting ? '处理中…' : mode === 'login' ? '登录' : '注册并登录' }}
      </button>

      <p class="mini login-tip">
        我们只收集手机号用于登录与配送联系，不获取位置与通讯录。
      </p>

      <button class="btn btn--text login-back" type="button" @click="router.replace('/')">先随便看看</button>
    </div>
  </div>
</template>

<style scoped>
.login-page {
  padding-top: calc(env(safe-area-inset-top) + 32px);
  display: flex;
  flex-direction: column;
}

.login-head {
  text-align: center;
  margin-bottom: var(--space-6);
}

.login-logo {
  display: flex;
  justify-content: center;
  margin-bottom: 12px;
}

.login-title {
  margin: 0;
  font-size: var(--font-title);
  font-weight: var(--weight-bold);
  color: var(--color-brand-700);
}

.login-sub {
  margin: 4px 0 0;
  font-size: var(--font-caption);
  color: var(--color-text-secondary);
}

.login-tabs {
  display: flex;
  gap: var(--space-5);
  justify-content: center;
  margin-bottom: var(--space-4);
}

.login-tab {
  padding: 6px 2px;
  font-size: var(--font-product);
  color: var(--color-text-muted);
  position: relative;
}

.login-tab.is-active {
  color: var(--color-brand-700);
  font-weight: var(--weight-semibold);
}

.login-tab.is-active::after {
  content: '';
  position: absolute;
  left: 0;
  right: 0;
  bottom: -4px;
  height: 2px;
  border-radius: var(--radius-pill);
  background: var(--color-brand-700);
}

.login-form {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.field {
  display: flex;
  align-items: center;
  gap: 12px;
  min-height: 48px;
  border-bottom: 1px solid var(--color-divider);
}

.field:last-child {
  border-bottom: none;
}

.field__label {
  flex: 0 0 56px;
  font-size: var(--font-caption);
  color: var(--color-text-secondary);
}

.agree {
  margin-top: var(--space-4);
  display: flex;
  align-items: flex-start;
  gap: 8px;
}

.agree input {
  display: none;
}

.agree__box {
  width: 18px;
  height: 18px;
  margin-top: 2px;
  border-radius: var(--radius-xs);
  border: 1.6px solid var(--color-divider);
  background: var(--color-surface);
  position: relative;
  flex: 0 0 18px;
}

.agree input:checked + .agree__box {
  background: var(--color-brand-700);
  border-color: var(--color-brand-700);
}

.agree input:checked + .agree__box::after {
  content: '';
  position: absolute;
  left: 5px;
  top: 1px;
  width: 5px;
  height: 9px;
  border-right: 2px solid var(--color-on-brand);
  border-bottom: 2px solid var(--color-on-brand);
  transform: rotate(40deg);
}

.agree__text {
  font-size: var(--font-mini);
  color: var(--color-text-secondary);
  line-height: 1.6;
}

.agree__link {
  color: var(--color-brand-600);
  text-decoration: underline;
  font-size: inherit;
}

.login-submit {
  margin-top: var(--space-5);
}

.login-tip {
  margin: var(--space-4) 0 0;
  text-align: center;
  line-height: 1.6;
}

.login-back {
  margin: var(--space-5) auto 0;
}
</style>
