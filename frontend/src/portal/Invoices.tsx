import { useQueryClient } from '@tanstack/react-query'
import { useEffect, useState } from 'react'
import { useSearchParams } from 'react-router'
import { Badge, Empty, ErrorState, Loading } from '../components/ui'
import { fmtShort, money } from '../lib/format'
import { useToast } from '../lib/toast'
import type { Invoice } from '../lib/types'
import PayModal, { waitForPayment } from './PayModal'
import { portalKeys, useInvoices } from './queries'

export default function Invoices() {
  const { data, isLoading, error, refetch } = useInvoices()
  const [pay, setPay] = useState<Invoice[] | null>(null)
  useCheckoutReturn()

  if (isLoading) return <Loading />
  if (error || !data) return <ErrorState error={error} retry={refetch} />

  const due = data.data.filter((i) => i.payable)
  const dueTotal = due.reduce((s, i) => s + i.total_cents, 0)

  return (
    <div className="stack" style={{ gap: 16 }}>
      <div className="spread fade-up" style={{ flexWrap: 'wrap', gap: 14 }}>
        <div className="row" style={{ gap: 28 }}>
          <div style={{ display: 'flex', flexDirection: 'column', gap: 4 }}><span className="eyebrow">outstanding</span><span className="mid-num">{money(data.totals.outstanding_cents)}</span></div>
          <div style={{ display: 'flex', flexDirection: 'column', gap: 4 }}><span className="eyebrow">paid in {data.totals.year}</span><span className="mid-num">{money(data.totals.paid_this_year_cents)}</span></div>
        </div>
        {due.length > 1 && (
          <button className="btn" onClick={() => setPay(due)}><i className="fa-solid fa-credit-card" style={{ fontSize: 12 }} />Pay all due · {money(dueTotal)}</button>
        )}
      </div>

      <div className="card card-flush fade-up" style={{ animationDelay: '.08s' }}>
        {data.data.length === 0 ? <Empty icon="fa-solid fa-file-invoice">No invoices yet.</Empty> : (
          <div className="table-wrap">
            <table className="table" style={{ minWidth: 620 }}>
              <thead><tr><th>invoice</th><th>description</th><th>status</th><th className="r">amount</th><th /></tr></thead>
              <tbody>
                {data.data.map((inv, i) => (
                  <tr key={inv.id} className="fade-up" style={{ animationDelay: `${i * 0.05}s`, animationDuration: '.4s' }}>
                    <td className="mono nowrap" style={{ fontSize: 12, color: 'var(--fg-2)', width: 96 }}>{inv.number}</td>
                    <td>
                      <div style={{ display: 'flex', flexDirection: 'column', gap: 2 }}>
                        <span style={{ fontWeight: 500 }}>{inv.description}</span>
                        <span className="sub">Issued {fmtShort(inv.issued_at)} · <DueLine invoice={inv} /></span>
                      </div>
                    </td>
                    <td style={{ width: 100 }}><Badge label={inv.display_status} /></td>
                    <td className="r mono nowrap" style={{ width: 100 }}>{money(inv.total_cents)}</td>
                    <td style={{ width: 150 }}>
                      <div className="row" style={{ justifyContent: 'flex-end', gap: 6 }}>
                        <a className="icon-btn" href={`/api/portal/invoices/${inv.id}/pdf`} title="Download PDF" aria-label={`Download ${inv.number} PDF`} download>
                          <i className="fa-solid fa-file-arrow-down" style={{ fontSize: 12 }} />
                        </a>
                        {inv.open && <PayAction invoice={inv} onPay={() => setPay([inv])} />}
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      {pay && <PayModal invoices={pay} onClose={() => setPay(null)} />}
    </div>
  )
}

/** "due Oct 15", "overdue · pay by Oct 18", "online payment closed Oct 18", "paid Oct 3". */
export function DueLine({ invoice: inv }: { invoice: Invoice }) {
  if (inv.status === 'paid') return <>paid {fmtShort(inv.paid_at)}</>
  if (inv.in_review) return <span style={{ color: 'var(--sky)' }}>receipt sent · I'll confirm within a business day</span>
  if (inv.rejection) return <span style={{ color: 'var(--red)' }}>receipt not accepted: {inv.rejection.reason}</span>
  if (inv.expired) return <span style={{ color: 'var(--red)' }}>online payment closed {fmtShort(inv.pay_until)}</span>
  if (inv.overdue) return <span style={{ color: 'var(--red)' }}>overdue · pay by {fmtShort(inv.pay_until)}</span>
  return <>due {fmtShort(inv.due_at)}</>
}

/** The action for an unpaid invoice: Pay, Pay offline (past its online window), or nothing while in review. */
export function PayAction({ invoice, onPay, ghost }: { invoice: Invoice; onPay: () => void; ghost?: boolean }) {
  if (invoice.payable) return <button className={ghost ? 'btn btn-ghost btn-sm pay' : 'btn btn-sm'} style={{ height: 32 }} onClick={onPay}>Pay</button>
  if (invoice.accepts_offline) return <button className="btn btn-ghost btn-sm" style={{ height: 32 }} title="Online card payment has closed. Pay by e-Transfer, cheque or bank transfer." onClick={onPay}>Pay offline</button>
  if (invoice.in_review) return <span className="mono dim" style={{ fontSize: 11 }}><i className="fa-solid fa-hourglass-half" style={{ marginRight: 6 }} />in review</span>
  return null
}

/** Handle returns from Stripe Checkout (?checkout=success&payment=ID) and 3-D Secure redirects (?payment=ID). */
function useCheckoutReturn() {
  const [params, setParams] = useSearchParams()
  const qc = useQueryClient()
  const toast = useToast()
  const paymentId = Number(params.get('payment')) || null
  const cancelled = params.get('checkout') === 'cancelled'

  useEffect(() => {
    if (cancelled) {
      toast.ok('Checkout cancelled. Nothing was charged.', 'fa-solid fa-circle-info')
      setParams({}, { replace: true })
      return
    }
    if (!paymentId) return
    setParams({}, { replace: true })
    toast.ok('Confirming your payment…', 'fa-solid fa-circle-notch')
    waitForPayment(paymentId, 10)
      .then((p) => {
        if (p.status === 'succeeded') toast.ok('Payment received. Thank you!')
        else if (p.status === 'failed') toast.error('The payment did not go through.')
        else toast.ok('Payment is processing. It will show as paid shortly.', 'fa-solid fa-clock')
      })
      .catch(() => toast.error('Could not confirm the payment yet. Refresh in a minute.'))
      .finally(() => qc.invalidateQueries({ queryKey: portalKeys.all }))
  }, [paymentId, cancelled])
}
