import { useEffect, useRef, useState, type FormEvent, type ReactNode } from 'react'
import { stamp } from '../lib/format'
import type { Ticket } from '../lib/types'
import { Badge } from './ui'

/** Conversation view for a ticket; `mine` decides which side a message sits on. */
export function Thread({ ticket, mine, onReply, sending, extra, header }: {
  ticket: Ticket
  mine: (fromAdmin: boolean) => boolean
  onReply: (body: string) => Promise<unknown>
  sending: boolean
  extra?: ReactNode
  header?: ReactNode
}) {
  const [reply, setReply] = useState('')
  const end = useRef<HTMLDivElement>(null)
  const count = ticket.messages?.length ?? 0

  useEffect(() => {
    end.current?.scrollIntoView({ block: 'nearest' })
  }, [count])

  const submit = async (e: FormEvent) => {
    e.preventDefault()
    if (!reply.trim()) return
    await onReply(reply.trim())
    setReply('')
  }

  return (
    <div className="card card-flush fade-up thread" style={{ animationDelay: '.1s' }}>
      <div style={{ padding: '16px 20px', borderBottom: '1px solid var(--border)', display: 'flex', flexDirection: 'column', gap: 4 }}>
        <div className="spread">
          <span className="mono dim" style={{ fontSize: 11 }}>{ticket.reference} · {ticket.status_label}</span>
          {header}
        </div>
        <span style={{ fontSize: 16, fontWeight: 500 }}>{ticket.subject}</span>
      </div>
      <div className="thread-body">
        {(ticket.messages ?? []).map((m, i) => {
          const me = mine(m.from_admin)
          return (
            <div key={m.id} className="fade-up" style={{ display: 'flex', flexDirection: 'column', gap: 6, maxWidth: '85%', alignSelf: me ? 'flex-end' : 'flex-start', animationDelay: `${Math.min(i, 6) * 0.08}s`, animationDuration: '.35s' }}>
              <span className="mono dim" style={{ fontSize: 11, alignSelf: me ? 'flex-end' : 'flex-start' }}>{m.author} · {stamp(m.created_at)}</span>
              <div className="bubble" style={me ? { background: 'var(--brand)', borderColor: 'var(--brand)' } : undefined}>{m.body}</div>
            </div>
          )
        })}
        <div ref={end} />
      </div>
      <form onSubmit={submit} style={{ display: 'flex', flexDirection: 'column', gap: 10, padding: 14, borderTop: '1px solid var(--border)' }}>
        <div style={{ display: 'flex', gap: 8 }}>
          <textarea className="input" rows={1} placeholder="Write a reply" value={reply} onChange={(e) => setReply(e.target.value)}
            onKeyDown={(e) => { if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) submit(e) }}
            style={{ flex: 1, padding: '10px 12px', minHeight: 42, resize: 'vertical' }} />
          <button type="submit" className="btn" style={{ width: 42, padding: 0 }} disabled={sending || !reply.trim()} aria-label="Send reply">
            {sending ? <i className="fa-solid fa-circle-notch spin" /> : <i className="fa-solid fa-paper-plane" style={{ fontSize: 12 }} />}
          </button>
        </div>
        {extra}
      </form>
    </div>
  )
}

export function TicketCard({ ticket, active, onClick, meta }: { ticket: Ticket; active: boolean; onClick: () => void; meta?: ReactNode }) {
  return (
    <button className={`ticket-card${active ? ' on' : ''}`} onClick={onClick}>
      <div className="spread" style={{ width: '100%' }}>
        <span className="mono dim" style={{ fontSize: 11 }}>{ticket.reference}</span>
        <Badge label={ticket.status_label} />
      </div>
      <span style={{ fontSize: 14, fontWeight: 500 }}>{ticket.subject}</span>
      <span className="dim" style={{ fontSize: 12 }}>{meta ?? `${ticket.message_count ?? 0} messages · ${stamp(ticket.last_activity_at)}`}</span>
    </button>
  )
}
