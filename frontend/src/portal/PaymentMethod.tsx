import { Elements, PaymentElement, useElements, useStripe } from '@stripe/react-stripe-js'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useEffect, useState, type FormEvent } from 'react'
import { Card, Modal, Toggle } from '../components/ui'
import { api, errorMessage } from '../lib/api'
import { ME_KEY, useMe } from '../lib/auth'
import { cardLabel } from '../lib/format'
import { P } from '../lib/sites'
import { appearance, getStripe } from '../lib/stripe'
import { useToast } from '../lib/toast'
import type { Me, User } from '../lib/types'

/** Profile → saved card and auto-pay. The card is stored by Stripe; we only show its brand, last 4 and expiry. */
export default function PaymentMethod({ delay }: { delay?: number }) {
  const { data: me } = useMe()
  const qc = useQueryClient()
  const toast = useToast()
  const [adding, setAdding] = useState(false)
  if (!me?.stripe.enabled || !me.stripe.key) return null

  const user = me.user
  const setUser = (u: User) => qc.setQueryData<Me | null>(ME_KEY, (old) => (old ? { ...old, user: u } : old))

  return (
    <Card delay={delay} style={{ padding: 24, gap: 12 }}>
      <span className="card-title">Payment method</span>
      {user.card ? (
        <>
          <div className="row" style={{ gap: 10 }}>
            <i className="fa-solid fa-credit-card" style={{ color: 'var(--link)' }} />
            <span className="mono" style={{ fontSize: 13 }}>{cardLabel(user.card)}</span>
          </div>
          <AutopaySwitch user={user} onChange={setUser} />
          <div className="row" style={{ gap: 14 }}>
            <button className="btn-link" onClick={() => setAdding(true)}>Replace card</button>
            <RemoveCard onRemoved={(u) => { setUser(u); toast.ok('Card removed · auto-pay off') }} />
          </div>
        </>
      ) : (
        <>
          <p className="muted" style={{ margin: 0, fontSize: 13, lineHeight: '20px' }}>
            Save a card to pay renewals automatically. You'll get a receipt each time, and you can remove it whenever you like.
          </p>
          <button className="btn btn-sm" style={{ alignSelf: 'flex-start' }} onClick={() => setAdding(true)}>
            <i className="fa-solid fa-plus" style={{ fontSize: 11 }} />Add a card
          </button>
        </>
      )}
      {adding && (
        <AddCardModal
          stripeKey={me.stripe.key}
          business={me.business.name}
          replacing={!!user.card}
          onClose={() => setAdding(false)}
          onSaved={(u) => { setUser(u); setAdding(false); toast.ok('Card saved · auto-pay on', 'fa-solid fa-credit-card') }}
        />
      )}
    </Card>
  )
}

function AutopaySwitch({ user, onChange }: { user: User; onChange: (u: User) => void }) {
  const toast = useToast()
  const toggle = useMutation({
    mutationFn: (enabled: boolean) => api.put<{ data: User }>('/portal/payment-method/autopay', { enabled }),
    onSuccess: (res) => onChange(res.data),
    onError: (err) => toast.error(errorMessage(err)),
  })

  return (
    <button className="notif-row" role="switch" aria-checked={user.autopay} disabled={toggle.isPending} onClick={() => toggle.mutate(!user.autopay)}>
      <div style={{ display: 'flex', flexDirection: 'column', gap: 2 }}>
        <span style={{ fontSize: 14 }}>Auto-pay</span>
        <span className="dim" style={{ fontSize: 12 }}>{user.autopay ? 'Renewal invoices are charged to this card' : 'Off · renewal invoices are emailed for you to pay'}</span>
      </div>
      <Toggle on={user.autopay} />
    </button>
  )
}

function RemoveCard({ onRemoved }: { onRemoved: (u: User) => void }) {
  const toast = useToast()
  const remove = useMutation({
    mutationFn: () => api.delete<{ data: User }>('/portal/payment-method'),
    onSuccess: (res) => onRemoved(res.data),
    onError: (err) => toast.error(errorMessage(err)),
  })

  return (
    <button className="btn-text" style={{ color: 'var(--red)' }} disabled={remove.isPending}
      onClick={() => { if (confirm('Remove this card? Auto-pay turns off and renewals will be emailed for you to pay.')) remove.mutate() }}>
      Remove
    </button>
  )
}

function AddCardModal({ stripeKey, business, replacing, onClose, onSaved }: {
  stripeKey: string; business: string; replacing: boolean; onClose: () => void; onSaved: (u: User) => void
}) {
  const [secret, setSecret] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)

  // A SetupIntent per opening of the modal.
  useEffect(() => {
    let open = true
    api.post<{ client_secret: string }>('/portal/payment-method/setup')
      .then((res) => { if (open) setSecret(res.client_secret) })
      .catch((err) => { if (open) setError(errorMessage(err)) })
    return () => { open = false }
  }, [])

  return (
    <Modal title={replacing ? 'Replace card' : 'Add a card'} eyebrow="auto-pay" onClose={onClose}>
      {error && <div className="modal-body"><div className="field-error"><i className="fa-solid fa-circle-exclamation" />{error}</div></div>}
      {!error && !secret && <div className="loading" style={{ padding: 32 }}><i className="fa-solid fa-circle-notch spin" />Preparing secure form</div>}
      {secret && (
        <Elements stripe={getStripe(stripeKey)} options={{ clientSecret: secret, appearance }}>
          <SetupForm business={business} onSaved={onSaved} onCancel={onClose} />
        </Elements>
      )}
    </Modal>
  )
}

function SetupForm({ business, onSaved, onCancel }: { business: string; onSaved: (u: User) => void; onCancel: () => void }) {
  const stripe = useStripe()
  const elements = useElements()
  const [ready, setReady] = useState(false)
  const [agreed, setAgreed] = useState(false)
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const submit = async (e: FormEvent) => {
    e.preventDefault()
    if (!stripe || !elements || !agreed) return
    setSubmitting(true)
    setError(null)
    const { error: err, setupIntent } = await stripe.confirmSetup({
      elements,
      redirect: 'if_required',
      confirmParams: { return_url: `${window.location.origin}${P('/profile')}` },
    })
    if (err || !setupIntent) {
      setError(err?.message ?? 'The card could not be saved.')
      setSubmitting(false)
      return
    }
    try {
      const res = await api.post<{ data: User }>('/portal/payment-method', { setup_intent: setupIntent.id })
      onSaved(res.data)
    } catch (e2) {
      setError(errorMessage(e2))
      setSubmitting(false)
    }
  }

  return (
    <form onSubmit={submit} className="modal-body fade-up" style={{ animationDuration: '.3s' }}>
      <PaymentElement onReady={() => setReady(true)} options={{ layout: 'tabs' }} />
      {!ready && <div className="loading" style={{ padding: 24 }}><i className="fa-solid fa-circle-notch spin" />Loading secure form</div>}
      <label className="checkbox" style={{ fontSize: 12, lineHeight: '18px' }}>
        <input type="checkbox" checked={agreed} disabled={submitting} onChange={(e) => setAgreed(e.target.checked)} />
        <span>I authorize {business} to charge this card for my services' renewal invoices until I remove it or turn auto-pay off. I'll get a receipt for every charge.</span>
      </label>
      {error && <div className="field-error"><i className="fa-solid fa-circle-exclamation" />{error}</div>}
      <button type="submit" className="btn btn-lg" disabled={!stripe || !ready || !agreed || submitting}>
        {submitting ? <><i className="fa-solid fa-circle-notch spin" />Saving</> : <><i className="fa-solid fa-lock" style={{ fontSize: 11 }} />Save card & turn on auto-pay</>}
      </button>
      <button type="button" className="btn-text" style={{ alignSelf: 'center', color: 'var(--fg-3)' }} onClick={onCancel} disabled={submitting}>Cancel</button>
    </form>
  )
}
