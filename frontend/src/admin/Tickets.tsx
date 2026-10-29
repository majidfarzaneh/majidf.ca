import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useState, type FormEvent } from 'react'
import { Link, useNavigate, useParams } from 'react-router'
import { TicketCard, Thread } from '../components/Thread'
import { Empty, ErrorState, Field, Loading, Modal } from '../components/ui'
import { api, errorMessage } from '../lib/api'
import { stamp } from '../lib/format'
import { useToast } from '../lib/toast'
import type { Ticket } from '../lib/types'
import { adminKeys, clientLabel, useAdminTicket, useAdminTickets, useClients } from './queries'
import { A } from '../lib/sites'

const FILTERS: [string, string][] = [['active', 'Active'], ['open', 'Open'], ['in_progress', 'In progress'], ['resolved', 'Resolved'], ['', 'All']]
const STATUSES: [Ticket['status'], string][] = [['open', 'Open'], ['in_progress', 'In progress'], ['resolved', 'Resolved']]

export default function Tickets() {
  const [status, setStatus] = useState('active')
  const { data, isLoading, error, refetch } = useAdminTickets({ status: status || undefined })
  const params = useParams()
  const navigate = useNavigate()
  const [creating, setCreating] = useState(false)
  const selected = Number(params.id) || data?.[0]?.id || 0

  return (
    <div className="stack" style={{ gap: 16 }}>
      <div className="toolbar fade-up">
        <div className="segmented">{FILTERS.map(([v, l]) => <button key={v} className={status === v ? 'on' : ''} onClick={() => setStatus(v)}>{l}</button>)}</div>
        <button className="btn" onClick={() => setCreating(true)}><i className="fa-solid fa-plus" style={{ fontSize: 11 }} />New ticket</button>
      </div>
      {isLoading ? <Loading /> : error || !data ? <ErrorState error={error} retry={refetch} /> : (
        <div className="tickets-layout">
          <div className="fade-up" style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
            {data.length === 0 && <div className="card"><Empty icon="fa-solid fa-circle-check">No tickets here.</Empty></div>}
            {data.map((t) => {
              const waiting = t.status !== 'resolved' && (!t.last_admin_reply_at || (t.last_activity_at ?? '') > t.last_admin_reply_at)
              return (
                <TicketCard key={t.id} ticket={t} active={t.id === selected} onClick={() => navigate(A(`/tickets/${t.id}`))}
                  meta={<>{clientLabel(t.user)} · {stamp(t.last_activity_at)}{waiting && <span style={{ color: 'var(--amber)' }}> · needs reply</span>}</>} />
              )
            })}
          </div>
          {selected ? <AdminThread id={selected} /> : <div className="card"><Empty icon="fa-solid fa-life-ring">Pick a ticket.</Empty></div>}
        </div>
      )}
      {creating && <NewTicket onClose={() => setCreating(false)} onCreated={(t) => navigate(A(`/tickets/${t.id}`))} />}
    </div>
  )
}

function AdminThread({ id }: { id: number }) {
  const qc = useQueryClient()
  const toast = useToast()
  const { data: ticket, isLoading, error } = useAdminTicket(id)
  const [nextStatus, setNextStatus] = useState<Ticket['status'] | ''>('')
  const [notify, setNotify] = useState(true)

  const onSaved = (res: { data: Ticket }) => {
    qc.setQueryData(adminKeys.ticket(id), res.data)
    qc.invalidateQueries({ queryKey: ['admin', 'tickets'] })
    qc.invalidateQueries({ queryKey: adminKeys.dashboard })
  }
  const reply = useMutation({
    mutationFn: (body: string) => api.post<{ data: Ticket }>(`/admin/tickets/${id}/messages`, { body, status: nextStatus || null, notify }),
    onSuccess: (res) => { onSaved(res); setNextStatus('') },
    onError: (e) => toast.error(errorMessage(e)),
  })
  const setStatus = useMutation({
    mutationFn: (status: Ticket['status']) => api.patch<{ data: Ticket }>(`/admin/tickets/${id}`, { status }),
    onSuccess: onSaved,
    onError: (e) => toast.error(errorMessage(e)),
  })

  if (isLoading) return <div className="card"><Loading /></div>
  if (error || !ticket) return <ErrorState error={error} />

  return (
    <Thread
      ticket={ticket}
      mine={(fromAdmin) => fromAdmin}
      onReply={reply.mutateAsync}
      sending={reply.isPending}
      header={
        <div className="row" style={{ gap: 8 }}>
          {ticket.user && <Link to={A(`/clients/${ticket.user.id}`)} style={{ fontSize: 12 }}>{clientLabel(ticket.user)}</Link>}
          <select className="input input-sm" style={{ width: 'auto', padding: '4px 28px 4px 8px', fontSize: 12 }} value={ticket.status} disabled={setStatus.isPending}
            onChange={(e) => setStatus.mutate(e.target.value as Ticket['status'])} aria-label="Ticket status">
            {STATUSES.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
          </select>
        </div>
      }
      extra={
        <div className="row-wrap" style={{ gap: 16 }}>
          <label className="checkbox"><input type="checkbox" checked={notify} onChange={(e) => setNotify(e.target.checked)} />Email the client</label>
          <label className="checkbox">and set status
            <select className="input input-sm" style={{ width: 'auto', padding: '4px 28px 4px 8px', fontSize: 12 }} value={nextStatus} onChange={(e) => setNextStatus(e.target.value as Ticket['status'] | '')}>
              <option value="">unchanged</option>
              {STATUSES.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
            </select>
          </label>
        </div>
      }
    />
  )
}

function NewTicket({ onClose, onCreated }: { onClose: () => void; onCreated: (t: Ticket) => void }) {
  const qc = useQueryClient()
  const { data: clients } = useClients()
  const [f, setF] = useState({ user_id: 0, subject: '', body: '', notify: true })
  const create = useMutation({
    mutationFn: () => api.post<{ data: Ticket }>('/admin/tickets', f),
    onSuccess: (res) => { qc.invalidateQueries({ queryKey: adminKeys.all }); onCreated(res.data); onClose() },
  })
  const submit = (e: FormEvent) => { e.preventDefault(); create.mutate() }

  return (
    <Modal wide title="New ticket" eyebrow="opened on the client's behalf" onClose={onClose} busy={create.isPending}>
      <form onSubmit={submit}>
        <div className="modal-body">
          <Field label="client">
            <select className="input" required value={f.user_id || ''} onChange={(e) => setF({ ...f, user_id: Number(e.target.value) })}>
              <option value="" disabled>Choose a client</option>
              {clients?.map((c) => <option key={c.id} value={c.id}>{clientLabel(c)}</option>)}
            </select>
          </Field>
          <Field label="subject"><input className="input" required maxLength={160} value={f.subject} onChange={(e) => setF({ ...f, subject: e.target.value })} /></Field>
          <Field label="first message"><textarea className="input" required rows={5} value={f.body} onChange={(e) => setF({ ...f, body: e.target.value })} /></Field>
          <label className="checkbox"><input type="checkbox" checked={f.notify} onChange={(e) => setF({ ...f, notify: e.target.checked })} />Email the client</label>
          {create.error && <div className="field-error">{errorMessage(create.error)}</div>}
        </div>
        <div className="modal-foot">
          <button type="button" className="btn btn-ghost" onClick={onClose}>Cancel</button>
          <button type="submit" className="btn" disabled={create.isPending}>Open ticket</button>
        </div>
      </form>
    </Modal>
  )
}
