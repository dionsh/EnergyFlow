import { useSearchParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { TriangleAlert } from 'lucide-react'
import { cn } from '../../lib/cn'
import { formatCo2, formatDate, formatDuration, formatEur, formatKwh, formatNumber, formatPercent } from '../../lib/format'
import { MachineIcon } from '../../lib/machineTypes'
import { PageHeader } from '../../components/layout/PageHeader'
import { BarList } from '../../components/ui/BarList'
import { Card, CardBody, CardHeader } from '../../components/ui/Card'
import { SeverityBadge } from '../../components/ui/Chips'
import { StatTile } from '../../components/ui/StatTile'
import { EmptyState, ErrorState, Skeleton } from '../../components/ui/States'
import { Segmented, Tabs } from '../../components/ui/Tabs'
import { useAlerts, useLive, useWasteEvents, useWasteSummary } from '../data'
import { TurnOffButton } from '../control/TurnOff'
import { AskButton } from '../assistant/AskButton'
import { AlertsPanel } from './AlertsPanel'
import { DailyWasteChart } from './WasteCharts'
import { StatusBadge, WasteDrawer } from './WasteDrawer'

const PERIODS = ['7d', 'mtd', '30d']

function EventsTable({ events, onOpen }) {
  const { t } = useTranslation()
  return (
    <div className="overflow-x-auto">
      <table className="w-full min-w-[900px] border-collapse text-[13px]">
        <thead>
          <tr className="border-b border-line text-left text-xs text-ink-3">
            <th className="px-5 py-2.5 font-medium">{t('wastePage.table.machine')}</th>
            <th className="px-3 py-2.5 font-medium">{t('wastePage.table.type')}</th>
            <th className="px-3 py-2.5 font-medium">{t('wastePage.table.when')}</th>
            <th className="px-3 py-2.5 font-medium">{t('wastePage.table.duration')}</th>
            <th className="px-3 py-2.5 text-right font-medium">{t('wastePage.table.energy')} (kWh)</th>
            <th className="px-3 py-2.5 text-right font-medium">{t('wastePage.table.cost')}</th>
            <th className="px-3 py-2.5 text-right font-medium">{t('wastePage.table.co2')}</th>
            <th className="px-3 py-2.5 font-medium">{t('wastePage.table.severity')}</th>
            <th className="px-5 py-2.5 font-medium">{t('wastePage.table.status')}</th>
          </tr>
        </thead>
        <tbody>
          {events.map((event) => (
            <tr
              key={event.id}
              tabIndex={0}
              onClick={() => onOpen(event.id)}
              onKeyDown={(e) => e.key === 'Enter' && onOpen(event.id)}
              className="cursor-pointer border-b border-line last:border-0 hover:bg-surface-2 focus-visible:bg-surface-2"
            >
              <td className="px-5 py-2.5">
                <span className="flex items-center gap-2.5">
                  <MachineIcon type={event.machine.type} className="size-4 shrink-0 text-ink-3" />
                  <span className="min-w-0">
                    <span className="block truncate font-medium text-ink">{event.machine.name}</span>
                    <span className="block font-mono text-[11px] text-ink-3">{event.machine.code}</span>
                  </span>
                </span>
              </td>
              <td className="px-3 py-2.5 text-ink-2">
                {t(`wasteTypes.${event.type}`)}
                {event.signals.includes('leak_signature') && <span className="block text-xs text-ink-3">{t('alertText.leak')}</span>}
              </td>
              <td className="whitespace-nowrap px-3 py-2.5 tabular text-ink-2">{formatDate(event.started_at, 'dateTime')}</td>
              <td className={cn('whitespace-nowrap px-3 py-2.5 tabular', event.ongoing && event.type !== 'excess_vs_baseline' ? 'text-warning-text' : 'text-ink-2')}>
                {formatDuration(event.duration_s)}
              </td>
              <td className="whitespace-nowrap px-3 py-2.5 text-right tabular text-ink">{formatNumber(event.kwh, event.kwh < 100 ? 1 : 0)}</td>
              <td className="whitespace-nowrap px-3 py-2.5 text-right tabular text-ink">{formatEur(event.eur)}</td>
              <td className="whitespace-nowrap px-3 py-2.5 text-right tabular text-ink-2">{formatCo2(event.co2_kg)}</td>
              <td className="px-3 py-2.5"><SeverityBadge severity={event.severity} /></td>
              <td className="px-5 py-2.5"><StatusBadge event={event} /></td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

function WasteOverview({ period, onOpen }) {
  const { t } = useTranslation()
  const summary = useWasteSummary(period)
  const events = useWasteEvents({ period })

  if (summary.isPending) {
    return (
      <div className="flex flex-col gap-6">
        <div className="grid grid-cols-2 gap-3 xl:grid-cols-4">{[0, 1, 2, 3].map((i) => <Skeleton key={i} className="h-24" />)}</div>
        <Skeleton className="h-72" />
      </div>
    )
  }
  if (summary.isError) return <ErrorState error={summary.error} onRetry={summary.refetch} />

  const s = summary.data.data
  const change = s.previous.change_ratio
  const list = events.data?.data ?? []
  return (
    <div className="flex flex-col gap-6">
      <div className="grid grid-cols-2 gap-3 xl:grid-cols-4">
        <StatTile
          label={t('wastePage.tiles.kwh')}
          value={formatNumber(s.totals.kwh, s.totals.kwh < 100 ? 1 : 0)}
          unit="kWh"
          context={t('wastePage.eventsContext', { count: s.totals.events })}
          delta={change === null ? undefined : { text: t('wastePage.vsPrevious', { value: formatPercent(Math.abs(change)) }), direction: change >= 0 ? 'up' : 'down', good: change < 0 }}
        />
        <StatTile label={t('wastePage.tiles.eur')} value={formatEur(s.totals.eur)} />
        <StatTile label={t('wastePage.tiles.co2')} value={formatCo2(s.totals.co2_kg)} />
        <StatTile
          label={t('wastePage.tiles.share')}
          value={s.totals.share_of_consumption === null ? '—' : formatPercent(s.totals.share_of_consumption)}
          context={t('wastePage.shareContext', { kwh: formatKwh(s.totals.consumption_kwh) })}
        />
      </div>

      {s.totals.events > 0 && (
        <div className="grid gap-6 xl:grid-cols-[minmax(0,8fr)_minmax(0,4fr)]">
          <Card>
            <CardHeader title={t('wastePage.dailyTitle')} />
            <CardBody>
              <p className="mb-2 text-[12.5px] text-ink-3">kWh</p>
              <DailyWasteChart days={s.daily} />
            </CardBody>
          </Card>
          <Card>
            <CardHeader title={t('wastePage.byType')} />
            <CardBody>
              <BarList
                tone="waste"
                rows={s.by_type.map((row) => ({ key: row.type, label: t(`wasteTypes.${row.type}`), value: row.kwh, display: formatKwh(row.kwh), hint: formatEur(row.eur) }))}
              />
            </CardBody>
            <div className="border-t border-line px-5 pb-1 pt-4">
              <h3 className="text-sm font-semibold text-ink">{t('wastePage.byMachine')}</h3>
            </div>
            <CardBody className="pt-2">
              <BarList
                tone="waste"
                rows={s.by_machine.map((row) => ({
                  key: row.machine.id,
                  label: row.machine.name,
                  value: row.kwh,
                  display: formatKwh(row.kwh),
                  hint: formatEur(row.eur),
                  to: `/machines/${row.machine.id}`,
                  icon: <MachineIcon type={row.machine.type} className="size-4 shrink-0 text-ink-3" />,
                }))}
              />
            </CardBody>
          </Card>
        </div>
      )}

      <Card className="overflow-hidden">
        <CardHeader title={t('wastePage.eventsTitle')} />
        {events.isPending ? (
          <div className="p-5"><Skeleton className="h-48" /></div>
        ) : events.isError ? (
          <ErrorState error={events.error} onRetry={events.refetch} />
        ) : list.length === 0 ? (
          <EmptyState icon={TriangleAlert} title={t('wastePage.emptyTitle')} body={t('wastePage.emptyBody')} />
        ) : (
          <EventsTable events={list} onOpen={onOpen} />
        )}
      </Card>
    </div>
  )
}

export function WastePage() {
  const { t } = useTranslation()
  const [params, setParams] = useSearchParams()
  const tab = params.get('tab') === 'alerts' ? 'alerts' : 'waste'
  const period = PERIODS.includes(params.get('period')) ? params.get('period') : 'mtd'
  const eventId = params.get('event')
  const activeAlerts = useAlerts('active')
  const live = useLive()

  const update = (changes) =>
    setParams((current) => {
      const next = new URLSearchParams(current)
      Object.entries(changes).forEach(([key, value]) => (value === null ? next.delete(key) : next.set(key, value)))
      return next
    })

  return (
    <>
      <PageHeader
        title={t('nav.waste')}
        subtitle={t('wastePage.subtitle')}
        actions={tab === 'waste' && (
          <Segmented label={t('wastePage.tabs.waste')} items={PERIODS.map((key) => ({ key, label: t(`periods.${key}`) }))} value={period} onChange={(key) => update({ period: key })} />
        )}
      />
      <Tabs
        items={[
          { key: 'waste', label: t('wastePage.tabs.waste') },
          { key: 'alerts', label: t('wastePage.tabs.alerts'), count: activeAlerts.data?.meta?.counts?.total ?? 0 },
        ]}
        value={tab}
        onChange={(key) => update({ tab: key === 'waste' ? null : key })}
      />
      {tab === 'waste' ? (
        <WasteOverview period={period} onOpen={(id) => update({ event: String(id) })} />
      ) : (
        <AlertsPanel onOpenEvent={(id) => update({ event: String(id) })} />
      )}
      <WasteDrawer
        eventId={eventId}
        onClose={() => update({ event: null })}
        actions={(event) => {
          // An episode that is still going on can be ended right here.
          const machine = live.data?.data?.machines.find((m) => m.id === event.machine.id)
          return (
            <>
              {event.ongoing && event.type !== 'excess_vs_baseline' && machine && <TurnOffButton machine={machine} />}
              <AskButton question={t('assistant.explain.waste')} context={{ waste_event_id: event.id }} onBeforeAsk={() => update({ event: null })} />
            </>
          )
        }}
      />
    </>
  )
}
