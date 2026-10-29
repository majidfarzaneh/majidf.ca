import { createContext, useCallback, useContext, useRef, useState, type ReactNode } from 'react'

type Toast = { msg: string; icon: string; tone: 'ok' | 'error' }
type ShowToast = (msg: string, icon?: string, tone?: Toast['tone']) => void

const ToastContext = createContext<ShowToast>(() => {})

export function ToastProvider({ children }: { children: ReactNode }) {
  const [toast, setToast] = useState<Toast | null>(null)
  const timer = useRef<number | undefined>(undefined)

  const show = useCallback<ShowToast>((msg, icon = 'fa-solid fa-check', tone = 'ok') => {
    window.clearTimeout(timer.current)
    setToast({ msg, icon, tone })
    timer.current = window.setTimeout(() => setToast(null), tone === 'error' ? 4200 : 2600)
  }, [])

  return (
    <ToastContext.Provider value={show}>
      {children}
      {toast && (
        <div className="toast" role="status" key={toast.msg + toast.icon}>
          <i className={toast.icon} style={{ color: toast.tone === 'error' ? 'var(--red)' : 'var(--lime)', fontSize: 12 }} />
          {toast.msg}
        </div>
      )}
    </ToastContext.Provider>
  )
}

export function useToast() {
  const show = useContext(ToastContext)
  return {
    ok: (msg: string, icon?: string) => show(msg, icon, 'ok'),
    error: (msg: string) => show(msg, 'fa-solid fa-circle-exclamation', 'error'),
  }
}
