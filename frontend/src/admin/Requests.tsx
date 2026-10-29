import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { Link } from 'react-router'
import { Badge, Card, Empty, ErrorState, Loading } from '../components/ui'
import { api, errorMessage } from '../lib/api'
import { ago } from '../lib/format'
import { cap } from '../lib/status'
import { useToast } from '../lib/toast'
import type { ProjectRequest } from '../lib/types'
import { adminKeys, clientLabel, useRequests } from './queries'
import { A } from '../lib/sites'

const STATUSES: ProjectRequest['status'][] = ['new', 'reviewing', 'quoted', 'won', 'declined']

export default function Requests() {
  const [status, setStatus] = useState('')
  const { data, isLoading, error, refetch } = useRequests({ status: status || undefined })

  return (
    <div className="stack" style={{ gap: 16 }}>
      <div className="toolbar fade-up">
        <div className="segmented">
          <button className={status === '' ? 'on' : ''} onClick={() => setStatus('')}>All</button>
          {STATUSES.map((s) => <button key={s} className={status === s ? 'on' : ''} onClick={() => setStatus(s)}>{cap(s)}</button>)}
        </div>
      </div>
      {isLoading ? <Loading /> : error || !data ? <ErrorState error={error} retry={refetch} /> : data.length === 0 ? (
        <div className="card"><Empty icon="fa-solid fa-paper-plane">No project requests.</Empty></div>
      ) : (
        <div className="grid-cards" style={{ gridTemplateColumns: 'repeat(auto-fill, minmax(min(100%, 380px), 1fr))' }}>
          {data.map((r, i) => <RequestCard key={r.id} request={r} delay={i * 0.05} />)}
        </div>
      )}
    </div>
  )
}

function RequestCard({ request: r, delay }: { request: ProjectRequest; delay: number }) {
  const qc = useQueryClient()
  const toast = useToast()
  const [notes, setNotes] = useState(r.admin_notes ?? '')
  const update = useMutation({
    mutationFn: (body: Partial<ProjectRequest>) => api.put(`/admin/project-requests/${r.id}`, body),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['admin', 'requests'] }); qc.invalidateQueries({ queryKey: adminKeys.dashboard }) },
    onError: (e) => toast.error(errorMessage(e)),
  })

  return (
    <Card delay={delay} style={{ gap: 12 }}>
      <div className="spread" style={{ alignItems: 'flex-start' }}>
        <div style={{ display: 'flex', flexDirection: 'column', gap: 2 }}>
          {r.user ? <Link to={A(`/clients/${r.user.id}`)} style={{ fontWeight: 500, fontSize: 15 }}>{clientLabel(r.user)}</Link> : '—'}
          <span className="mono dim" style={{ fontSize: 11 }}>{ago(r.created_at)} · CAD {r.budget_label}</span>
        </div>
        <Badge label={cap(r.status)} />
      </div>
      <div className="row-wrap" style={{ gap: 6 }}>{r.type_labels.map((t) => <span key={t} className="chip on" style={{ padding: '4px 10px', fontSize: 12, cursor: 'default' }}>{t}</span>)}</div>
      {r.description && <p className="muted" style={{ margin: 0, fontSize: 13, whiteSpace: 'pre-wrap' }}>{r.description}</p>}
      <textarea className="input input-sm" rows={2} placeholder="Private notes" value={notes} onChange={(e) => setNotes(e.target.value)}
        onBlur={() => { if (notes !== (r.admin_notes ?? '')) update.mutate({ admin_notes: notes }) }} />
      <div className="row-wrap" style={{ gap: 6 }}>
        {STATUSES.filter((s) => s !== r.status).map((s) => (
          <button key={s} className="btn btn-ghost btn-sm" disabled={update.isPending} onClick={() => update.mutate({ status: s })}>Mark {s}</button>
        ))}
        {r.user && <a className="btn btn-sm" href={`mailto:${r.user.email}?subject=${encodeURIComponent('Your project request')}`}><i className="fa-solid fa-envelope" style={{ fontSize: 11 }} />Reply</a>}
      </div>
    </Card>
  )
}
