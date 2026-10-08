import { createContext, use, useCallback, useMemo, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { CircleAlert, CircleCheck, Info, X } from 'lucide-react'
import { cn } from '../lib/cn'

const ToastContext = createContext(null)
const DURATION_MS = 4000

const TONES = {
  good: { icon: CircleCheck, bar: 'bg-good', icn: 'text-good-text' },
  critical: { icon: CircleAlert, bar: 'bg-critical', icn: 'text-critical-text' },
  info: { icon: Info, bar: 'bg-info', icn: 'text-info-text' },
}

/** Bottom-right, 4 s: "Command sent · verified by meter in 12 s" (docs/06 §4.6). */
export function ToastProvider({ children }) {
  const { t } = useTranslation()
  const [toasts, setToasts] = useState([])
  const nextId = useRef(1)

  const dismiss = useCallback((id) => setToasts((list) => list.filter((toast) => toast.id !== id)), [])
  const toast = useCallback(
    ({ tone = 'good', title, body }) => {
      const id = nextId.current++
      setToasts((list) => [...list.slice(-2), { id, tone, title, body }])
      setTimeout(() => dismiss(id), DURATION_MS)
    },
    [dismiss],
  )
  const value = useMemo(() => ({ toast }), [toast])

  return (
    <ToastContext value={value}>
      {children}
      <div className="pointer-events-none fixed bottom-4 right-4 z-[70] flex print:hidden w-[min(360px,calc(100vw-2rem))] flex-col gap-2" aria-live="polite">
        {toasts.map(({ id, tone, title, body }) => {
          const style = TONES[tone] ?? TONES.info
          const Icon = style.icon
          return (
            <div key={id} className="animate-pop pointer-events-auto relative flex items-start gap-2.5 overflow-hidden rounded-md border border-line bg-surface px-3.5 py-3 shadow-overlay">
              <span className={cn('absolute inset-y-0 left-0 w-[3px]', style.bar)} aria-hidden="true" />
              <Icon className={cn('mt-0.5 size-4 shrink-0', style.icn)} aria-hidden="true" />
              <div className="min-w-0 flex-1 text-[13px]">
                <p className="font-medium text-ink">{title}</p>
                {body && <p className="mt-0.5 text-ink-2">{body}</p>}
              </div>
              <button type="button" onClick={() => dismiss(id)} aria-label={t('common.close')} className="-mr-1 text-ink-3 hover:text-ink">
                <X className="size-4" aria-hidden="true" />
              </button>
            </div>
          )
        })}
      </div>
    </ToastContext>
  )
}

export function useToast() {
  const context = use(ToastContext)
  if (!context) throw new Error('useToast must be used inside <ToastProvider>')
  return context.toast
}
