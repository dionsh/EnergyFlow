import { LoaderCircle } from 'lucide-react'
import { cn } from '../../lib/cn'
import { buttonClasses } from './buttonStyles'

export function Button({
  ref,
  variant = 'primary',
  size = 'md',
  loading = false,
  icon: Icon,
  type = 'button',
  className,
  disabled,
  children,
  ...props
}) {
  return (
    <button
      ref={ref}
      type={type}
      disabled={disabled || loading}
      className={buttonClasses({ variant, size, className })}
      {...props}
    >
      {loading ? (
        <LoaderCircle className="size-4 animate-spin" aria-hidden="true" />
      ) : (
        Icon && <Icon className="size-4 shrink-0" aria-hidden="true" />
      )}
      {children}
    </button>
  )
}

export function IconButton({ label, icon: Icon, className, ...props }) {
  return (
    <button
      type="button"
      aria-label={label}
      title={label}
      className={cn(
        'inline-flex size-9 items-center justify-center rounded-sm text-ink-2 transition-colors',
        'hover:bg-surface-2 hover:text-ink disabled:cursor-not-allowed disabled:opacity-55',
        className,
      )}
      {...props}
    >
      <Icon className="size-[18px]" aria-hidden="true" />
    </button>
  )
}
