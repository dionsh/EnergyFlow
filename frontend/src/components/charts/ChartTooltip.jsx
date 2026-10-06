/** Shared tooltip: value + unit + time, in text tokens (never the series colour). */
export function ChartTooltip({ active, payload, label, formatLabel, rows }) {
  if (!active || !payload?.length) return null
  const datum = payload[0].payload
  return (
    <div className="rounded-md border border-line bg-surface px-3 py-2 text-[12.5px] shadow-overlay">
      <p className="mb-1 font-medium text-ink">{formatLabel ? formatLabel(label, datum) : label}</p>
      {rows(datum).map((row) => (
        <p key={row.label} className="flex items-center justify-between gap-6 text-ink-2">
          <span className="flex items-center gap-1.5">
            {row.swatch && <span className="inline-block h-0.5 w-3 rounded-full" style={{ background: row.swatch }} aria-hidden="true" />}
            {row.label}
          </span>
          <span className="tabular font-medium text-ink">{row.value}</span>
        </p>
      ))}
    </div>
  )
}
