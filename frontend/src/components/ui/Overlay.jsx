import { useEffect, useId, useRef } from 'react'
import { createPortal } from 'react-dom'
import { useTranslation } from 'react-i18next'
import { X } from 'lucide-react'
import { cn } from '../../lib/cn'
import { IconButton } from './Button'

/** Escape closes, the page behind does not scroll, focus moves into the panel. */
function useOverlay(open, onClose) {
  const panel = useRef(null)
  useEffect(() => {
    if (!open) return undefined
    const previous = document.activeElement
    const onKey = (event) => {
      if (event.key === 'Escape') onClose()
    }
    document.addEventListener('keydown', onKey)
    const overflow = document.body.style.overflow
    document.body.style.overflow = 'hidden'
    panel.current?.focus()
    return () => {
      document.removeEventListener('keydown', onKey)
      document.body.style.overflow = overflow
      if (previous instanceof HTMLElement) previous.focus()
    }
  }, [open, onClose])
  return panel
}

/** 480 px right panel for details: waste event, alert, command (docs/06 §4.6). */
export function Drawer({ open, onClose, title, subtitle, badges, footer, children, className }) {
  const { t } = useTranslation()
  const panel = useOverlay(open, onClose)
  const titleId = useId()
  if (!open) return null
  return createPortal(
    <div className="fixed inset-0 z-50">
      <button type="button" aria-label={t('common.close')} className="animate-fade absolute inset-0 bg-black/30" onClick={onClose} />
      <aside
        ref={panel}
        tabIndex={-1}
        role="dialog"
        aria-modal="true"
        aria-labelledby={titleId}
        className={cn(
          'animate-drawer absolute inset-y-0 right-0 flex w-full max-w-[480px] flex-col border-l border-line bg-surface shadow-overlay focus:outline-none',
          className,
        )}
      >
        <header className="flex items-start gap-3 border-b border-line px-5 py-4">
          <div className="min-w-0 flex-1">
            {badges && <div className="mb-2 flex flex-wrap items-center gap-1.5">{badges}</div>}
            <h2 id={titleId} className="text-base font-semibold text-ink">{title}</h2>
            {subtitle && <p className="mt-0.5 text-[13px] text-ink-2">{subtitle}</p>}
          </div>
          <IconButton label={t('common.close')} icon={X} onClick={onClose} className="-mr-2 -mt-1 size-8 shrink-0" />
        </header>
        <div className="min-h-0 flex-1 overflow-y-auto px-5 py-4">{children}</div>
        {footer && <footer className="flex flex-wrap items-center gap-2 border-t border-line px-5 py-3">{footer}</footer>}
      </aside>
    </div>,
    document.body,
  )
}

/** Centred dialog for consequential confirmations (480 / 640 px). */
export function Modal({ open, onClose, title, children, footer, wide = false }) {
  const { t } = useTranslation()
  const panel = useOverlay(open, onClose)
  const titleId = useId()
  if (!open) return null
  return createPortal(
    <div className="fixed inset-0 z-[60] flex items-end justify-center p-4 sm:items-center">
      <button type="button" aria-label={t('common.close')} className="animate-fade absolute inset-0 bg-black/35" onClick={onClose} />
      <div
        ref={panel}
        tabIndex={-1}
        role="dialog"
        aria-modal="true"
        aria-labelledby={titleId}
        className={cn('animate-pop relative flex max-h-full w-full flex-col rounded-lg border border-line bg-surface shadow-overlay focus:outline-none', wide ? 'max-w-[640px]' : 'max-w-[480px]')}
      >
        <div className="flex shrink-0 items-start justify-between gap-3 px-5 pb-1 pt-4">
          <h2 id={titleId} className="text-base font-semibold text-ink">{title}</h2>
          <IconButton label={t('common.close')} icon={X} onClick={onClose} className="-mr-2 -mt-1 size-8 shrink-0" />
        </div>
        {/* Long content scrolls inside the dialog; title and actions stay in view. */}
        <div className="min-h-0 overflow-y-auto px-5 pb-4 pt-1 text-sm text-ink-2">{children}</div>
        {footer && <div className="flex shrink-0 flex-wrap justify-end gap-2 border-t border-line px-5 py-3">{footer}</div>}
      </div>
    </div>,
    document.body,
  )
}
