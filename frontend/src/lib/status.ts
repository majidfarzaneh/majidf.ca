// Badge colors for every status shown in the UI: [text, background].
const STATUS: Record<string, [string, string]> = {
  Active: ['#b6d957', 'rgba(182,217,87,.1)'],
  'In development': ['#5699f6', 'rgba(86,153,246,.12)'],
  Paused: ['#fac364', 'rgba(250,195,100,.12)'],
  Cancelled: ['#71717a', '#18181b'],
  Draft: ['#a1a1aa', '#18181b'],
  Unpaid: ['#fac364', 'rgba(250,195,100,.12)'],
  Overdue: ['#e86466', 'rgba(232,100,102,.12)'],
  Expired: ['#f4a3a4', '#2a1215'],
  'In review': ['#8cd3ff', 'rgba(140,211,255,.1)'],
  Review: ['#8cd3ff', 'rgba(140,211,255,.1)'],
  Rejected: ['#e86466', 'rgba(232,100,102,.12)'],
  Paid: ['#71717a', '#18181b'],
  Void: ['#52525b', '#18181b'],
  Open: ['#fac364', 'rgba(250,195,100,.12)'],
  'In progress': ['#5699f6', 'rgba(86,153,246,.12)'],
  Resolved: ['#71717a', '#18181b'],
  New: ['#d998cb', 'rgba(217,152,203,.12)'],
  Reviewing: ['#5699f6', 'rgba(86,153,246,.12)'],
  Quoted: ['#fac364', 'rgba(250,195,100,.12)'],
  Won: ['#b6d957', 'rgba(182,217,87,.1)'],
  Declined: ['#71717a', '#18181b'],
  Succeeded: ['#b6d957', 'rgba(182,217,87,.1)'],
  Pending: ['#fac364', 'rgba(250,195,100,.12)'],
  Failed: ['#e86466', 'rgba(232,100,102,.12)'],
  Inactive: ['#71717a', '#18181b'],
}

export function statusColors(label: string): [string, string] {
  return STATUS[label] ?? ['#a1a1aa', '#18181b']
}

export const cap = (s: string) => s.charAt(0).toUpperCase() + s.slice(1).replace(/_/g, ' ')

export const PAYMENT_METHOD_LABELS: Record<string, string> = {
  card: 'Card (portal)',
  checkout: 'Stripe Checkout',
  autopay: 'Saved card (auto-pay)',
  etransfer: 'Interac e-Transfer',
  bank: 'Bank transfer',
  cash: 'Cash',
  cheque: 'Cheque',
  other: 'Other',
}
