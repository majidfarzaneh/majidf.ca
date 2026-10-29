import { Elements, PaymentElement, useElements, useStripe } from '@stripe/react-stripe-js'
import { useQueryClient } from '@tanstack/react-query'
import { useState, type FormEvent } from 'react'
import { Modal } from '../components/ui'
import { api, errorMessage } from '../lib/api'
import { ME_KEY, useMe } from '../lib/auth'
import { money } from '../lib/format'
import type { Invoice, Payment } from '../lib/types'
import { P } from '../lib/sites'
import { appearance, getStripe } from '../lib/stripe'
import OfflinePay from './OfflinePay'
import { portalKeys } from './queries'

type Step = 'method' | 'card' | 'processing' | 'redirect' | 'done' | 'offline' | 'offline-done'

/** Poll our API until the webhook (or a direct Stripe sync) marks the payment as succeeded. */
export async function waitForPayment(id: number, tries = 15): Promise<Payment> {
  let payment: Payment | null = null
  for (let i = 0; i < tries; i++) {
    payment = (await api.get<{ data: Payment }>(`/portal/payments/${id}`)).data
    if (payment.status !== 'pending') return payment
    await new Promise((r) => setTimeout(r, 1000))
  }
  return payment!
}

export default function PayModal({ invoices, onClose }: { invoices: Invoice[]; onClose: () => void }) {
  const { data: me } = useMe()
  const qc = useQueryClient()
  const offlineMethods = me?.offline_methods ?? []
  // Card / checkout only while every invoice is inside its payment window; otherwise only offline proof.
  const online = !!me?.stripe.enabled && !!me.stripe.key && invoices.every((i) => i.payable)
  const [step, setStep] = useState<Step>(online || offlineMethods.length === 0 ? 'method' : 'offline')
  const [intent, setIntent] = useState<{ id: number; secret: string } | null>(null)
  const [error, setError] = useState<string | null>(null)

  const total = invoices.reduce((a, i) => a + i.total_cents, 0)
  const label = invoices.length === 1 ? `${invoices[0].number} · ${invoices[0].description}` : `All due · ${invoices.length} invoices`
  const body = { invoice_ids: invoices.map((i) => i.id) }

  const chooseCard = async () => {
    setError(null)
    setStep('card')
    if (intent) return
    try {
      const res = await api.post<{ payment_id: number; client_secret: string }>('/portal/payments/intent', body)
      setIntent({ id: res.payment_id, secret: res.client_secret })
    } catch (err) {
      setError(errorMessage(err))
      setStep('method')
    }
  }

  const chooseCheckout = async () => {
    setError(null)
    setStep('redirect')
    try {
      const { url } = await api.post<{ url: string }>('/portal/payments/checkout', body)
      window.location.assign(url)
    } catch (err) {
      setError(errorMessage(err))
      setStep('method')
    }
  }

  const finished = async (paymentId: number) => {
    setStep('processing')
    const payment = await waitForPayment(paymentId).catch(() => null)
    await qc.invalidateQueries({ queryKey: portalKeys.all })
    await qc.invalidateQueries({ queryKey: ME_KEY }) // a card saved with the payment shows on Profile
    if (payment?.status === 'failed') {
      setError(payment.note ?? 'The payment did not go through.')
      setIntent(null)
      setStep('method')
      return
    }
    // A still-pending payment will be confirmed by the webhook shortly; the card has been charged.
    setStep('done')
  }

  const busy = step === 'processing' || step === 'redirect'

  return (
    <Modal
      onClose={onClose}
      busy={busy}
      eyebrow={label}
      title={<span className="mono" style={{ fontWeight: 700, fontSize: 22, lineHeight: 1 }}>{money(total, true)} <span style={{ fontSize: 12, fontWeight: 400, color: 'var(--fg-3)' }}>CAD</span></span>}
    >
      {step === 'method' && (
        <div className="modal-body fade-up" style={{ gap: 10, animationDuration: '.3s' }}>
          {error && <div className="field-error"><i className="fa-solid fa-circle-exclamation" />{error}</div>}
          {online && (
            <>
              <MethodButton icon="fa-solid fa-credit-card" color="var(--link)" title="Pay with card" hint="Visa, Mastercard, Amex · stays in the portal" onClick={chooseCard} />
              <MethodButton icon="fa-solid fa-arrow-up-right-from-square" color="var(--lime)" title="Secure checkout" hint="Apple Pay, Google Pay, Link · opens Stripe" onClick={chooseCheckout} />
            </>
          )}
          {offlineMethods.length > 0 && (
            <MethodButton icon="fa-solid fa-building-columns" color="var(--amber)" title={offlineMethods.map((m) => m.label.replace('Interac ', '')).join(', ').replace(/, ([^,]*)$/, ' or $1')}
              hint="Pay outside the portal, then upload your receipt" onClick={() => setStep('offline')} />
          )}
          {!online && offlineMethods.length === 0 && (
            <div className="notice">
              <i className="fa-solid fa-circle-info" style={{ color: 'var(--link)', marginTop: 3 }} />
              <span>Online payment isn't available for this invoice. Email <span style={{ color: 'var(--fg)' }}>{me?.business.email}</span> and I'll help you settle it.</span>
            </div>
          )}
        </div>
      )}

      {step === 'card' && (
        intent && me?.stripe.key ? (
          <Elements stripe={getStripe(me.stripe.key)} options={{ clientSecret: intent.secret, appearance }}>
            <CardForm paymentId={intent.id} amount={money(total, true)} replacing={!!me.user.card} onBack={() => setStep('method')} onPaid={() => finished(intent.id)} />
          </Elements>
        ) : (
          <Busy text="Preparing secure payment…" />
        )
      )}

      {step === 'offline' && (
        <OfflinePay invoices={invoices} methods={offlineMethods} onBack={online ? () => setStep('method') : undefined} onDone={() => setStep('offline-done')} />
      )}

      {step === 'offline-done' && (
        <div style={{ padding: '40px 20px 28px', display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 14, textAlign: 'center' }}>
          <span className="success-ring" style={{ width: 60, height: 60 }}><i className="fa-solid fa-paper-plane" style={{ fontSize: 22 }} /></span>
          <h3 className="display fade-up" style={{ margin: 0, fontWeight: 700, fontSize: 20, animationDelay: '.15s' }}>Receipt received</h3>
          <p className="muted fade-up" style={{ margin: 0, fontSize: 13, maxWidth: 320, animationDelay: '.22s' }}>Thanks! I'll confirm the payment within one business day and email your receipt. Until then the invoice shows as in review.</p>
          <button className="btn btn-ghost fade-up" style={{ marginTop: 10, animationDelay: '.3s' }} onClick={onClose}>Done</button>
        </div>
      )}

      {step === 'processing' && <Busy text="Processing payment…" />}
      {step === 'redirect' && <Busy text="Opening secure checkout…" />}

      {step === 'done' && (
        <div style={{ padding: '40px 20px 28px', display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 14, textAlign: 'center' }}>
          <span className="success-ring" style={{ width: 60, height: 60 }}><i className="fa-solid fa-check" style={{ fontSize: 24 }} /></span>
          <h3 className="display fade-up" style={{ margin: 0, fontWeight: 700, fontSize: 20, animationDelay: '.15s' }}>Payment received</h3>
          <p className="muted fade-up" style={{ margin: 0, fontSize: 13, animationDelay: '.22s' }}>{label} · receipt sent to your email.</p>
          <button className="btn btn-ghost fade-up" style={{ marginTop: 10, animationDelay: '.3s' }} onClick={onClose}>Done</button>
        </div>
      )}
    </Modal>
  )
}

function MethodButton({ icon, color, title, hint, onClick }: { icon: string; color: string; title: string; hint: string; onClick: () => void }) {
  return (
    <button className="pay-method" onClick={onClick}>
      <span className="icon-tile" style={{ width: 40, height: 40, color, border: 'none' }}><i className={icon} /></span>
      <div style={{ display: 'flex', flexDirection: 'column', gap: 2, flex: 1 }}>
        <span style={{ fontSize: 14, fontWeight: 500 }}>{title}</span>
        <span className="dim" style={{ fontSize: 12 }}>{hint}</span>
      </div>
      <i className="fa-solid fa-chevron-right dim" style={{ fontSize: 11 }} />
    </button>
  )
}

function Busy({ text }: { text: string }) {
  return (
    <div className="fade-in" style={{ padding: '48px 20px', display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 16, textAlign: 'center' }}>
      <i className="fa-solid fa-circle-notch spin" style={{ fontSize: 28, color: 'var(--brand)' }} />
      <span className="muted" style={{ fontSize: 14 }}>{text}</span>
    </div>
  )
}

function CardForm({ paymentId, amount, replacing, onBack, onPaid }: { paymentId: number; amount: string; replacing: boolean; onBack: () => void; onPaid: () => void }) {
  const stripe = useStripe()
  const elements = useElements()
  const { data: me } = useMe()
  const [save, setSave] = useState(false)
  const [ready, setReady] = useState(false)
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const submit = async (e: FormEvent) => {
    e.preventDefault()
    if (!stripe || !elements) return
    setSubmitting(true)
    setError(null)
    if (save) {
      // Stripe must know before confirming that the card is kept for later charges.
      try {
        await api.post(`/portal/payments/${paymentId}/save-card`, { save: true })
        await elements.fetchUpdates()
      } catch (err) {
        setError(errorMessage(err))
        setSubmitting(false)
        return
      }
    }
    const { error: err, paymentIntent } = await stripe.confirmPayment({
      elements,
      redirect: 'if_required',
      confirmParams: { return_url: `${window.location.origin}${P('/invoices')}?payment=${paymentId}` },
    })
    setSubmitting(false)
    if (err) {
      setError(err.message ?? 'Your card was declined.')
      return
    }
    if (paymentIntent && ['succeeded', 'processing'].includes(paymentIntent.status)) onPaid()
  }

  return (
    <form onSubmit={submit} className="modal-body fade-up" style={{ animationDuration: '.3s' }}>
      <PaymentElement onReady={() => setReady(true)} options={{ layout: 'tabs' }} />
      {!ready && <div className="loading" style={{ padding: 24 }}><i className="fa-solid fa-circle-notch spin" />Loading secure form</div>}
      <label className="checkbox" style={{ fontSize: 12, lineHeight: '18px' }}>
        <input type="checkbox" checked={save} disabled={submitting} onChange={(e) => setSave(e.target.checked)} />
        <span>
          Save this card {replacing ? '(replaces your saved card) ' : ''}and pay renewals automatically.{' '}
          <span className="dim">I authorize {me?.business.name ?? 'the business'} to charge it for my services' renewal invoices until I remove it in Profile.</span>
        </span>
      </label>
      {error && <div className="field-error"><i className="fa-solid fa-circle-exclamation" />{error}</div>}
      <button type="submit" className="btn btn-lg" disabled={!stripe || !ready || submitting}>
        {submitting ? <><i className="fa-solid fa-circle-notch spin" />Confirming</> : <><i className="fa-solid fa-lock" style={{ fontSize: 11 }} />Pay {amount}</>}
      </button>
      <button type="button" className="btn-text" style={{ alignSelf: 'center', color: 'var(--fg-3)' }} onClick={onBack} disabled={submitting}>Choose another method</button>
    </form>
  )
}
