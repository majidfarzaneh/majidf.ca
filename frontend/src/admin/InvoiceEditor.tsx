import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useEffect, useState, type FormEvent } from 'react'
import { Link, useNavigate, useParams, useSearchParams } from 'react-router'
import { Card, ErrorState, Field, Loading } from '../components/ui'
import { api, ApiError, errorMessage } from '../lib/api'
import { addDays, fmtDate, money, todayIso } from '../lib/format'
import type { Invoice, Service } from '../lib/types'
import { adminKeys, clientLabel, useAdminInvoice, useAdminServices, useClients, useMeta } from './queries'
import { A } from '../lib/sites'

interface Line { key: number; description: string; quantity: string; unit_price: string; service_id: number | null }

let nextKey = 1
const blankLine = (): Line => ({ key: nextKey++, description: '', quantity: '1', unit_price: '', service_id: null })
const cents = (v: string) => Math.round((parseFloat(v.replace(/[,$]/g, '')) || 0) * 100)

export default function InvoiceEditor() {
  const id = Number(useParams().id) || 0
  const { data: invoice, isLoading, error } = useAdminInvoice(id)
  if (id && isLoading) return <Loading />
  if (id && (error || !invoice)) return <ErrorState error={error} />
  if (invoice && !['draft', 'unpaid'].includes(invoice.status)) return <ErrorState error={new Error('Paid and voided invoices cannot be edited.')} />
  return <Editor invoice={invoice} />
}

function Editor({ invoice }: { invoice?: Invoice }) {
  const [params] = useSearchParams()
  const navigate = useNavigate()
  const qc = useQueryClient()
  const { data: meta } = useMeta()
  const { data: clients } = useClients()

  const [f, setF] = useState({
    user_id: invoice?.user_id ?? (Number(params.get('client')) || 0),
    description: invoice?.description ?? '',
    issued_at: invoice?.issued_at ?? todayIso(),
    due_at: invoice?.due_at ?? '',
    grace_days: invoice ? String(invoice.grace_days) : '',
    tax_rate: invoice ? String(invoice.tax_rate) : '',
    tax_label: invoice?.tax_label ?? '',
    notes: invoice?.notes ?? '',
  })
  const [lines, setLines] = useState<Line[]>(() =>
    invoice?.items?.length
      ? invoice.items.map((i) => ({ key: nextKey++, description: i.description, quantity: String(i.quantity), unit_price: (i.unit_price_cents / 100).toFixed(2), service_id: i.service_id }))
      : [blankLine()],
  )
  const [sendEmail, setSendEmail] = useState(true)

  // Fill defaults from settings once they load.
  useEffect(() => {
    if (!meta || invoice) return
    setF((cur) => ({
      ...cur,
      tax_rate: cur.tax_rate || String(meta.defaults.tax_rate),
      tax_label: cur.tax_label || meta.defaults.tax_label,
      due_at: cur.due_at || todayIso(meta.defaults.payment_terms_days),
      grace_days: cur.grace_days || String(meta.defaults.grace_days),
    }))
  }, [meta, invoice])

  const { data: services } = useAdminServices({ user_id: f.user_id || undefined })
  const clientServices = f.user_id ? (services ?? []).filter((s) => s.price_cents) : []

  const payUntil = f.due_at ? addDays(f.due_at, Number(f.grace_days) || 0) : null
  const subtotal = lines.reduce((s, l) => s + Math.round(cents(l.unit_price) * (parseFloat(l.quantity) || 0)), 0)
  const tax = Math.round((subtotal * (parseFloat(f.tax_rate) || 0)) / 100)

  const save = useMutation({
    mutationFn: (send: boolean) => {
      const body = {
        ...f,
        user_id: f.user_id || undefined,
        due_at: f.due_at || null,
        issued_at: f.issued_at || null,
        tax_rate: parseFloat(f.tax_rate) || 0,
        grace_days: f.grace_days === '' ? null : Number(f.grace_days),
        items: lines.filter((l) => l.description.trim()).map(({ description, quantity, unit_price, service_id }) => ({ description, quantity, unit_price: unit_price.replace(/[,$]/g, '') || 0, service_id })),
        send,
        email: sendEmail,
      }
      return invoice ? api.put<{ data: Invoice }>(`/admin/invoices/${invoice.id}`, body) : api.post<{ data: Invoice }>('/admin/invoices', body)
    },
    onSuccess: (res) => {
      qc.invalidateQueries({ queryKey: adminKeys.all })
      navigate(A(`/invoices/${res.data.id}`), { replace: true })
    },
  })

  const err = (k: string) => (save.error instanceof ApiError ? save.error.field(k) : undefined)
  const set = (k: keyof typeof f) => (e: { target: { value: string } }) => setF({ ...f, [k]: e.target.value })
  const setLine = (key: number, patch: Partial<Line>) => setLines(lines.map((l) => (l.key === key ? { ...l, ...patch } : l)))
  const addService = (s: Service) => {
    const line: Line = { key: nextKey++, description: `${s.type_label} · ${s.name}`, quantity: '1', unit_price: ((s.price_cents ?? 0) / 100).toFixed(2), service_id: s.id }
    setLines([...lines.filter((l) => l.description || l.unit_price), line])
    if (!f.description) setF({ ...f, description: `${s.name} · ${new Date().toLocaleString('en-CA', { month: 'long' })}` })
  }
  const submit = (e: FormEvent) => { e.preventDefault(); save.mutate(false) }
  const isDraft = !invoice || invoice.status === 'draft'

  return (
    <form onSubmit={submit} className="stack" style={{ gap: 16, maxWidth: 980 }}>
      <Link to={invoice ? A(`/invoices/${invoice.id}`) : A('/invoices')} className="btn-text fade-up"><i className="fa-solid fa-arrow-left" style={{ fontSize: 10 }} />{invoice ? invoice.number : 'Invoices'}</Link>

      <Card style={{ gap: 18 }}>
        <div className="spread">
          <span className="card-title">{invoice ? `Edit ${invoice.number}` : `New invoice · ${meta?.defaults.next_invoice_number ?? ''}`}</span>
          {invoice && invoice.status === 'unpaid' && <span className="dim" style={{ fontSize: 12 }}>Already sent. The client sees changes immediately.</span>}
        </div>
        <div className="form-grid">
          <Field label="client" error={err('user_id')}>
            <select className="input" required value={f.user_id || ''} disabled={!!invoice} onChange={(e) => setF({ ...f, user_id: Number(e.target.value) })}>
              <option value="" disabled>Choose a client</option>
              {clients?.map((c) => <option key={c.id} value={c.id}>{clientLabel(c)}{c.company ? ` (${c.name})` : ''}</option>)}
            </select>
          </Field>
          <Field label="description" error={err('description')} hint="Short title the client sees, e.g. Care plan · October"><input className="input" required maxLength={160} value={f.description} onChange={set('description')} /></Field>
        </div>
        <div className="form-grid">
          <Field label="issued" error={err('issued_at')}><input className="input" type="date" value={f.issued_at} onChange={set('issued_at')} /></Field>
          <Field label="due" error={err('due_at')}><input className="input" type="date" value={f.due_at} onChange={set('due_at')} /></Field>
          <Field label="payable after due · days" error={err('grace_days')} hint={payUntil ? `Payable online until ${fmtDate(payUntil)}` : 'Grace period for paying online'}>
            <input className="input" type="number" min={0} max={365} value={f.grace_days} onChange={set('grace_days')} />
          </Field>
          <Field label="tax label" error={err('tax_label')}><input className="input" value={f.tax_label} onChange={set('tax_label')} placeholder="HST" /></Field>
          <Field label="tax rate %" error={err('tax_rate')}><input className="input" inputMode="decimal" required value={f.tax_rate} onChange={set('tax_rate')} /></Field>
        </div>
      </Card>

      <Card delay={0.05} style={{ gap: 14 }}>
        <div className="spread" style={{ flexWrap: 'wrap' }}>
          <span className="card-title">Line items</span>
          {clientServices.length > 0 && (
            <select className="input input-sm" style={{ width: 'auto' }} value="" onChange={(e) => { const s = clientServices.find((x) => x.id === Number(e.target.value)); if (s) addService(s) }}>
              <option value="">+ Add from a service…</option>
              {clientServices.map((s) => <option key={s.id} value={s.id}>{s.name} · {s.price_label}</option>)}
            </select>
          )}
        </div>
        <div className="line-items">
          <div className="line-item eyebrow" style={{ fontSize: 10 }}><span>description</span><span>qty</span><span>rate</span><span className="amount" style={{ textAlign: 'right' }}>amount</span><span /></div>
          {lines.map((l) => (
            <div key={l.key} className="line-item">
              <input className="input input-sm" placeholder="What you did" value={l.description} onChange={(e) => setLine(l.key, { description: e.target.value })} />
              <input className="input input-sm mono" inputMode="decimal" value={l.quantity} onChange={(e) => setLine(l.key, { quantity: e.target.value })} />
              <input className="input input-sm mono" inputMode="decimal" placeholder="0.00" value={l.unit_price} onChange={(e) => setLine(l.key, { unit_price: e.target.value })} />
              <span className="amount mono" style={{ textAlign: 'right', fontSize: 13 }}>{money(Math.round(cents(l.unit_price) * (parseFloat(l.quantity) || 0)), true)}</span>
              <button type="button" className="icon-btn bare" aria-label="Remove line" disabled={lines.length === 1} onClick={() => setLines(lines.filter((x) => x.key !== l.key))}><i className="fa-solid fa-xmark" style={{ fontSize: 11 }} /></button>
            </div>
          ))}
          {err('items') && <div className="field-error">{err('items')}</div>}
          <button type="button" className="new-ticket" style={{ padding: 9 }} onClick={() => setLines([...lines, blankLine()])}><i className="fa-solid fa-plus" style={{ fontSize: 10 }} />Add line</button>
        </div>
        <div style={{ display: 'flex', justifyContent: 'flex-end' }}>
          <dl className="kv" style={{ gridTemplateColumns: '120px 120px', textAlign: 'right' }}>
            <dt>Subtotal</dt><dd className="mono">{money(subtotal, true)}</dd>
            <dt>{f.tax_label || 'Tax'} {f.tax_rate || 0}%</dt><dd className="mono">{money(tax, true)}</dd>
            <dt style={{ color: 'var(--fg)', fontWeight: 500 }}>Total CAD</dt><dd className="mono" style={{ fontWeight: 700, fontSize: 16 }}>{money(subtotal + tax, true)}</dd>
          </dl>
        </div>
      </Card>

      <Card delay={0.1}>
        <Field label="notes" hint="Printed on the PDF, e.g. payment instructions or thanks."><textarea className="input" rows={3} value={f.notes} onChange={set('notes')} /></Field>
      </Card>

      {save.error && <div className="field-error"><i className="fa-solid fa-circle-exclamation" />{errorMessage(save.error)}</div>}
      <div className="row-wrap fade-up" style={{ justifyContent: 'flex-end', animationDelay: '.15s' }}>
        {isDraft && <label className="checkbox" style={{ marginRight: 8 }}><input type="checkbox" checked={sendEmail} onChange={(e) => setSendEmail(e.target.checked)} />Email it to the client</label>}
        <button type="submit" className="btn btn-ghost" disabled={save.isPending}>{invoice && !isDraft ? 'Save changes' : 'Save draft'}</button>
        {isDraft && (
          <button type="button" className="btn" disabled={save.isPending} onClick={() => save.mutate(true)}>
            {save.isPending ? <i className="fa-solid fa-circle-notch spin" /> : <i className="fa-solid fa-paper-plane" style={{ fontSize: 11 }} />}{sendEmail ? 'Save & send' : 'Save & issue'}
          </button>
        )}
      </div>
    </form>
  )
}
