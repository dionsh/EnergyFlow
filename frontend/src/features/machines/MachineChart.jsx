import { useTranslation } from 'react-i18next'
import { Area, AreaChart, Bar, BarChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { formatDate, formatKw, formatKwh, formatNumber, formatTime } from '../../lib/format'
import { ChartTooltip } from '../../components/charts/ChartTooltip'

const axis = { fontSize: 12, fill: 'var(--text-3)' }

/** 24 h / 7 d → power over time (kW); 30 d → energy per day (kWh). One y-axis per chart. */
export function MachineChart({ points, range }) {
  const { t } = useTranslation()
  if (range === '30d') {
    return (
      <ResponsiveContainer width="100%" height={260}>
        <BarChart data={points} margin={{ top: 8, right: 8, bottom: 0, left: 0 }}>
          <CartesianGrid vertical={false} stroke="var(--border)" />
          <XAxis dataKey="t" tickFormatter={(v) => formatDate(v, 'dayMonth')} tick={axis} tickLine={false} axisLine={{ stroke: 'var(--border-strong)' }} minTickGap={24} />
          <YAxis tick={axis} tickLine={false} axisLine={false} width={48} tickFormatter={(v) => formatNumber(v, 0)} />
          <Tooltip
            cursor={{ fill: 'var(--surface-2)' }}
            content={<ChartTooltip formatLabel={(v) => formatDate(v)} rows={(d) => [{ label: t('machineDetail.kwh'), value: formatKwh(d.kwh), swatch: 'var(--series-1)' }]} />}
          />
          <Bar dataKey="kwh" fill="var(--series-1)" radius={[4, 4, 0, 0]} maxBarSize={22} />
        </BarChart>
      </ResponsiveContainer>
    )
  }
  return (
    <ResponsiveContainer width="100%" height={260}>
      <AreaChart data={points} margin={{ top: 8, right: 8, bottom: 0, left: 0 }}>
        <CartesianGrid vertical={false} stroke="var(--border)" />
        <XAxis
          dataKey="t"
          tickFormatter={(v) => (range === '24h' ? formatTime(v) : formatDate(v, 'weekdayDay'))}
          tick={axis}
          tickLine={false}
          axisLine={{ stroke: 'var(--border-strong)' }}
          minTickGap={32}
        />
        <YAxis tick={axis} tickLine={false} axisLine={false} width={48} tickFormatter={(v) => formatNumber(v, 0)} />
        <Tooltip
          cursor={{ stroke: 'var(--border-strong)' }}
          content={
            <ChartTooltip
              formatLabel={(v) => (range === '24h' ? formatTime(v) : formatDate(v, 'dateTime'))}
              rows={(d) => [{ label: t('machineDetail.kw'), value: formatKw(d.kw), swatch: 'var(--series-1)' }]}
            />
          }
        />
        <Area type="monotone" dataKey="kw" stroke="var(--series-1)" strokeWidth={2} fill="var(--series-1)" fillOpacity={0.08} isAnimationActive={false} />
      </AreaChart>
    </ResponsiveContainer>
  )
}
