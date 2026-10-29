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
const RESEND_SECONDS = 30

export default function Login() {
  useTitle(site === 'admin' ? 'Admin' : 'Client portal')
  const { data: me, isLoading } = useMe()
  const [params] = useSearchParams()
  const [screen, setScreen] = useState<'login' | 'otp'>('login')
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
        {screen === 'login'
          ? <EmailStep email={email} setEmail={setEmail} onSent={() => setScreen('otp')} admin={admin} />
          : <CodeStep email={email} onBack={() => setScreen('login')} />}
      </div>
    </div>
  )
}

function EmailStep({ email, setEmail, onSent, admin }: { email: string; setEmail: (v: string) => void; onSent: () => void; admin: boolean }) {
  const [sending, setSending] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const submit = async (e: FormEvent) => {
    e.preventDefault()
    if (!email) return
    setSending(true)
    setError(null)
    try {
      await api.post('/auth/code', { email })
      onSent()
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

function CodeStep({ email, onBack }: { email: string; onBack: () => void }) {
  const qc = useQueryClient()
  const navigate = useNavigate()
  const location = useLocation()
  const toast = useToast()
  const [otp, setOtp] = useState(EMPTY)
  const [error, setError] = useState<string | null>(null)
  const [verifying, setVerifying] = useState(false)
  const [resendIn, setResendIn] = useState(RESEND_SECONDS)
  const refs = useRef<(HTMLInputElement | null)[]>([])

  useEffect(() => {
    refs.current[0]?.focus()
  }, [])

  useEffect(() => {
    if (resendIn <= 0) return
    const id = window.setTimeout(() => setResendIn((s) => s - 1), 1000)
    return () => window.clearTimeout(id)
  }, [resendIn])

  const focus = (i: number) => {
    const el = refs.current[i]
    if (el) { el.focus(); el.select() }
  }

  const verify = async (digits = otp) => {
    const code = digits.join('')
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
      await api.post('/auth/code', { email })
      setResendIn(RESEND_SECONDS)
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
      <button className="btn btn-lg" onClick={() => verify()} disabled={verifying}>
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
