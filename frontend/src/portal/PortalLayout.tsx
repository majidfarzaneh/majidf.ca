import { Outlet, useLocation, useNavigate } from 'react-router'
import { Shell, SystemStatus } from '../components/Shell'
import { useMe } from '../lib/auth'
import { firstName, greeting } from '../lib/format'
import { useTitle } from '../lib/hooks'
import { useDashboard } from './queries'
import { P, splitHosts } from '../lib/sites'

const PAGES: Record<string, [string, string | ((name: string) => string)]> = {
  '': ['overview', (name) => `${greeting()}, ${name}`],
  services: ['services', 'Everything I run for you'],
  invoices: ['billing', 'Invoices'],
  tickets: ['support', 'Tickets'],
  request: ['new project', 'Tell me what you need'],
  profile: ['account', 'Profile & settings'],
}

export default function PortalLayout() {
  const { data: me } = useMe()
  const { data: dash } = useDashboard()
  const location = useLocation()
  const navigate = useNavigate()
  const user = me!.user

  const section = location.pathname.split('/')[splitHosts ? 1 : 2] ?? ''
  const [eyebrow, title] = PAGES[section] ?? PAGES['']
  const heading = typeof title === 'function' ? title(firstName(user.name)) : title
  useTitle(section ? heading : 'Client portal')

  return (
    <Shell
      user={user}
      subtitle={user.company ?? user.email}
      eyebrow={eyebrow}
      title={heading}
      status={<SystemStatus up={dash?.uptime.up} />}
      action={
        <button className="btn" style={{ padding: '9px 14px' }} onClick={() => navigate(P('/request'))}>
          <i className="fa-solid fa-plus" style={{ fontSize: 11 }} /><span className="hide-sm">New project</span>
        </button>
      }
      nav={[
        { to: P(''), label: 'Dashboard', icon: 'fa-solid fa-chart-simple', end: true },
        { to: P('/services'), label: 'Services', icon: 'fa-solid fa-layer-group' },
        { to: P('/invoices'), label: 'Invoices', icon: 'fa-solid fa-file-invoice-dollar', badge: dash?.kpis.due_count },
        { to: P('/tickets'), label: 'Support', icon: 'fa-solid fa-life-ring', badge: dash?.kpis.open_tickets },
        { to: P('/request'), label: 'New project', icon: 'fa-solid fa-plus' },
        { to: P('/profile'), label: 'Profile', icon: 'fa-solid fa-user' },
      ]}
    >
      <Outlet />
    </Shell>
  )
}
