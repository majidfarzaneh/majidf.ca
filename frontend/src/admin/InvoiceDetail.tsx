import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useState, type FormEvent } from 'react'
import { Link, useNavigate, useParams } from 'react-router'
import { Badge, Card, ErrorState, Field, Loading, Modal } from '../components/ui'
import { api, errorMessage } from '../lib/api'
import { ago, fmtDate, fmtShort, money, todayIso } from '../lib/format'
import { cap, PAYMENT_METHOD_LABELS } from '../lib/status'
import { useToast } from '../lib/toast'
import type { Invoice, Payment } from '../lib/types'
import PaymentReview from './PaymentReview'
import { adminKeys, clientLabel, useAdminInvoice, useMeta } from './queries'
import { A } from '../lib/sites'

const REMINDERS = [['due_soon', 'Due soon'], ['due_today', 'Due today'], ['overdue', 'Overdue'], ['closed', 'Closed']] as const

export default function InvoiceDetail() {
  const id = Number(useParams().id)
  const { data: inv, isLoading, error, refetch } = useAdminInvoice(id)
  const qc = useQueryClient()
  const toast = useToast()
  const navigate = useNavigate()
  const [recording, setRecording] = useState(false)
  const [reviewing, setReviewing] = useState<Payment | null>(null)

  const action = useMutation({
    mutationFn: ({ path, method = 'post' }: { path: string; method?: 'post' | 'delete'; done: string }) =>
      method === 'delete' ? api.delete(`/admin/invoices/${id}${path}`) : api.post<{ data: Invoice }>(`/admin/invoices/${id}${path}`),
    onSuccess: (_res, vars) => {
      qc.invalidateQueries({ queryKey: adminKeys.all })
      toast.ok(vars.done)
      if (vars.method === 'delete') navigate(A('/invoices'), { replace: true })
    },
    onError: (e) => toast.error(errorMessage(e)),
  })

  if (isLoading) return <Loading />
  if (error || !inv) return <ErrorState error={error} retry={refetch} />

  const editable = inv.status === 'draft' || inv.status === 'unpaid'

  return (
    <div className="stack" style={{ gap: 16 }}>
      <Link to={A('/invoices')} className="btn-text fade-up"><i className="fa-solid fa-arrow-left" style={{ fontSize: 10 }} />Invoices</Link>
      <div className="detail-grid">
        <Card style={{ gap: 20 }}>
          <div className="spread" style={{ alignItems: 'flex-start', flexWrap: 'wrap' }}>
            <div style={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
              <div className="row"><span className="mono dim" style={{ fontSize: 12 }}>{inv.number}</span><Badge label={inv.display_status} /></div>
              <span className="display" style={{ fontSize: 22, fontWeight: 700 }}>{inv.description}</span>
              <Link to={A(`/clients/${inv.user_id}`)} style={{ fontSize: 13 }}>{clientLabel(inv.user)}</Link>
            </div>
            <div style={{ textAlign: 'right' }}>
              <div className="mono" style={{ fontWeight: 700, fontSize: 28 }}>{money(inv.total_cents, true)}</div>
              <div className="dim" style={{ fontSize: 12 }}>{inv.currency}</div>
            </div>
          </div>
          <div className="table-wrap" style={{ margin: '0 -20px' }}>
            <table className="table">
              <thead><tr><th>description</th><th className="r">qty</th><th className="r">rate</th><th className="r">amount</th></tr></thead>
              <tbody>
                {inv.items?.map((it) => (
                  <tr key={it.id}>
                    <td>{it.description}</td>
                    <td className="r mono">{it.quantity}</td>
                    <td className="r mono">{money(it.unit_price_cents, true)}</td>
                    <td className="r mono">{money(it.amount_cents, true)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <div style={{ display: 'flex', justifyContent: 'flex-end' }}>
            <dl className="kv" style={{ gridTemplateColumns: '120px 120px', textAlign: 'right' }}>
              <dt>Subtotal</dt><dd className="mono">{money(inv.subtotal_cents, true)}</dd>
              {inv.tax_rate > 0 && <><dt>{inv.tax_label || 'Tax'} {inv.tax_rate}%</dt><dd className="mono">{money(inv.tax_cents, true)}</dd></>}
              <dt style={{ color: 'var(--fg)', fontWeight: 500 }}>Total</dt><dd className="mono" style={{ fontWeight: 700 }}>{money(inv.total_cents, true)}</dd>
            </dl>
          </div>
          {inv.notes && <div className="notice" style={{ whiteSpace: 'pre-wrap' }}>{inv.notes}</div>}
        </Card>

        <div className="stack" style={{ gap: 14 }}>
          <Card delay={0.06} style={{ gap: 10 }}>
            <span className="card-title">Actions</span>
            {inv.status === 'draft' && (
              <button className="btn btn-block" disabled={action.isPending} onClick={() => action.mutate({ path: '/send', done: `${inv.number} sent to ${inv.user?.email}` })}>
                <i className="fa-solid fa-paper-plane" style={{ fontSize: 11 }} />Send to client
              </button>
            )}
            {inv.open && (
              <>
                <button className="btn btn-block" onClick={() => setRecording(true)}><i className="fa-solid fa-circle-check" style={{ fontSize: 11 }} />Record payment</button>
                <button className="btn btn-ghost btn-block" disabled={action.isPending} onClick={() => action.mutate({ path: '/remind', done: 'Reminder sent' })}>
                  <i className="fa-solid fa-bell" style={{ fontSize: 11 }} />Send reminder
                </button>
                {!inv.expired && (
                  <button className="btn btn-ghost btn-block" disabled={action.isPending} onClick={() => action.mutate({ path: '/send', done: 'Invoice re-sent' })}>
                    <i className="fa-solid fa-rotate-right" style={{ fontSize: 11 }} />Re-send invoice
                  </button>
                )}
              </>
            )}
            {editable && <Link to={A(`/invoices/${inv.id}/edit`)} className="btn btn-ghost btn-block"><i className="fa-solid fa-pen" style={{ fontSize: 11 }} />Edit</Link>}
            <a href={`/api/admin/invoices/${inv.id}/pdf`} target="_blank" rel="noreferrer" className="btn btn-ghost btn-block"><i className="fa-solid fa-file-pdf" style={{ fontSize: 11 }} />View PDF</a>
            {inv.status === 'draft' && (
              <button className="btn btn-danger btn-block" disabled={action.isPending} onClick={() => { if (confirm('Delete this draft?')) action.mutate({ path: '', method: 'delete', done: 'Draft deleted' }) }}>Delete draft</button>
            )}
            {inv.status === 'unpaid' && (
              <button className="btn btn-danger btn-block" disabled={action.isPending} onClick={() => { if (confirm(`Void ${inv.number}? The client will no longer be able to pay it.`)) action.mutate({ path: '/void', done: 'Invoice voided' }) }}>Void invoice</button>
            )}
          </Card>
          <Card delay={0.1} style={{ gap: 12 }}>
            <span className="card-title">Timeline</span>
            <dl className="kv">
              <dt>Created</dt><dd>{fmtDate(inv.created_at)}</dd>
              <dt>Issued</dt><dd>{fmtDate(inv.issued_at)}</dd>
              <dt>Due</dt><dd style={{ color: inv.overdue ? 'var(--red)' : undefined }}>{fmtDate(inv.due_at)}</dd>
              <dt>Payable until</dt><dd style={{ color: inv.expired ? 'var(--red)' : undefined }}>{inv.pay_until ? `${fmtDate(inv.pay_until)} (+${inv.grace_days} d)` : '—'}</dd>
              <dt>Emailed</dt><dd>{inv.sent_at ? ago(inv.sent_at) : '—'}</dd>
              <dt>Reminders</dt><dd>{REMINDERS.filter(([k]) => inv.reminders?.[k]).map(([k, l]) => `${l} ${fmtShort(inv.reminders![k])}`).join(' · ') || '—'}</dd>
              <dt>Manual nudge</dt><dd>{inv.reminded_at ? ago(inv.reminded_at) : '—'}</dd>
              <dt>Paid</dt><dd>{inv.paid_at ? fmtDate(inv.paid_at) : '—'}</dd>
            </dl>
          </Card>
          {!!inv.payments?.length && (
            <Card delay={0.14} style={{ gap: 6 }}>
              <span className="card-title">Payments</span>
              {inv.payments.map((p) => (
                <div key={p.id} className="list-row">
                  <div style={{ display: 'flex', flexDirection: 'column', gap: 2, flex: 1 }}>
                    <span style={{ fontSize: 13 }}>{PAYMENT_METHOD_LABELS[p.method] ?? p.method}{p.reference ? ` · ${p.reference}` : ''}</span>
                    <span className="mono dim" style={{ fontSize: 11 }}>{fmtDate(p.paid_at ?? p.created_at)}</span>
                  </div>
                  <Badge label={cap(p.status)} />
                  {p.has_receipt && <button className={p.status === 'review' ? 'btn btn-sm' : 'btn btn-ghost btn-sm'} onClick={() => setReviewing(p)}>{p.status === 'review' ? 'Review' : 'Receipt'}</button>}
                </div>
              ))}
            </Card>
          )}
        </div>
      </div>
      {recording && <RecordPayment invoice={inv} onClose={() => setRecording(false)} />}
      {reviewing && <PaymentReview payment={{ ...reviewing, user: reviewing.user ?? inv.user, invoices: reviewing.invoices ?? [{ id: inv.id, number: inv.number, description: inv.description, amount_cents: inv.total_cents }] }} onClose={() => setReviewing(null)} />}
    </div>
  )
}

function RecordPayment({ invoice, onClose }: { invoice: Invoice; onClose: () => void }) {
  const qc = useQueryClient()
  const toast = useToast()
  const { data: meta } = useMeta()
  const [f, setF] = useState({ method: 'etransfer', reference: '', paid_on: todayIso(), note: '', send_receipt: true })
  const save = useMutation({
    mutationFn: () => api.post(`/admin/invoices/${invoice.id}/payments`, f),
    onSuccess: () => { qc.invalidateQueries({ queryKey: adminKeys.all }); toast.ok(`${invoice.number} marked paid`); onClose() },
  })
  const submit = (e: FormEvent) => { e.preventDefault(); save.mutate() }

  return (
    <Modal title={`Record payment · ${money(invoice.total_cents, true)}`} eyebrow={`${invoice.number} · ${clientLabel(invoice.user)}`} onClose={onClose} busy={save.isPending}>
      <form onSubmit={submit}>
        <div className="modal-body">
          <div className="form-grid">
            <Field label="method">
              <select className="input" value={f.method} onChange={(e) => setF({ ...f, method: e.target.value })}>
                {meta?.payment_methods.map((m) => <option key={m} value={m}>{PAYMENT_METHOD_LABELS[m] ?? m}</option>)}
              </select>
            </Field>
            <Field label="paid on"><input className="input" type="date" max={todayIso()} value={f.paid_on} onChange={(e) => setF({ ...f, paid_on: e.target.value })} /></Field>
          </div>
          <Field label="reference" hint="e-Transfer ref, cheque #…"><input className="input" value={f.reference} onChange={(e) => setF({ ...f, reference: e.target.value })} /></Field>
          <Field label="note"><input className="input" value={f.note} onChange={(e) => setF({ ...f, note: e.target.value })} /></Field>
          <label className="checkbox"><input type="checkbox" checked={f.send_receipt} onChange={(e) => setF({ ...f, send_receipt: e.target.checked })} />Email a receipt to the client</label>
          {save.error && <div className="field-error">{errorMessage(save.error)}</div>}
        </div>
        <div className="modal-foot">
          <button type="button" className="btn btn-ghost" onClick={onClose}>Cancel</button>
          <button type="submit" className="btn" disabled={save.isPending}>{save.isPending && <i className="fa-solid fa-circle-notch spin" />}Mark as paid</button>
        </div>
      </form>
    </Modal>
  )
}
