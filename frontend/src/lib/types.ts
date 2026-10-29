export type Role = 'admin' | 'client'

export interface Prefs { inv: boolean; svc: boolean; tick: boolean; renew: boolean; news: boolean }

/** The client's saved card (display details only; the card itself stays in Stripe). */
export interface SavedCard { brand: string; last4: string; exp_month: number; exp_year: number }

export interface User {
  id: number
  name: string
  email: string
  role: Role
  company: string | null
  phone: string | null
  address: string | null
  initials: string
  prefs: Prefs
  card: SavedCard | null
  autopay: boolean
  is_active: boolean
  last_login_at: string | null
  created_at: string | null
  // admin-only
  admin_notes?: string | null
  last_login_ip?: string | null
  services_count?: number | null
  open_tickets_count?: number | null
  outstanding_cents?: number | null
}

export interface OfflineMethod {
  method: 'etransfer' | 'cheque' | 'bank'
  label: string
  lines: [string, string][]
  hint: string
}

export interface Me {
  user: User
  offline_methods: OfflineMethod[]
  stripe: { enabled: boolean; key: string | null }
  business: {
    name: string
    owner_name: string
    email: string
    currency: string
    tax_label: string
    tax_rate: number
    payment_terms_days: number
  }
}

export interface Service {
  id: number
  name: string
  type: string
  type_label: string
  icon: string
  status: 'active' | 'in_development' | 'paused' | 'cancelled'
  status_label: string
  detail: string | null
  meta: string | null
  price_cents: number | null
  price_label: string
  billing_cycle: string | null
  progress: number | null
  progress_label: string | null
  hours_total: number | null
  hours_used: number | null
  started_at: string | null
  renews_at: string | null
  monitored: boolean
  // admin-only
  user_id?: number
  user?: User
  auto_invoice?: boolean
  /** The period "Invoice renewal" would bill now, or why it can't (admin only). */
  renewal?: { from: string; to: string } | { blocked: string }
  monitor_url?: string | null
  raw_progress?: number | null
  sort?: number
}

export interface InvoiceItem {
  id: number
  service_id: number | null
  description: string
  quantity: number
  unit_price_cents: number
  amount_cents: number
}

export interface Payment {
  id: number
  amount_cents: number
  currency: string
  method: string
  status: 'pending' | 'review' | 'succeeded' | 'failed' | 'rejected'
  method_label: string
  client_note: string | null
  has_receipt: boolean
  receipt_name: string | null
  receipt_mime: string | null
  receipt_size: number | null
  submitted_at: string | null
  reviewed_at: string | null
  reviewed_by?: string | null
  rejection_reason: string | null
  reference: string | null
  note: string | null
  paid_at: string | null
  created_at: string | null
  invoices?: { id: number; number: string; description: string; amount_cents: number }[]
  user?: User
}

export interface Invoice {
  id: number
  number: string
  description: string
  status: 'draft' | 'unpaid' | 'paid' | 'void'
  display_status: 'Draft' | 'Unpaid' | 'In review' | 'Overdue' | 'Expired' | 'Paid' | 'Void'
  /** Issued and still owed. */
  open: boolean
  /** Can be paid online now (open and within due date + grace days). */
  payable: boolean
  overdue: boolean
  expired: boolean
  /** Offline payment proof waiting for review. */
  in_review: boolean
  accepts_offline: boolean
  rejection?: { reason: string | null; at: string | null } | null
  grace_days: number
  pay_until: string | null
  issued_at: string | null
  due_at: string | null
  paid_at: string | null
  currency: string
  subtotal_cents: number
  tax_rate: number
  tax_label: string | null
  tax_cents: number
  total_cents: number
  notes: string | null
  items?: InvoiceItem[]
  // admin-only
  user_id?: number
  user?: User
  sent_at?: string | null
  reminded_at?: string | null
  reminders?: Partial<Record<'due_soon' | 'due_today' | 'overdue' | 'closed', string>>
  payments?: Payment[]
  created_at?: string | null
}

export interface TicketMessage {
  id: number
  body: string
  from_admin: boolean
  author: string
  created_at: string
}

export interface Ticket {
  id: number
  number: number
  reference: string
  subject: string
  status: 'open' | 'in_progress' | 'resolved'
  status_label: string
  message_count: number | null
  last_activity_at: string | null
  last_admin_reply_at: string | null
  created_at: string | null
  messages?: TicketMessage[]
  user?: User
}

export interface Activity {
  id: number
  kind: string
  icon: string
  color: string
  text: string
  created_at: string
}

export interface ProjectRequest {
  id: number
  types: string[]
  type_labels: string[]
  budget: string
  budget_label: string
  description: string | null
  status: 'new' | 'reviewing' | 'quoted' | 'won' | 'declined'
  created_at: string
  admin_notes?: string | null
  user?: User
}

export interface UptimeDay { date: string; percent: number | null; state: 'up' | 'degraded' | 'down' | 'none' }
export interface Uptime { percent: number | null; days: UptimeDay[]; up: boolean | null }

export interface Paginated<T> {
  data: T[]
  meta?: { current_page: number; last_page: number; total: number }
}

export interface Option { value: string; label: string; icon?: string }
