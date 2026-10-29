import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useEffect, useRef, useState } from 'react'
import { useNavigate } from 'react-router'
import { api } from '../lib/api'
import { ago, fmtTime } from '../lib/format'
import { A } from '../lib/sites'
import { adminKeys, notificationKeys, useNotifications, type AdminNotification } from './queries'

const KIND_ICONS: Record<string, [string, string]> = {
  payment: ['fa-solid fa-circle-check', 'var(--lime)'],
  autopay: ['fa-solid fa-credit-card', 'var(--sky)'],
  invoice: ['fa-solid fa-file-invoice-dollar', 'var(--amber)'],
  ticket: ['fa-solid fa-life-ring', 'var(--link)'],
  request: ['fa-solid fa-paper-plane', 'var(--pink)'],
}

/** Admin header bell: unread count, latest notifications, click to open the related page. */
export default function NotificationBell() {
  const { data } = useNotifications()
  const qc = useQueryClient()
  const navigate = useNavigate()
  const [open, setOpen] = useState(false)
  const box = useRef<HTMLDivElement>(null)
  const unread = data?.unread ?? 0

  // New notifications usually mean a sidebar count changed (tickets, payments to review…).
  const seen = useRef(unread)
  useEffect(() => {
    if (unread > seen.current) qc.invalidateQueries({ queryKey: adminKeys.dashboard })
    seen.current = unread
  }, [unread, qc])

  useEffect(() => {
    if (!open) return
    const onDown = (e: MouseEvent) => { if (!box.current?.contains(e.target as Node)) setOpen(false) }
    const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape') setOpen(false) }
    window.addEventListener('mousedown', onDown)
    window.addEventListener('keydown', onKey)
    return () => { window.removeEventListener('mousedown', onDown); window.removeEventListener('keydown', onKey) }
  }, [open])

  const markRead = useMutation({
    mutationFn: (id: string) => api.post(`/admin/notifications/${id}/read`),
    onSettled: () => qc.invalidateQueries({ queryKey: notificationKeys }),
  })
  const markAll = useMutation({
    mutationFn: () => api.post('/admin/notifications/read-all'),
    onSettled: () => qc.invalidateQueries({ queryKey: notificationKeys }),
  })

  const openOne = (n: AdminNotification) => {
    if (!n.read) markRead.mutate(n.id)
    setOpen(false)
    if (n.path) navigate(A(n.path))
  }

  return (
    <div className="bell" ref={box}>
      <button className="icon-btn" style={{ width: 38, height: 38 }} aria-label={`Notifications${unread ? ` (${unread} unread)` : ''}`} aria-expanded={open} onClick={() => setOpen((o) => !o)}>
        <i className="fa-regular fa-bell" />
        {unread > 0 && <span className="bell-count">{unread > 99 ? '99+' : unread}</span>}
      </button>
      {open && (
        <div className="bell-panel" role="dialog" aria-label="Notifications">
          <div className="bell-head">
            <span style={{ fontSize: 14, fontWeight: 500 }}>Notifications</span>
            {unread > 0 && <button className="btn-link" style={{ fontSize: 12 }} onClick={() => markAll.mutate()} disabled={markAll.isPending}>Mark all read</button>}
          </div>
          <div className="bell-list">
            {!data?.data.length ? (
              <div className="dim" style={{ padding: '32px 16px', textAlign: 'center', fontSize: 13 }}>
                <i className="fa-regular fa-bell-slash" style={{ display: 'block', fontSize: 20, marginBottom: 8 }} />Nothing yet.
              </div>
            ) : data.data.map((n) => {
              const [icon, color] = KIND_ICONS[n.kind] ?? ['fa-solid fa-circle-info', 'var(--fg-3)']
              const details = Object.values(n.lines ?? {}).slice(0, 2).join(' · ')
              return (
                <button key={n.id} className={`bell-item${n.read ? '' : ' unread'}`} onClick={() => openOne(n)} title={fmtTime(n.at)}>
                  <i className={icon} style={{ color, marginTop: 3, width: 16, textAlign: 'center' }} />
                  <span style={{ display: 'flex', flexDirection: 'column', gap: 2, minWidth: 0, flex: 1 }}>
                    <span style={{ fontSize: 13, color: 'var(--fg)' }}>{n.title}</span>
                    {details && <span className="dim ellipsis" style={{ fontSize: 12 }}>{details}</span>}
                    <span className="mono dim" style={{ fontSize: 11 }}>{ago(n.at)}</span>
                  </span>
                  {!n.read && <span className="bell-dot" aria-label="unread" />}
                </button>
              )
            })}
          </div>
          <button className="bell-foot" onClick={() => { setOpen(false); navigate(A('/activity')) }}>View activity log<i className="fa-solid fa-arrow-right" style={{ fontSize: 10 }} /></button>
        </div>
      )}
    </div>
  )
}
