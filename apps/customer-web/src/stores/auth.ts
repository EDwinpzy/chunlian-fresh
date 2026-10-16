import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { api, getToken, setToken, UnauthorizedError } from '@/api/client'
import type { AuthUser } from '@/api/types'

/**
 * 顾客登录态。
 *
 * 【为什么把 token 放在 localStorage 而不是内存】
 * 网页版会被顾客加到手机桌面当 App 用（PWA），每次打开都要求重新登录
 * 会直接劝退。token 有效期 90 天，且服务端只存 SHA-256 ——
 * 即使浏览器数据泄露，攻击者拿到的也只是「一个可被服务端随时吊销的会话」。
 */
export const useAuthStore = defineStore('auth', () => {
  const token = ref(getToken())
  const user = ref<AuthUser | null>(null)
  const loading = ref(false)

  const isLoggedIn = computed(() => token.value !== '')

  function applyToken(next: string) {
    token.value = next
    setToken(next)
  }

  async function login(phone: string, password: string) {
    loading.value = true
    try {
      const result = await api.login(phone, password)
      applyToken(result.token)
      user.value = result.user

      return result.user
    } finally {
      loading.value = false
    }
  }

  async function register(phone: string, password: string, nickname: string) {
    loading.value = true
    try {
      const result = await api.register(phone, password, nickname)
      applyToken(result.token)
      user.value = result.user

      return result.user
    } finally {
      loading.value = false
    }
  }

  /** 拉取当前用户；token 失效时静默登出。 */
  async function fetchProfile(): Promise<AuthUser | null> {
    if (!token.value) return null

    try {
      user.value = await api.me()

      return user.value
    } catch (error) {
      if (error instanceof UnauthorizedError) {
        applyToken('')
        user.value = null
      }

      return null
    }
  }

  async function logout() {
    try {
      if (token.value) await api.logout()
    } catch {
      // 登出失败也要清本地状态，否则用户会卡在「点了退出但还是登录着」
    }
    applyToken('')
    user.value = null
  }

  function clear() {
    applyToken('')
    user.value = null
  }

  return { token, user, loading, isLoggedIn, login, register, fetchProfile, logout, clear }
})
