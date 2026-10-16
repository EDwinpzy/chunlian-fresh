import { defineConfig, devices } from '@playwright/test'

/**
 * 独立 staging 浏览器测试：使用专用端口，固定代理目标为 loopback 隔离隧道。
 * 不复用常规开发服务器，防止误把写入型测试发往默认生产 API。
 */
export default defineConfig({
  testDir: './tests',
  testMatch: '**/staging-local-proxy.spec.js',
  fullyParallel: false,
  workers: 1,
  forbidOnly: true,
  retries: 0,
  // This scenario traverses the entire account, funds and delivery life cycle.
  timeout: 120_000,
  reporter: 'list',
  use: {
    baseURL: 'http://127.0.0.1:5174/app/',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
  projects: [
    { name: 'chromium-mobile-staging', use: { ...devices['Pixel 7'] } },
  ],
  webServer: {
    command: 'node tests/staging-vite-local-proxy.js',
    url: 'http://127.0.0.1:5174/app/',
    reuseExistingServer: false,
    timeout: 60_000,
  },
})
