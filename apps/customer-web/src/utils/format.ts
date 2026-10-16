/**
 * 展示层格式化工具。
 *
 * 【为什么金额格式化必须集中在一处】
 * 金额在服务端一律是整数分。页面上有「¥3.50」「合计 ¥35.80」「差额 +¥9.40」
 * 三种形态，如果每处各写一遍 `/100`，早晚会有一处写成 `/1000` 或漏掉补零。
 */

/** 整数分 → 「3.50」（不带货币符号）。 */
export function fenToYuan(fen: number): string {
  const value = Number.isFinite(fen) ? Math.round(fen) : 0
  const sign = value < 0 ? '-' : ''
  const abs = Math.abs(value)

  return `${sign}${Math.floor(abs / 100)}.${String(abs % 100).padStart(2, '0')}`
}

/** 整数分 → 「¥3.50」。 */
export function formatFen(fen: number): string {
  return `¥${fenToYuan(fen)}`
}

/** 带正负号的差额：「+¥9.40」/「-¥3.00」/「¥0.00」。 */
export function formatDelta(fen: number): string {
  if (fen > 0) return `+¥${fenToYuan(fen)}`
  if (fen < 0) return `-¥${fenToYuan(-fen)}`
  return '¥0.00'
}

/** 元字符串（用户输入）→ 整数分。四舍五入到分，避免 3.5 → 349 这类误差。 */
export function yuanToFen(input: string | number): number {
  const value = typeof input === 'number' ? input : Number.parseFloat(String(input).replace(/[^\d.-]/g, ''))
  if (!Number.isFinite(value)) return 0

  return Math.round(value * 100)
}

/** ISO8601 / MySQL datetime → 「10-13 17:30」。 */
export function formatDateTime(input: string | null | undefined): string {
  if (!input) return ''
  const parsed = parseDate(input)
  if (!parsed) return ''
  const date = shopCalendarDate(parsed)

  const pad = (n: number) => String(n).padStart(2, '0')
  return `${pad(date.getMonth() + 1)}-${pad(date.getDate())} ${pad(date.getHours())}:${pad(date.getMinutes())}`
}

/** 「今天 / 明天 / 10月15日」这类相对日期。 */
export function formatDayLabel(input: string | null | undefined): string {
  if (!input) return ''
  const parsed = parseDate(input)
  if (!parsed) return ''
  const date = shopCalendarDate(parsed)

  const today = shopCalendarDate()
  const startOf = (d: Date) => new Date(d.getFullYear(), d.getMonth(), d.getDate()).getTime()
  const diffDays = Math.round((startOf(date) - startOf(today)) / 86400000)

  if (diffDays === 0) return '今天'
  if (diffDays === 1) return '明天'
  if (diffDays === 2) return '后天'

  return `${date.getMonth() + 1}月${date.getDate()}日`
}

/**
 * 兼容 Safari 解析 `YYYY-MM-DD HH:mm:ss`。
 * 该格式在部分 WebKit 上会被当作 UTC 或直接 NaN，必须先转成 ISO。
 */
function parseDate(input: string): Date | null {
  let text = input.trim()
  if (/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(text)) {
    text = text.replace(' ', 'T') + '+08:00'
  }
  const date = new Date(text)

  return Number.isNaN(date.getTime()) ? null : date
}

/** A wall-calendar date for the store; date pickers must not inherit the browser zone. */
export function shopCalendarDate(date: Date = new Date()): Date {
  const parts = new Intl.DateTimeFormat('en-GB', {
    timeZone: 'Asia/Shanghai', year: 'numeric', month: '2-digit', day: '2-digit',
    hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23',
  }).formatToParts(date)
  const value = (type: string) => Number(parts.find((p) => p.type === type)?.value || 0)
  return new Date(value('year'), value('month') - 1, value('day'), value('hour'), value('minute'), value('second'))
}

/** 手机号脱敏：138****8000。 */
export function maskPhone(phone: string): string {
  if (!phone || phone.length < 7) return phone || ''

  return `${phone.slice(0, 3)}****${phone.slice(-4)}`
}

/** 配送时间的展示文案。 */
export function deliveryText(mode: string, expectedAt: string | null): string {
  if (mode === 'SCHEDULED' && expectedAt) {
    return `预约 ${formatDayLabel(expectedAt)} ${formatDateTime(expectedAt).split(' ')[1] || ''}`.trim()
  }

  return '尽快配送'
}

/** 把日期 + 时间拼成后端要的 ISO8601（带 +08:00 偏移）。 */
export function toExpectedIso(day: Date, hour: number, minute: number): string {
  const pad = (n: number) => String(n).padStart(2, '0')
  const y = day.getFullYear()
  const m = pad(day.getMonth() + 1)
  const d = pad(day.getDate())

  return `${y}-${m}-${d}T${pad(hour)}:${pad(minute)}:00+08:00`
}
