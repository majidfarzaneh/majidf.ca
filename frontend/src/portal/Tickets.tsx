import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useState, type FormEvent } from 'react'
import { useNavigate, useParams } from 'react-router'
import { TicketCard, Thread } from '../components/Thread'
import { Empty, ErrorState, Field, Loading } from '../components/ui'
import { api, errorMessage } from '../lib/api'
import { useToast } from '../lib/toast'
import type { Ticket } from '../lib/types'
import { portalKeys, useTicket, useTickets } from './queries'
import { P } from '../lib/sites'

export default function Tickets() {
  const params = useParams()
  const navigate = useNavigate()
  const { data: tickets, isLoading, error, refetch } = useTickets()
  const isNew = params.number === 'new'
  const selected = isNew ? null : Number(params.number) || tickets?.[0]?.number || null

  if (isLoading) return <Loading />
  if (error || !tickets) return <ErrorState error={error} retry={refetch} />

  return (
    <div className="tickets-layout">
      <div className="fade-up" style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
        {tickets.map((t) => (
          <TicketCard key={t.id} ticket={t} active={t.number === selected} onClick={() => navigate(P(`/tickets/${t.number}`))} />
        ))}
        <button className="new-ticket" onClick={() => navigate(P('/tickets/new'))}><i className="fa-solid fa-plus" style={{ fontSize: 11 }} />New ticket</button>
      </div>
      {isNew || !selected ? <NewTicket hasTickets={tickets.length > 0} /> : <TicketThread number={selected} />}
    </div>
  )
}

function TicketThread({ number }: { number: number }) {
  const qc = useQueryClient()
  const toast = useToast()
  const { data: ticket, isLoading, error } = useTicket(number)
  const reply = useMutation({
    mutationFn: (body: string) => api.post<{ data: Ticket }>(`/portal/tickets/${number}/messages`, { body }),
    onSuccess: (res) => {
      qc.setQueryData(portalKeys.ticket(number), res.data)
      qc.invalidateQueries({ queryKey: portalKeys.tickets })
      qc.invalidateQueries({ queryKey: portalKeys.dashboard })
    },
    onError: (err) => toast.error(errorMessage(err)),
  })

  if (isLoading) return <div className="card"><Loading /></div>
  if (error || !ticket) return <ErrorState error={error} />

  return <Thread ticket={ticket} mine={(fromAdmin) => !fromAdmin} onReply={reply.mutateAsync} sending={reply.isPending} />
}

function NewTicket({ hasTickets }: { hasTickets: boolean }) {
  const qc = useQueryClient()
  const navigate = useNavigate()
  const toast = useToast()
  const [subject, setSubject] = useState('')
  const [body, setBody] = useState('')
  const create = useMutation({
    mutationFn: () => api.post<{ data: Ticket }>('/portal/tickets', { subject, body }),
    onSuccess: (res) => {
      qc.invalidateQueries({ queryKey: portalKeys.tickets })
      qc.invalidateQueries({ queryKey: portalKeys.dashboard })
      qc.setQueryData(portalKeys.ticket(res.data.number), res.data)
      toast.ok(`Ticket ${res.data.reference} opened. I'll reply soon.`, 'fa-solid fa-life-ring')
      navigate(P(`/tickets/${res.data.number}`), { replace: true })
    },
    onError: (err) => toast.error(errorMessage(err)),
  })

  const submit = (e: FormEvent) => {
    e.preventDefault()
    create.mutate()
  }

  return (
    <form onSubmit={submit} className="card fade-up" style={{ animationDelay: '.1s', gap: 18, padding: 24 }}>
      <div style={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
        <span style={{ fontSize: 16, fontWeight: 500 }}>{hasTickets ? 'New ticket' : 'Need a hand?'}</span>
        <span className="muted" style={{ fontSize: 13 }}>Describe what's going on. Screenshots or links help. I usually reply the same day.</span>
      </div>
      {!hasTickets && <Empty icon="fa-solid fa-life-ring">No tickets yet.</Empty>}
      <Field label="subject"><input className="input" required maxLength={160} placeholder="e.g. Contact form not sending" value={subject} onChange={(e) => setSubject(e.target.value)} /></Field>
      <Field label="details"><textarea className="input" required rows={6} placeholder="What happened, where, and what you expected." value={body} onChange={(e) => setBody(e.target.value)} /></Field>
      <button type="submit" className="btn" style={{ alignSelf: 'flex-start' }} disabled={create.isPending}>
        {create.isPending ? <i className="fa-solid fa-circle-notch spin" /> : <i className="fa-solid fa-paper-plane" style={{ fontSize: 12 }} />}Open ticket
      </button>
    </form>
  )
}
