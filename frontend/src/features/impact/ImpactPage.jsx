import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { CircleCheck, Clock3, ShieldCheck, TrendingDown } from 'lucide-react'
import { cn } from '../../lib/cn'
import { formatCo2, formatDate, formatEur, formatKwh, formatNumber, formatPercent } from '../../lib/format'
import { MachineIcon } from '../../lib/machineTypes'
import { PageHeader } from '../../components/layout/PageHeader'
import { Badge } from '../../components/ui/Badge'
import { Card, CardBody, CardHeader } from '../../components/ui/Card'
import { EmptyState, ErrorState, Skeleton } from '../../components/ui/States'
import { Segmented } from '../../components/ui/Tabs'
import { useImpact, useIntervention } from '../data'
import { ImpactChart } from './ImpactChart'
import { actionLabel } from './impactText'

const MIN_DAYS = 7

function InterventionStatus({ iv }) {
  const { t } = useTranslation()
  if (iv.verified) return <Badge tone="good" icon={CircleCheck}>{t('impactPage.status.verified')}</Badge>
  if (iv.collecting) return <Badge tone="info" icon={Clock3}>{t('impactPage.status.collecting', { days: iv.reporting.days, min: MIN_DAYS })}</Badge>
  return <Badge>{t('impactPage.status.inconclusive')}</Badge>
}

/** Before → after for one quantity, with the difference underneath. */
function Comparison({ label, before, after, format }) {
  return (
    <div className="px-5 py-4">
      <p className="text-[13px] font-medium text-ink-2">{label}</p>
      <div className="mt-2 flex flex-wrap items-baseline gap-x-3 gap-y-1">
        <span className="text-xl font-semibold tracking-[-0.01em] text-ink-3 line-through decoration-1">{format(before)}</span>
        <span className="text-ink-3" aria-hidden="true">→</span>
        <span className="text-[26px] font-semibold leading-tight tracking-[-0.01em] text-ink">{format(after)}</span>
      </div>
    </div>
  )
}

function Detail({ id }) {
  const { t } = useTranslation()
  const detail = useIntervention(id)
  if (detail.isPending) return <Skeleton className="h-64" />
  if (detail.isError) return <ErrorState error={detail.error} onRetry={detail.refetch} />
  const iv = detail.data.data
  return (
    <Card>
      <CardHeader title={t('impactPage.chartTitle', { machine: `${iv.machine.code} · ${iv.machine.name}` })} description={actionLabel(t, iv)} actions={<InterventionStatus iv={iv} />} />
      <CardBody>
        <ImpactChart daily={iv.daily} />
      </CardBody>
    </Card>
  )
}

function HowWeCalculate({ interventions }) {
  const { t } = useTranslation()
  const types = [...new Set(interventions.flatMap((iv) => iv.model.day_types.map((d) => d.type)))]
    .map((type) => t('impactPage.dayType', { hours: type.replace('h', '') }))
    .join(', ')
  const items = ['baseline', 'model', 'adjusted', 'savings', 'verified', 'valuation']
  return (
    <Card>
      <CardHeader title={t('impactPage.how')} />
      <CardBody>
        <ul className="flex flex-col gap-2 border-l-2 border-line pl-3 text-[13px] leading-snug text-ink-2">
          {items.map((key) => (
            <li key={key}>{t(`impactPage.howItems.${key}`, { days: 28, min: MIN_DAYS, types })}</li>
          ))}
        </ul>
        <p className="mt-3 flex items-center gap-2 text-[12.5px] text-ink-3">
          <ShieldCheck className="size-4 shrink-0" aria-hidden="true" />
          {t('impactPage.howItems.label')}
        </p>
      </CardBody>
    </Card>
  )
}

export function ImpactPage() {
  const { t } = useTranslation()
  const impact = useImpact()
  const [mode, setMode] = useState('adjusted')
  const [selected, setSelected] = useState(null)

  const header = (
    <PageHeader
      title={t('nav.impact')}
      subtitle={t('impactPage.subtitle')}
      actions={<Segmented items={[{ key: 'adjusted', label: t('impactPage.adjusted') }, { key: 'raw', label: t('impactPage.raw') }]} value={mode} onChange={setMode} />}
    />
  )
  if (impact.isPending) {
    return (
      <>
        {header}
        <Skeleton className="h-40" />
      </>
    )
  }
  if (impact.isError) return <ErrorState error={impact.error} onRetry={impact.refetch} />

  const data = impact.data.data
  const interventions = data.interventions
  if (interventions.length === 0) {
    return (
      <>
        {header}
        <Card>
          <EmptyState
            icon={TrendingDown}
            title={t('impactPage.emptyTitle')}
            body={t('impactPage.emptyBody')}
            actions={<Link to="/opportunities" className="inline-flex h-9 items-center rounded-sm bg-brand px-3.5 text-sm font-medium text-on-brand hover:bg-brand-hover">{t('nav.opportunities')}</Link>}
          />
        </Card>
      </>
    )
  }

  const view = data[mode]
  const current = selected ?? (interventions.find((iv) => iv.verified) ?? interventions[0]).id
  return (
    <>
      {header}
      <div className="flex flex-col gap-6">
        <Card>
          <div className="grid divide-y divide-line md:grid-cols-3 md:divide-x md:divide-y-0">
            <Comparison label={t('impactPage.energy')} before={view.before.kwh} after={view.after.kwh} format={formatKwh} />
            <Comparison label={t('impactPage.cost')} before={view.before.eur} after={view.after.eur} format={formatEur} />
            <Comparison label={t('impactPage.co2')} before={view.before.co2_kg} after={view.after.co2_kg} format={formatCo2} />
          </div>
          <div className="flex flex-wrap items-center gap-x-4 gap-y-2 border-t border-line bg-good-subtle/60 px-5 py-3 text-[13px]">
            <span className="flex items-center gap-2 font-semibold text-good-text">
              <TrendingDown className="size-4" aria-hidden="true" />
              {t('impactPage.delta', { share: formatPercent(view.savings.share ?? 0), eur: formatEur(view.savings.eur), co2: formatCo2(view.savings.co2_kg) })}
            </span>
            {mode === 'adjusted' && <span className="text-ink-2">{t('impactPage.ci', { kwh: formatKwh(view.savings.ci90_kwh) })}</span>}
            <span className="ml-auto text-xs text-ink-3">
              {t('impactPage.before')}: {mode === 'adjusted' ? t('impactPage.beforeHint') : t('impactPage.beforeHintRaw')} · {t('impactPage.after')}: {t('impactPage.afterHint')}
            </span>
          </div>
        </Card>

        <Card className="overflow-hidden">
          <CardHeader
            title={t('impactPage.interventions')}
            actions={data.verified.kwh > 0 && (
              <span className="text-[13px] text-ink-2">
                {t('impactPage.verifiedTotal')}: <strong className="font-semibold text-ink">{formatEur(data.verified.eur)}</strong> · {formatKwh(data.verified.kwh)} · {formatCo2(data.verified.co2_kg)}
              </span>
            )}
          />
          <div className="overflow-x-auto">
            <table className="w-full min-w-[860px] border-collapse text-[13px]">
              <thead>
                <tr className="border-b border-line text-left text-xs text-ink-3">
                  <th className="px-5 py-2.5 font-medium">{t('impactPage.table.machine')}</th>
                  <th className="px-3 py-2.5 font-medium">{t('impactPage.table.action')}</th>
                  <th className="px-3 py-2.5 font-medium">{t('impactPage.table.since')}</th>
                  <th className="px-3 py-2.5 text-right font-medium">{t('impactPage.table.period')}</th>
                  <th className="px-3 py-2.5 text-right font-medium">{t('impactPage.table.savings')}</th>
                  <th className="px-3 py-2.5 text-right font-medium">{t('impactPage.table.eur')}</th>
                  <th className="px-3 py-2.5 text-right font-medium">{t('impactPage.table.co2')}</th>
                  <th className="px-5 py-2.5 font-medium">{t('impactPage.table.status')}</th>
                </tr>
              </thead>
              <tbody>
                {interventions.map((iv) => (
                  <tr
                    key={iv.id}
                    tabIndex={0}
                    onClick={() => setSelected(iv.id)}
                    onKeyDown={(e) => e.key === 'Enter' && setSelected(iv.id)}
                    className={cn('cursor-pointer border-b border-line last:border-0 hover:bg-surface-2', iv.id === current && 'bg-surface-2')}
                  >
                    <td className="px-5 py-3">
                      <span className="flex items-center gap-2.5">
                        <MachineIcon type={iv.machine.type} className="size-4 shrink-0 text-ink-3" />
                        <span>
                          <span className="block font-medium text-ink">{iv.machine.name}</span>
                          <span className="block font-mono text-[11px] text-ink-3">{iv.machine.code}</span>
                        </span>
                      </span>
                    </td>
                    <td className="px-3 py-3 text-ink-2">{actionLabel(t, iv)}</td>
                    <td className="whitespace-nowrap px-3 py-3 tabular text-ink-2">{formatDate(iv.since)}</td>
                    <td className="whitespace-nowrap px-3 py-3 text-right tabular text-ink-2">{t('impactPage.days', { count: iv.reporting.days })}</td>
                    <td className="whitespace-nowrap px-3 py-3 text-right tabular text-ink">
                      {formatKwh(iv.savings.kwh)}
                      {iv.reporting.days > 0 && <span className="block text-xs text-ink-3">± {formatNumber(iv.savings.ci90_kwh, 0)} kWh</span>}
                    </td>
                    <td className="whitespace-nowrap px-3 py-3 text-right tabular text-ink">{formatEur(iv.savings.eur)}</td>
                    <td className="whitespace-nowrap px-3 py-3 text-right tabular text-ink-2">{formatCo2(iv.savings.co2_kg)}</td>
                    <td className="px-5 py-3"><InterventionStatus iv={iv} /></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </Card>

        <div className="grid gap-6 xl:grid-cols-[minmax(0,8fr)_minmax(0,4fr)]">
          <Detail id={current} />
          <HowWeCalculate interventions={interventions} />
        </div>
      </div>
    </>
  )
}
