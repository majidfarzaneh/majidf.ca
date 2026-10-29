import { useQueryClient } from '@tanstack/react-query'
import { useEffect, useRef, useState, type FormEvent, type KeyboardEvent } from 'react'
import { useLocation, useNavigate, useSearchParams } from 'react-router'
import { Brand, Loading } from '../components/ui'
import { api, errorMessage } from '../lib/api'
import { homeFor, ME_KEY, Redirect, useMe } from '../lib/auth'
import { go, isAbsolute, landingPath, site } from '../lib/sites'
import { useTitle } from '../lib/hooks'
import { useToast } from '../lib/toast'
import type { Me } from '../lib/types'
import './login.css'

const EMPTY = ['', '', '', '', '', '']

/** POST /auth/code reply: how long the code lives and when another may be requested (seconds). */
type CodeSent = { expires_in?: number; resend_in?: number }
/** Absolute deadlines (ms) for the code on screen, so the countdowns survive re-renders. */
type Deadlines = { expiresAt: number; resendAt: number }

const deadlines = (res: CodeSent | undefined): Deadlines => ({
  expiresAt: Date.now() + (res?.expires_in ?? 120) * 1000,
  resendAt: Date.now() + (res?.resend_in ?? 60) * 1000,
})
const secondsLeft = (at: number, now: number) => Math.max(0, Math.ceil((at - now) / 1000))
const clock = (s: number) => `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`

export default function Login() {
  useTitle(site === 'admin' ? 'Admin' : 'Client portal')
  const { data: me, isLoading } = useMe()
  const [params] = useSearchParams()
  const [sent, setSent] = useState<Deadlines | null>(null)
  const [email, setEmail] = useState(params.get('email') ?? '')
  const admin = site === 'admin'

  if (isLoading) return <Loading full />
  if (me) return <Redirect to={homeFor(me.user.role)} />

  return (
    <div className="auth" data-screen-label="Auth">
      <div className="dot-grid auth-grid" />
      <div className="auth-intro">
        <div className="fade-up"><Brand suffix={site === 'admin' ? 'admin' : 'portal'} to={landingPath()} /></div>
        <div style={{ display: 'flex', flexDirection: 'column', gap: 20, maxWidth: 420 }}>
          <div className="mono dim fade-up" style={{ fontSize: 12, animationDelay: '.1s' }}>{admin ? '// super admin' : '// client portal'}</div>
          <h1 className="auth-title fade-up" style={{ animationDelay: '.18s' }}>{admin ? <>Run the<br />business.</> : <>Your projects,<br />one place.</>}</h1>
          <p className="muted fade-up" style={{ margin: 0, fontSize: 15, lineHeight: '24px', animationDelay: '.26s' }}>{admin ? 'Clients, services, invoices and tickets.' : 'Track services, pay invoices and reach me directly.'}</p>
        </div>
        <div className="eyebrow row fade-up" style={{ letterSpacing: '.08em', animationDelay: '.3s' }}><span className="cursor-block" />secure · passwordless</div>
      </div>
      <div className="auth-panel">
        {sent === null
          ? <EmailStep email={email} setEmail={setEmail} onSent={(res) => setSent(deadlines(res))} admin={admin} />
          : <CodeStep email={email} sent={sent} onResent={(res) => setSent(deadlines(res))} onBack={() => setSent(null)} />}
      </div>
    </div>
  )
}

function EmailStep({ email, setEmail, onSent, admin }: { email: string; setEmail: (v: string) => void; onSent: (res: CodeSent) => void; admin: boolean }) {
  const [sending, setSending] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const submit = async (e: FormEvent) => {
    e.preventDefault()
    if (!email) return
    setSending(true)
    setError(null)
    try {
      onSent(await api.post<CodeSent>('/auth/code', { email }))
    } catch (err) {
      setError(errorMessage(err))
    } finally {
      setSending(false)
    }
  }

  return (
    <form onSubmit={submit} className="auth-card scale-in" style={{ animationDelay: '.15s', gap: 20 }}>
      <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
        <h2 className="auth-h2">Sign in</h2>
        <p className="muted" style={{ margin: 0, fontSize: 14 }}>Enter your email. We'll send a one-time code.</p>
      </div>
      <label className="field">
        <span className="field-label">email</span>
        <input type="email" required autoFocus autoComplete="email" placeholder="you@company.com" className="input input-lg"
          value={email} onChange={(e) => setEmail(e.target.value)} />
        {error && <span className="field-error"><i className="fa-solid fa-circle-exclamation" />{error}</span>}
      </label>
      <button type="submit" className="btn btn-lg" disabled={sending}>
        {sending ? <><i className="fa-solid fa-circle-notch spin" />Sending code</> : <>Send code<i className="fa-solid fa-arrow-right" style={{ fontSize: 12 }} /></>}
      </button>
      {!admin && <div className="dim" style={{ fontSize: 12, textAlign: 'center' }}>No account yet? <a href="mailto:majix.fa@gmail.com">Email me</a> and I'll set one up.</div>}
    </form>
  )
}

function CodeStep({ email, sent, onResent, onBack }: { email: string; sent: Deadlines; onResent: (res: CodeSent) => void; onBack: () => void }) {
  const qc = useQueryClient()
  const navigate = useNavigate()
  const location = useLocation()
  const toast = useToast()
  const [otp, setOtp] = useState(EMPTY)
  const [error, setError] = useState<string | null>(null)
  const [verifying, setVerifying] = useState(false)
  const [now, setNow] = useState(Date.now)
  const refs = useRef<(HTMLInputElement | null)[]>([])

  useEffect(() => {
    refs.current[0]?.focus()
  }, [])

  const expiresIn = secondsLeft(sent.expiresAt, now)
  const resendIn = secondsLeft(sent.resendAt, now)
  const expired = expiresIn === 0

  // Tick while either countdown is running.
  useEffect(() => {
    if (expired && resendIn === 0) return
    const id = window.setInterval(() => setNow(Date.now()), 1000)
    return () => window.clearInterval(id)
  }, [expired, resendIn])

  const focus = (i: number) => {
    const el = refs.current[i]
    if (el) { el.focus(); el.select() }
  }

  const verify = async (digits = otp) => {
    const code = digits.join('')
    if (expired) { setError('This code has expired. Send a new one.'); return }
    if (code.length < 6) { setError('Enter all 6 digits.'); return }
    setVerifying(true)
    setError(null)
    try {
      const me = await api.post<Me>('/auth/verify', { email, code })
      qc.setQueryData(ME_KEY, me)
      const from = (location.state as { from?: string } | null)?.from
      const home = homeFor(me.user.role)
      // `from` is a path on this host; only reuse it when this host is the user's home.
      go(navigate, from && !isAbsolute(home) && from.startsWith(home) ? from : home, true)
    } catch (err) {
      setError(errorMessage(err))
      setOtp(EMPTY)
      focus(0)
    } finally {
      setVerifying(false)
    }
  }

  const change = (i: number, raw: string) => {
    const v = raw.replace(/\D/g, '')
    const next = [...otp]
    if (v.length > 1) {
      v.split('').slice(0, 6 - i).forEach((ch, k) => { next[i + k] = ch })
      setOtp(next)
      setError(null)
      if (next.every(Boolean)) verify(next)
      else focus(Math.min(i + v.length, 5))
      return
    }
    next[i] = v
    setOtp(next)
    setError(null)
    if (v && i < 5) focus(i + 1)
    if (v && i === 5 && next.every(Boolean)) verify(next)
  }

  const key = (i: number, e: KeyboardEvent<HTMLInputElement>) => {
    if (e.key === 'Backspace' && !otp[i] && i > 0) focus(i - 1)
    if (e.key === 'ArrowLeft' && i > 0) focus(i - 1)
    if (e.key === 'ArrowRight' && i < 5) focus(i + 1)
    if (e.key === 'Enter') verify()
  }

  const resend = async () => {
    try {
      onResent(await api.post<CodeSent>('/auth/code', { email }))
      setOtp(EMPTY)
      setError(null)
      focus(0)
      toast.ok('New code sent to ' + email, 'fa-solid fa-paper-plane')
    } catch (err) {
      toast.error(errorMessage(err))
    }
  }

  return (
    <div className="auth-card scale-in" style={{ gap: 22 }}>
      <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
        <h2 className="auth-h2">Check your inbox</h2>
        <p className="muted" style={{ margin: 0, fontSize: 14 }}>If <span className="mono" style={{ color: 'var(--fg)', fontSize: 13 }}>{email}</span> has an account, a code is on its way.</p>
      </div>
      <div className="otp-row" style={{ animation: error ? 'shake .3s linear both' : 'none' }}>
        {otp.map((v, i) => (
          <input
            key={i}
            ref={(el) => { refs.current[i] = el }}
            className="otp-box"
            inputMode="numeric"
            autoComplete={i === 0 ? 'one-time-code' : 'off'}
            maxLength={6}
            aria-label={`Digit ${i + 1}`}
            value={v}
            onChange={(e) => change(i, e.target.value)}
            onKeyDown={(e) => key(i, e)}
            onFocus={(e) => e.target.select()}
            style={{ borderColor: error ? 'var(--red)' : v ? 'var(--border-strong)' : undefined, transform: v ? 'scale(1.04)' : undefined }}
          />
        ))}
      </div>
      {error && <div className="field-error"><i className="fa-solid fa-circle-exclamation" />{error}</div>}
      {expired
        ? <div className="field-error"><i className="fa-solid fa-clock" />Code expired. Send a new one below.</div>
        : <div className="dim mono" style={{ fontSize: 12 }}>Code expires in {clock(expiresIn)}</div>}
      <button className="btn btn-lg" onClick={() => verify()} disabled={verifying || expired}>
        {verifying ? <><i className="fa-solid fa-circle-notch spin" />Verifying</> : 'Continue'}
      </button>
      <div className="spread dim" style={{ fontSize: 12 }}>
        <button className="btn-text" onClick={onBack}><i className="fa-solid fa-arrow-left" style={{ fontSize: 10 }} />Change email</button>
        {resendIn > 0
          ? <span className="mono">Resend in {resendIn}s</span>
          : <button className="btn-link" onClick={resend}>Resend code</button>}
      </div>
    </div>
  )
}
