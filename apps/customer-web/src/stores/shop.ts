import { defineStore } from 'pinia'
import { ref } from 'vue'
import { api } from '@/api/client'
import type { Address, ShopSetting } from '@/api/types'

/**
 * 店铺设置与收货地址。
 *
 * 这两块都会被多个页面用到（首页要看营业状态与公告，结算页要看配送费与地址），
 * 放在 store 里可以避免每个页面各自发一次请求。
 */
export const useShopStore = defineStore('shop', () => {
  const setting = ref<ShopSetting | null>(null)
  const categories = ref<{ id: string; name: string; icon_url: string }[]>([])
  const addresses = ref<Address[]>([])
  const loading = ref(false)
  let settingRequest = 0
  let categoriesRequest = 0

  async function loadSetting(force = false) {
    if (setting.value && !force) return setting.value

    const requestId = ++settingRequest
    const nextSetting = await api.shop()
    if (requestId === settingRequest) setting.value = nextSetting

    return setting.value ?? nextSetting
  }

  async function loadCategories(force = false) {
    if (categories.value.length > 0 && !force) return categories.value

    const requestId = ++categoriesRequest
    const list = await api.categories()
    // 顾客端的分类入口只展示一级分类，二级分类在分类页里展开
    const nextCategories = list.map((c) => ({ id: c.id, name: c.name, icon_url: c.icon_url }))
    if (requestId === categoriesRequest) categories.value = nextCategories

    return categories.value.length ? categories.value : nextCategories
  }

  async function loadAddresses(force = false) {
    if (addresses.value.length > 0 && !force) return addresses.value

    addresses.value = await api.addresses()

    return addresses.value
  }

  async function saveAddress(payload: {
    id?: string
    name: string
    phone: string
    address: string
    is_default?: boolean
  }) {
    addresses.value = await api.saveAddress(payload)

    return addresses.value
  }

  async function removeAddress(id: string) {
    addresses.value = await api.deleteAddress(id)

    return addresses.value
  }

  async function setDefault(id: string) {
    addresses.value = await api.setDefaultAddress(id)

    return addresses.value
  }

  function defaultAddress(): Address | null {
    if (addresses.value.length === 0) return null

    return addresses.value.find((a) => a.is_default) ?? addresses.value[0]
  }

  function reset() {
    addresses.value = []
  }

  return {
    setting,
    categories,
    addresses,
    loading,
    loadSetting,
    loadCategories,
    loadAddresses,
    saveAddress,
    removeAddress,
    setDefault,
    defaultAddress,
    reset,
  }
})
