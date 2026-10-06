import { useTranslation } from 'react-i18next'
import { CircleAlert, LoaderCircle, RefreshCw } from 'lucide-react'
import { cn } from '../../lib/cn'
import { Button } from './Button'

/** Why it's empty + the next step. Never a dead end. */
export function EmptyState({ icon: Icon, title, body, actions, className }) {
  return (
    <div className={cn('flex flex-col items-center px-6 py-14 text-center', className)}>
      {Icon && (
        <div className="mb-4 flex size-11 items-center justify-center rounded-md border border-line bg-surface-2 text-ink-2">
          <Icon className="size-5" aria-hidden="true" />
        </div>
      )}
      <h3 className="text-base font-semibold text-ink">{title}</h3>
      {body && <p className="mt-1.5 max-w-md text-sm text-ink-2">{body}</p>}
      {actions && <div className="mt-5 flex flex-wrap justify-center gap-2">{actions}</div>}
    </div>
  )
}

export function ErrorState({ error, onRetry, className }) {
  const { t } = useTranslation()
  const message = t(`errors.${error?.code}`, { defaultValue: t('errors.generic') })
  return (
    <div className={cn('flex flex-col items-center px-6 py-10 text-center', className)} role="alert">
      <CircleAlert className="mb-3 size-6 text-critical" aria-hidden="true" />
      <p className="text-sm text-ink">{message}</p>
      {error?.code && <p className="mt-1 font-mono text-xs text-ink-3">{error.code}</p>}
      {onRetry && (
        <Button variant="secondary" size="sm" icon={RefreshCw} onClick={onRetry} className="mt-4">
          {t('common.retry')}
        </Button>
      )}
    </div>
  )
}

export function Skeleton({ className }) {
  return <div className={cn('animate-pulse rounded-sm bg-surface-2', className)} aria-hidden="true" />
}

export function FullPageSpinner() {
  const { t } = useTranslation()
  return (
    <div className="flex min-h-svh items-center justify-center bg-bg" role="status">
      <LoaderCircle className="size-6 animate-spin text-ink-3" aria-hidden="true" />
      <span className="sr-only">{t('common.loading')}</span>
    </div>
  )
}

const CALLOUT_TONES = {
  info: 'border-info bg-info-subtle text-ink',
  good: 'border-good bg-good-subtle text-ink',
  warning: 'border-warning bg-warning-subtle text-ink',
  critical: 'border-critical bg-critical-subtle text-ink',
}

export function Callout({ tone = 'info', icon: Icon, children, className }) {
  return (
    <div className={cn('flex items-start gap-2.5 rounded-sm border-l-[3px] px-3.5 py-2.5 text-sm', CALLOUT_TONES[tone], className)} role={tone === 'critical' ? 'alert' : 'status'}>
      {Icon && <Icon className="mt-0.5 size-4 shrink-0" aria-hidden="true" />}
      <div className="min-w-0">{children}</div>
    </div>
  )
}
