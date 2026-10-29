import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useState, type FormEvent } from 'react'
import { Card, Field, Toggle } from '../components/ui'
import { api, ApiError, errorMessage } from '../lib/api'
import { ME_KEY, useMe } from '../lib/auth'
import { ago, fmtTime } from '../lib/format'
import { useToast } from '../lib/toast'
import type { Me, Prefs, User } from '../lib/types'

const NOTIFS: [keyof Prefs, string, string][] = [
  ['inv', 'New invoices', 'When an invoice is issued or due'],
  ['svc', 'New services', 'When a service is added to your account'],
  ['tick', 'Ticket replies', 'When I answer a ticket'],
  ['renew', 'Renewals', '14 days before a service renews'],
  ['news', 'Product updates', 'Occasional, a few times a year'],
]

export default function Profile() {
  const { data: me } = useMe()
  const user = me!.user
  const qc = useQueryClient()
  const toast = useToast()
  const [pf, setPf] = useState({ name: user.name, company: user.company ?? '', email: user.email, phone: user.phone ?? '' })

  const setUser = (u: User) => qc.setQueryData<Me | null>(ME_KEY, (old) => (old ? { ...old, user: u } : old))

  const save = useMutation({
    mutationFn: () => api.put<{ data: User }>('/portal/profile', pf),
    onSuccess: (res) => { setUser(res.data); toast.ok('Profile saved') },
  })

  const prefs = useMutation({
    mutationFn: (next: Partial<Prefs>) => api.put<{ data: User }>('/portal/profile/notifications', next),
    onMutate: (next) => setUser({ ...user, prefs: { ...user.prefs, ...next } }),
    onSuccess: (res) => setUser(res.data),
    onError: (err) => { setUser(user); toast.error(errorMessage(err)) },
  })

  const submit = (e: FormEvent) => {
    e.preventDefault()
    save.mutate()
  }
  const fieldError = (k: string) => (save.error instanceof ApiError ? save.error.field(k) : undefined)
  const input = (k: keyof typeof pf, type = 'text') => (
    <input className={`input${fieldError(k) ? ' invalid' : ''}`} type={type} value={pf[k]} onChange={(e) => setPf({ ...pf, [k]: e.target.value })} required={k === 'name' || k === 'email'} />
  )

  return (
    <div className="grid-3" style={{ maxWidth: 900 }}>
      <form onSubmit={submit} className="card fade-up" style={{ padding: 24 }}>
        <span className="card-title">Account</span>
        <Field label="name" error={fieldError('name')}>{input('name')}</Field>
        <Field label="company" error={fieldError('company')}>{input('company')}</Field>
        <Field label="email" error={fieldError('email')} hint="You sign in with this address.">{input('email', 'email')}</Field>
        <Field label="phone" error={fieldError('phone')}>{input('phone', 'tel')}</Field>
        <button type="submit" className="btn" style={{ alignSelf: 'flex-start' }} disabled={save.isPending}>
          {save.isPending && <i className="fa-solid fa-circle-notch spin" />}Save changes
        </button>
      </form>
      <div style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
        <Card delay={0.08} style={{ padding: 24, gap: 6 }}>
          <span className="card-title" style={{ marginBottom: 10 }}>Notifications</span>
          {NOTIFS.map(([id, label, hint]) => (
            <button key={id} className="notif-row" role="switch" aria-checked={user.prefs[id]} onClick={() => prefs.mutate({ [id]: !user.prefs[id] })}>
              <div style={{ display: 'flex', flexDirection: 'column', gap: 2 }}><span style={{ fontSize: 14 }}>{label}</span><span className="dim" style={{ fontSize: 12 }}>{hint}</span></div>
              <Toggle on={user.prefs[id]} />
            </button>
          ))}
        </Card>
        <Card delay={0.16} style={{ padding: 24, gap: 12 }}>
          <span className="card-title">Sign-in</span>
          <p className="muted" style={{ margin: 0, fontSize: 13, lineHeight: '20px' }}>Passwordless. A one-time code goes to your email each time you sign in.</p>
          {user.last_login_at && <span className="mono dim" style={{ fontSize: 11 }}>Last sign-in · {ago(user.last_login_at).toLowerCase()}, {fmtTime(user.last_login_at)}</span>}
        </Card>
      </div>
    </div>
  )
}
