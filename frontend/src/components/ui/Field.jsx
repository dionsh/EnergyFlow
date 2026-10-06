import { useId, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { CircleAlert, Eye, EyeOff } from 'lucide-react'
import { cn } from '../../lib/cn'

const inputBase =
  'h-9 w-full rounded-sm border bg-surface px-3 text-sm text-ink placeholder:text-ink-3 ' +
  'transition-colors focus:outline-none focus-visible:outline-2 focus-visible:outline-offset-0 ' +
  'disabled:cursor-not-allowed disabled:bg-surface-2 disabled:text-ink-2'

export function Input({ invalid, className, ...props }) {
  return (
    <input
      aria-invalid={invalid || undefined}
      className={cn(inputBase, invalid ? 'border-critical' : 'border-line-strong hover:border-ink-3', className)}
      {...props}
    />
  )
}

export function Select({ invalid, className, children, ...props }) {
  return (
    <select
      aria-invalid={invalid || undefined}
      className={cn(inputBase, 'pr-8', invalid ? 'border-critical' : 'border-line-strong hover:border-ink-3', className)}
      {...props}
    >
      {children}
    </select>
  )
}

/**
 * Label + control + hint/error. `error` is already a translated message.
 * Units go in `suffix` (e.g. "€", "kW") so the number field stays clean.
 * A custom control can be passed as `children({ id, invalid, describedBy })`.
 */
export function Field({ label, hint, error, optional, suffix, className, children, ...inputProps }) {
  const { t } = useTranslation()
  const id = useId()
  const describedBy = error ? `${id}-error` : hint ? `${id}-hint` : undefined

  const control =
    typeof children === 'function' ? (
      children({ id, invalid: Boolean(error), describedBy })
    ) : (
      <div className="relative">
        <Input id={id} invalid={Boolean(error)} aria-describedby={describedBy} className={suffix && 'pr-10'} {...inputProps} />
        {suffix && (
          <span className="pointer-events-none absolute inset-y-0 right-3 flex items-center text-[13px] text-ink-3">{suffix}</span>
        )}
      </div>
    )

  return (
    <div className={cn('flex flex-col gap-1.5', className)}>
      <label htmlFor={id} className="text-[13px] font-medium text-ink">
        {label}
        {optional && <span className="ml-1 font-normal text-ink-3">({t('common.optional')})</span>}
      </label>
      {control}
      {error ? (
        <p id={`${id}-error`} className="flex items-start gap-1.5 text-[13px] text-critical-text">
          <CircleAlert className="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />
          {error}
        </p>
      ) : (
        hint && (
          <p id={`${id}-hint`} className="text-[13px] text-ink-3">
            {hint}
          </p>
        )
      )}
    </div>
  )
}

export function PasswordField({ label, hint, error, optional, className, ...inputProps }) {
  const { t } = useTranslation()
  const [visible, setVisible] = useState(false)
  return (
    <Field label={label} hint={hint} error={error} optional={optional} className={className}>
      {({ id, invalid, describedBy }) => (
        <div className="relative">
          <Input
            id={id}
            type={visible ? 'text' : 'password'}
            invalid={invalid}
            aria-describedby={describedBy}
            className="pr-10"
            {...inputProps}
          />
          <button
            type="button"
            onClick={() => setVisible((v) => !v)}
            className="absolute inset-y-0 right-0 flex w-10 items-center justify-center text-ink-3 hover:text-ink"
            aria-label={visible ? t('auth.hidePassword') : t('auth.showPassword')}
          >
            {visible ? <EyeOff className="size-4" aria-hidden="true" /> : <Eye className="size-4" aria-hidden="true" />}
          </button>
        </div>
      )}
    </Field>
  )
}
