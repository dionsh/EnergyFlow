import { useTranslation } from 'react-i18next'
import { Area, CartesianGrid, ComposedChart, Line, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { formatKw, formatNumber, formatTime } from '../../lib/format'
import { ChartTooltip } from '../../components/charts/ChartTooltip'

const axis = { fontSize: 12, fill: 'var(--text-3)' }

/** Site power today vs the typical curve for this kind of day (neutral band + line, direct legend). */
export function TodayChart({ curve }) {
  const { t } = useTranslation()
  const data = curve.points.map((p) => ({
    ...p,
    range: p.typical_min === null ? null : [p.typical_min, p.typical_max],
  }))

  return (
    <div>
      <div className="mb-3 flex flex-wrap items-center gap-x-5 gap-y-1 text-[12.5px] text-ink-2">
        <span className="inline-flex items-center gap-2"><span className="h-0.5 w-4 rounded-full bg-[var(--series-1)]" aria-hidden="true" />{t('overviewData.legendToday')}</span>
        <span className="inline-flex items-center gap-2"><span className="h-0.5 w-4 rounded-full bg-ink-3" aria-hidden="true" />{t('overviewData.legendTypical')}</span>
        <span className="inline-flex items-center gap-2"><span className="h-2.5 w-4 rounded-sm bg-surface-2" aria-hidden="true" />{t('overviewData.legendRange')}</span>
        <span className="ml-auto text-ink-3">kW</span>
      </div>
      <ResponsiveContainer width="100%" height={250}>
        <ComposedChart data={data} margin={{ top: 4, right: 8, bottom: 0, left: 0 }}>
          <CartesianGrid vertical={false} stroke="var(--border)" />
          <XAxis dataKey="t" tickFormatter={formatTime} tick={axis} tickLine={false} axisLine={{ stroke: 'var(--border-strong)' }} interval={11} />
          <YAxis tick={axis} tickLine={false} axisLine={false} width={44} tickFormatter={(v) => formatNumber(v, 0)} unit="" />
          <Tooltip
            cursor={{ stroke: 'var(--border-strong)' }}
            content={
              <ChartTooltip
                formatLabel={formatTime}
                rows={(d) => [
                  { label: t('overviewData.legendToday'), value: d.kw === null ? '—' : formatKw(d.kw), swatch: 'var(--series-1)' },
                  { label: t('overviewData.legendTypical'), value: d.typical_kw === null ? '—' : formatKw(d.typical_kw), swatch: 'var(--text-3)' },
                ]}
              />
            }
          />
          <Area dataKey="range" stroke="none" fill="var(--surface-2)" fillOpacity={1} isAnimationActive={false} />
          <Line dataKey="typical_kw" stroke="var(--text-3)" strokeWidth={1.5} dot={false} isAnimationActive={false} />
          <Line dataKey="kw" stroke="var(--series-1)" strokeWidth={2} dot={false} isAnimationActive={false} connectNulls={false} />
        </ComposedChart>
      </ResponsiveContainer>
    </div>
  )
}
