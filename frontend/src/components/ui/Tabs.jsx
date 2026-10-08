import { cn } from '../../lib/cn'

/** Page-level tabs with an underline (Settings, Waste & Alerts, Carbon & ESG). `items`: [{ key, label, count? }] */
export function Tabs({ items, value, onChange, className }) {
  // The inner strip overlaps the outer hairline by 1 px (so the active underline sits on it)
  // and scrolls sideways on narrow screens without a vertical scrollbar.
  return (
    <div className={cn('mb-6 border-b border-line', className)}>
      <div role="tablist" className="-mb-px flex gap-1 overflow-x-auto overflow-y-hidden [scrollbar-width:none]">
        {items.map((item) => (
          <button
            key={item.key}
            role="tab"
            type="button"
            aria-selected={value === item.key}
            onClick={() => onChange(item.key)}
            className={cn(
              'inline-flex h-10 shrink-0 items-center gap-2 border-b-2 px-3 text-sm font-medium transition-colors',
              value === item.key ? 'border-brand text-ink' : 'border-transparent text-ink-2 hover:text-ink',
            )}
          >
            {item.label}
            {item.count > 0 && (
              <span className="inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-surface-2 px-1.5 text-[11px] font-semibold tabular text-ink-2">
                {item.count}
              </span>
            )}
          </button>
        ))}
      </div>
    </div>
  )
}

/** Compact segmented control for periods and ranges. `items`: [{ key, label }] */
export function Segmented({ items, value, onChange, label, className }) {
  return (
    <div role="tablist" aria-label={label} className={cn('flex rounded-sm border border-line-strong p-0.5', className)}>
      {items.map((item) => (
        <button
          key={item.key}
          role="tab"
          type="button"
          aria-selected={value === item.key}
          onClick={() => onChange(item.key)}
          className={cn('h-7 whitespace-nowrap rounded-[3px] px-2.5 text-xs font-medium', value === item.key ? 'bg-surface-2 text-ink' : 'text-ink-3 hover:text-ink')}
        >
          {item.label}
        </button>
      ))}
    </div>
  )
}
