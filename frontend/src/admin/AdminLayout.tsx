import { Outlet, useLocation, useNavigate } from 'react-router'
import { Shell } from '../components/Shell'
import { useMe } from '../lib/auth'
import { useTitle } from '../lib/hooks'
import { useAdminDashboard } from './queries'
import { A, splitHosts } from '../lib/sites'

const PAGES: Record<string, [string, string]> = {
  '': ['super admin', 'Overview'],
  clients: ['clients', 'Clients'],
  services: ['services', 'All services'],
  invoices: ['billing', 'Invoices'],
  payments: ['billing', 'Payments'],
  tickets: ['support', 'Tickets'],
  requests: ['pipeline', 'Project requests'],
}

export default function AdminLayout() {
  const { data: me } = useMe()
  const { data: dash } = useAdminDashboard()
  const location = useLocation()
  const navigate = useNavigate()
  const section = location.pathname.split('/')[splitHosts ? 1 : 2] ?? ''
  const [eyebrow, title] = PAGES[section] ?? PAGES['']
  useTitle(`Admin · ${title}`)

  return (
    <Shell
      user={me!.user}
      subtitle="Super admin"
      eyebrow={eyebrow}
      title={title}
      action={
        <button className="btn" style={{ padding: '9px 14px' }} onClick={() => navigate(A('/invoices/new'))}>
          <i className="fa-solid fa-plus" style={{ fontSize: 11 }} /><span className="hide-sm">New invoice</span>
        </button>
      }
      nav={[
        { to: A(''), label: 'Overview', icon: 'fa-solid fa-chart-simple', end: true },
        { to: A('/clients'), label: 'Clients', icon: 'fa-solid fa-users' },
        { to: A('/services'), label: 'Services', icon: 'fa-solid fa-layer-group' },
        { to: A('/invoices'), label: 'Invoices', icon: 'fa-solid fa-file-invoice-dollar', badge: dash?.kpis.overdue_count },
        { to: A('/payments'), label: 'Payments', icon: 'fa-solid fa-money-bill-wave', badge: dash?.kpis.payments_to_review },
        { to: A('/tickets'), label: 'Tickets', icon: 'fa-solid fa-life-ring', badge: dash?.kpis.awaiting_reply },
        { to: A('/requests'), label: 'Requests', icon: 'fa-solid fa-paper-plane', badge: dash?.kpis.new_requests },
      ]}
    >
      <Outlet />
    </Shell>
  )
}
