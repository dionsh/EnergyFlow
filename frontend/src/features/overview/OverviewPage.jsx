import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useQuery } from '@tanstack/react-query'
import { ArrowRight, Building2, CalendarClock, CircleCheck, Cpu, Gauge, Receipt, TriangleAlert } from 'lucide-react'
import { api } from '../../lib/api'
import { cn } from '../../lib/cn'
import { formatCo2, formatDate, formatEur, formatKw, formatKwh, formatNumber, formatPercent } from '../../lib/format'
import { useAuth } from '../../providers/AuthProvider'
import { PageHeader } from '../../components/layout/PageHeader'
import { Badge } from '../../components/ui/Badge'
import { Card, CardBody, CardHeader } from '../../components/ui/Card'
import { StatTile } from '../../components/ui/StatTile'
import { StateBadge } from '../../components/ui/StateBadge'
import { EmptyState, ErrorState, Skeleton } from '../../components/ui/States'
import { useLive, useOverview } from '../data'
import { WasteNow } from '../live/LiveParts'
import { TodayChart } from './TodayChart'

const STEP_META = {
  company: { icon: Building2, to: '/settings' },
  schedule: { icon: CalendarClock, to: '/settings' },
  tariff: { icon: Receipt, to: '/settings' },
  device: { icon: Cpu, to: '/devices' },
}

function SetupChecklist() {
  const { t } = useTranslation()
  const onboarding = useQuery({ queryKey: ['onboarding'], queryFn: async () => (await api.get('/onboarding')).data.steps })

  if (onboarding.isPending) {
    return (
      <div className="flex flex-col gap-3 p-5">
        {[0, 1, 2, 3].map((index) => <Skeleton key={index} className="h-12" />)}
      </div>
    )
  }
  if (onboarding.isError) return <ErrorState error={onboarding.error} onRetry={onboarding.refetch} />

  const steps = onboarding.data
  const done = steps.filter((step) => step.done).length
  return (
    <>
      <CardHeader title={t('overview.setup.title')} actions={<span className="text-[13px] text-ink-2">{t('overview.setup.progress', { done, total: steps.length })}</span>} />
      <div className="h-1 bg-surface-2" aria-hidden="true">
        <div className="h-full bg-brand transition-[width]" style={{ width: `${(done / steps.length) * 100}%` }} />
      </div>
      <ol className="divide-y divide-line">
        {steps.map((step) => {
          const meta = STEP_META[step.key]
          const Icon = step.done ? CircleCheck : meta.icon
          return (
            <li key={step.key}>
              <Link to={meta.to} className="group flex items-center gap-4 px-5 py-3.5 hover:bg-surface-2">
                <Icon className={cn('size-5 shrink-0', step.done ? 'text-good' : 'text-ink-3')} aria-hidden="true" />
                <div className="min-w-0 flex-1">
                  <p className={cn('text-sm font-medium', step.done ? 'text-ink-2' : 'text-ink')}>{t(`overview.setup.${step.key}.title`)}</p>
                  <p className="text-[13px] text-ink-3">{t(`overview.setup.${step.key}.body`)}</p>
                </div>
                <span className={cn('text-xs font-medium', step.done ? 'text-good-text' : 'text-ink-3')}>{step.done ? t('common.done') : t('common.todo')}</span>
                <ArrowRight className="size-4 shrink-0 text-ink-3 opacity-0 transition-opacity group-hover:opacity-100" aria-hidden="true" />
              </Link>
            </li>
          )
        })}
      </ol>
    </>
  )
}

function BillCard({ month, projection, tariff }) {
  const { t } = useTranslation()
  const bill = month.bill_so_far
  if (!bill) return null
  return (
    <Card>
      <CardHeader title={t('overviewData.billTitle')} />
      <CardBody className="py-2">
        <dl>
          {bill.lines.map((line) => (
            <div key={line.key} className="flex items-baseline justify-between gap-4 border-b border-line py-2 text-[13px]">
              <dt className="text-ink-2">
                {t(`overviewData.billLines.${line.key}`, { kw: formatNumber(month.peak_kw, 1) })}
                {line.unit === 'kWh' && <span className="ml-1 text-ink-3">· {formatNumber(line.quantity, 0)} kWh × {formatNumber(line.rate * 100, 2)} c</span>}
              </dt>
              <dd className="tabular font-medium text-ink">{formatEur(line.amount, { compact: false })}</dd>
            </div>
          ))}
          <div className="flex items-baseline justify-between gap-4 py-2.5 text-sm">
            <dt className="font-semibold text-ink">{t('overviewData.subtotal')}</dt>
            <dd className="tabular font-semibold text-ink">{formatEur(bill.subtotal, { compact: false })}</dd>
          </div>
        </dl>
        <p className="border-t border-line pt-2.5 text-[12.5px] text-ink-2">
          {t('overviewData.projection', { eur: formatEur(projection.bill?.subtotal), kwh: formatKwh(projection.kwh) })}
        </p>
        {tariff && <p className="pb-2 pt-1 text-[11.5px] leading-snug text-ink-3">{t('overviewData.tariffSource', { name: tariff.name })}</p>}
      </CardBody>
    </Card>
  )
}

function RunningNow({ live }) {
  const { t } = useTranslation()
  const running = live.machines.filter((m) => m.state === 'running' || m.state === 'idle').sort((a, b) => (b.power_kw ?? 0) - (a.power_kw ?? 0))
  return (
    <Card>
      <CardHeader title={t('overviewData.runningNow')} actions={<Link to="/live" className="text-[13px] font-medium text-brand hover:underline">{t('overviewData.viewAll')}</Link>} />
      <ul className="divide-y divide-line">
        {running.slice(0, 6).map((m) => (
          <li key={m.id}>
            <Link to={`/machines/${m.id}`} className="flex items-center justify-between gap-3 px-5 py-2.5 text-[13px] hover:bg-surface-2">
              <span className="flex min-w-0 items-center gap-2.5">
                <StateBadge state={m.state} className="w-24 shrink-0" />
                <span className="truncate text-ink">{m.name}</span>
                {m.after_hours && <TriangleAlert className="size-3.5 shrink-0 text-warning-text" aria-label={t('live.offSchedule')} />}
              </span>
              <span className="tabular text-ink-2">{formatKw(m.power_kw ?? 0)}</span>
            </Link>
          </li>
        ))}
      </ul>
    </Card>
  )
}

function Dashboard({ overview, live }) {
  const { t } = useTranslation()
  const { month, today, projection } = overview
  const change = month.change_ratio
  return (
    <div className="flex flex-col gap-6">
      {live && <WasteNow waste={live.waste_now} />}

      <div className="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
        <StatTile label={t('overviewData.now')} value={live ? formatNumber(live.site_kw, 1) : '—'} unit="kW" context={live ? t('overviewData.devices', live.devices) : undefined} />
        <StatTile label={t('overviewData.today')} value={formatNumber(today.kwh, 0)} unit="kWh" context={t('overviewData.soFar', { eur: formatEur(today.eur) })} />
        <StatTile
          label={t('overviewData.month')}
          value={formatNumber(month.kwh, 0)}
          unit="kWh"
          delta={change === null ? undefined : { text: t('overviewData.vsLastMonth', { value: formatPercent(Math.abs(change)) }), direction: change >= 0 ? 'up' : 'down', good: change < 0 }}
        />
        <StatTile label={t('overviewData.bill')} value={formatEur(projection.bill?.subtotal)} context={t('overviewData.billContext')} />
        <StatTile label={t('overviewData.co2')} value={formatCo2(month.co2_kg)} context={`→ ${formatCo2(projection.co2_kg)}`} />
        <StatTile label={t('overviewData.running')} value={live ? `${live.on}` : '—'} unit={live ? `/ ${live.machines.length}` : undefined} />
      </div>

      <div className="grid gap-6 xl:grid-cols-[minmax(0,8fr)_minmax(0,4fr)]">
        <Card>
          <CardHeader title={t('overviewData.chartTitle', { dayType: t(`overviewData.dayTypes.${overview.today_curve.day_type}`) })} />
          <CardBody>
            <TodayChart curve={overview.today_curve} />
          </CardBody>
        </Card>
        <BillCard month={month} projection={projection} tariff={overview.tariff} />
      </div>

      <div className="grid gap-6 xl:grid-cols-[minmax(0,8fr)_minmax(0,4fr)]">
        {live ? <RunningNow live={live} /> : <div />}
        <Card>
          <CardBody className="flex flex-col gap-2 py-4 text-[12.5px] text-ink-2">
            {overview.monitoring_since && <p>{t('overviewData.monitoringSince', { date: formatDate(overview.monitoring_since) })}</p>}
            <p>
              {t('overviewData.factor', {
                value: formatNumber(overview.emission_factor.value, 3),
                source: `${overview.emission_factor.source_name}`,
              })}
            </p>
          </CardBody>
        </Card>
      </div>
    </div>
  )
}

export function OverviewPage() {
  const { t } = useTranslation()
  const { company } = useAuth()
  const overview = useOverview()
  const live = useLive()

  const header = (
    <PageHeader
      title={t('overview.title')}
      subtitle={t('overview.subtitle', { company: company?.name })}
      actions={company?.is_demo ? <Badge tone="info">{t('demo.badge')}</Badge> : null}
    />
  )

  if (overview.isPending) {
    return (
      <>
        {header}
        <div className="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
          {[0, 1, 2, 3, 4, 5].map((i) => <Skeleton key={i} className="h-24" />)}
        </div>
      </>
    )
  }
  if (overview.isError) return <ErrorState error={overview.error} onRetry={overview.refetch} />

  const data = overview.data.data
  if (!data.has_data) {
    return (
      <>
        {header}
        <div className="grid gap-6 xl:grid-cols-[minmax(0,7fr)_minmax(0,5fr)]">
          <Card>
            <EmptyState icon={Gauge} title={t('overview.emptyTitle')} body={t('overview.emptyBody')} />
          </Card>
          <Card className="overflow-hidden">
            <SetupChecklist />
          </Card>
        </div>
      </>
    )
  }

  return (
    <>
      {header}
      <Dashboard overview={data} live={live.data?.data} />
    </>
  )
}
