import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useState, type FormEvent } from 'react'
import { Link, useNavigate, useParams } from 'react-router'
import { Badge, Card, Empty, ErrorState, Field, Loading, Modal } from '../components/ui'
import { api, errorMessage } from '../lib/api'
import { ago, fmtDate, fmtShort, money } from '../lib/format'
import { cap, PAYMENT_METHOD_LABELS } from '../lib/status'
import { useToast } from '../lib/toast'
import type { Service } from '../lib/types'
import ClientForm from './ClientForm'
import InvoicesTable from './InvoicesTable'
import { adminKeys, useClient, useMeta } from './queries'
import ServiceForm from './ServiceForm'
import ServicesTable from './ServicesTable'
import { A } from '../lib/sites'

type Tab = 'services' | 'invoices' | 'payments' | 'tickets' | 'requests' | 'activity'

export default function ClientDetail() {
  const id = Number(useParams().id)
  const { data, isLoading, error, refetch } = useClient(id)
  const qc = useQueryClient()
  const toast = useToast()
  const navigate = useNavigate()
  const [tab, setTab] = useState<Tab>('services')
  const [editing, setEditing] = useState(false)
  const [service, setService] = useState<Service | 'new' | null>(null)
  const [posting, setPosting] = useState(false)

  const invite = useMutation({
    mutationFn: () => api.post(`/admin/clients/${id}/invite`),
    onSuccess: () => toast.ok('Invite sent', 'fa-solid fa-paper-plane'),
    onError: (e) => toast.error(errorMessage(e)),
  })
  const remove = useMutation({
    mutationFn: () => api.delete(`/admin/clients/${id}`),
    onSuccess: () => { qc.invalidateQueries({ queryKey: adminKeys.all }); toast.ok('Client deleted'); navigate(A('/clients')) },
    onError: (e) => toast.error(errorMessage(e)),
  })
  const removeActivity = useMutation({
    mutationFn: (activityId: number) => api.delete(`/admin/activities/${activityId}`),
    onSuccess: () => qc.invalidateQueries({ queryKey: adminKeys.client(id) }),
  })

  if (isLoading) return <Loading />
  if (error || !data) return <ErrorState error={error} retry={refetch} />
  const c = data.client
  const openTickets = data.tickets.filter((t) => t.status !== 'resolved').length

  const tabs: [Tab, string, number][] = [
    ['services', 'Services', data.services.length],
    ['invoices', 'Invoices', data.invoices.length],
    ['payments', 'Payments', data.payments.length],
    ['tickets', 'Tickets', openTickets],
    ['requests', 'Requests', data.requests.length],
    ['activity', 'Activity', 0],
  ]

  return (
    <div className="stack" style={{ gap: 16 }}>
      <Link to={A('/clients')} className="btn-text fade-up"><i className="fa-solid fa-arrow-left" style={{ fontSize: 10 }} />All clients</Link>
      <div className="detail-grid">
        <div className="stack" style={{ gap: 16, minWidth: 0 }}>
          <Card style={{ gap: 14 }}>
            <div className="spread" style={{ alignItems: 'flex-start', flexWrap: 'wrap' }}>
              <div className="row" style={{ gap: 14 }}>
                <span className="avatar" style={{ width: 44, height: 44, fontSize: 15 }}>{c.initials}</span>
                <div style={{ display: 'flex', flexDirection: 'column', gap: 2 }}>
                  <span className="display" style={{ fontSize: 20, fontWeight: 700 }}>{c.company || c.name}</span>
                  <span className="muted" style={{ fontSize: 13 }}>{c.company ? `${c.name} · ` : ''}{c.email}</span>
                </div>
              </div>
              <div className="row-wrap">
                {!c.is_active && <Badge label="Inactive" />}
                <button className="btn btn-ghost btn-sm" onClick={() => setEditing(true)}><i className="fa-solid fa-pen" style={{ fontSize: 11 }} />Edit</button>
                <button className="btn btn-ghost btn-sm" onClick={() => invite.mutate()} disabled={invite.isPending}><i className="fa-solid fa-paper-plane" style={{ fontSize: 11 }} />Send invite</button>
                <button className="btn btn-sm" onClick={() => navigate(A(`/invoices/new?client=${c.id}`))}><i className="fa-solid fa-file-invoice" style={{ fontSize: 11 }} />New invoice</button>
              </div>
            </div>
            <div className="grid-kpi" style={{ gridTemplateColumns: 'repeat(auto-fit, minmax(120px, 1fr))' }}>
              <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}><span className="eyebrow">outstanding</span><span className="mid-num" style={{ color: c.outstanding_cents ? 'var(--amber)' : undefined }}>{money(c.outstanding_cents ?? 0)}</span></div>
              <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}><span className="eyebrow">paid to date</span><span className="mid-num">{money(data.payments.filter((p) => p.status === 'succeeded').reduce((s, p) => s + p.amount_cents, 0))}</span></div>
              <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}><span className="eyebrow">services</span><span className="mid-num">{data.services.length}</span></div>
              <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}><span className="eyebrow">open tickets</span><span className="mid-num">{openTickets}</span></div>
            </div>
          </Card>

          <div className="card card-flush fade-up" style={{ animationDelay: '.06s' }}>
            <div className="spread" style={{ paddingRight: 14, borderBottom: '1px solid var(--border)' }}>
              <div className="tabs" style={{ border: 'none' }}>
                {tabs.map(([key, label, n]) => (
                  <button key={key} className={`tab${tab === key ? ' on' : ''}`} onClick={() => setTab(key)}>{label}{n > 0 && <span className="count-pill">{n}</span>}</button>
                ))}
              </div>
              {tab === 'services' && <button className="btn btn-sm" onClick={() => setService('new')}><i className="fa-solid fa-plus" style={{ fontSize: 10 }} />Add</button>}
              {tab === 'activity' && <button className="btn btn-sm" onClick={() => setPosting(true)}><i className="fa-solid fa-plus" style={{ fontSize: 10 }} />Post update</button>}
            </div>
            {tab === 'services' && <ServicesTable services={data.services} onEdit={setService} showClient={false} />}
            {tab === 'invoices' && <InvoicesTable invoices={data.invoices} showClient={false} />}
            {tab === 'payments' && (data.payments.length === 0 ? <Empty icon="fa-solid fa-money-bill-wave">No payments.</Empty> : (
              <div className="table-wrap"><table className="table">
                <thead><tr><th>date</th><th>invoices</th><th>method</th><th>status</th><th className="r">amount</th></tr></thead>
                <tbody>{data.payments.map((p) => (
                  <tr key={p.id}>
                    <td className="sub nowrap">{fmtShort(p.paid_at ?? p.created_at)}</td>
                    <td className="mono" style={{ fontSize: 12 }}>{p.invoices?.map((i) => i.number).join(', ')}</td>
                    <td className="muted">{PAYMENT_METHOD_LABELS[p.method] ?? p.method}{p.reference ? ` · ${p.reference}` : ''}</td>
                    <td><Badge label={cap(p.status)} /></td>
                    <td className="r mono">{money(p.amount_cents)}</td>
                  </tr>
                ))}</tbody>
              </table></div>
            ))}
            {tab === 'tickets' && (data.tickets.length === 0 ? <Empty icon="fa-solid fa-life-ring">No tickets.</Empty> : (
              <div className="table-wrap"><table className="table">
                <thead><tr><th>ticket</th><th>subject</th><th>status</th><th>last activity</th></tr></thead>
                <tbody>{data.tickets.map((t) => (
                  <tr key={t.id} className="clickable" onClick={() => navigate(A(`/tickets/${t.id}`))}>
                    <td className="mono" style={{ fontSize: 12 }}>{t.reference}</td>
                    <td style={{ fontWeight: 500 }}>{t.subject}</td>
                    <td><Badge label={t.status_label} /></td>
                    <td className="sub">{ago(t.last_activity_at)}</td>
                  </tr>
                ))}</tbody>
              </table></div>
            ))}
            {tab === 'requests' && (data.requests.length === 0 ? <Empty icon="fa-solid fa-paper-plane">No project requests.</Empty> : (
              <div style={{ padding: '4px 20px' }}>
                {data.requests.map((r) => (
                  <div key={r.id} className="list-row" style={{ alignItems: 'flex-start' }}>
                    <div style={{ display: 'flex', flexDirection: 'column', gap: 4, flex: 1 }}>
                      <span style={{ fontWeight: 500 }}>{r.type_labels.join(', ')} · CAD {r.budget_label}</span>
                      {r.description && <span className="muted" style={{ fontSize: 13, whiteSpace: 'pre-wrap' }}>{r.description}</span>}
                      <span className="mono dim" style={{ fontSize: 11 }}>{fmtDate(r.created_at)}</span>
                    </div>
                    <Badge label={cap(r.status)} />
                  </div>
                ))}
              </div>
            ))}
            {tab === 'activity' && (data.activity.length === 0 ? <Empty icon="fa-solid fa-wave-square">No activity.</Empty> : (
              <div style={{ padding: 20, display: 'flex', flexDirection: 'column', gap: 14 }}>
                {data.activity.map((a) => (
                  <div key={a.id} className="row activity-row" style={{ alignItems: 'flex-start', gap: 12 }}>
                    <span className="timeline-dot" style={{ color: a.color }}><i className={a.icon} /></span>
                    <div style={{ display: 'flex', flexDirection: 'column', gap: 2, flex: 1 }}>
                      <span style={{ fontSize: 13 }}>{a.text}</span>
                      <span className="mono dim" style={{ fontSize: 11 }}>{fmtDate(a.created_at)}</span>
                    </div>
                    <button className="icon-btn bare" title="Remove from client's feed" onClick={() => removeActivity.mutate(a.id)}><i className="fa-solid fa-xmark" style={{ fontSize: 11 }} /></button>
                  </div>
                ))}
              </div>
            ))}
          </div>
        </div>

        <Card delay={0.1} style={{ gap: 14 }}>
          <span className="card-title">Details</span>
          <dl className="kv">
            <dt>Phone</dt><dd>{c.phone || '—'}</dd>
            <dt>Address</dt><dd style={{ whiteSpace: 'pre-line' }}>{c.address || '—'}</dd>
            <dt>Client since</dt><dd>{fmtDate(c.created_at)}</dd>
            <dt>Last sign-in</dt><dd>{c.last_login_at ? `${ago(c.last_login_at)}${c.last_login_ip ? ` · ${c.last_login_ip}` : ''}` : 'Never'}</dd>
            <dt>Emails</dt><dd>{Object.entries(c.prefs).filter(([, v]) => v).map(([k]) => ({ inv: 'invoices', svc: 'services', tick: 'tickets', renew: 'renewals', news: 'news' })[k]).join(', ') || 'none'}</dd>
          </dl>
          {c.admin_notes && (<><hr className="divider" /><span className="eyebrow">private notes</span><p className="muted" style={{ margin: 0, fontSize: 13, whiteSpace: 'pre-wrap' }}>{c.admin_notes}</p></>)}
          <hr className="divider" />
          <button className="btn btn-danger btn-sm" style={{ alignSelf: 'flex-start' }} disabled={remove.isPending}
            onClick={() => { if (confirm(`Delete ${c.company || c.name} and ALL their services, invoices, payments and tickets? This can't be undone.`)) remove.mutate() }}>
            <i className="fa-solid fa-trash" style={{ fontSize: 11 }} />Delete client
          </button>
        </Card>
      </div>

      {editing && <ClientForm client={c} onClose={() => setEditing(false)} />}
      {service && <ServiceForm service={service === 'new' ? undefined : service} userId={c.id} onClose={() => setService(null)} />}
      {posting && <PostUpdate userId={c.id} onClose={() => setPosting(false)} />}
    </div>
  )
}

function PostUpdate({ userId, onClose }: { userId: number; onClose: () => void }) {
  const qc = useQueryClient()
  const { data: meta } = useMeta()
  const [kind, setKind] = useState('milestone')
  const [text, setText] = useState('')
  const post = useMutation({
    mutationFn: () => api.post('/admin/activities', { user_id: userId, kind, text }),
    onSuccess: () => { qc.invalidateQueries({ queryKey: adminKeys.client(userId) }); onClose() },
  })
  const submit = (e: FormEvent) => { e.preventDefault(); post.mutate() }

  return (
    <Modal title="Post an update" eyebrow="shows in the client's recent activity" onClose={onClose}>
      <form onSubmit={submit}>
        <div className="modal-body">
          <Field label="kind">
            <select className="input" value={kind} onChange={(e) => setKind(e.target.value)}>{meta?.activity_kinds.map((k) => <option key={k} value={k}>{cap(k)}</option>)}</select>
          </Field>
          <Field label="text" error={post.error ? errorMessage(post.error) : undefined}><input className="input" required maxLength={255} value={text} onChange={(e) => setText(e.target.value)} placeholder="Milestone 3 delivered · Olive & Fig app" autoFocus /></Field>
        </div>
        <div className="modal-foot">
          <button type="button" className="btn btn-ghost" onClick={onClose}>Cancel</button>
          <button type="submit" className="btn" disabled={post.isPending}>Post</button>
        </div>
      </form>
    </Modal>
  )
}
