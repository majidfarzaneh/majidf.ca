import { useState } from 'react'
import { useSearchParams } from 'react-router'
import { Link } from 'react-router'
import { Badge, Empty, ErrorState, Loading } from '../components/ui'
import { fmtDate, money } from '../lib/format'
import { cap, PAYMENT_METHOD_LABELS } from '../lib/status'
import type { Payment } from '../lib/types'
import PaymentReview from './PaymentReview'
import { clientLabel, useAdminDashboard, useAdminPayments } from './queries'
import { A } from '../lib/sites'

const FILTERS: [string, string][] = [['review', 'To review'], ['succeeded', 'Received'], ['rejected', 'Rejected'], ['pending', 'Pending'], ['failed', 'Failed'], ['all', 'All']]

export default function Payments() {
  const [params, setParams] = useSearchParams()
  const { data: dash } = useAdminDashboard()
  const status = params.get('status') ?? (dash?.kpis.payments_to_review ? 'review' : 'succeeded')
  const setStatus = (s: string) => setParams({ status: s }, { replace: true })
  const { data, isLoading, error, refetch } = useAdminPayments({ status })
  const [reviewing, setReviewing] = useState<Payment | null>(null)
  const total = data?.filter((p) => p.status === 'succeeded').reduce((s, p) => s + p.amount_cents, 0) ?? 0

  return (
    <div className="stack" style={{ gap: 16 }}>
      <div className="toolbar fade-up">
        <div className="segmented">{FILTERS.map(([v, l]) => <button key={v} className={status === v ? 'on' : ''} onClick={() => setStatus(v)}>{l}{v === 'review' && !!dash?.kpis.payments_to_review && <span className="count-pill" style={{ marginLeft: 6 }}>{dash.kpis.payments_to_review}</span>}</button>)}</div>
        {status === 'succeeded' && <span className="mono muted" style={{ fontSize: 13 }}>{data?.length ?? 0} payments · {money(total)}</span>}
      </div>
      {isLoading ? <Loading /> : error || !data ? <ErrorState error={error} retry={refetch} /> : (
        <div className="card card-flush fade-up" style={{ animationDelay: '.06s' }}>
          {data.length === 0 ? <Empty icon="fa-solid fa-money-bill-wave">{status === 'review' ? 'Nothing to review. All caught up.' : 'No payments here.'}</Empty> : (
            <div className="table-wrap">
              <table className="table" style={{ minWidth: 720 }}>
                <thead><tr><th>date</th><th>client</th><th>invoices</th><th>method</th><th>status</th><th className="r">amount</th><th /></tr></thead>
                <tbody>
                  {data.map((p) => (
                    <tr key={p.id}>
                      <td className="sub nowrap">{fmtDate(p.paid_at ?? p.submitted_at ?? p.created_at)}</td>
                      <td>{p.user ? <Link to={A(`/clients/${p.user.id}`)}>{clientLabel(p.user)}</Link> : '—'}</td>
                      <td className="mono" style={{ fontSize: 12 }}>{p.invoices?.map((i, k) => <span key={i.id}>{k > 0 && ', '}<Link to={A(`/invoices/${i.id}`)} className="kbd-link">{i.number}</Link></span>)}</td>
                      <td className="muted">{PAYMENT_METHOD_LABELS[p.method] ?? p.method}{p.reference ? <span className="sub"> · {p.reference}</span> : null}</td>
                      <td><Badge label={cap(p.status)} /></td>
                      <td className="r mono">{money(p.amount_cents, true)}</td>
                      <td className="r" style={{ width: 96 }}>
                        {p.has_receipt && (
                          <button className={p.status === 'review' ? 'btn btn-sm' : 'btn btn-ghost btn-sm'} onClick={() => setReviewing(p)}>
                            {p.status === 'review' ? 'Review' : 'Receipt'}
                          </button>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>
      )}
      {reviewing && <PaymentReview payment={reviewing} onClose={() => setReviewing(null)} />}
    </div>
  )
}
