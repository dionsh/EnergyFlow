import { useTranslation } from 'react-i18next'
import { Area, AreaChart, Bar, BarChart, CartesianGrid, Line, LineChart, ReferenceArea, ReferenceLine, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { formatCo2, formatDate, formatEur, formatKw, formatKwh, formatNumber, formatTime } from '../../lib/format'
import { ChartTooltip } from '../../components/charts/ChartTooltip'

const axis = { fontSize: 12, fill: 'var(--text-3)' }
const refLabel = (value, position = 'insideTopLeft') => ({ value, position, fontSize: 11, fill: 'var(--text-3)' })
// Local dates (YYYY-MM-DD) as noon UTC, so day labels never shift across time zones.
const dayIso = (date) => `${date}T12:00:00Z`

/** Waste per day (kWh), drawn in the critical hue at reduced opacity (docs/06 §4.3). */
export function DailyWasteChart({ days }) {
  const { t } = useTranslation()
  return (
    <ResponsiveContainer width="100%" height={220}>
      <BarChart data={days} margin={{ top: 8, right: 8, bottom: 0, left: 0 }}>
        <CartesianGrid vertical={false} stroke="var(--border)" />
        <XAxis dataKey="date" tickFormatter={(d) => formatDate(dayIso(d), 'dayMonth')} tick={axis} tickLine={false} axisLine={{ stroke: 'var(--border-strong)' }} minTickGap={20} />
        <YAxis tick={axis} tickLine={false} axisLine={false} width={44} tickFormatter={(v) => formatNumber(v, 0)} />
        <Tooltip
          cursor={{ fill: 'var(--surface-2)' }}
          content={
            <ChartTooltip
              formatLabel={(d) => formatDate(dayIso(d))}
              rows={(d) => [
                { label: t('wasteDetail.energy'), value: formatKwh(d.kwh), swatch: 'var(--critical)' },
                { label: t('wasteDetail.cost'), value: formatEur(d.eur) },
                { label: t('wasteDetail.co2'), value: formatCo2(d.co2_kg) },
              ]}
            />
          }
        />
        <Bar dataKey="kwh" fill="var(--critical)" fillOpacity={0.6} radius={[4, 4, 0, 0]} maxBarSize={22} isAnimationActive={false} />
      </BarChart>
    </ResponsiveContainer>
  )
}

/** The episode with context: power over time, the out-of-schedule window as a labelled neutral wash. */
export function EpisodeChart({ points, scheduleEnd, start, end, resolution }) {
  const { t } = useTranslation()
  // Numeric time axis, so boundaries that fall between samples still render.
  const data = points.map((p) => ({ ts: Date.parse(p.t), kw: p.kw }))
  const first = data[0]?.ts
  const last = data[data.length - 1]?.ts
  const washFrom = Date.parse(scheduleEnd ?? start)
  const washTo = end ? Math.min(Date.parse(end), last) : last
  const shiftEnd = scheduleEnd ? Date.parse(scheduleEnd) : null
  const label = (v) => (resolution === '1m' ? formatTime(v) : formatDate(v, 'dateTime'))
  return (
    <ResponsiveContainer width="100%" height={200}>
      <AreaChart data={data} margin={{ top: 16, right: 8, bottom: 0, left: 0 }}>
        <CartesianGrid vertical={false} stroke="var(--border)" />
        <XAxis dataKey="ts" type="number" scale="time" domain={['dataMin', 'dataMax']} tickFormatter={formatTime} tick={axis} tickLine={false} axisLine={{ stroke: 'var(--border-strong)' }} minTickGap={36} />
        <YAxis tick={axis} tickLine={false} axisLine={false} width={36} tickFormatter={(v) => formatNumber(v, 0)} />
        {first !== undefined && washFrom < washTo && (
          <ReferenceArea x1={Math.max(washFrom, first)} x2={washTo} fill="var(--surface-2)" fillOpacity={1} ifOverflow="hidden" />
        )}
        {shiftEnd !== null && shiftEnd > first && (
          <ReferenceLine x={shiftEnd} stroke="var(--text-3)" strokeDasharray="3 3" label={refLabel(t('wasteDetail.shiftEnd'), 'insideTopRight')} />
        )}
        <Tooltip
          cursor={{ stroke: 'var(--border-strong)' }}
          content={<ChartTooltip formatLabel={label} rows={(d) => [{ label: t('wasteDetail.chartPower'), value: formatKw(d.kw), swatch: 'var(--series-1)' }]} />}
        />
        <Area type="monotone" dataKey="kw" stroke="var(--series-1)" strokeWidth={2} fill="var(--series-1)" fillOpacity={0.08} isAnimationActive={false} />
      </AreaChart>
    </ResponsiveContainer>
  )
}

/** Daily running power against the machine's own reference (neutral line, direct label). */
export function DriftChart({ daily, referenceKw, onset }) {
  const { t } = useTranslation()
  const values = daily.map((d) => d.kw)
  const min = Math.min(referenceKw, ...values)
  const max = Math.max(referenceKw, ...values)
  const pad = (max - min) * 0.25 || 1
  return (
    <ResponsiveContainer width="100%" height={200}>
      <LineChart data={daily} margin={{ top: 16, right: 8, bottom: 0, left: 0 }}>
        <CartesianGrid vertical={false} stroke="var(--border)" />
        <XAxis dataKey="d" tickFormatter={(d) => formatDate(dayIso(d), 'dayMonth')} tick={axis} tickLine={false} axisLine={{ stroke: 'var(--border-strong)' }} minTickGap={28} />
        <YAxis tick={axis} tickLine={false} axisLine={false} width={40} domain={[Math.floor((min - pad) * 2) / 2, Math.ceil((max + pad) * 2) / 2]} tickFormatter={(v) => formatNumber(v, 1)} />
        <ReferenceLine y={referenceKw} stroke="var(--text-3)" strokeWidth={1.5} label={refLabel(`${t('wasteDetail.reference')} ${formatKw(referenceKw)}`, 'insideBottomLeft')} />
        {onset && <ReferenceLine x={onset} stroke="var(--text-3)" strokeDasharray="3 3" />}
        <Tooltip
          cursor={{ stroke: 'var(--border-strong)' }}
          content={<ChartTooltip formatLabel={(d) => formatDate(dayIso(d))} rows={(d) => [{ label: t('wasteDetail.chartRunning'), value: formatKw(d.kw), swatch: 'var(--series-1)' }]} />}
        />
        <Line dataKey="kw" stroke="var(--series-1)" strokeWidth={2} dot={false} isAnimationActive={false} />
      </LineChart>
    </ResponsiveContainer>
  )
}
