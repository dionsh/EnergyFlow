import { useTranslation } from 'react-i18next'
import { Bar, BarChart, CartesianGrid, Cell, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { formatCo2, formatKwh, formatNumber } from '../../lib/format'
import { ChartTooltip } from '../../components/charts/ChartTooltip'

const axis = { fontSize: 12, fill: 'var(--text-3)' }

function monthLabel(t, month) {
  const [year, m] = month.split('-').map(Number)
  return `${t('calendar.monthsShort', { returnObjects: true })[m - 1]} ${String(year).slice(2)}`
}

/** CO₂e per month from the ledger; months that are only partly measured are drawn lighter and say so. */
export function MonthlyCarbonChart({ months }) {
  const { t } = useTranslation()
  const data = months.map((m) => {
    const [year, month] = m.month.split('-').map(Number)
    const full = new Date(Date.UTC(year, month, 0)).getUTCDate()
    return { ...m, t: m.co2_kg / 1000, partial: m.days < full - 1 }
  })
  return (
    <ResponsiveContainer width="100%" height={220}>
      <BarChart data={data} margin={{ top: 8, right: 8, bottom: 0, left: 0 }}>
        <CartesianGrid vertical={false} stroke="var(--border)" />
        <XAxis dataKey="month" tickFormatter={(m) => monthLabel(t, m)} tick={axis} tickLine={false} axisLine={{ stroke: 'var(--border-strong)' }} />
        <YAxis tick={axis} tickLine={false} axisLine={false} width={40} tickFormatter={(v) => formatNumber(v, 1)} />
        <Tooltip
          cursor={{ fill: 'var(--surface-2)' }}
          content={
            <ChartTooltip
              formatLabel={(m) => monthLabel(t, m)}
              rows={(d) => [
                { label: 'CO₂e', value: formatCo2(d.co2_kg), swatch: 'var(--series-1)' },
                { label: t('carbonPage.tiles.electricity'), value: formatKwh(d.kwh) },
                ...(d.partial ? [{ label: t('carbonPage.partialMonth', { days: d.days }), value: '' }] : []),
              ]}
            />
          }
        />
        <Bar dataKey="t" radius={[4, 4, 0, 0]} maxBarSize={36} isAnimationActive={false}>
          {data.map((d) => <Cell key={d.month} fill="var(--series-1)" fillOpacity={d.partial ? 0.4 : 1} />)}
        </Bar>
      </BarChart>
    </ResponsiveContainer>
  )
}
