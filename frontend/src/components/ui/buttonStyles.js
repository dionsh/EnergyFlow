import { cn } from '../../lib/cn'

const VARIANTS = {
  primary: 'border-transparent bg-brand text-on-brand hover:bg-brand-hover',
  secondary: 'border-line-strong bg-surface text-ink hover:bg-surface-2',
  ghost: 'border-transparent bg-transparent text-ink-2 hover:bg-surface-2 hover:text-ink',
  danger: 'border-critical/50 bg-surface text-critical-text hover:bg-critical-subtle',
  // The outlined counterpart of danger for a non-destructive row action (e.g. Edit next to Delete).
  brand: 'border-brand/50 bg-surface text-brand hover:bg-brand-subtle',
  // The final step of a consequential confirm (docs/06 §4.6: critical outline → fill on confirm).
  confirmDanger: 'border-transparent bg-critical text-white hover:opacity-90',
}

const SIZES = {
  sm: 'h-8 gap-1.5 px-3 text-[13px]',
  md: 'h-9 gap-2 px-3.5 text-sm',
  lg: 'h-10 gap-2 px-4 text-sm',
}

/** The button look, also for a link that acts as one: <Link className={buttonClasses({ variant: 'secondary' })}>. */
export function buttonClasses({ variant = 'primary', size = 'md', className } = {}) {
  return cn(
    'inline-flex items-center justify-center whitespace-nowrap rounded-sm border font-medium transition-colors',
    'disabled:cursor-not-allowed disabled:opacity-55',
    VARIANTS[variant],
    SIZES[size],
    className,
  )
}
