import { useTranslation } from 'react-i18next'
import { Bar, CartesianGrid, ComposedChart, Line, ReferenceArea, ReferenceLine, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { formatDate, formatKwh, formatNumber } from '../../lib/format'
import { ChartTooltip } from '../../components/charts/ChartTooltip'

const axis = { fontSize: 12, fill: 'var(--text-3)' }
const dayIso = (date) => `${date}T12:00:00Z`

/**
 * Daily energy of one machine before and after an action: measured bars, the
 * adjusted-baseline model as a neutral line, the baseline period as a labelled wash.
 */
export function ImpactChart({ daily }) {
  const { t } = useTranslation()
  const firstReporting = daily.find((d) => d.phase === 'reporting')?.d
  const baseline = daily.filter((d) => d.phase === 'baseline')
  return (
    <div>
      <div className="mb-2 flex flex-wrap items-center gap-x-5 gap-y-1 text-[12.5px] text-ink-2">
        <span className="inline-flex items-center gap-2"><span className="size-2.5 rounded-sm bg-[var(--series-1)]" aria-hidden="true" />{t('impactPage.legendActual')}</span>
        <span className="inline-flex items-center gap-2"><span className="h-0.5 w-4 rounded-full bg-ink-3" aria-hidden="true" />{t('impactPage.legendModel')}</span>
        <span className="ml-auto text-ink-3">kWh</span>
      </div>
      <ResponsiveContainer width="100%" height={240}>
        <ComposedChart data={daily} margin={{ top: 16, right: 8, bottom: 0, left: 0 }}>
          <CartesianGrid vertical={false} stroke="var(--border)" />
          {baseline.length > 0 && (
            <ReferenceArea x1={baseline[0].d} x2={baseline[baseline.length - 1].d} fill="var(--surface-2)" fillOpacity={1} />
          )}
          <XAxis dataKey="d" tickFormatter={(d) => formatDate(dayIso(d), 'dayMonth')} tick={axis} tickLine={false} axisLine={{ stroke: 'var(--border-strong)' }} minTickGap={24} />
          <YAxis tick={axis} tickLine={false} axisLine={false} width={40} tickFormatter={(v) => formatNumber(v, 0)} />
          {firstReporting && (
            <ReferenceLine x={firstReporting} stroke="var(--text-2)" strokeDasharray="3 3" label={{ value: t('impactPage.marker'), position: 'insideTopLeft', fontSize: 11, fill: 'var(--text-2)' }} />
          )}
          <Tooltip
            cursor={{ fill: 'var(--surface-2)' }}
            content={
              <ChartTooltip
                formatLabel={(d) => formatDate(dayIso(d))}
                rows={(d) => [
                  { label: t('impactPage.legendActual'), value: formatKwh(d.actual), swatch: 'var(--series-1)' },
                  { label: t('impactPage.legendModel'), value: formatKwh(d.model), swatch: 'var(--text-3)' },
                ]}
              />
            }
          />
          <Bar dataKey="actual" fill="var(--series-1)" radius={[3, 3, 0, 0]} maxBarSize={14} isAnimationActive={false} />
          <Line dataKey="model" type="stepAfter" stroke="var(--text-3)" strokeWidth={1.5} dot={false} isAnimationActive={false} />
        </ComposedChart>
      </ResponsiveContainer>
    </div>
  )
}
