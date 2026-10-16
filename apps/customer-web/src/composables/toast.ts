import { ref } from 'vue'

/**
 * 轻量 Toast。
 *
 * 方案 §4.5 要求「正确状态四件套：图标 + 简短文字 + 下一步建议 + 可恢复操作」，
 * 而加入购物车、保存地址这类**非阻塞反馈**不该弹模态框 ——
 * 模态框会打断「继续挑菜」这个主流程。
 * 因此这里只做一个 2 秒自动消失的顶部提示。
 */
export interface ToastItem {
  id: number
  text: string
  type: 'info' | 'success' | 'warning' | 'error'
}

const items = ref<ToastItem[]>([])
let seq = 0

export function useToast() {
  function show(text: string, type: ToastItem['type'] = 'info', duration = 2000) {
    const id = ++seq
    items.value.push({ id, text, type })
    window.setTimeout(() => {
      items.value = items.value.filter((it) => it.id !== id)
    }, duration)
  }

  return {
    items,
    show,
    info: (text: string) => show(text, 'info'),
    success: (text: string) => show(text, 'success'),
    warning: (text: string) => show(text, 'warning'),
    error: (text: string) => show(text, 'error', 2600),
  }
}
