import { cn } from '../../lib/cn'

const TONES = {
  neutral: 'border-line-strong bg-surface text-ink-2',
  brand: 'border-transparent bg-brand-subtle text-brand',
  good: 'border-transparent bg-good-subtle text-good-text',
  warning: 'border-transparent bg-warning-subtle text-warning-text',
  critical: 'border-transparent bg-critical-subtle text-critical-text',
  info: 'border-transparent bg-info-subtle text-info-text',
}

/** Status is never colour-alone: pass an icon or make the label say it. */
export function Badge({ tone = 'neutral', icon: Icon, className, children }) {
  return (
    <span
      className={cn(
        'inline-flex h-6 items-center gap-1 whitespace-nowrap rounded-sm border px-2 text-xs font-medium',
        TONES[tone],
        className,
      )}
    >
      {Icon && <Icon className="size-3.5 shrink-0" aria-hidden="true" />}
      {children}
    </span>
  )
}
