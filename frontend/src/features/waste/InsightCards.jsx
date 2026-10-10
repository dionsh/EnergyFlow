import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { ArrowRight, CircleCheck } from 'lucide-react'
import { formatDate, formatEur, formatNumber } from '../../lib/format'
import { Card, CardHeader } from '../../components/ui/Card'
import { MethodChip, SeverityBadge } from '../../components/ui/Chips'
import { Skeleton } from '../../components/ui/States'
import { useAlerts, useWasteEvents } from '../data'
import { DETAIL_TYPES, alertText } from './alertText'

/** Overview: the top 3 things that need attention, linked to their evidence. */
export function OpenAlertsCard() {
  const { t } = useTranslation()
  const alerts = useAlerts('active')
  const list = alerts.data?.data ?? []
  return (
    <Card>
      <CardHeader
        title={t('overviewAlerts.title')}
        actions={<Link to="/waste?tab=alerts" className="text-[13px] font-medium text-brand hover:underline">{t('common.viewAll')}</Link>}
      />
      {alerts.isPending ? (
        <div className="p-5"><Skeleton className="h-24" /></div>
      ) : list.length === 0 ? (
        <p className="flex items-start gap-2 px-5 py-4 text-[13px] text-ink-2">
          <CircleCheck className="mt-0.5 size-4 shrink-0 text-good" aria-hidden="true" />
          {t('overviewAlerts.empty')}
        </p>
      ) : (
        <ul className="divide-y divide-line">
          {list.slice(0, 3).map((alert) => {
            const text = alertText(t, alert)
            return (
              <li key={alert.id}>
                <Link
                  to={alert.waste_event_id ? `/waste?event=${alert.waste_event_id}` : DETAIL_TYPES.has(alert.type) ? `/waste?tab=alerts&alert=${alert.id}` : '/waste?tab=alerts'}
                  className="flex items-start gap-3 px-5 py-3 hover:bg-surface-2"
                >
                  <SeverityBadge severity={alert.severity} className="mt-0.5 shrink-0" />
                  <span className="min-w-0 flex-1 text-[13px]">
                    <span className="block font-medium text-ink">{text.title}</span>
                    {text.detail && <span className="mt-0.5 block text-ink-3">{text.detail}</span>}
                  </span>
                </Link>
              </li>
            )
          })}
        </ul>
      )}
    </Card>
  )
}

/** Machine detail: deterministic findings for this machine in the last 30 days (docs/06 §3.3). */
export function MachineFindings({ machineId }) {
  const { t } = useTranslation()
  const events = useWasteEvents({ period: '30d', machine_id: machineId })
  const list = events.data?.data ?? []
  return (
    <Card>
      <CardHeader title={t('machineInsights.title')} />
      {events.isPending ? (
        <div className="p-5"><Skeleton className="h-20" /></div>
      ) : list.length === 0 ? (
        <p className="flex items-start gap-2 px-5 py-4 text-[13px] text-ink-2">
          <CircleCheck className="mt-0.5 size-4 shrink-0 text-good" aria-hidden="true" />
          {t('machineInsights.empty')}
        </p>
      ) : (
        <ul className="divide-y divide-line">
          {list.slice(0, 4).map((event) => (
            <li key={event.id}>
              <Link to={`/waste?event=${event.id}`} className="group flex items-start gap-3 px-5 py-3 hover:bg-surface-2">
                <span className="min-w-0 flex-1 text-[13px]">
                  <span className="flex flex-wrap items-center gap-1.5">
                    <span className="font-medium text-ink">{t(`wasteTypes.${event.type}`)}</span>
                    <MethodChip method={event.method} detail={event.type === 'excess_vs_baseline' ? 'CUSUM' : undefined} />
                  </span>
                  <span className="mt-0.5 block text-ink-3">
                    {event.type === 'excess_vs_baseline'
                      ? t('alertText.drift', { machine: event.machine.code, pct: `${formatNumber(event.deviation_pct, 1)}%` })
                      : `${formatDate(event.started_at, 'dateTime')} · ${formatEur(event.eur)}`}
                  </span>
                </span>
                <SeverityBadge severity={event.severity} className="shrink-0" />
                <ArrowRight className="mt-1 size-4 shrink-0 text-ink-3 opacity-0 transition-opacity group-hover:opacity-100" aria-hidden="true" />
              </Link>
            </li>
          ))}
        </ul>
      )}
    </Card>
  )
}
