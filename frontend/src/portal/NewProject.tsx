import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useState, type FormEvent } from 'react'
import { api, errorMessage } from '../lib/api'
import { useMe } from '../lib/auth'
import { portalKeys } from './queries'

const TYPES: [string, string][] = [['web', 'Website'], ['app', 'Mobile app'], ['shop', 'Online store'], ['api', 'Backend / API'], ['host', 'Hosting + care'], ['other', 'Something else']]
const BUDGETS: [string, string][] = [['b1', '< 5k'], ['b2', '5–15k'], ['b3', '15–40k'], ['b4', '40k+']]

export default function NewProject() {
  const { data: me } = useMe()
  const qc = useQueryClient()
  const [types, setTypes] = useState<Record<string, boolean>>({})
  const [budget, setBudget] = useState('b2')
  const [description, setDescription] = useState('')
  const [sent, setSent] = useState(false)

  const send = useMutation({
    mutationFn: () => api.post('/portal/project-requests', { types: Object.keys(types).filter((k) => types[k]), budget, description }),
    onSuccess: () => {
      setSent(true)
      qc.invalidateQueries({ queryKey: portalKeys.dashboard })
    },
  })

  const submit = (e: FormEvent) => {
    e.preventDefault()
    send.mutate()
  }

  if (sent) {
    return (
      <div className="card scale-in" style={{ maxWidth: 640, padding: '48px 28px', alignItems: 'center', textAlign: 'center' }}>
        <span className="success-ring"><i className="fa-solid fa-check" style={{ fontSize: 22 }} /></span>
        <h2 className="display" style={{ margin: 0, fontWeight: 700, fontSize: 22 }}>Request sent</h2>
        <p className="muted" style={{ margin: 0, fontSize: 14, maxWidth: 380 }}>You'll get a reply at <span style={{ color: 'var(--fg)' }}>{me?.user.email}</span> with a scope and estimate.</p>
        <button className="btn btn-ghost" style={{ marginTop: 8 }} onClick={() => { setSent(false); setTypes({}); setDescription('') }}>Send another</button>
      </div>
    )
  }

  return (
    <form onSubmit={submit} className="card fade-up" style={{ maxWidth: 640, padding: 28, gap: 24 }}>
      <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
        <span className="field-label">what are we building</span>
        <div className="row-wrap" style={{ gap: 8 }}>
          {TYPES.map(([id, label]) => (
            <button key={id} type="button" className={`chip${types[id] ? ' on' : ''}`} aria-pressed={!!types[id]} onClick={() => setTypes({ ...types, [id]: !types[id] })}>{label}</button>
          ))}
        </div>
      </div>
      <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
        <span className="field-label">budget · CAD</span>
        <div className="segmented" role="radiogroup">
          {BUDGETS.map(([id, label]) => (
            <button key={id} type="button" role="radio" aria-checked={budget === id} className={budget === id ? 'on' : ''} onClick={() => setBudget(id)}>{label}</button>
          ))}
        </div>
      </div>
      <label className="field" style={{ gap: 10 }}>
        <span className="field-label">a few lines about it</span>
        <textarea className="input" rows={5} placeholder="Goal, audience, deadline, anything you already have." value={description} onChange={(e) => setDescription(e.target.value)} />
      </label>
      {send.error && <div className="field-error"><i className="fa-solid fa-circle-exclamation" />{errorMessage(send.error)}</div>}
      <div className="spread" style={{ flexWrap: 'wrap' }}>
        <span className="dim" style={{ fontSize: 12 }}>I reply within one business day.</span>
        <button type="submit" className="btn" style={{ padding: '11px 18px', fontSize: 14 }} disabled={send.isPending}>
          {send.isPending ? <><i className="fa-solid fa-circle-notch spin" />Sending</> : <>Send request<i className="fa-solid fa-arrow-right" style={{ fontSize: 12 }} /></>}
        </button>
      </div>
    </form>
  )
}
