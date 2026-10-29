import { useState, type ReactNode } from 'react'
import { NavLink, useLocation, useNavigate } from 'react-router'
import { useLogout } from '../lib/auth'
import { go, landingPath, loginPath } from '../lib/sites'
import type { User } from '../lib/types'
import { Brand } from './ui'
import './shell.css'

export interface NavItem {
  to: string
  label: string
  icon: string
  badge?: number | null
  end?: boolean
}

/** Sidebar + sticky header layout shared by the client portal and the admin area. */
export function Shell({ nav, user, subtitle, eyebrow, title, status, action, children }: {
  nav: NavItem[]
  user: User
  subtitle: string
  eyebrow: string
  title: string
  status?: ReactNode
  action?: ReactNode
  children: ReactNode
}) {
  const location = useLocation()
  const navigate = useNavigate()
  const logout = useLogout()
  // The mobile drawer is open only for the page it was opened on, so navigating closes it.
  const [openOn, setOpenOn] = useState<string | null>(null)
  const open = openOn === location.pathname
  const setOpen = (v: boolean) => setOpenOn(v ? location.pathname : null)

  const signOut = async () => {
    await logout()
    go(navigate, loginPath(), true)
  }

  return (
    <div className="shell fade-in">
      <aside className={`shell-side${open ? ' open' : ''}`}>
        <div style={{ padding: '0 10px' }} className="spread">
          <Brand to={landingPath()} />
          <button className="icon-btn bare shell-close" onClick={() => setOpen(false)} aria-label="Close menu"><i className="fa-solid fa-xmark" /></button>
        </div>
        <nav className="shell-nav">
          {nav.map((n) => (
            <NavLink key={n.to} to={n.to} end={n.end} className={({ isActive }) => `shell-link${isActive ? ' on' : ''}`}>
              <span className="mark" />
              <i className={n.icon} />
              <span style={{ flex: 1 }}>{n.label}</span>
              {!!n.badge && <span className="count-pill">{n.badge}</span>}
            </NavLink>
          ))}
        </nav>
        <div style={{ marginTop: 'auto', display: 'flex', flexDirection: 'column', gap: 12 }}>
          <div className="shell-user">
            <span className="avatar">{user.initials}</span>
            <div style={{ display: 'flex', flexDirection: 'column', minWidth: 0 }}>
              <span className="ellipsis" style={{ fontSize: 13, fontWeight: 500 }}>{user.name}</span>
              <span className="ellipsis dim" style={{ fontSize: 11 }}>{subtitle}</span>
            </div>
          </div>
          <button className="shell-signout" onClick={signOut}><i className="fa-solid fa-arrow-right-from-bracket" style={{ fontSize: 12 }} />Sign out</button>
        </div>
      </aside>
      {open && <div className="shell-scrim" onClick={() => setOpen(false)} />}

      <div className="shell-main">
        <header className="shell-head">
          <div className="row" style={{ gap: 12, minWidth: 0 }}>
            <button className="icon-btn shell-menu" onClick={() => setOpen(true)} aria-label="Open menu"><i className="fa-solid fa-bars" /></button>
            <div style={{ display: 'flex', flexDirection: 'column', gap: 2, minWidth: 0 }}>
              <span className="eyebrow">{eyebrow}</span>
              <h1 className="display ellipsis" style={{ margin: 0, fontWeight: 700, fontSize: 22, lineHeight: 1.1 }}>{title}</h1>
            </div>
          </div>
          <div className="row">
            {status && <div className="shell-status">{status}</div>}
            {action}
          </div>
        </header>
        <main className="shell-content">{children}</main>
      </div>
    </div>
  )
}

export function SystemStatus({ up }: { up: boolean | null | undefined }) {
  if (up === null || up === undefined) return null
  return (
    <div className="row eyebrow" style={{ gap: 8 }}>
      <span style={{ width: 7, height: 7, borderRadius: '50%', background: up ? 'var(--lime)' : 'var(--red)', animation: `${up ? 'pulse' : 'pulseRed'} 1.8s ease-out infinite` }} />
      {up ? 'all systems up' : 'investigating an issue'}
    </div>
  )
}
