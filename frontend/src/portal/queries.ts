import { useQuery } from '@tanstack/react-query'
import { api } from '../lib/api'
import type { Activity, Invoice, Service, Ticket, Uptime } from '../lib/types'

export interface Dashboard {
  kpis: {
    outstanding_cents: number
    due_count: number
    overdue_count: number
    active_services: number
    in_development: number
    open_tickets: number
    last_reply_at: string | null
    next_renewal: { date: string; name: string; price_label: string } | null
  }
  due_invoices: Invoice[]
  progress: { id: number; label: string; percent: number; kind: 'hours' | 'milestone' }[]
  uptime: Uptime
  activity: Activity[]
}

export interface InvoiceList {
  data: Invoice[]
  totals: { outstanding_cents: number; paid_this_year_cents: number; year: number }
}

export const portalKeys = {
  all: ['portal'] as const,
  dashboard: ['portal', 'dashboard'] as const,
  services: ['portal', 'services'] as const,
  invoices: ['portal', 'invoices'] as const,
  tickets: ['portal', 'tickets'] as const,
  ticket: (n: number) => ['portal', 'tickets', n] as const,
}

export const useDashboard = () => useQuery({ queryKey: portalKeys.dashboard, queryFn: () => api.get<Dashboard>('/portal/dashboard') })
export const useServices = () => useQuery({ queryKey: portalKeys.services, queryFn: () => api.get<{ data: Service[] }>('/portal/services').then((r) => r.data) })
export const useInvoices = () => useQuery({ queryKey: portalKeys.invoices, queryFn: () => api.get<InvoiceList>('/portal/invoices') })
export const useTickets = () => useQuery({ queryKey: portalKeys.tickets, queryFn: () => api.get<{ data: Ticket[] }>('/portal/tickets').then((r) => r.data) })
export const useTicket = (number: number | null) => useQuery({
  queryKey: portalKeys.ticket(number ?? 0),
  queryFn: () => api.get<{ data: Ticket }>(`/portal/tickets/${number}`).then((r) => r.data),
  enabled: !!number,
})
