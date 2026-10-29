import { useEffect, type CSSProperties, type ReactNode } from 'react'
import { Link } from 'react-router'
import { statusColors } from '../lib/status'
import type { Uptime } from '../lib/types'

export function Brand({ suffix, to = '/' }: { suffix?: string; to?: string }) {
  return (
    <Link to={to} className="brandmark" aria-label="Majid Farzaneh, home">
      <span className="t">~</span><span className="s">/</span><span>majid</span><span className="s">.</span><span>farzaneh</span>
      {suffix && <span className="s">/{suffix}</span>}
    </Link>
  )
}

export function Loading({ full, label = 'Loading' }: { full?: boolean; label?: string }) {
  return (
    <div className="loading" style={full ? { minHeight: '100vh' } : undefined}>
      <i className="fa-solid fa-circle-notch spin" style={{ color: 'var(--brand)' }} />
      {label}
    </div>
  )
}

export function ErrorState({ error, retry }: { error: unknown; retry?: () => void }) {
  return (
    <div className="card empty">
      <i className="fa-solid fa-triangle-exclamation" style={{ color: 'var(--amber)' }} />
      <span>{error instanceof Error ? error.message : 'Could not load this page.'}</span>
      {retry && <button className="btn btn-ghost btn-sm" onClick={retry}>Try again</button>}
    </div>
  )
}

export function Empty({ icon = 'fa-solid fa-inbox', children }: { icon?: string; children: ReactNode }) {
  return (
    <div className="empty">
      <i className={icon} />
      <span>{children}</span>
    </div>
  )
}

export function Badge({ label, style }: { label: string; style?: CSSProperties }) {
  const [color, background] = statusColors(label)
  return <span className="badge" style={{ color, background, ...style }}>{label}</span>
}

export function Spinner() {
  return <i className="fa-solid fa-circle-notch spin" />
}

export function ProgressBar({ percent, color = 'var(--brand)', thin, delay = 0 }: { percent: number; color?: string; thin?: boolean; delay?: number }) {
  return (
    <div className={`bar-track${thin ? ' thin' : ''}`}>
      <div className="bar-fill" style={{ width: `${Math.max(0, Math.min(100, percent))}%`, background: color, transitionDelay: `${delay}s` }} />
    </div>
  )
}

const UPTIME_COLORS = { up: 'var(--lime)', degraded: 'var(--amber)', down: 'var(--red)', none: 'var(--border-strong)' }

export function UptimeBars({ uptime, ready }: { uptime: Uptime; ready: boolean }) {
  return (
    <div className="uptime" role="img" aria-label={`Uptime over 30 days: ${uptime.percent ?? 'no data'}%`}>
      {uptime.days.map((d) => (
        <span
          key={d.date}
          title={`${d.date} · ${d.percent === null ? 'no data' : d.percent + '%'}`}
          style={{ background: UPTIME_COLORS[d.state], opacity: ready ? (d.state === 'up' ? 0.85 : d.state === 'none' ? 0.4 : 1) : 0.08 }}
        />
      ))}
    </div>
  )
}

export function Field({ label, hint, error, children, style }: { label: string; hint?: ReactNode; error?: string; children: ReactNode; style?: CSSProperties }) {
  return (
    <label className="field" style={style}>
      <span className="field-label">{label}</span>
      {children}
      {error ? <span className="field-error"><i className="fa-solid fa-circle-exclamation" />{error}</span> : hint ? <span className="field-hint">{hint}</span> : null}
    </label>
  )
}

export function Toggle({ on }: { on: boolean }) {
  return <span className={`switch${on ? ' on' : ''}`} aria-hidden />
}

export function Modal({ title, eyebrow, onClose, children, footer, wide, busy }: {
  title: ReactNode
  eyebrow?: ReactNode
  onClose: () => void
  children: ReactNode
  footer?: ReactNode
  wide?: boolean
  busy?: boolean
}) {
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape' && !busy) onClose() }
    window.addEventListener('keydown', onKey)
    const overflow = document.body.style.overflow
    document.body.style.overflow = 'hidden'
    return () => {
      window.removeEventListener('keydown', onKey)
      document.body.style.overflow = overflow
    }
  }, [onClose, busy])

  return (
    <div className="modal-backdrop" onMouseDown={(e) => { if (e.target === e.currentTarget && !busy) onClose() }}>
      <div className={`modal${wide ? ' wide' : ''}`} role="dialog" aria-modal="true">
        <div className="modal-head">
          <div style={{ display: 'flex', flexDirection: 'column', gap: 2, minWidth: 0 }}>
            {eyebrow && <span className="mono dim" style={{ fontSize: 11 }}>{eyebrow}</span>}
            <span style={{ fontSize: 16, fontWeight: 500 }}>{title}</span>
          </div>
          <button className="icon-btn bare" onClick={onClose} disabled={busy} aria-label="Close"><i className="fa-solid fa-xmark" /></button>
        </div>
        {children}
        {footer && <div className="modal-foot">{footer}</div>}
      </div>
    </div>
  )
}

/** Card with a fade-up entrance, staggered by `delay` seconds. */
export function Card({ children, delay = 0, className = '', style, as: Tag = 'div' }: { children: ReactNode; delay?: number; className?: string; style?: CSSProperties; as?: 'div' | 'section' }) {
  return <Tag className={`card fade-up ${className}`} style={{ animationDelay: `${delay}s`, ...style }}>{children}</Tag>
}

export function Kpi({ label, value, sub, delay }: { label: string; value: ReactNode; sub?: ReactNode; delay?: number }) {
  return (
    <Card className="kpi" delay={delay}>
      <span className="eyebrow">{label}</span>
      <span className="big-num">{value}</span>
      {sub && <span className="kpi-sub">{sub}</span>}
    </Card>
  )
}
