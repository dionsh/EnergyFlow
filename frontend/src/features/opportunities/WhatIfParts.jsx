import { useTranslation } from 'react-i18next'
import { Bar, BarChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { Info } from 'lucide-react'
import { formatCo2, formatDate, formatEur, formatKw, formatKwh, formatNumber, formatPercent } from '../../lib/format'
import { ChartTooltip } from '../../components/charts/ChartTooltip'
import { Callout } from '../../components/ui/States'

const axis = { fontSize: 12, fill: 'var(--text-3)' }
const dayIso = (date) => `${date}T12:00:00Z`

/** Before → after on one track (docs/06 §3.5: a dumbbell for kWh, € and CO₂). */
function Dumbbell({ label, before, after, format }) {
  const max = Math.max(before, after, 1e-6)
  const b = (before / max) * 100
  const a = (after / max) * 100
  return (
    <div className="grid grid-cols-[72px_minmax(0,1fr)] items-center gap-x-3 gap-y-1 py-1.5 sm:grid-cols-[72px_minmax(0,1fr)_auto]">
      <span className="text-[13px] text-ink-2">{label}</span>
      <span className="relative mx-1.5 h-4" aria-hidden="true">
        <span className="absolute inset-x-0 top-1/2 h-px -translate-y-1/2 bg-line" />
        <span className="absolute top-1/2 h-1 -translate-y-1/2 rounded-full bg-brand-subtle" style={{ left: `${Math.min(a, b)}%`, width: `${Math.abs(b - a)}%` }} />
        <span className="absolute top-1/2 size-3 -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-surface bg-[var(--series-other)]" style={{ left: `${b}%` }} />
        <span className="absolute top-1/2 size-3 -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-surface bg-brand" style={{ left: `${a}%` }} />
      </span>
      <span className="col-span-2 text-right text-[13px] tabular sm:col-span-1">
        <span className="text-ink-3">{format(before)}</span>
        <span className="mx-1 text-ink-3">→</span>
        <span className="font-medium text-ink">{format(after)}</span>
      </span>
    </div>
  )
}

function Savings({ label, values, tag }) {
  const { t } = useTranslation()
  return (
    <div className="rounded-md border border-line px-3.5 py-3">
      <p className="text-xs text-ink-2">{label}</p>
      <p className="mt-0.5 text-[22px] font-semibold leading-tight tracking-[-0.01em] text-ink">{formatEur(values.eur)}</p>
      <p className="mt-0.5 text-[12.5px] text-ink-2">
        {tag === 'eur' ? t('opportunities.tag.eur') : `${formatKwh(values.kwh)} · ${formatCo2(values.co2_kg)}`}
      </p>
    </div>
  )
}

/** Daily energy as it ran vs with the change: the grey part above each blue bar is what the change saves. */
export function ReplayChart({ daily, height = 200 }) {
  const { t } = useTranslation()
  return (
    <div>
      <div className="mb-2 flex flex-wrap items-center gap-x-5 gap-y-1 text-[12.5px] text-ink-2">
        <span className="inline-flex items-center gap-2"><span className="size-2.5 rounded-sm bg-[var(--series-other)]" aria-hidden="true" />{t('whatIf.legendActual')}</span>
        <span className="inline-flex items-center gap-2"><span className="size-2.5 rounded-sm bg-[var(--series-1)]" aria-hidden="true" />{t('whatIf.legendScenario')}</span>
        <span className="ml-auto text-ink-3">{t('whatIf.chartUnit')}</span>
      </div>
      <ResponsiveContainer width="100%" height={height}>
        <BarChart data={daily} barGap="-100%" margin={{ top: 4, right: 4, bottom: 0, left: 0 }}>
          <CartesianGrid vertical={false} stroke="var(--border)" />
          <XAxis dataKey="date" tickFormatter={(d) => formatDate(dayIso(d), 'dayMonth')} tick={axis} tickLine={false} axisLine={{ stroke: 'var(--border-strong)' }} minTickGap={24} />
          <YAxis tick={axis} tickLine={false} axisLine={false} width={40} tickFormatter={(v) => formatNumber(v, 0)} />
          <Tooltip
            cursor={{ fill: 'var(--surface-2)' }}
            content={
              <ChartTooltip
                formatLabel={(d) => formatDate(dayIso(d))}
                rows={(d) => [
                  { label: t('whatIf.legendActual'), value: formatKwh(d.baseline_kwh), swatch: 'var(--series-other)' },
                  { label: t('whatIf.legendScenario'), value: formatKwh(d.scenario_kwh), swatch: 'var(--series-1)' },
                ]}
              />
            }
          />
          <Bar dataKey="baseline_kwh" fill="var(--series-other)" fillOpacity={0.55} radius={[3, 3, 0, 0]} maxBarSize={18} isAnimationActive={false} />
          <Bar dataKey="scenario_kwh" fill="var(--series-1)" radius={[3, 3, 0, 0]} maxBarSize={18} isAnimationActive={false} />
        </BarChart>
      </ResponsiveContainer>
    </div>
  )
}

const pct = (value) => formatPercent(value, 0)

/** Plain-language assumptions: what was measured and what is assumed (and editable). */
export function Assumptions({ result }) {
  const { t } = useTranslation()
  const text = (a) => {
    const p = a.params ?? {}
    return t(`whatIf.assumption.${a.key}`, {
      grace: p.grace_min,
      share: p.leak_share !== undefined ? pct(p.leak_share) : p.repair_share !== undefined ? pct(p.repair_share) : p.share !== undefined ? pct(p.share) : undefined,
      loaded: p.loaded_kw === undefined ? undefined : formatKw(p.loaded_kw),
      unloaded: p.unloaded_kw === undefined ? undefined : formatKw(p.unloaded_kw),
      pct: p.pct === undefined ? undefined : formatNumber(p.pct, 1),
    })
  }
  return (
    <div className="flex flex-col gap-2">
      <ul className="flex flex-col gap-1.5 border-l-2 border-line pl-3 text-[13px] leading-snug text-ink-2">
        {result.assumptions.map((a) => <li key={a.key}>{text(a)}</li>)}
      </ul>
      {result.caveats.map((c) => (
        <Callout key={c} tone="info" icon={Info} className="text-[13px]">{t(`whatIf.caveat.${c}`)}</Callout>
      ))}
    </div>
  )
}

/** The whole answer to "what if?": before → after, per month and per year, day by day. */
export function WhatIfResult({ result }) {
  const { t } = useTranslation()
  return (
    <div className="flex flex-col gap-5">
      <div>
        <div className="mb-1 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-ink-3">
          <span className="inline-flex items-center gap-1.5"><span className="size-2.5 rounded-full bg-[var(--series-other)]" aria-hidden="true" />{t('whatIf.today')}</span>
          <span className="inline-flex items-center gap-1.5"><span className="size-2.5 rounded-full bg-brand" aria-hidden="true" />{t('whatIf.with')}</span>
          <span className="ml-auto">{t('whatIf.days', { count: result.window.days })}</span>
        </div>
        <Dumbbell label={t('whatIf.energy')} before={result.baseline.kwh} after={result.scenario.kwh} format={formatKwh} />
        <Dumbbell label={t('whatIf.cost')} before={result.baseline.eur} after={result.scenario.eur} format={formatEur} />
        <Dumbbell label={t('whatIf.co2')} before={result.baseline.co2_kg} after={result.scenario.co2_kg} format={formatCo2} />
      </div>
      <div className="grid grid-cols-2 gap-3">
        <Savings label={t('whatIf.perMonth')} values={result.per_month} tag={result.impact_tag} />
        <Savings label={t('whatIf.perYear')} values={result.annualized} tag={result.impact_tag} />
      </div>
      <ReplayChart daily={result.daily} />
      <Assumptions result={result} />
    </div>
  )
}
