import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useState, type FormEvent } from 'react'
import { useNavigate } from 'react-router'
import { Field, Modal } from '../components/ui'
import { api, ApiError, errorMessage } from '../lib/api'
import { cardLabel, fmtDate } from '../lib/format'
import { A } from '../lib/sites'
import { useToast } from '../lib/toast'
import type { Service } from '../lib/types'
import { adminKeys, clientLabel, useClients, useMeta } from './queries'

const RECURRING = ['monthly', 'quarterly', 'yearly']

export default function ServiceForm({ service, userId, onClose }: { service?: Service; userId?: number; onClose: () => void }) {
  const qc = useQueryClient()
  const toast = useToast()
  const { data: meta } = useMeta()
  const { data: clients } = useClients()
  const [f, setF] = useState({
    user_id: service?.user_id ?? userId ?? 0,
    name: service?.name ?? '',
    type: service?.type ?? 'website',
    status: service?.status ?? 'active',
    detail: service?.detail ?? '',
    meta: service?.meta ?? '',
    price: service?.price_cents != null ? String(service.price_cents / 100) : '',
    billing_cycle: service?.billing_cycle ?? '',
    auto_invoice: service?.auto_invoice ?? false,
    progress: service?.raw_progress != null ? String(service.raw_progress) : '',
    progress_label: service?.progress_label ?? '',
    hours_total: service?.hours_total != null ? String(service.hours_total) : '',
    hours_used: service?.hours_used != null ? String(service.hours_used) : '',
    started_at: service?.started_at ?? '',
    renews_at: service?.renews_at ?? '',
    monitor_url: service?.monitor_url ?? '',
    sort: service?.sort ?? 0,
  })
  const [notify, setNotify] = useState(true)

  const payload = () => {
    const nullable = (v: string) => (v === '' ? null : v)
    return {
      ...f,
      user_id: f.user_id || undefined,
      price: nullable(f.price), billing_cycle: nullable(f.billing_cycle), progress: nullable(f.progress),
      hours_total: nullable(f.hours_total), hours_used: nullable(f.hours_used), started_at: nullable(f.started_at),
      renews_at: nullable(f.renews_at), monitor_url: nullable(f.monitor_url),
    }
  }

  const save = useMutation({
    mutationFn: () => (service ? api.put(`/admin/services/${service.id}`, payload()) : api.post('/admin/services', { ...payload(), notify })),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: adminKeys.all })
      toast.ok(service ? 'Service updated' : 'Service added')
      onClose()
    },
  })
  const remove = useMutation({
    mutationFn: () => api.delete(`/admin/services/${service!.id}`),
    onSuccess: () => { qc.invalidateQueries({ queryKey: adminKeys.all }); toast.ok('Service deleted'); onClose() },
  })

  const err = (k: string) => (save.error instanceof ApiError ? save.error.field(k) : undefined)
  const set = (k: keyof typeof f) => (e: { target: { value: string } }) => setF({ ...f, [k]: e.target.value })
  const submit = (e: FormEvent) => { e.preventDefault(); save.mutate() }
  const recurring = RECURRING.includes(f.billing_cycle)

  return (
    <Modal wide title={service ? 'Edit service' : 'Add service'} eyebrow={service ? clientLabel(service.user) : 'services'} onClose={onClose} busy={save.isPending}>
      <form onSubmit={submit}>
        <div className="modal-body" style={{ maxHeight: '70vh', overflowY: 'auto' }}>
          {!service && (
            <Field label="client" error={err('user_id')}>
              <select className="input" required value={f.user_id || ''} onChange={(e) => setF({ ...f, user_id: Number(e.target.value) })}>
                <option value="" disabled>Choose a client</option>
                {clients?.map((c) => <option key={c.id} value={c.id}>{clientLabel(c)}{c.company ? ` (${c.name})` : ''}</option>)}
              </select>
            </Field>
          )}
          <div className="form-grid">
            <Field label="name" error={err('name')}><input className="input" required value={f.name} onChange={set('name')} placeholder="oliveandfig.ca" /></Field>
            <Field label="type" error={err('type')}>
              <select className="input" value={f.type} onChange={set('type')}>{meta?.service_types.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}</select>
            </Field>
            <Field label="status" error={err('status')}>
              <select className="input" value={f.status} onChange={set('status')}>{meta?.service_statuses.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}</select>
            </Field>
          </div>
          <div className="form-grid">
            <Field label="detail" error={err('detail')} hint="e.g. Online ordering · Laravel + React"><input className="input" value={f.detail} onChange={set('detail')} /></Field>
            <Field label="footer note" error={err('meta')} hint="Leave empty to show the renewal date."><input className="input" value={f.meta} onChange={set('meta')} /></Field>
          </div>
          <hr className="divider" />
          <div className="form-grid">
            <Field label="price · CAD" error={err('price')}><input className="input" inputMode="decimal" value={f.price} onChange={set('price')} placeholder="29" /></Field>
            <Field label="billing" error={err('billing_cycle')}>
              <select className="input" value={f.billing_cycle} onChange={set('billing_cycle')}>
                <option value="">—</option>
                {meta?.billing_cycles.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
              </select>
            </Field>
            <Field label="renews on" error={err('renews_at')}><input className="input" type="date" value={f.renews_at} onChange={set('renews_at')} /></Field>
          </div>
          <label className="checkbox" style={{ opacity: recurring ? 1 : 0.5 }}>
            <input type="checkbox" disabled={!recurring} checked={f.auto_invoice && recurring} onChange={(e) => setF({ ...f, auto_invoice: e.target.checked })} />
            Auto-invoice on each renewal date (issues and emails the invoice, then moves the date forward)
          </label>
          {err('auto_invoice') && <div className="field-error">{err('auto_invoice')}</div>}
          {service?.renewal && <RenewalInvoice service={service} onDone={onClose} />}
          <hr className="divider" />
          <div className="form-grid">
            <Field label="progress %" error={err('progress')} hint="For builds in progress."><input className="input" inputMode="decimal" value={f.progress} onChange={set('progress')} placeholder="68" /></Field>
            <Field label="progress label" error={err('progress_label')}><input className="input" value={f.progress_label} onChange={set('progress_label')} placeholder="milestone 3 of 4" /></Field>
            <Field label="hours used" error={err('hours_used')}><input className="input" inputMode="decimal" value={f.hours_used} onChange={set('hours_used')} /></Field>
            <Field label="hours total" error={err('hours_total')} hint="For hour blocks."><input className="input" inputMode="decimal" value={f.hours_total} onChange={set('hours_total')} /></Field>
          </div>
          <div className="form-grid">
            <Field label="started on" error={err('started_at')}><input className="input" type="date" value={f.started_at} onChange={set('started_at')} /></Field>
            <Field label="uptime monitor url" error={err('monitor_url')} hint="Pinged every 5 minutes."><input className="input" type="url" value={f.monitor_url} onChange={set('monitor_url')} placeholder="https://" /></Field>
          </div>
          {!service && (
            <label className="checkbox">
              <input type="checkbox" checked={notify} onChange={(e) => setNotify(e.target.checked)} />
              Email the client about this service (skipped if they turned off "New services" emails)
            </label>
          )}
        </div>
        <div className="modal-foot" style={{ justifyContent: 'space-between' }}>
          <div>{service && <button type="button" className="btn btn-danger" disabled={remove.isPending} onClick={() => { if (confirm(`Delete ${service.name}? This can't be undone.`)) remove.mutate() }}>Delete</button>}</div>
          <div className="row">
            <button type="button" className="btn btn-ghost" onClick={onClose}>Cancel</button>
            <button type="submit" className="btn" disabled={save.isPending}>{save.isPending && <i className="fa-solid fa-circle-notch spin" />}{service ? 'Save' : 'Add service'}</button>
          </div>
        </div>
      </form>
    </Modal>
  )
}

/** Issue the next renewal period's invoice now, instead of waiting for the renewal date. */
function RenewalInvoice({ service, onDone }: { service: Service; onDone: () => void }) {
  const qc = useQueryClient()
  const toast = useToast()
  const navigate = useNavigate()
  const renewal = service.renewal!
  const card = service.user?.autopay ? service.user.card : null

  const issue = useMutation({
    mutationFn: (send: boolean) => api.post<{ invoice: { id: number; number: string; status: string }; autopay: string | null }>(
      `/admin/services/${service.id}/renewal-invoice`, { send },
    ),
    onSuccess: (res) => {
      qc.invalidateQueries({ queryKey: adminKeys.all })
      const { number } = res.invoice
      if (res.autopay === 'succeeded') toast.ok(`${number} paid with the saved card`)
      else if (res.autopay === 'failed') toast.error(`${number} sent · the saved card was declined`)
      else toast.ok(res.invoice.status === 'draft' ? `${number} saved as a draft` : `${number} sent to the client`)
      onDone()
      navigate(A(`/invoices/${res.invoice.id}`))
    },
    onError: (e) => toast.error(errorMessage(e)),
  })

  return (
    <div className="notice" style={{ flexDirection: 'column', alignItems: 'stretch', gap: 10 }}>
      <span className="eyebrow">renewal invoice</span>
      {'blocked' in renewal ? (
        <span className="dim" style={{ fontSize: 13 }}>{renewal.blocked}</span>
      ) : (
        <>
          <span style={{ fontSize: 13 }}>
            Bill <b>{fmtDate(renewal.from)} – {fmtDate(renewal.to)}</b> at {service.price_label} now. The renewal date then moves to the next cycle, so it isn't billed twice.
            {card && <span className="dim"> Auto-pay is on: sending charges {cardLabel(card)}.</span>}
          </span>
          <div className="row" style={{ gap: 8 }}>
            <button type="button" className="btn btn-sm" disabled={issue.isPending}
              onClick={() => { if (confirm(`Invoice ${service.name} for ${fmtDate(renewal.from)} – ${fmtDate(renewal.to)} and ${card ? 'charge the saved card' : 'email it to the client'}?`)) issue.mutate(true) }}>
              {issue.isPending && <i className="fa-solid fa-circle-notch spin" />}{card ? 'Invoice & charge card' : 'Invoice & send'}
            </button>
            <button type="button" className="btn btn-ghost btn-sm" disabled={issue.isPending} onClick={() => issue.mutate(false)}>Save as draft</button>
          </div>
        </>
      )}
    </div>
  )
}
