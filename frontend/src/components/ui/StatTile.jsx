import { TrendingDown, TrendingUp } from 'lucide-react'
import { cn } from '../../lib/cn'
import { Skeleton } from './States'

/**
 * Label → value + unit → context line. Values use proportional figures (docs/06 §4.4).
 * `delta` = { text, direction: 'up' | 'down', good: boolean } — colour only when meaning is clear.
 */
export function StatTile({ label, value, unit, context, delta, loading, className }) {
  // A formatted value carries its unit after a no-break space ("672 kg CO₂e"): show that unit like `unit`,
  // smaller and free to move to the next line on a narrow tile.
  const [main, ...rest] = typeof value === 'string' && !unit ? value.split(' ') : [value]
  const suffix = unit ?? (rest.length > 0 ? rest.join(' ') : null)
  return (
    <div className={cn('flex min-w-0 flex-col gap-1 rounded-md border border-line bg-surface px-4 py-3.5', className)}>
      <p className="truncate text-[13px] font-medium text-ink-2">{label}</p>
      {loading ? (
        <Skeleton className="my-1 h-7 w-24" />
      ) : (
        <p className="flex flex-wrap items-baseline gap-x-1.5 text-ink">
          <span className="text-[24px] font-semibold leading-tight tracking-[-0.01em] sm:text-[26px]">{main}</span>
          {suffix && <span className="text-sm text-ink-2">{suffix}</span>}
        </p>
      )}
      {delta && !loading && (
        <p className={cn('flex items-center gap-1 text-[12.5px]', delta.good ? 'text-good-text' : 'text-critical-text')}>
          {delta.direction === 'up' ? <TrendingUp className="size-3.5" aria-hidden="true" /> : <TrendingDown className="size-3.5" aria-hidden="true" />}
          {delta.text}
        </p>
      )}
      {context && !loading && <p className="line-clamp-2 text-[12.5px] text-ink-3">{context}</p>}
    </div>
  )
}
