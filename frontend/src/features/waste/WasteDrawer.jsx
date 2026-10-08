import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { ArrowRight, CircleCheck, Clock3, Lightbulb, Lock } from 'lucide-react'
import { api } from '../../lib/api'
import { formatCo2, formatDate, formatDuration, formatEur, formatKw, formatKwh, formatNumber, formatPercent, formatTime } from '../../lib/format'
import { hasRole } from '../../lib/roles'
import { useAuth } from '../../providers/AuthProvider'
import { Badge } from '../../components/ui/Badge'
import { Button } from '../../components/ui/Button'
import { MethodChip, SeverityBadge } from '../../components/ui/Chips'
import { Drawer, Modal } from '../../components/ui/Overlay'
import { Callout, ErrorState, Skeleton } from '../../components/ui/States'
import { useWasteEvent } from '../data'
import { DriftChart, EpisodeChart } from './WasteCharts'
import { oppText } from '../opportunities/oppText'

const cents = (rate) => `${formatNumber(rate * 100, 2)} c/kWh`
const dayIso = (date) => `${date}T12:00:00Z`

export function StatusBadge({ event }) {
  const { t } = useTranslation()
  if (event.action_status === 'acted') return <Badge tone="good" icon={CircleCheck}>{t('wastePage.status.acted')}</Badge>
  if (event.action_status === 'dismissed') return <Badge>{t('wastePage.status.dismissed')}</Badge>
  if (event.ongoing) return <Badge tone="warning" icon={Clock3}>{t('wastePage.status.ongoing')}</Badge>
  return <Badge>{t('wastePage.status.ended')}</Badge>
}

function Figure({ label, value }) {
  return (
    <div className="rounded-md border border-line px-3 py-2.5">
      <p className="text-xs text-ink-2">{label}</p>
      <p className="mt-0.5 text-lg font-semibold tracking-[-0.01em] text-ink">{value}</p>
    </div>
  )
}

function Evidence({ event }) {
  const { t } = useTranslation()
  const e = event.evidence ?? {}
  const lines = []
  if (event.type === 'excess_vs_baseline') {
    lines.push(t('wasteDetail.rule.cusum', { days: e.reference?.days, k: e.cusum?.k, h: e.cusum?.h }))
    lines.push(t('wasteDetail.referenceLine', {
      ref: formatKw(e.reference?.mean_kw),
      from: formatDate(dayIso(e.reference?.from)),
      to: formatDate(dayIso(e.reference?.to)),
      recent: formatKw(e.recent?.mean_kw),
    }))
    if (e.cusum?.onset) lines.push(t('wasteDetail.cusum', { onset: formatDate(dayIso(e.cusum.onset)), alarm: formatDate(dayIso(e.cusum.alarm)) }))
  } else {
    const thresholds = e.thresholds ?? {}
    lines.push(event.type === 'idle'
      ? t('wasteDetail.rule.idle', { min: thresholds.min_minutes, warmup: thresholds.warmup_minutes })
      : t('wasteDetail.rule.after_hours', { kw: formatKw(thresholds.off_kw ?? 0), min: thresholds.min_minutes }))
    if (e.observed) lines.push(t('wasteDetail.observed', { kw: formatKw(e.observed.avg_kw), max: formatKw(e.observed.max_kw) }))
    const leak = (e.signals ?? []).find((s) => s.key === 'leak_signature')
    if (leak) lines.push(t('wasteDetail.leak', { share: formatPercent(leak.load_share, 0), cycles: formatNumber(leak.cycles_per_hour, 0) }))
    if (e.rates && e.observed) {
      lines.push(t('wasteDetail.tariff', {
        high: formatKwh(e.observed.kwh_high),
        low: formatKwh(e.observed.kwh_low),
        rateHigh: cents(e.rates.high),
        rateLow: cents(e.rates.low),
      }))
    }
  }
  if (e.factor) lines.push(t('wasteDetail.factor', { value: formatNumber(e.factor.value, 3), source: e.factor.source }))

  return (
    <section className="mt-5">
      <h3 className="mb-2 text-[13px] font-semibold text-ink">{t('wasteDetail.why')}</h3>
      <ul className="flex flex-col gap-2 border-l-2 border-line pl-3 text-[13px] leading-snug text-ink-2">
        {lines.map((line) => <li key={line}>{line}</li>)}
      </ul>
    </section>
  )
}

function DismissDialog({ event, open, onClose }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const [reason, setReason] = useState('')
  const dismiss = useMutation({
    mutationFn: () => api.post(`/waste-events/${event.id}/dismiss`, { reason }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['waste'] })
      queryClient.invalidateQueries({ queryKey: ['alerts'] })
      onClose()
    },
  })
  return (
    <Modal
      open={open}
      onClose={onClose}
      title={t('wasteDetail.dismissTitle')}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>{t('common.cancel')}</Button>
          <Button loading={dismiss.isPending} disabled={reason.trim().length < 3} onClick={() => dismiss.mutate()}>{t('wasteDetail.dismissConfirm')}</Button>
        </>
      }
    >
      <label htmlFor="dismiss-reason" className="mb-1.5 block text-[13px] font-medium text-ink">{t('wasteDetail.dismissReason')}</label>
      <textarea
        id="dismiss-reason"
        rows={3}
        maxLength={200}
        value={reason}
        onChange={(e) => setReason(e.target.value)}
        className="w-full rounded-sm border border-line-strong bg-surface px-3 py-2 text-sm text-ink placeholder:text-ink-3 hover:border-ink-3"
      />
      <p className="mt-1.5 text-[13px] text-ink-3">{t('wasteDetail.dismissHint')}</p>
    </Modal>
  )
}

/** One waste episode: what happened, what it cost, why EnergyFlow flagged it, what to do. */
export function WasteDrawer({ eventId, onClose, actions }) {
  const { t } = useTranslation()
  const { user } = useAuth()
  const query = useWasteEvent(eventId)
  const [dismissing, setDismissing] = useState(false)
  const event = query.data?.data
  const canAct = hasRole(user, 'manager')

  let body
  if (query.isPending) body = <Skeleton className="h-80" />
  else if (query.isError) body = <ErrorState error={query.error} onRetry={query.refetch} />
  else {
    const e = event.evidence ?? {}
    body = (
      <>
        {event.projection && (
          <Callout tone="warning" icon={Clock3} className="mb-4">
            {t('wasteDetail.projection', {
              time: formatTime(event.projection.until),
              kwh: formatKwh(event.projection.kwh),
              eur: formatEur(event.projection.eur),
              co2: formatCo2(event.projection.co2_kg),
            })}
          </Callout>
        )}
        {event.action_status === 'acted' && <Callout tone="good" icon={CircleCheck} className="mb-4">{t('wasteDetail.acted')}</Callout>}
        {event.action_status === 'dismissed' && <Callout tone="info" className="mb-4">{t('wasteDetail.dismissed', { reason: event.dismissed_reason })}</Callout>}

        <div className="grid grid-cols-3 gap-2">
          <Figure label={t('wasteDetail.energy')} value={formatKwh(event.kwh)} />
          <Figure label={t('wasteDetail.cost')} value={formatEur(event.eur)} />
          <Figure label={t('wasteDetail.co2')} value={formatCo2(event.co2_kg)} />
        </div>

        <section className="mt-5">
          <p className="mb-2 text-[12.5px] text-ink-3">{event.type === 'excess_vs_baseline' ? t('wasteDetail.chartRunning') : t('wasteDetail.chartPower')}</p>
          {event.type === 'excess_vs_baseline' ? (
            <DriftChart daily={e.daily ?? []} referenceKw={e.reference?.mean_kw} onset={e.cusum?.onset} />
          ) : (
            <EpisodeChart points={event.series?.points ?? []} resolution={event.series?.resolution} scheduleEnd={e.schedule_end} start={event.started_at} end={event.ended_at} />
          )}
        </section>

        <Evidence event={event} />
        {event.opportunity && (
          <Link
            to={`/opportunities?opportunity=${event.opportunity.id}`}
            className="mt-5 flex items-center gap-3 rounded-md border border-line px-4 py-3 hover:bg-surface-2"
          >
            <Lightbulb className="size-4 shrink-0 text-brand" aria-hidden="true" />
            <span className="min-w-0 flex-1 text-[13px]">
              <span className="block font-medium text-ink">{oppText(t, event.opportunity).title}</span>
              <span className="block text-ink-3">{formatEur(event.opportunity.per_month.eur)} / {t('opportunities.perMonth')} · {t(`opportunities.status.${event.opportunity.status}`)}</span>
            </span>
            <span className="shrink-0 text-[13px] font-medium text-brand">{t('wasteDetail.seeOpportunity')} →</span>
          </Link>
        )}
        {!canAct && <p className="mt-5 flex items-center gap-1.5 text-[12.5px] text-ink-3"><Lock className="size-3.5" aria-hidden="true" />{t('wasteDetail.readOnly')}</p>}
      </>
    )
  }

  const subtitle = !event
    ? null
    : event.type === 'excess_vs_baseline'
      ? t('wasteDetail.drift', { date: formatDate(event.started_at), pct: `${formatNumber(event.deviation_pct, 1)}%` })
      : event.ongoing
        ? (event.type === 'idle'
          ? t('wasteDetail.ongoingIdle', { duration: formatDuration(event.duration_s) })
          : t('wasteDetail.ongoing', { duration: formatDuration(event.duration_s), time: formatTime(event.evidence?.schedule_end ?? event.started_at) }))
        : t('wasteDetail.ended', { from: formatDate(event.started_at, 'dateTime'), to: formatTime(event.ended_at), duration: formatDuration(event.duration_s) })

  return (
    <>
      <Drawer
        open={Boolean(eventId)}
        onClose={onClose}
        title={event ? t(`wasteDetail.${event.ongoing ? 'titleOngoing' : 'title'}.${event.type}`, { machine: event.machine.name }) : t('common.loading')}
        subtitle={subtitle}
        badges={event && (
          <>
            <SeverityBadge severity={event.severity} />
            <StatusBadge event={event} />
            <MethodChip method={event.method} detail={event.type === 'excess_vs_baseline' ? 'CUSUM' : undefined} />
          </>
        )}
        footer={event && (
          <>
            {actions?.(event)}
            <Link to={`/machines/${event.machine.id}`} className="inline-flex h-9 items-center gap-2 rounded-sm border border-line-strong bg-surface px-3.5 text-sm font-medium text-ink hover:bg-surface-2">
              {t('wasteDetail.openMachine')}
              <ArrowRight className="size-4" aria-hidden="true" />
            </Link>
            {canAct && event.action_status !== 'dismissed' && (
              <Button variant="ghost" onClick={() => setDismissing(true)} className="ml-auto">{t('wasteDetail.dismiss')}</Button>
            )}
          </>
        )}
      >
        {body}
      </Drawer>
      {event && <DismissDialog key={event.id} event={event} open={dismissing} onClose={() => setDismissing(false)} />}
    </>
  )
}
