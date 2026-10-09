import { useTranslation } from 'react-i18next'
import { Bar, BarChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { formatDate, formatNumber } from '../../lib/format'
import { ChartTooltip } from '../../components/charts/ChartTooltip'

const axis = { fontSize: 12, fill: 'var(--text-3)' }
const atNoon = (date) => `${date}T12:00:00Z`

/**
 * New customer companies and users per week: two counts on one axis, grouped bars with
 * a 2 px gap, a legend with the 12-week totals, a tooltip per week and a table for screen readers.
 */
export function SignupsChart({ weeks }) {
  const { t } = useTranslation()
  const series = [
    { key: 'companies', label: t('admin.signups.companies'), color: 'var(--series-1)' },
    { key: 'users', label: t('admin.signups.users'), color: 'var(--series-2)' },
  ]
  const total = (key) => weeks.reduce((sum, week) => sum + week[key], 0)
  const weekLabel = (date) => t('admin.signups.week', { date: formatDate(atNoon(date)) })

  return (
    <>
      <div className="mb-3 flex flex-wrap gap-x-5 gap-y-1 text-[12.5px] text-ink-2">
        {series.map((s) => (
          <span key={s.key} className="inline-flex items-center gap-2">
            <span className="size-2.5 rounded-sm" style={{ background: s.color }} aria-hidden="true" />
            {s.label}
            <span className="tabular font-medium text-ink">{formatNumber(total(s.key))}</span>
          </span>
        ))}
      </div>
      <div aria-hidden="true">
        <ResponsiveContainer width="100%" height={200}>
          <BarChart data={weeks} margin={{ top: 4, right: 4, bottom: 0, left: 0 }} barGap={2} barCategoryGap="24%">
            <CartesianGrid vertical={false} stroke="var(--border)" />
            <XAxis
              dataKey="week"
              tickFormatter={(date) => formatDate(atNoon(date), 'dayMonth')}
              tick={axis}
              tickLine={false}
              axisLine={{ stroke: 'var(--border-strong)' }}
              minTickGap={16}
            />
            <YAxis tick={axis} tickLine={false} axisLine={false} width={28} allowDecimals={false} />
            <Tooltip
              cursor={{ fill: 'var(--surface-2)' }}
              content={
                <ChartTooltip
                  formatLabel={weekLabel}
                  rows={(d) => series.map((s) => ({ label: s.label, value: formatNumber(d[s.key]), swatch: s.color }))}
                />
              }
            />
            {series.map((s) => (
              <Bar key={s.key} dataKey={s.key} fill={s.color} radius={[4, 4, 0, 0]} maxBarSize={14} isAnimationActive={false} />
            ))}
          </BarChart>
        </ResponsiveContainer>
      </div>
      <table className="sr-only">
        <caption>{t('admin.signups.description')}</caption>
        <thead>
          <tr>
            <th scope="col">{t('admin.signups.weekColumn')}</th>
            {series.map((s) => <th key={s.key} scope="col">{s.label}</th>)}
          </tr>
        </thead>
        <tbody>
          {weeks.map((week) => (
            <tr key={week.week}>
              <th scope="row">{weekLabel(week.week)}</th>
              {series.map((s) => <td key={s.key}>{week[s.key]}</td>)}
            </tr>
          ))}
        </tbody>
      </table>
    </>
  )
}
