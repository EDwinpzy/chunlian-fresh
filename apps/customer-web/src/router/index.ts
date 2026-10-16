import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router'
import { getToken } from '@/api/client'
import { useToast } from '@/composables/toast'

/**
 * 路由表。
 *
 * 【底部导航只有 4 项】首页 / 分类 / 购物车 / 我的（方案 §5.1）。
 * 订单从「我的」进入，不单独占一个 Tab。
 *
 * 【返回原则】提交付款后不再返回可二次提交的旧结算页 ——
 * 下单成功后用 `replace` 跳订单详情，而不是 push。
 */
const routes: RouteRecordRaw[] = [
  {
    path: '/',
    name: 'home',
    component: () => import('@/views/HomeView.vue'),
    meta: { tabbar: true, title: '首页' },
  },
  {
    path: '/category',
    name: 'category',
    component: () => import('@/views/CategoryView.vue'),
    meta: { tabbar: true, title: '分类' },
  },
  {
    path: '/cart',
    name: 'cart',
    component: () => import('@/views/CartView.vue'),
    meta: { tabbar: true, title: '购物车' },
  },
  {
    path: '/me',
    name: 'me',
    component: () => import('@/views/ProfileView.vue'),
    meta: { tabbar: true, title: '我的' },
  },

  {
    path: '/search',
    name: 'search',
    component: () => import('@/views/SearchView.vue'),
    meta: { title: '搜索' },
  },
  {
    path: '/goods/:id',
    name: 'goods',
    component: () => import('@/views/GoodsDetailView.vue'),
    meta: { title: '商品详情' },
  },

  {
    path: '/checkout',
    name: 'checkout',
    component: () => import('@/views/CheckoutView.vue'),
    meta: { title: '确认订单', requiresAuth: true },
  },
  {
    path: '/order-result/:id',
    name: 'order-result',
    component: () => import('@/views/OrderResultView.vue'),
    meta: { title: '下单结果', requiresAuth: true },
  },

  {
    path: '/orders',
    name: 'orders',
    component: () => import('@/views/OrderListView.vue'),
    meta: { title: '我的订单', requiresAuth: true },
  },
  {
    path: '/orders/:id',
    name: 'order-detail',
    component: () => import('@/views/OrderDetailView.vue'),
    meta: { title: '订单详情', requiresAuth: true },
  },
  {
    path: '/orders/:id/edit',
    name: 'order-edit',
    component: () => import('@/views/OrderEditView.vue'),
    meta: { title: '修改订单', requiresAuth: true },
  },
  {
    path: '/orders/:id/refunds',
    name: 'order-refunds',
    component: () => import('@/views/RefundProgressView.vue'),
    meta: { title: '退款进度', requiresAuth: true },
  },

  {
    path: '/addresses',
    name: 'addresses',
    component: () => import('@/views/AddressListView.vue'),
    meta: { title: '收货地址', requiresAuth: true },
  },
  {
    path: '/addresses/edit',
    name: 'address-edit',
    component: () => import('@/views/AddressEditView.vue'),
    meta: { title: '编辑地址', requiresAuth: true },
  },

  {
    path: '/login',
    name: 'login',
    component: () => import('@/views/LoginView.vue'),
    meta: { title: '登录' },
  },
  {
    path: '/about',
    name: 'about',
    component: () => import('@/views/AboutView.vue'),
    meta: { title: '关于与政策' },
  },

  { path: '/:pathMatch(.*)*', redirect: '/' },
]

const router = createRouter({
  history: createWebHistory('/app/'),
  routes,
  scrollBehavior(_to, _from, savedPosition) {
    // 返回时恢复原滚动位置：顾客在商品列表翻到一半点进详情，
    // 返回后回到顶部会让他彻底迷路
    return savedPosition ?? { top: 0 }
  },
})

router.beforeEach((to) => {
  if (to.meta.requiresAuth && !getToken()) {
    useToast().info('请先登录')

    return { name: 'login', query: { redirect: to.fullPath } }
  }

  return true
})

export default router
