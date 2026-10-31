import { useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { Outlet, useLocation, useNavigate } from 'react-router'
import { Shell, SystemStatus } from '../components/Shell'
import { api, errorMessage } from '../lib/api'
import { useMe } from '../lib/auth'
import { firstName, greeting } from '../lib/format'
import { useTitle } from '../lib/hooks'
import { useDashboard } from './queries'
import { A, P, splitHosts } from '../lib/sites'
import { useToast } from '../lib/toast'
import type { User } from '../lib/types'

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
      banner={me!.impersonator && <ImpersonationBanner user={user} />}
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

/** Shown while an admin is signed in as this client, with the way back to the admin. */
function ImpersonationBanner({ user }: { user: User }) {
  const qc = useQueryClient()
  const toast = useToast()
  const [leaving, setLeaving] = useState(false)

  const back = async () => {
    setLeaving(true)
    try {
      await api.post('/auth/stop-impersonating')
      qc.clear()
      window.location.assign(A(`/clients/${user.id}`))
    } catch (e) {
      toast.error(errorMessage(e))
      setLeaving(false)
    }
  }

  return (
    <div className="shell-banner" role="status">
      <span><i className="fa-solid fa-user-secret" style={{ marginRight: 8 }} />You’re signed in as {user.company || user.name}. Anything you do is logged.</span>
      <button onClick={back} disabled={leaving}>Back to admin</button>
    </div>
  )
}
