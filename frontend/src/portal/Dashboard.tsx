import { useState } from 'react'
import { Link } from 'react-router'
import { Card, Empty, ErrorState, Kpi, Loading, ProgressBar, UptimeBars } from '../components/ui'
import { ago, monthDay, money } from '../lib/format'
import { useEntrance, useReady } from '../lib/hooks'
import type { Invoice } from '../lib/types'
import { DueLine, PayAction } from './Invoices'
import PayModal from './PayModal'
import { useDashboard } from './queries'
import { P } from '../lib/sites'

const BAR_COLORS = ['var(--brand)', 'var(--lime)', 'var(--sky)']

export default function Dashboard() {
  const { data, isLoading, error, refetch } = useDashboard()
  const a = useEntrance(data ? 'ready' : 'loading')
  const ready = useReady()
  const [pay, setPay] = useState<Invoice[] | null>(null)

  if (isLoading) return <Loading />
  if (error || !data) return <ErrorState error={error} retry={refetch} />

  const k = data.kpis
  const renewal = monthDay(k.next_renewal?.date)
  const servicesSub = k.in_development ? `${k.in_development} in development` : 'All live'

  return (
    <div className="stack">
      <div className="grid-kpi">
        <Kpi delay={0.05} label="outstanding" value={money(Math.round(k.outstanding_cents * a / 100) * 100)}
          sub={k.due_count
            ? <span style={{ color: k.overdue_count ? 'var(--red)' : 'var(--amber)', display: 'flex', alignItems: 'center', gap: 6 }}><i className="fa-solid fa-circle-exclamation" style={{ fontSize: 10 }} />{k.due_count} invoice{k.due_count === 1 ? '' : 's'} due</span>
            : <span style={{ color: 'var(--lime)' }}>All paid up</span>} />
        <Kpi delay={0.12} label="active services" value={Math.round(k.active_services * a)} sub={servicesSub} />
        <Kpi delay={0.19} label="open tickets" value={Math.round(k.open_tickets * a)} sub={k.last_reply_at ? `Last reply ${ago(k.last_reply_at).toLowerCase()}` : 'No replies yet'} />
        <Kpi delay={0.26} label="next renewal"
          value={renewal ? <>{renewal.month} <span className="brand-text">{renewal.day}</span></> : '—'}
          sub={<span className="ellipsis">{k.next_renewal ? `${k.next_renewal.name} · ${k.next_renewal.price_label}` : 'Nothing scheduled'}</span>} />
      </div>

      <div className="grid-3">
        <Card delay={0.3}>
          <div className="card-head">
            <span className="card-title">Due invoices</span>
            <Link to={P('/invoices')} className="btn-link">View all</Link>
          </div>
          <div style={{ display: 'flex', flexDirection: 'column' }}>
            {data.due_invoices.length === 0 && <Empty icon="fa-solid fa-circle-check">Nothing due. Thank you!</Empty>}
            {data.due_invoices.map((inv) => (
              <div key={inv.id} className="list-row" style={{ borderTop: '1px solid var(--border-soft)' }}>
                <div style={{ display: 'flex', flexDirection: 'column', gap: 2, flex: 1, minWidth: 0 }}>
                  <span className="ellipsis" style={{ fontSize: 14, fontWeight: 500 }}>{inv.description}</span>
                  <span className="mono" style={{ fontSize: 11, color: 'var(--fg-3)' }}>{inv.number} · <DueLine invoice={inv} /></span>
                </div>
                <span className="mono" style={{ fontSize: 14 }}>{money(inv.total_cents)}</span>
                <PayAction invoice={inv} ghost onPay={() => setPay([inv])} />
              </div>
            ))}
          </div>
        </Card>

        <Card delay={0.37} style={{ gap: 18 }}>
          <span className="card-title">In progress</span>
          {data.progress.length === 0 && !data.uptime.days.length && <Empty icon="fa-solid fa-mug-hot">Nothing in flight right now.</Empty>}
          {data.progress.map((p, i) => (
            <div key={p.id} style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
              <div className="spread" style={{ fontSize: 13 }}>
                <span className="muted ellipsis">{p.label}</span>
                <span className="mono">{Math.round(p.percent * a)}%</span>
              </div>
              <ProgressBar percent={ready ? p.percent : 0} color={BAR_COLORS[i % BAR_COLORS.length]} delay={i * 0.15} />
            </div>
          ))}
          {data.uptime.days.length > 0 && (
            <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
              <div className="spread" style={{ fontSize: 13 }}>
                <span className="muted">Hosting uptime · 30 days</span>
                <span className="mono">{data.uptime.percent === null ? '—' : `${data.uptime.percent}%`}</span>
              </div>
              <UptimeBars uptime={data.uptime} ready={ready} />
            </div>
          )}
        </Card>

        <Card delay={0.44}>
          <span className="card-title">Recent activity</span>
          <div style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
            {data.activity.length === 0 && <Empty icon="fa-solid fa-wave-square">No activity yet.</Empty>}
            {data.activity.map((item) => (
              <div key={item.id} style={{ display: 'flex', gap: 12, alignItems: 'flex-start' }}>
                <span className="timeline-dot" style={{ color: item.color }}><i className={item.icon} /></span>
                <div style={{ display: 'flex', flexDirection: 'column', gap: 2, minWidth: 0 }}>
                  <span style={{ fontSize: 13 }}>{item.text}</span>
                  <span className="mono dim" style={{ fontSize: 11 }}>{ago(item.created_at)}</span>
                </div>
              </div>
            ))}
          </div>
        </Card>
      </div>

      {pay && <PayModal invoices={pay} onClose={() => setPay(null)} />}
    </div>
  )
}
