import { cn } from '../../lib/cn'

export function LogoMark({ className }) {
  return (
    <svg viewBox="0 0 32 32" className={cn('size-7 shrink-0', className)} aria-hidden="true">
      <rect width="32" height="32" rx="7" fill="var(--brand)" />
      <path
        d="M6 20.5c3.5 0 3.5-9 7-9s3.5 9 7 9 3.5-9 6-9"
        fill="none"
        stroke="var(--on-brand)"
        strokeWidth="2.6"
        strokeLinecap="round"
        strokeLinejoin="round"
      />
    </svg>
  )
}

export function Logo({ className }) {
  return (
    <span className={cn('inline-flex items-center gap-2.5', className)}>
      <LogoMark />
      <span className="text-[17px] font-semibold tracking-[-0.01em] text-ink">EnergyFlow</span>
    </span>
  )
}
