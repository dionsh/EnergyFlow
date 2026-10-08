import { Link } from 'react-router-dom'
import { cn } from '../../lib/cn'

const FILL = {
  series: 'bg-[var(--series-1)]',
  waste: 'bg-critical/60',
  warning: 'bg-warning',
  good: 'bg-good',
}

/**
 * Ranked horizontal bars with direct labels (same grammar as the live power flow).
 * `rows`: [{ key, label, value, display, hint?, to?, icon? }]; bars are relative to the largest value.
 */
export function BarList({ rows, tone = 'series', className }) {
  const max = Math.max(0, ...rows.map((row) => row.value))
  return (
    <ul className={cn('flex flex-col gap-2', className)}>
      {rows.map((row) => {
        const content = (
          <>
            <span className="flex min-w-0 items-center gap-2">
              {row.icon}
              <span className="truncate text-[13px] text-ink">{row.label}</span>
              {row.hint && <span className="shrink-0 text-xs text-ink-3">{row.hint}</span>}
            </span>
            <span className="shrink-0 text-[13px] font-medium tabular text-ink">{row.display}</span>
            <span className="relative col-span-2 h-2 overflow-hidden rounded-full bg-surface-2" aria-hidden="true">
              <span
                className={cn('absolute inset-y-0 left-0 rounded-full transition-[width] duration-300', FILL[tone])}
                style={{ width: `${max > 0 ? Math.max(2, (row.value / max) * 100) : 0}%` }}
              />
            </span>
          </>
        )
        return (
          <li key={row.key}>
            {row.to ? (
              <Link to={row.to} className="-mx-1.5 grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-3 gap-y-1.5 rounded-sm px-1.5 py-1 hover:bg-surface-2">
                {content}
              </Link>
            ) : (
              <div className="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-3 gap-y-1.5 py-1">{content}</div>
            )}
          </li>
        )
      })}
    </ul>
  )
}
