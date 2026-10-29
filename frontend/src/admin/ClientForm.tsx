import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useState, type FormEvent } from 'react'
import { Field, Modal } from '../components/ui'
import { api, ApiError } from '../lib/api'
import { useToast } from '../lib/toast'
import type { User } from '../lib/types'
import { adminKeys, useMeta } from './queries'
import TaxField from './TaxField'

export default function ClientForm({ client, onClose, onSaved }: { client?: User; onClose: () => void; onSaved?: (u: User) => void }) {
  const qc = useQueryClient()
  const toast = useToast()
  const { data: meta } = useMeta()
  const [f, setF] = useState({
    name: client?.name ?? '',
    email: client?.email ?? '',
    company: client?.company ?? '',
    phone: client?.phone ?? '',
    address: client?.address ?? '',
    admin_notes: client?.admin_notes ?? '',
    is_active: client?.is_active ?? true,
    send_invite: !client,
    tax_rate: client?.tax_rate != null ? String(client.tax_rate) : '',
    tax_label: client?.tax_label ?? '',
  })

  const save = useMutation({
    mutationFn: () => {
      const body = { ...f, tax_rate: f.tax_rate === '' ? null : f.tax_rate, tax_label: f.tax_label || null }
      return client ? api.put<{ data: User }>(`/admin/clients/${client.id}`, body) : api.post<{ data: User }>('/admin/clients', body)
    },
    onSuccess: (res) => {
      qc.invalidateQueries({ queryKey: adminKeys.all })
      toast.ok(client ? 'Client updated' : f.send_invite ? `Client added · invite sent to ${res.data.email}` : 'Client added')
      onSaved?.(res.data)
      onClose()
    },
  })

  const err = (k: string) => (save.error instanceof ApiError ? save.error.field(k) : undefined)
  const set = (k: keyof typeof f) => (e: { target: { value: string } }) => setF({ ...f, [k]: e.target.value })
  const submit = (e: FormEvent) => { e.preventDefault(); save.mutate() }

  return (
    <Modal wide title={client ? 'Edit client' : 'New client'} eyebrow={client ? client.email : 'clients'} onClose={onClose} busy={save.isPending}>
      <form onSubmit={submit}>
        <div className="modal-body">
          <div className="form-grid">
            <Field label="name" error={err('name')}><input className="input" required value={f.name} onChange={set('name')} autoFocus /></Field>
            <Field label="company" error={err('company')}><input className="input" value={f.company} onChange={set('company')} /></Field>
            <Field label="email" error={err('email')} hint="They sign in with this."><input className="input" type="email" required value={f.email} onChange={set('email')} /></Field>
            <Field label="phone" error={err('phone')}><input className="input" value={f.phone} onChange={set('phone')} /></Field>
          </div>
          <Field label="billing address" error={err('address')} hint="Printed on invoices."><textarea className="input" rows={2} value={f.address} onChange={set('address')} /></Field>
          <TaxField value={f} onChange={(v) => setF({ ...f, ...v })} error={err('tax_rate')}
            inheritName="Business default" inherited={meta && { rate: meta.defaults.tax_rate, label: meta.defaults.tax_label }} />
          <Field label="private notes" error={err('admin_notes')} hint="Only you can see these."><textarea className="input" rows={3} value={f.admin_notes} onChange={set('admin_notes')} /></Field>
          <div className="row-wrap" style={{ gap: 20 }}>
            {client && <label className="checkbox"><input type="checkbox" checked={f.is_active} onChange={(e) => setF({ ...f, is_active: e.target.checked })} />Can sign in</label>}
            {!client && <label className="checkbox"><input type="checkbox" checked={f.send_invite} onChange={(e) => setF({ ...f, send_invite: e.target.checked })} />Email a portal invite now</label>}
          </div>
          {save.error && !(save.error instanceof ApiError && Object.keys(save.error.errors).length) && <div className="field-error">{save.error.message}</div>}
        </div>
        <div className="modal-foot">
          <button type="button" className="btn btn-ghost" onClick={onClose}>Cancel</button>
          <button type="submit" className="btn" disabled={save.isPending}>{save.isPending && <i className="fa-solid fa-circle-notch spin" />}{client ? 'Save' : 'Add client'}</button>
        </div>
      </form>
    </Modal>
  )
}
