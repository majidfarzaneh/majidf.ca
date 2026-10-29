import { useEffect, useState } from 'react'

/** 0 → 1 with ease-out-cubic over `ms`, restarting when `key` changes. Drives count-up numbers. */
export function useEntrance(key: unknown = null, ms = 900): number {
  const [t, setT] = useState(0)
  useEffect(() => {
    let raf = 0
    const t0 = performance.now()
    const step = (now: number) => {
      const p = Math.min(1, (now - t0) / ms)
      setT(1 - Math.pow(1 - p, 3))
      if (p < 1) raf = requestAnimationFrame(step)
    }
    setT(0)
    raf = requestAnimationFrame(step)
    return () => cancelAnimationFrame(raf)
  }, [key, ms])
  return t
}

/** False for a beat after mount, so CSS width transitions animate from zero. */
export function useReady(delay = 120): boolean {
  const [ready, setReady] = useState(false)
  useEffect(() => {
    const id = window.setTimeout(() => setReady(true), delay)
    return () => window.clearTimeout(id)
  }, [delay])
  return ready
}

export function useTitle(title: string) {
  useEffect(() => {
    document.title = title ? `${title} · Majid Farzaneh` : 'Majid Farzaneh · Full-stack engineer'
  }, [title])
}
