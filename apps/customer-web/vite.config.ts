import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import { fileURLToPath, URL } from 'node:url'

/**
 * 构建产物部署在 ShopXO 站点的 /app/ 子路径下（见 docs/DEPLOYMENT.md）。
 *
 * 正式网页与 API、图片在同一个 HTTPS 入口，部署 base 为 /app/。
 * 开发代理默认指向本机隔离 QA 隧道，避免开发操作意外写入生产。
 * 调试其他隔离后端时，可显式提供 VITE_API_TARGET。
 */
export default defineConfig({
  base: '/app/',
  plugins: [vue()],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  server: {
    host: '0.0.0.0',
    port: 5173,
    // 默认只连接隔离 QA；正式网页不使用此开发代理。
    proxy: {
      '/api': {
        target: process.env.VITE_API_TARGET || 'http://127.0.0.1:18096',
        changeOrigin: true,
      },
      '/static': {
        target: process.env.VITE_API_TARGET || 'http://127.0.0.1:18096',
        changeOrigin: true,
      },
    },
  },
  build: {
    outDir: 'dist',
    assetsDir: 'assets',
    // 顾客端在 4G 下打开，首屏体积直接影响流失率
    chunkSizeWarningLimit: 800,
    rollupOptions: {
      output: {
        manualChunks: {
          vendor: ['vue', 'vue-router', 'pinia'],
        },
      },
    },
  },
})
