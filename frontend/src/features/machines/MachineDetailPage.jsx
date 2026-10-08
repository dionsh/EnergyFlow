import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { ArrowLeft, TriangleAlert } from 'lucide-react'
import { cn } from '../../lib/cn'
import { formatCo2, formatDuration, formatEur, formatKw, formatKwh, formatNumber } from '../../lib/format'
import { MachineIcon } from '../../lib/machineTypes'
import { Card, CardBody, CardHeader } from '../../components/ui/Card'
import { StatTile } from '../../components/ui/StatTile'
import { StateBadge } from '../../components/ui/StateBadge'
import { Callout, EmptyState, ErrorState, Skeleton } from '../../components/ui/States'
import { useMachine, useMachineSeries } from '../data'
import { TurnOffButton } from '../control/TurnOff'
import { MachineFindings } from '../waste/InsightCards'
import { MachineChart } from './MachineChart'

const RANGES = ['24h', '7d', '30d']

function Detail({ label, children }) {
  return (
    <div className="flex items-start justify-between gap-4 border-b border-line py-2.5 last:border-0">
      <dt className="text-[13px] text-ink-2">{label}</dt>
      <dd className="text-right text-[13px] font-medium text-ink">{children ?? '—'}</dd>
    </div>
  )
}

export function MachineDetailPage() {
  const { t } = useTranslation()
  const { id } = useParams()
  const [range, setRange] = useState('24h')
  const query = useMachine(id)
  const series = useMachineSeries(id, range)

  if (query.isPending) return <Skeleton className="h-[480px]" />
  if (query.isError) {
    return query.error.status === 404 ? (
      <Card><EmptyState title={t('machineDetail.notFound')} /></Card>
    ) : (
      <ErrorState error={query.error} onRetry={query.refetch} />
    )
  }

  const { machine, live, today, month, week_hours: hours } = query.data.data

  return (
    <>
      <Link to="/machines" className="mb-4 inline-flex items-center gap-1.5 text-[13px] text-ink-2 hover:text-ink">
        <ArrowLeft className="size-4" aria-hidden="true" />
        {t('machineDetail.back')}
      </Link>

      <div className="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div className="flex items-start gap-3">
          <span className="flex size-10 items-center justify-center rounded-md border border-line bg-surface text-ink-2">
            <MachineIcon type={machine.type} className="size-5" />
          </span>
          <div>
            <h1 className="text-xl font-semibold tracking-[-0.01em] text-ink">{machine.name}</h1>
            <p className="mt-0.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-[13px] text-ink-3">
              <span className="font-mono">{machine.code}</span>
              <span>{t(`machineTypes.${machine.type}`, { defaultValue: machine.type })}</span>
              {live && <StateBadge state={live.state} />}
            </p>
          </div>
        </div>
        {live && <TurnOffButton machine={live} />}
      </div>

      {live?.after_hours && (
        <Callout tone="warning" icon={TriangleAlert} className="mb-6">
          {t('machineDetail.afterHours', {
            duration: formatDuration(live.after_hours.duration_s),
            kwh: formatKwh(live.after_hours.kwh),
            eur: formatEur(live.after_hours.eur),
            co2: formatCo2(live.after_hours.co2_kg),
          })}
        </Callout>
      )}

      <div className="mb-6 grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-5">
        <StatTile label={t('machineDetail.now')} value={live?.power_kw == null ? '—' : formatNumber(live.power_kw, 2)} unit="kW" context={live?.power_factor ? `PF ${formatNumber(live.power_factor, 2)}` : undefined} />
        <StatTile label={t('machineDetail.today')} value={formatNumber(today.kwh, 1)} unit="kWh" context={formatEur(today.eur)} />
        <StatTile label={t('machineDetail.month')} value={formatNumber(month.kwh, 0)} unit="kWh" context={formatEur(month.eur)} />
        <StatTile label={t('machineDetail.co2Month')} value={formatCo2(month.co2_kg)} />
        <StatTile label={t('machineDetail.outside')} value={formatNumber(hours.outside_schedule, 1)} unit={t('units.h')} context={t('machineDetail.outsideContext')} />
      </div>

      <div className="grid gap-6 xl:grid-cols-[minmax(0,8fr)_minmax(0,3fr)]">
        <Card>
          <CardHeader
            title={t('machineDetail.chartTitle')}
            actions={
              <div role="tablist" className="flex rounded-sm border border-line-strong p-0.5">
                {RANGES.map((key) => (
                  <button
                    key={key}
                    role="tab"
                    type="button"
                    aria-selected={range === key}
                    onClick={() => setRange(key)}
                    className={cn('h-7 rounded-[3px] px-2.5 text-xs font-medium', range === key ? 'bg-surface-2 text-ink' : 'text-ink-3 hover:text-ink')}
                  >
                    {t(`machineDetail.ranges.${key}`)}
                  </button>
                ))}
              </div>
            }
          />
          <CardBody>
            {series.isPending ? (
              <Skeleton className="h-[260px]" />
            ) : series.isError ? (
              <ErrorState error={series.error} onRetry={series.refetch} />
            ) : (
              <>
                <p className="mb-2 text-[12.5px] text-ink-3">{range === '30d' ? t('machineDetail.kwh') : t('machineDetail.kw')}</p>
                <MachineChart points={series.data.data} range={range} />
              </>
            )}
          </CardBody>
        </Card>

        <div className="flex flex-col gap-6">
          <MachineFindings machineId={machine.id} />
          <Card>
            <CardHeader title={t('machineDetail.info')} />
            <CardBody className="py-1">
              <dl>
                <Detail label={t('machineDetail.schedule')}>{machine.schedule}</Detail>
                <Detail label={t('machineDetail.department')}>{machine.department}</Detail>
                <Detail label={t('machineDetail.rated')}>{machine.rated_power_kw ? formatKw(machine.rated_power_kw) : null}</Detail>
                <Detail label={t('machineDetail.phases')}>{machine.phases}</Detail>
                <Detail label={t('machineDetail.control')}>{t(`controlModes.${machine.control_mode}`)}</Detail>
                <Detail label={t('machineDetail.criticalityLabel')}>{t(`criticality.${machine.criticality}`)}</Detail>
                <Detail label={t('machineDetail.device')}>
                  {live?.device ? <span className="font-mono">{live.device.serial}</span> : null}
                </Detail>
              </dl>
            </CardBody>
          </Card>
        </div>
      </div>
    </>
  )
}
