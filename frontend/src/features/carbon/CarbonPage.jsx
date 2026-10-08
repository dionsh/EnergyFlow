import { useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Check, CircleDashed, ExternalLink, FlaskConical, Info } from 'lucide-react'
import { api } from '../../lib/api'
import { cn } from '../../lib/cn'
import { formatCo2, formatDate, formatKwh, formatNumber, formatPercent } from '../../lib/format'
import { hasRole } from '../../lib/roles'
import { MachineIcon } from '../../lib/machineTypes'
import { useAuth } from '../../providers/AuthProvider'
import { PageHeader } from '../../components/layout/PageHeader'
import { Badge } from '../../components/ui/Badge'
import { BarList } from '../../components/ui/BarList'
import { Card, CardBody, CardHeader } from '../../components/ui/Card'
import { StatTile } from '../../components/ui/StatTile'
import { Callout, ErrorState, Skeleton } from '../../components/ui/States'
import { Segmented, Tabs } from '../../components/ui/Tabs'
import { useCarbon, useCarbonBreakdown, useReadiness, useVsme } from '../data'
import { MonthlyCarbonChart } from './CarbonCharts'

const TABS = ['overview', 'breakdown', 'vsme', 'readiness', 'methodology']
const PERIODS = ['mtd', '30d', 'ytd']
const STATUS_TONES = { auto: 'brand', manual: 'info', estimated: 'warning', missing: 'neutral', not_available: 'neutral' }

function FactorChip({ factor, onClick }) {
  const { t } = useTranslation()
  return (
    <button type="button" onClick={onClick} className="inline-flex h-7 items-center gap-1.5 rounded-sm border border-line-strong bg-surface px-2.5 text-xs text-ink-2 hover:bg-surface-2">
      <Info className="size-3.5" aria-hidden="true" />
      {t('carbonPage.factorChip', { value: formatNumber(factor.value, 3), source: `Ember ${factor.reference_year}`, methodology: factor.methodology })}
    </button>
  )
}

function Overview({ period, onMethodology }) {
  const { t } = useTranslation()
  const carbon = useCarbon(period)
  if (carbon.isPending) return <Skeleton className="h-72" />
  if (carbon.isError) return <ErrorState error={carbon.error} onRetry={carbon.refetch} />
  const c = carbon.data.data
  const change = c.previous.change_ratio
  return (
    <div className="flex flex-col gap-6">
      <div className="flex flex-wrap items-center gap-2">
        <FactorChip factor={c.factor} onClick={onMethodology} />
        {c.simulated_data && <Badge tone="info" icon={FlaskConical}>{t('demo.badge')}</Badge>}
      </div>
      <div className="grid grid-cols-2 gap-3 xl:grid-cols-4">
        <StatTile
          label={t('carbonPage.tiles.scope2')}
          value={formatCo2(c.scope2_location_kg)}
          context={t('carbonPage.tiles.scope2Context')}
          delta={change === null ? undefined : { text: t('wastePage.vsPrevious', { value: formatPercent(Math.abs(change)) }), direction: change >= 0 ? 'up' : 'down', good: change < 0 }}
        />
        <StatTile
          label={t('carbonPage.tiles.intensity')}
          value={c.intensity.kg_per_k_eur_turnover === null ? '—' : formatNumber(c.intensity.kg_per_k_eur_turnover, 1)}
          unit={c.intensity.kg_per_k_eur_turnover === null ? undefined : t('carbonPage.tiles.intensityUnit')}
          context={c.intensity.kg_per_k_eur_turnover === null ? t('carbonPage.tiles.intensityMissing') : t('carbonPage.tiles.intensityContext')}
        />
        <StatTile label={t('carbonPage.tiles.avoided')} value={formatCo2(c.avoided_verified_kg)} context={t('carbonPage.tiles.avoidedContext')} />
        <StatTile label={t('carbonPage.tiles.electricity')} value={formatNumber(c.electricity_kwh, 0)} unit="kWh" />
      </div>
      <div className="grid gap-6 xl:grid-cols-[minmax(0,8fr)_minmax(0,4fr)]">
        <Card>
          <CardHeader title={t('carbonPage.monthlyTitle')} />
          <CardBody>
            <p className="mb-2 text-[12.5px] text-ink-3">{t('carbonPage.monthlyUnit')}</p>
            <MonthlyCarbonChart months={c.monthly} />
          </CardBody>
        </Card>
        <Card>
          <CardHeader title={t('carbonPage.scopes')} />
          <dl className="divide-y divide-line text-[13px]">
            <div className="px-5 py-3">
              <dt className="text-ink-2">{t('carbonPage.scope1')}</dt>
              <dd className="mt-1 text-ink">
                {c.scope1.status === 'missing' ? (
                  <span className="text-ink-3">{t('carbonPage.scope1Missing')}</span>
                ) : c.scope1.status === 'declared_none' ? (
                  <span>0 kg CO₂e · <span className="text-ink-3">{t('carbonPage.scope1None')}</span></span>
                ) : formatCo2(c.scope1.kg)}
              </dd>
            </div>
            <div className="px-5 py-3">
              <dt className="text-ink-2">{t('carbonPage.scope2Location')}</dt>
              <dd className="mt-1 text-base font-semibold text-ink">{formatCo2(c.scope2_location_kg)}</dd>
            </div>
            <div className="px-5 py-3">
              <dt className="text-ink-2">{t('carbonPage.scope2Market')}</dt>
              <dd className="mt-1 text-ink-3">{t('carbonPage.scope2MarketNa')}</dd>
            </div>
          </dl>
        </Card>
      </div>
    </div>
  )
}

function Breakdown({ period }) {
  const { t } = useTranslation()
  const [groupBy, setGroupBy] = useState('machine')
  const breakdown = useCarbonBreakdown(period, groupBy)
  const rows = breakdown.data?.data.rows ?? []
  const label = (row) => {
    if (row.key === 'unmonitored') return t('carbonPage.unmonitored')
    if (groupBy === 'tariff_period') return row.key === 'high' ? t('carbonPage.tariffHigh') : t('carbonPage.tariffLow')
    return row.label
  }
  return (
    <Card>
      <CardHeader
        title={t('carbonPage.tabs.breakdown')}
        actions={<Segmented items={['machine', 'department', 'tariff_period'].map((key) => ({ key, label: t(`carbonPage.groupBy.${key}`) }))} value={groupBy} onChange={setGroupBy} />}
      />
      <CardBody>
        {breakdown.isPending ? (
          <Skeleton className="h-48" />
        ) : (
          <>
            <BarList
              rows={rows.map((row) => ({
                key: row.key,
                label: label(row),
                value: row.co2_kg,
                display: formatCo2(row.co2_kg),
                hint: `${formatKwh(row.kwh)} · ${formatPercent(row.share, 0)}`,
                to: row.machine ? `/machines/${row.machine.id}` : undefined,
                icon: row.machine ? <MachineIcon type={row.machine.type} className="size-4 shrink-0 text-ink-3" /> : undefined,
              }))}
            />
            {groupBy === 'tariff_period' && <Callout tone="info" icon={Info} className="mt-4">{t('carbonPage.tariffNote')}</Callout>}
          </>
        )}
      </CardBody>
    </Card>
  )
}

function Vsme() {
  const { t } = useTranslation()
  const vsme = useVsme()
  if (vsme.isPending) return <Skeleton className="h-80" />
  if (vsme.isError) return <ErrorState error={vsme.error} onRetry={vsme.refetch} />
  const v = vsme.data.data
  return (
    <Card className="overflow-hidden">
      <CardHeader title={t('carbonPage.vsmeTitle')} description={t('carbonPage.vsmeYear', { year: v.year })} />
      {v.partial_year && <p className="border-b border-line px-5 py-2.5 text-[12.5px] text-ink-3">{t('carbonPage.partialYear', { date: formatDate(v.rows[0]?.since ?? v.from) })}</p>}
      <div className="overflow-x-auto">
        <table className="w-full min-w-[720px] border-collapse text-[13px]">
          <thead>
            <tr className="border-b border-line text-left text-xs text-ink-3">
              <th className="px-5 py-2.5 font-medium">{t('carbonPage.vsmeTable.datapoint')}</th>
              <th className="px-3 py-2.5 text-right font-medium">{t('carbonPage.vsmeTable.value')}</th>
              <th className="px-3 py-2.5 font-medium">{t('carbonPage.vsmeTable.status')}</th>
              <th className="px-5 py-2.5 font-medium">{t('carbonPage.vsmeTable.note')}</th>
            </tr>
          </thead>
          <tbody>
            {v.rows.map((row) => (
              <tr key={row.key} className="border-b border-line last:border-0">
                <td className="px-5 py-2.5 text-ink">{t(`carbonPage.vsme.${row.key}`)}</td>
                <td className="whitespace-nowrap px-3 py-2.5 text-right tabular text-ink">
                  {row.value === null ? '—' : `${formatNumber(row.value, row.unit === 'MWh' || row.unit === 't CO2e' ? 2 : 1)} ${row.unit.replace('CO2e', 'CO₂e')}`}
                </td>
                <td className="px-3 py-2.5">
                  <Badge tone={STATUS_TONES[row.status]} icon={row.status === 'missing' ? CircleDashed : undefined}>{t(`carbonPage.statusChip.${row.status}`)}</Badge>
                </td>
                <td className="px-5 py-2.5 text-ink-3">
                  {t(`carbonPage.notes.${row.source}`)}
                  {row.note && t(`carbonPage.notes.${row.note}`) && ` · ${t(`carbonPage.notes.${row.note}`)}`}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <p className="flex items-start gap-2 border-t border-line px-5 py-3 text-[12.5px] text-ink-3">
        <Info className="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />
        {t('carbonPage.vsmeDisclaimer')}
      </p>
    </Card>
  )
}

function Readiness() {
  const { t } = useTranslation()
  const { user } = useAuth()
  const queryClient = useQueryClient()
  const readiness = useReadiness()
  const answer = useMutation({
    mutationFn: ({ key, value }) => api.put(`/esg/answers/${key}`, { value }),
    onSuccess: () => ['esg', 'carbon'].forEach((key) => queryClient.invalidateQueries({ queryKey: [key] })),
  })
  if (readiness.isPending) return <Skeleton className="h-80" />
  if (readiness.isError) return <ErrorState error={readiness.error} onRetry={readiness.refetch} />
  const r = readiness.data.data
  const canAnswer = hasRole(user, 'admin')
  const answerKey = (item) => ({ fuels: 'no_fuel_combustion', onsite_generation: 'no_onsite_generation' })[item.key] ?? item.key

  return (
    <div className="grid gap-6 xl:grid-cols-[minmax(0,8fr)_minmax(0,4fr)]">
      <Card>
        <CardHeader title={t('carbonPage.readinessTitle')} description={t('carbonPage.readinessBody')} actions={<span className="text-2xl font-semibold text-ink">{formatPercent(r.score, 0)}</span>} />
        <div className="flex gap-1 px-5 pt-4" aria-hidden="true">
          {r.items.map((item) => <span key={item.key} className={cn('h-1.5 flex-1 rounded-full', item.done ? 'bg-brand' : 'bg-surface-2')} style={{ flexGrow: item.weight }} />)}
        </div>
        {r.groups.map((group) => (
          <section key={group.group} className="px-5 py-4">
            <h3 className="mb-2 flex items-center justify-between text-[13px] font-semibold text-ink">
              {t(`carbonPage.groups.${group.group}`)}
              <span className="font-normal text-ink-3">{group.done}/{group.total}</span>
            </h3>
            <ul className="flex flex-col">
              {r.items.filter((item) => item.group === group.group).map((item) => (
                <li key={item.key} className="flex items-center gap-3 border-b border-line py-2 text-[13px] last:border-0">
                  {item.done ? <Check className="size-4 shrink-0 text-good" aria-hidden="true" /> : <CircleDashed className="size-4 shrink-0 text-ink-3" aria-hidden="true" />}
                  <span className={cn('min-w-0 flex-1', item.done ? 'text-ink' : 'text-ink-2')}>{t(`carbonPage.items.${item.key}`)}</span>
                  {item.source === 'answer' && (
                    <label className={cn('flex shrink-0 items-center gap-2 text-xs text-ink-2', canAnswer ? 'cursor-pointer' : 'opacity-60')}>
                      <input
                        type="checkbox"
                        disabled={!canAnswer || answer.isPending}
                        checked={r.answers[answerKey(item)] === true}
                        onChange={(e) => answer.mutate({ key: answerKey(item), value: e.target.checked })}
                        className="size-4 accent-[var(--brand)]"
                      />
                      <span className="hidden sm:inline">{t(`carbonPage.answerHint.${item.key}`)}</span>
                    </label>
                  )}
                  {item.source === 'company' && !item.done && (
                    <Link to="/settings" className="shrink-0 text-xs font-medium text-brand hover:underline">{t('carbonPage.goTo.company')}</Link>
                  )}
                  {item.source === 'auto' && <span className="shrink-0 text-xs text-ink-3">{t('carbonPage.goTo.auto')}</span>}
                </li>
              ))}
            </ul>
          </section>
        ))}
      </Card>
      <Card className="h-fit">
        <CardHeader title={t('carbonPage.nextSteps')} />
        <ol className="flex flex-col gap-2 px-5 py-4 text-[13px] text-ink">
          {r.next_steps.map((key, i) => (
            <li key={key} className="flex gap-3">
              <span className="flex size-5 shrink-0 items-center justify-center rounded-full bg-brand-subtle text-[11px] font-semibold text-brand">{i + 1}</span>
              {t(`carbonPage.items.${key}`)}
            </li>
          ))}
        </ol>
      </Card>
    </div>
  )
}

function Methodology() {
  const { t } = useTranslation()
  const carbon = useCarbon('30d')
  if (carbon.isPending) return <Skeleton className="h-80" />
  if (carbon.isError) return <ErrorState error={carbon.error} onRetry={carbon.refetch} />
  const c = carbon.data.data
  const f = c.factor
  const section = (title, children) => (
    <section className="border-b border-line px-5 py-4 last:border-0">
      <h3 className="mb-1.5 text-[13px] font-semibold text-ink">{title}</h3>
      <div className="text-[13px] leading-relaxed text-ink-2">{children}</div>
    </section>
  )
  return (
    <Card>
      {section(t('carbonPage.methodology.factorTitle'), (
        <>
          <p>{t('carbonPage.methodology.factorBody', { value: formatNumber(f.value, 3), source: f.source_name, year: f.reference_year, methodology: f.methodology })}</p>
          {f.source_url && (
            <a href={f.source_url} target="_blank" rel="noreferrer" className="mt-1 inline-flex items-center gap-1 font-medium text-brand hover:underline">
              {t('carbonPage.methodology.source')} <ExternalLink className="size-3.5" aria-hidden="true" />
            </a>
          )}
        </>
      ))}
      {section(t('carbonPage.methodology.caveatsTitle'), (
        <ul className="list-disc space-y-1 pl-5">
          {['lifecycle', 'generation', 'annual'].map((key) => <li key={key}>{t(`carbonPage.methodology.caveats.${key}`)}</li>)}
        </ul>
      ))}
      {section(t('carbonPage.methodology.scopesTitle'), (
        <>
          <p>{t('carbonPage.methodology.scope1')}</p>
          <p className="mt-1">{t('carbonPage.methodology.scope2')}</p>
          <p className="mt-1">{t('carbonPage.scope2MarketNa')}</p>
        </>
      ))}
      {section(t('carbonPage.methodology.mvTitle'), <p>{t('carbonPage.methodology.mv')}</p>)}
      {c.coverage !== null && section(t('carbonPage.methodology.coverageTitle'), <p>{t('carbonPage.methodology.coverage', { share: formatPercent(c.coverage) })}</p>)}
      {c.simulated_data && section(t('carbonPage.methodology.simulatedTitle'), <p>{t('carbonPage.methodology.simulated')}</p>)}
    </Card>
  )
}

export function CarbonPage() {
  const { t } = useTranslation()
  const [params, setParams] = useSearchParams()
  const tab = TABS.includes(params.get('tab')) ? params.get('tab') : 'overview'
  const [period, setPeriod] = useState('mtd')
  const setTab = (key) => setParams((p) => {
    const next = new URLSearchParams(p)
    if (key === 'overview') next.delete('tab')
    else next.set('tab', key)
    return next
  })

  return (
    <>
      <PageHeader
        title={t('nav.carbon')}
        subtitle={t('carbonPage.subtitle')}
        actions={['overview', 'breakdown'].includes(tab) && (
          <Segmented items={PERIODS.map((key) => ({ key, label: t(`periods.${key}`) }))} value={period} onChange={setPeriod} />
        )}
      />
      <Tabs items={TABS.map((key) => ({ key, label: t(`carbonPage.tabs.${key}`) }))} value={tab} onChange={setTab} />
      {tab === 'overview' && <Overview period={period} onMethodology={() => setTab('methodology')} />}
      {tab === 'breakdown' && <Breakdown period={period} />}
      {tab === 'vsme' && <Vsme />}
      {tab === 'readiness' && <Readiness />}
      {tab === 'methodology' && <Methodology />}
    </>
  )
}

