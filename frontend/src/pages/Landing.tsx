import { useEffect, useMemo, useState } from 'react'
import { Link } from 'react-router'
import { useTitle } from '../lib/hooks'
import { loginPath } from '../lib/sites'
import './landing.css'

const EMAIL = 'majix.fa@gmail.com'
const PHONE = '437-328-3301'
const HERO = 'Full-stack engineer.'
const TYPING_SPEED = 28
const STACK = ['React', 'TypeScript', 'PHP', 'Laravel', 'Docker']

const C = { kw: '#5699f6', v: '#fafafa', pr: '#8cd3ff', s: '#b6d957', p: '#71717a', b: '#fac364', f: '#d998cb' }
type Tok = [string, keyof typeof C]

const LINES: Tok[][] = [
  [['const ', 'kw'], ['majid', 'v'], [' = {', 'p']],
  [['  role', 'pr'], [': ', 'p'], ['"Full-stack engineer"', 's'], [',', 'p']],
  [['  stack', 'pr'], [': [', 'p'], ['"React"', 's'], [', ', 'p'], ['"TypeScript"', 's'], [', ', 'p'], ['"PHP"', 's'], [', ', 'p'], ['"Laravel"', 's'], [', ', 'p'], ['"Docker"', 's'], ['],', 'p']],
  [['  scope', 'pr'], [': [', 'p'], ['"frontend"', 's'], [', ', 'p'], ['"backend"', 's'], [', ', 'p'], ['"mobile"', 's'], [', ', 'p'], ['"database"', 's'], [', ', 'p'], ['"deploy"', 's'], ['],', 'p']],
  [['  delivery', 'pr'], [': ', 'p'], ['"idea → production"', 's'], [',', 'p']],
  [['  available', 'pr'], [': ', 'p'], ['true', 'b'], [',', 'p']],
  [['  contact', 'pr'], [': () => ', 'p'], ['book', 'f'], ['(', 'p'], ['"a call"', 's'], ['),', 'p']],
  [['};', 'p']],
  [],
  [['export default ', 'kw'], ['majid', 'v'], [';', 'p']],
]

const lineLen = (l: Tok[]) => l.reduce((a, t) => a + t[0].length, 0)
const CODE_LEN = LINES.reduce((a, l) => a + lineLen(l) + 1, 0)
const TOTAL = HERO.length + CODE_LEN

function useTyping() {
  const [pos, setPos] = useState(() => (window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ? TOTAL : 0))
  useEffect(() => {
    if (pos >= TOTAL) return
    const delay = pos === HERO.length ? 600 : TYPING_SPEED + Math.random() * TYPING_SPEED * 0.8
    const id = window.setTimeout(() => setPos((p) => p + 1), delay)
    return () => window.clearTimeout(id)
  }, [pos])
  return pos
}

function Sparkles() {
  const dots = useMemo(() => {
    const cols = ['#3f3f46', '#52525b', '#1752af']
    return Array.from({ length: 22 }, (_, i) => ({
      key: i,
      left: 12 + 24 * Math.floor(Math.random() * 80) - 1,
      top: 12 + 24 * Math.floor(Math.random() * 45) - 1,
      background: cols[Math.floor(Math.random() * cols.length)],
      animation: `twinkle ${(7 + Math.random() * 6).toFixed(2)}s ease-in-out ${(Math.random() * 14).toFixed(2)}s infinite`,
    }))
  }, [])
  return (
    <>
      {dots.map(({ key, ...style }) => (
        <span key={key} style={{ position: 'absolute', width: 3, height: 3, borderRadius: '50%', opacity: 0, ...style }} />
      ))}
    </>
  )
}

export default function Landing() {
  useTitle('')
  const pos = useTyping()

  const heroTyped = HERO.slice(0, Math.min(pos, HERO.length))
  const rem = Math.max(0, pos - HERO.length)
  let start = 0
  const codeLines = LINES.map((toks, i) => {
    const L = lineLen(toks)
    let vis = Math.max(0, Math.min(rem - start, L))
    const active = rem >= start && (rem <= start + L || (i === LINES.length - 1 && rem >= CODE_LEN)) && pos > HERO.length
    const out: { t: string; c: string }[] = []
    for (const [t, c] of toks) {
      if (vis <= 0) break
      const take = Math.min(vis, t.length)
      out.push({ t: t.slice(0, take), c: C[c] })
      vis -= take
    }
    start += L + 1
    return { n: i + 1, toks: out, active }
  })
  const activeLine = (codeLines.find((l) => l.active)?.n) ?? 1
  const progress = Math.round((Math.min(rem, CODE_LEN) / CODE_LEN) * 100)

  return (
    <div className="landing" data-screen-label="Landing">
      <div className="dot-grid landing-grid"><Sparkles /></div>

      <header className="landing-head fade-up" style={{ animationDuration: '.6s' }}>
        <div className="brandmark"><span className="t">~</span><span className="s">/</span><span>majid</span><span className="s">.</span><span>farzaneh</span></div>
        <div className="landing-status eyebrow" style={{ letterSpacing: '.08em' }}>
          <span className="cursor-block" /><span>status:</span><span style={{ color: 'var(--fg)' }}>available for projects</span>
        </div>
        <Link to={loginPath()} className="landing-login"><i className="fa-solid fa-user" />Client login</Link>
      </header>

      <main className="landing-main">
        <section className="landing-hero">
          <div className="mono dim fade-up" style={{ fontSize: 12, animationDelay: '.1s' }}>// hello, I'm</div>
          <h1 className="landing-name fade-up" style={{ animationDelay: '.18s' }}>Majid<br />Farzaneh</h1>
          <div className="landing-typed fade-up" style={{ animationDelay: '.26s' }} aria-label={HERO}>
            <span>{heroTyped}</span>
            <span className="caret" />
          </div>
          <p className="landing-lede fade-up" style={{ animationDelay: '.34s' }}>
            I take web and mobile products from idea to production. Frontend, backend, infrastructure. Whatever the project needs.
          </p>
          <div className="row-wrap fade-up" style={{ animationDelay: '.42s' }}>
            <a href={`mailto:${EMAIL}?subject=Let's%20book%20a%20call`} className="btn btn-lg" style={{ minHeight: 0, padding: '11px 18px' }}>
              <i className="fa-solid fa-calendar" style={{ fontSize: 13 }} />Book a call
            </a>
            <a href={`mailto:${EMAIL}`} className="landing-contact"><i className="fa-solid fa-envelope" />{EMAIL}</a>
            <a href={`tel:+1${PHONE.replace(/\D/g, '')}`} className="landing-contact"><i className="fa-solid fa-phone" />{PHONE}</a>
          </div>
        </section>

        <section className="landing-editor-wrap fade-up" style={{ animationDuration: '.7s', animationDelay: '.3s' }}>
          <div className="landing-editor" aria-hidden>
            <div className="editor-bar">
              <div className="row" style={{ gap: 6 }}>
                <span className="dot" /><span className="dot" /><span className="dot" />
              </div>
              <div className="editor-tab"><span style={{ width: 6, height: 6, borderRadius: 1, background: 'var(--brand)' }} />majid.ts</div>
            </div>
            <div className="editor-code">
              {codeLines.map((ln) => (
                <div key={ln.n} className="editor-line">
                  <span className="editor-gutter">{ln.n}</span>
                  <span style={{ display: 'flex', alignItems: 'center', minWidth: 0 }}>
                    {ln.toks.map((tk, i) => <span key={i} style={{ whiteSpace: 'pre', color: tk.c }}>{tk.t}</span>)}
                    {ln.active && <span className="editor-cursor" />}
                  </span>
                </div>
              ))}
            </div>
            <div className="editor-foot">
              <span>TypeScript · UTF-8</span>
              <span>Ln {activeLine} · {progress}%</span>
              <span className="editor-progress" style={{ width: `${progress}%` }} />
            </div>
          </div>
        </section>
      </main>

      <footer className="landing-foot fade-up" style={{ animationDuration: '.6s', animationDelay: '.5s' }}>
        <div className="row" style={{ gap: 40 }}>
          <div className="landing-stat">
            <span className="mono" style={{ fontWeight: 700, fontSize: 24, lineHeight: 1 }}><span className="brand-text">since </span>2011</span>
            <span className="eyebrow">shipping code</span>
          </div>
          <div className="landing-stat">
            <span className="mono" style={{ fontWeight: 700, fontSize: 24, lineHeight: 1 }}>140<span className="brand-text">+</span></span>
            <span className="eyebrow">projects shipped</span>
          </div>
        </div>
        <div className="row-wrap" style={{ gap: 8 }}>
          {STACK.map((s) => <span key={s} className="landing-chip">{s}</span>)}
        </div>
      </footer>
    </div>
  )
}
