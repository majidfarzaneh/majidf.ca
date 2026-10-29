import { useState } from 'react'
import { Link, useNavigate } from 'react-router'
import type { Payment } from '../lib/types'
import PaymentReview from './PaymentReview'
import { Masonry } from '../components/Masonry'
import { Badge, Card, Empty, ErrorState, Kpi, Loading } from '../components/ui'
import { ago, compactMoney, fmtShort, money } from '../lib/format'
import { useEntrance, useReady } from '../lib/hooks'
import { PAYMENT_METHOD_LABELS } from '../lib/status'
import { clientLabel, useAdminDashboard } from './queries'
import { A } from '../lib/sites'

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']

export default function AdminDashboard() {
  const { data, isLoading, error, refetch } = useAdminDashboard()
  const a = useEntrance(data ? 1 : 0)
  const ready = useReady()
  const navigate = useNavigate()
  const [reviewing, setReviewing] = useState<Payment | null>(null)

  if (isLoading) return <Loading />
  if (error || !data) return <ErrorState error={error} retry={refetch} />

  const k = data.kpis
  const count = (cents: number) => money(Math.round((cents * a) / 100) * 100)
  const max = Math.max(1, ...data.revenue_by_month.map((m) => m.cents))

  return (
    <div className="stack">
      <div className="grid-kpi">
        <Kpi delay={0.05} label="outstanding" value={count(k.outstanding_cents)} sub={`${k.unpaid_count} unpaid${k.drafts ? ` · ${k.drafts} draft${k.drafts === 1 ? '' : 's'}` : ''}`} />
        <Kpi delay={0.1} label="overdue" value={<span style={{ color: k.overdue_count ? 'var(--red)' : undefined }}>{count(k.overdue_cents)}</span>}
          sub={k.overdue_count ? <span style={{ color: 'var(--red)' }}>{k.overdue_count} invoice{k.overdue_count === 1 ? '' : 's'} late</span> : 'Nothing late'} />
        <Kpi delay={0.15} label="revenue · month" value={count(k.revenue_month_cents)} sub={`${count(k.revenue_year_cents)} this year`} />
        <Kpi delay={0.2} label="recurring · mrr" value={count(k.mrr_cents)} sub={`${k.clients} active client${k.clients === 1 ? '' : 's'}`} />
        <Kpi delay={0.25} label="open tickets" value={Math.round(k.open_tickets * a)}
          sub={k.awaiting_reply ? <span style={{ color: 'var(--amber)' }}>{k.awaiting_reply} awaiting your reply</span> : 'All answered'} />
      </div>

      <Card delay={0.3}>
        <div className="card-head">
          <span className="card-title">Revenue · last 12 months</span>
          <span className="mono dim" style={{ fontSize: 12 }}>{money(data.revenue_by_month.reduce((s, m) => s + m.cents, 0))}</span>
        </div>
        <div className="rev-chart">
          {data.revenue_by_month.map((m) => {
            const month = MONTHS[Number(m.month.slice(5)) - 1]
            return (
              <div key={m.month} className="rev-col" title={`${month} ${m.month.slice(0, 4)} · ${money(m.cents)}`}>
                <span className="rev-val mono">{m.cents ? compactMoney(m.cents) : ''}</span>
                <div className="rev-bar" style={{ height: ready ? `${Math.max(m.cents ? 3 : 1, (m.cents / max) * 100)}%` : '1%', background: m.cents ? 'var(--brand)' : 'var(--border)' }} />
                <span className="mono dim" style={{ fontSize: 10 }}>{month}</span>
              </div>
            )
          })}
        </div>
      </Card>

      <Masonry>
        {data.review_payments.length > 0 && (
          <Card delay={0.33} style={{ borderColor: 'rgba(140,211,255,.35)' }}>
            <div className="card-head"><span className="card-title">Payments to review</span><Link to={A('/payments?status=review')} className="btn-link">Review all</Link></div>
            <div>
              {data.review_payments.map((p) => (
                <button key={p.id} className="list-row list-btn" onClick={() => setReviewing(p)}>
                  <span className="timeline-dot" style={{ color: 'var(--sky)' }}><i className="fa-solid fa-receipt" /></span>
                  <div style={{ display: 'flex', flexDirection: 'column', gap: 2, flex: 1, minWidth: 0 }}>
                    <span className="ellipsis" style={{ fontWeight: 500 }}>{clientLabel(p.user)}</span>
                    <span className="mono dim" style={{ fontSize: 11 }}>{p.invoices?.map((i) => i.number).join(', ')} · {p.method_label} · {ago(p.submitted_at)}</span>
                  </div>
                  <span className="mono">{money(p.amount_cents)}</span>
                </button>
              ))}
            </div>
          </Card>
        )}
        <Card delay={0.35}>
          <div className="card-head"><span className="card-title">Awaiting your reply</span><Link to={A('/tickets')} className="btn-link">All tickets</Link></div>
          <div>
            {data.awaiting_tickets.length === 0 && <Empty icon="fa-solid fa-circle-check">Inbox zero.</Empty>}
            {data.awaiting_tickets.map((t) => (
              <button key={t.id} className="list-row list-btn" onClick={() => navigate(A(`/tickets/${t.id}`))}>
                <div style={{ display: 'flex', flexDirection: 'column', gap: 2, flex: 1, minWidth: 0 }}>
                  <span className="ellipsis" style={{ fontWeight: 500 }}>{t.subject}</span>
                  <span className="mono dim" style={{ fontSize: 11 }}>{t.reference} · {clientLabel(t.user)} · {ago(t.last_activity_at)}</span>
                </div>
                <Badge label={t.status_label} />
              </button>
            ))}
          </div>
        </Card>

        <Card delay={0.4}>
          <div className="card-head"><span className="card-title">Overdue invoices</span><Link to={A('/invoices?status=overdue')} className="btn-link">View all</Link></div>
          <div>
            {data.overdue_invoices.length === 0 && <Empty icon="fa-solid fa-circle-check">Everyone's paid up.</Empty>}
            {data.overdue_invoices.map((inv) => (
              <button key={inv.id} className="list-row list-btn" onClick={() => navigate(A(`/invoices/${inv.id}`))}>
                <div style={{ display: 'flex', flexDirection: 'column', gap: 2, flex: 1, minWidth: 0 }}>
                  <span className="ellipsis" style={{ fontWeight: 500 }}>{clientLabel(inv.user)}</span>
                  <span className="mono" style={{ fontSize: 11, color: 'var(--red)' }}>{inv.number} · due {fmtShort(inv.due_at)}</span>
                </div>
                <span className="mono">{money(inv.total_cents)}</span>
              </button>
            ))}
          </div>
        </Card>

        <Card delay={0.45}>
          <div className="card-head"><span className="card-title">Renewals · next 30 days</span><Link to={A('/services')} className="btn-link">Services</Link></div>
          <div>
            {data.upcoming_renewals.length === 0 && <Empty icon="fa-solid fa-calendar">No renewals coming up.</Empty>}
            {data.upcoming_renewals.map((s) => (
              <div key={s.id} className="list-row">
                <span className="icon-tile" style={{ width: 28, height: 28 }}><i className={s.icon} style={{ fontSize: 11 }} /></span>
                <div style={{ display: 'flex', flexDirection: 'column', gap: 2, flex: 1, minWidth: 0 }}>
                  <span className="ellipsis" style={{ fontWeight: 500 }}>{s.name}</span>
                  <span className="mono dim" style={{ fontSize: 11 }}>{clientLabel(s.user)} · {fmtShort(s.renews_at)}{s.auto_invoice ? ' · auto-invoice' : ''}</span>
                </div>
                <span className="mono" style={{ fontSize: 12 }}>{s.price_label}</span>
              </div>
            ))}
          </div>
        </Card>

        <Card delay={0.5}>
          <div className="card-head"><span className="card-title">Recent payments</span><Link to={A('/payments')} className="btn-link">All payments</Link></div>
          <div>
            {data.recent_payments.length === 0 && <Empty icon="fa-solid fa-money-bill-wave">No payments yet.</Empty>}
            {data.recent_payments.map((p) => (
              <div key={p.id} className="list-row">
                <span className="timeline-dot" style={{ color: 'var(--lime)' }}><i className="fa-solid fa-check" /></span>
                <div style={{ display: 'flex', flexDirection: 'column', gap: 2, flex: 1, minWidth: 0 }}>
                  <span className="ellipsis" style={{ fontWeight: 500 }}>{clientLabel(p.user)}</span>
                  <span className="mono dim" style={{ fontSize: 11 }}>{p.invoices?.map((i) => i.number).join(', ')} · {PAYMENT_METHOD_LABELS[p.method] ?? p.method} · {fmtShort(p.paid_at)}</span>
                </div>
                <span className="mono">{money(p.amount_cents)}</span>
              </div>
            ))}
          </div>
        </Card>
      </Masonry>
      {reviewing && <PaymentReview payment={reviewing} onClose={() => setReviewing(null)} />}
    </div>
  )
}
