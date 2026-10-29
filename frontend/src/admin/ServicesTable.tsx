import { Badge, Empty } from '../components/ui'
import { fmtShort } from '../lib/format'
import type { Service } from '../lib/types'
import { clientLabel } from './queries'

export default function ServicesTable({ services, onEdit, showClient = true }: { services: Service[]; onEdit: (s: Service) => void; showClient?: boolean }) {
  if (services.length === 0) return <Empty icon="fa-solid fa-layer-group">No services.</Empty>
  return (
    <div className="table-wrap">
      <table className="table" style={{ minWidth: 680 }}>
        <thead><tr><th>service</th>{showClient && <th>client</th>}<th>status</th><th>renews</th><th className="r">price</th></tr></thead>
        <tbody>
          {services.map((s) => (
            <tr key={s.id} className="clickable" onClick={() => onEdit(s)}>
              <td>
                <div className="row" style={{ gap: 10 }}>
                  <span className="icon-tile" style={{ width: 28, height: 28 }}><i className={s.icon} style={{ fontSize: 11 }} /></span>
                  <div style={{ display: 'flex', flexDirection: 'column', minWidth: 0 }}>
                    <span style={{ fontWeight: 500 }}>{s.name}</span>
                    <span className="sub">{s.type_label}{s.progress != null ? ` · ${Math.round(s.progress)}%` : ''}{s.monitored ? ' · monitored' : ''}</span>
                  </div>
                </div>
              </td>
              {showClient && <td className="muted">{clientLabel(s.user)}</td>}
              <td><Badge label={s.status_label} /></td>
              <td className="sub nowrap">{s.renews_at ? fmtShort(s.renews_at) : '—'}{s.auto_invoice && <i className="fa-solid fa-rotate" title="Auto-invoice" style={{ marginLeft: 6, color: 'var(--link)' }} />}</td>
              <td className="r mono nowrap">{s.price_label}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}
