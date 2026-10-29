import { useQuery } from '@tanstack/react-query'
import { api, qs } from '../lib/api'
import type { Activity, Invoice, Option, Payment, ProjectRequest, Service, Ticket, User } from '../lib/types'

export interface Meta {
  service_types: Option[]
  service_statuses: Option[]
  billing_cycles: Option[]
  payment_methods: string[]
  activity_kinds: string[]
  defaults: { tax_rate: number; tax_label: string; payment_terms_days: number; grace_days: number; reminder_days_before: number; next_invoice_number: string }
}

export interface AdminDashboardData {
  kpis: {
    outstanding_cents: number
    unpaid_count: number
    overdue_cents: number
    overdue_count: number
    revenue_month_cents: number
    revenue_year_cents: number
    mrr_cents: number
    clients: number
    open_tickets: number
    awaiting_reply: number
    new_requests: number
    drafts: number
    payments_to_review: number
  }
  revenue_by_month: { month: string; cents: number }[]
  overdue_invoices: Invoice[]
  awaiting_tickets: Ticket[]
  review_payments: Payment[]
  recent_payments: Payment[]
  upcoming_renewals: Service[]
}

export interface ClientDetailData {
  client: User
  services: Service[]
  invoices: Invoice[]
  payments: Payment[]
  tickets: Ticket[]
  requests: ProjectRequest[]
  activity: Activity[]
}

export const adminKeys = {
  all: ['admin'] as const,
  dashboard: ['admin', 'dashboard'] as const,
  meta: ['admin', 'meta'] as const,
  clients: (search = '') => ['admin', 'clients', search] as const,
  client: (id: number) => ['admin', 'client', id] as const,
  services: (f: object) => ['admin', 'services', f] as const,
  invoices: (f: object) => ['admin', 'invoices', f] as const,
  invoice: (id: number) => ['admin', 'invoice', id] as const,
  payments: (f: object) => ['admin', 'payments', f] as const,
  tickets: (f: object) => ['admin', 'tickets', f] as const,
  ticket: (id: number) => ['admin', 'ticket', id] as const,
  requests: (f: object) => ['admin', 'requests', f] as const,
}

export const useMeta = () => useQuery({ queryKey: adminKeys.meta, queryFn: () => api.get<Meta>('/admin/meta'), staleTime: 5 * 60_000 })
export const useAdminDashboard = () => useQuery({ queryKey: adminKeys.dashboard, queryFn: () => api.get<AdminDashboardData>('/admin/dashboard') })
export const useClients = (search = '') => useQuery({ queryKey: adminKeys.clients(search), queryFn: () => api.get<{ data: User[] }>(`/admin/clients${qs({ search })}`).then((r) => r.data) })
export const useClient = (id: number) => useQuery({ queryKey: adminKeys.client(id), queryFn: () => api.get<ClientDetailData>(`/admin/clients/${id}`) })
export const useAdminServices = (f: { user_id?: number; status?: string; type?: string }) =>
  useQuery({ queryKey: adminKeys.services(f), queryFn: () => api.get<{ data: Service[] }>(`/admin/services${qs(f)}`).then((r) => r.data) })
export const useAdminInvoices = (f: { status?: string; user_id?: number; search?: string }) =>
  useQuery({ queryKey: adminKeys.invoices(f), queryFn: () => api.get<{ data: Invoice[] }>(`/admin/invoices${qs(f)}`).then((r) => r.data) })
export const useAdminInvoice = (id: number) => useQuery({ queryKey: adminKeys.invoice(id), queryFn: () => api.get<{ data: Invoice }>(`/admin/invoices/${id}`).then((r) => r.data), enabled: !!id })
export const useAdminPayments = (f: { status?: string }) =>
  useQuery({ queryKey: adminKeys.payments(f), queryFn: () => api.get<{ data: Payment[] }>(`/admin/payments${qs(f)}`).then((r) => r.data) })
export const useAdminTickets = (f: { status?: string }) =>
  useQuery({ queryKey: adminKeys.tickets(f), queryFn: () => api.get<{ data: Ticket[] }>(`/admin/tickets${qs(f)}`).then((r) => r.data) })
export const useAdminTicket = (id: number) => useQuery({ queryKey: adminKeys.ticket(id), queryFn: () => api.get<{ data: Ticket }>(`/admin/tickets/${id}`).then((r) => r.data), enabled: !!id })
export const useRequests = (f: { status?: string }) =>
  useQuery({ queryKey: adminKeys.requests(f), queryFn: () => api.get<{ data: ProjectRequest[] }>(`/admin/project-requests${qs(f)}`).then((r) => r.data) })

export const clientLabel = (u?: User | null) => (u ? u.company || u.name : '—')

/** One entry in the admin's notification bell. */
export interface AdminNotification {
  id: string
  at: string
  read: boolean
  kind: 'payment' | 'invoice' | 'ticket' | 'request' | 'autopay' | string
  title: string
  lines: Record<string, string>
  path: string | null
}

/** One line of the admin activity log. */
export interface AuditEntry {
  id: number
  at: string
  event: string
  text: string
  actor: { type: 'client' | 'admin' | 'system'; id: number | null; name: string | null }
  client: { id: number | null; name: string | null } | null
  ip: string | null
  meta: Record<string, string> | null
  path: string | null
}

export interface AuditFilters { client_id?: number; actor?: string; category?: string; search?: string; page?: number }

export const notificationKeys = ['admin', 'notifications'] as const

/** The bell: checked every 30 seconds while the tab is open, and whenever it regains focus. */
export const useNotifications = () =>
  useQuery({
    queryKey: notificationKeys,
    queryFn: () => api.get<{ data: AdminNotification[]; unread: number }>('/admin/notifications'),
    refetchInterval: 30_000,
    refetchOnWindowFocus: true,
  })

export const useActivityLog = (f: AuditFilters) =>
  useQuery({
    queryKey: ['admin', 'activity-log', f] as const,
    queryFn: () => api.get<{ data: AuditEntry[]; meta: { current_page: number; last_page: number; total: number }; categories: Record<string, string> }>(`/admin/activity-log${qs({ ...f })}`),
    placeholderData: (prev) => prev,
  })
