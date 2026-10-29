import { useNavigate } from 'react-router'
import { Badge, Empty } from '../components/ui'
import { fmtShort, money } from '../lib/format'
import type { Invoice } from '../lib/types'
import { clientLabel } from './queries'
import { A } from '../lib/sites'

export default function InvoicesTable({ invoices, showClient = true }: { invoices: Invoice[]; showClient?: boolean }) {
  const navigate = useNavigate()
  if (invoices.length === 0) return <Empty icon="fa-solid fa-file-invoice">No invoices.</Empty>
  return (
    <div className="table-wrap">
      <table className="table" style={{ minWidth: 680 }}>
        <thead><tr><th>invoice</th>{showClient && <th>client</th>}<th>description</th><th>status</th><th>due</th><th className="r">total</th></tr></thead>
        <tbody>
          {invoices.map((inv) => (
            <tr key={inv.id} className="clickable" onClick={() => navigate(A(`/invoices/${inv.id}`))}>
              <td className="mono nowrap" style={{ fontSize: 12, color: 'var(--fg-2)' }}>{inv.number}</td>
              {showClient && <td style={{ fontWeight: 500 }}>{clientLabel(inv.user)}</td>}
              <td className="muted">{inv.description}</td>
              <td><Badge label={inv.display_status} /></td>
              <td className="sub nowrap" style={{ color: inv.overdue ? 'var(--red)' : undefined }}>{fmtShort(inv.due_at)}</td>
              <td className="r mono nowrap">{money(inv.total_cents)}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}
