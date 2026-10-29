import { Badge, Card, Empty, ErrorState, Loading, ProgressBar } from '../components/ui'
import { fmtShort } from '../lib/format'
import { useReady } from '../lib/hooks'
import type { Service } from '../lib/types'
import { useServices } from './queries'

/** The footer line: explicit meta text, else the renewal or start date. */
function metaLine(s: Service) {
  if (s.meta) return s.meta
  if (s.renews_at) return `Renews ${fmtShort(s.renews_at)}`
  if (s.started_at) return `Since ${fmtShort(s.started_at)}`
  return ''
}

export default function Services() {
  const { data, isLoading, error, refetch } = useServices()
  const ready = useReady()

  if (isLoading) return <Loading />
  if (error || !data) return <ErrorState error={error} retry={refetch} />
  if (data.length === 0) return <div className="card"><Empty icon="fa-solid fa-layer-group">No services yet. Once we start working together they'll show up here.</Empty></div>

  return (
    <div className="grid-cards">
      {data.map((s, i) => (
        <Card key={s.id} delay={i * 0.06} className="service-card" style={{ gap: 14 }}>
          <div className="spread">
            <span className="icon-tile"><i className={s.icon} style={{ fontSize: 14 }} /></span>
            <Badge label={s.status_label} />
          </div>
          <div style={{ display: 'flex', flexDirection: 'column', gap: 3 }}>
            <span className="mono dim" style={{ fontSize: 11 }}>{s.type_label}</span>
            <span style={{ fontSize: 16, fontWeight: 500 }}>{s.name}</span>
            {s.detail && <span className="muted" style={{ fontSize: 13 }}>{s.detail}</span>}
          </div>
          {s.progress !== null && <ProgressBar thin percent={ready ? s.progress : 0} />}
          <div className="spread" style={{ paddingTop: 12, borderTop: '1px solid var(--border-soft)', fontSize: 12, marginTop: 'auto' }}>
            <span className="dim">{metaLine(s)}</span>
            <span className="mono">{s.price_label}</span>
          </div>
        </Card>
      ))}
    </div>
  )
}
