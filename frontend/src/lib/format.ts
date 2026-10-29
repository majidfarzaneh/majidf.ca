const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']

/** "$1,240" or "$1,240.50" when there are cents. */
export function money(cents: number | null | undefined, forceDecimals = false): string {
  if (cents === null || cents === undefined) return '—'
  const decimals = forceDecimals || cents % 100 !== 0 ? 2 : 0
  const value = Math.abs(cents) / 100
  return (cents < 0 ? '-' : '') + '$' + value.toLocaleString('en-CA', { minimumFractionDigits: decimals, maximumFractionDigits: decimals })
}

/** Parse "2026-10-15" as a local date (not UTC), or a full ISO timestamp. */
export function toDate(value: string | null | undefined): Date | null {
  if (!value) return null
  const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value)
  if (m) return new Date(+m[1], +m[2] - 1, +m[3])
  const d = new Date(value)
  return Number.isNaN(d.getTime()) ? null : d
}

export function fmtDate(value: string | null | undefined): string {
  const d = toDate(value)
  return d ? `${MONTHS[d.getMonth()]} ${d.getDate()}, ${d.getFullYear()}` : '—'
}

export function fmtShort(value: string | null | undefined): string {
  const d = toDate(value)
  if (!d) return '—'
  return d.getFullYear() === new Date().getFullYear() ? `${MONTHS[d.getMonth()]} ${d.getDate()}` : `${MONTHS[d.getMonth()]} ${d.getDate()}, ${d.getFullYear()}`
}

export function monthDay(value: string | null | undefined): { month: string; day: number } | null {
  const d = toDate(value)
  return d ? { month: MONTHS[d.getMonth()], day: d.getDate() } : null
}

export function fmtTime(value: string | null | undefined): string {
  const d = toDate(value)
  return d ? d.toLocaleTimeString('en-CA', { hour: '2-digit', minute: '2-digit', hour12: false }) : ''
}

function sameDay(a: Date, b: Date) {
  return a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate()
}

/** "Just now", "2 h ago", "Yesterday", "Oct 8". */
export function ago(value: string | null | undefined): string {
  const d = toDate(value)
  if (!d) return '—'
  const diff = (Date.now() - d.getTime()) / 1000
  if (diff < 60) return 'Just now'
  if (diff < 3600) return `${Math.floor(diff / 60)} min ago`
  const now = new Date()
  if (sameDay(d, now)) return `${Math.floor(diff / 3600)} h ago`
  const yesterday = new Date(now)
  yesterday.setDate(now.getDate() - 1)
  if (sameDay(d, yesterday)) return 'Yesterday'
  return fmtShort(value)
}

/** Message timestamps: "Today 09:10", "Yesterday 16:20", "Oct 8". */
export function stamp(value: string | null | undefined): string {
  const d = toDate(value)
  if (!d) return ''
  const now = new Date()
  if (sameDay(d, now)) return `Today ${fmtTime(value)}`
  const yesterday = new Date(now)
  yesterday.setDate(now.getDate() - 1)
  if (sameDay(d, yesterday)) return `Yesterday ${fmtTime(value)}`
  return fmtShort(value)
}

export function greeting(): string {
  const h = new Date().getHours()
  return h < 12 ? 'Good morning' : h < 18 ? 'Good afternoon' : 'Good evening'
}

export function firstName(name: string | undefined | null): string {
  return (name ?? '').trim().split(/\s+/)[0] ?? ''
}

export function todayIso(offsetDays = 0): string {
  const d = new Date()
  d.setDate(d.getDate() + offsetDays)
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

/** "$840", "$1.2k", "$12k" for tight chart labels. */
export function compactMoney(cents: number): string {
  const dollars = cents / 100
  if (dollars < 1000) return '$' + Math.round(dollars)
  return '$' + (dollars < 10_000 ? (dollars / 1000).toFixed(1).replace(/\.0$/, '') : Math.round(dollars / 1000)) + 'k'
}

/** "2026-10-15" + 3 → "2026-10-18". */
export function addDays(isoDate: string, days: number): string {
  const d = toDate(isoDate)!
  d.setDate(d.getDate() + days)
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

/** "84 KB", "2.3 MB". */
export function fileSize(bytes: number): string {
  return bytes < 1024 * 1024 ? `${Math.max(1, Math.round(bytes / 1024))} KB` : `${(bytes / 1024 / 1024).toFixed(1)} MB`
}

/** "Visa •••• 4242 · 08/30" for a saved card. */
export function cardLabel(card: { brand: string; last4: string; exp_month: number; exp_year: number }): string {
  const brand = card.brand === 'amex' ? 'Amex' : card.brand.charAt(0).toUpperCase() + card.brand.slice(1)
  return `${brand} •••• ${card.last4} · ${String(card.exp_month).padStart(2, '0')}/${String(card.exp_year).slice(-2)}`
}
