import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Check, CircleAlert, CircleCheck, FlaskConical, Workflow } from 'lucide-react'
import { api } from '../../lib/api'
import { cn } from '../../lib/cn'
import { formatCo2, formatDate, formatEur, formatKw, formatKwh, formatNumber, formatPercent } from '../../lib/format'
import { hasRole } from '../../lib/roles'
import { MachineIcon } from '../../lib/machineTypes'
import { useApiErrors } from '../../hooks/useApiErrors'
import { useAuth } from '../../providers/AuthProvider'
import { useToast } from '../../providers/ToastProvider'
import { Badge } from '../../components/ui/Badge'
import { Button } from '../../components/ui/Button'
import { MethodChip } from '../../components/ui/Chips'
import { Drawer, Modal } from '../../components/ui/Overlay'
import { Callout, ErrorState, Skeleton } from '../../components/ui/States'
import { useRecommendation } from '../data'
import { oppText } from './oppText'
import { Assumptions, ReplayChart } from './WhatIfParts'

const dayIso = (date) => `${date}T12:00:00Z`

export function BandBadge({ band }) {
  const { t } = useTranslation()
  return <Badge tone={band === 'high' ? 'brand' : 'neutral'}>{t(`opportunities.band.${band}`)}</Badge>
}

export function TagChip({ tag }) {
  const { t } = useTranslation()
  return <Badge tone={tag === 'eur_co2' ? 'brand' : 'neutral'}>{t(`opportunities.tag.${tag}`)}</Badge>
}

export function StatusChip({ status }) {
  const { t } = useTranslation()
  const tone = { implemented: 'good', verified: 'good', accepted: 'info', dismissed: 'neutral', proposed: 'neutral' }[status]
  return <Badge tone={tone} icon={['implemented', 'verified'].includes(status) ? CircleCheck : undefined}>{t(`opportunities.status.${status}`)}</Badge>
}

function useInvalidate() {
  const queryClient = useQueryClient()
  return () => ['recommendations', 'policies', 'commands', 'what-if'].forEach((key) => queryClient.invalidateQueries({ queryKey: [key] }))
}

/** "Accepting will create: …" — states the consequence, then lets the user choose how EnergyFlow acts. */
export function AcceptDialog({ rec, open, onClose }) {
  const { t } = useTranslation()
  const toast = useToast()
  const invalidate = useInvalidate()
  const { errorMessage } = useApiErrors()
  const [mode, setMode] = useState('auto')
  const accept = useMutation({
    mutationFn: () => api.post(`/recommendations/${rec.id}/accept`, { mode }),
    onSuccess: () => {
      invalidate()
      toast({ tone: 'good', title: oppText(t, rec).title, body: t(`opportunities.status.${rec.policy ? 'implemented' : 'accepted'}`) })
      onClose()
    },
  })
  return (
    <Modal
      open={open}
      onClose={onClose}
      title={t('opportunities.acceptTitle')}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>{t('common.cancel')}</Button>
          <Button icon={Check} loading={accept.isPending} onClick={() => accept.mutate()}>{t('opportunities.acceptConfirm')}</Button>
        </>
      }
    >
      <p className="font-medium text-ink">{oppText(t, rec).title}</p>
      <p className="mt-2">
        {rec.policy
          ? t('opportunities.acceptPolicy', { code: rec.machine?.code, grace: rec.policy.params?.grace_min ?? 15 })
          : t('opportunities.acceptTask')}
      </p>
      {rec.policy && (
        <fieldset className="mt-4">
          <legend className="mb-2 text-[13px] font-medium text-ink">{t('opportunities.mode')}</legend>
          {['auto', 'approve', 'notify'].map((m) => (
            <label key={m} className="flex cursor-pointer items-center gap-2.5 py-1 text-[13px] text-ink">
              <input type="radio" name="mode" value={m} checked={mode === m} onChange={() => setMode(m)} className="size-4 accent-[var(--brand)]" />
              {t(`opportunities.modes.${m}`)}
            </label>
          ))}
        </fieldset>
      )}
      {accept.isError && <Callout tone="critical" icon={CircleAlert} className="mt-3">{errorMessage(accept.error)}</Callout>}
    </Modal>
  )
}

export function DismissDialog({ rec, open, onClose }) {
  const { t } = useTranslation()
  const invalidate = useInvalidate()
  const [reason, setReason] = useState('')
  const dismiss = useMutation({
    mutationFn: () => api.post(`/recommendations/${rec.id}/dismiss`, { reason }),
    onSuccess: () => {
      invalidate()
      onClose()
    },
  })
  return (
    <Modal
      open={open}
      onClose={onClose}
      title={t('opportunities.dismissTitle')}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>{t('common.cancel')}</Button>
          <Button loading={dismiss.isPending} disabled={reason.trim().length < 3} onClick={() => dismiss.mutate()}>{t('opportunities.dismiss')}</Button>
        </>
      }
    >
      <label htmlFor={`dismiss-${rec.id}`} className="mb-1.5 block text-[13px] font-medium text-ink">{t('wasteDetail.dismissReason')}</label>
      <textarea
        id={`dismiss-${rec.id}`}
        rows={3}
        maxLength={200}
        value={reason}
        onChange={(e) => setReason(e.target.value)}
        className="w-full rounded-sm border border-line-strong bg-surface px-3 py-2 text-sm text-ink hover:border-ink-3"
      />
      <p className="mt-1.5 text-[13px] text-ink-3">{t('opportunities.dismissHint')}</p>
    </Modal>
  )
}

/** Accept / Simulate / Dismiss / Mark done, depending on the state and the user's role. */
export function OpportunityActions({ rec, onSimulate, size = 'sm' }) {
  const { t } = useTranslation()
  const { user } = useAuth()
  const invalidate = useInvalidate()
  const [dialog, setDialog] = useState(null)
  const canAct = hasRole(user, 'manager')
  const done = useMutation({ mutationFn: () => api.post(`/recommendations/${rec.id}/implemented`), onSuccess: invalidate })
  return (
    <>
      {onSimulate && rec.what_if && (
        <Button size={size} variant="secondary" icon={FlaskConical} onClick={() => onSimulate(rec)}>{t('opportunities.simulate')}</Button>
      )}
      {canAct && rec.status === 'proposed' && <Button size={size} icon={Check} onClick={() => setDialog('accept')}>{t('opportunities.accept')}</Button>}
      {canAct && rec.status === 'accepted' && <Button size={size} icon={Check} loading={done.isPending} onClick={() => done.mutate()}>{t('opportunities.markDone')}</Button>}
      {rec.policy_id && (
        <Link to="/automations" className="inline-flex h-8 items-center gap-1.5 rounded-sm border border-line-strong bg-surface px-3 text-[13px] font-medium text-ink hover:bg-surface-2">
          <Workflow className="size-4" aria-hidden="true" />
          {t('opportunities.viewAutomation')}
        </Link>
      )}
      {canAct && ['proposed', 'accepted'].includes(rec.status) && (
        <Button size={size} variant="ghost" onClick={() => setDialog('dismiss')} className="ml-auto">{t('opportunities.dismiss')}</Button>
      )}
      <AcceptDialog key={`a${rec.id}`} rec={rec} open={dialog === 'accept'} onClose={() => setDialog(null)} />
      <DismissDialog key={`d${rec.id}`} rec={rec} open={dialog === 'dismiss'} onClose={() => setDialog(null)} />
    </>
  )
}

/** A ranked opportunity: what to do, what it saves per month, how hard it is. */
export function OpportunityCard({ rec, onOpen, onSimulate }) {
  const { t } = useTranslation()
  const text = oppText(t, rec)
  return (
    <article className="flex flex-col rounded-md border border-line bg-surface">
      <div className="flex flex-wrap items-center gap-1.5 px-4 pt-4">
        <BandBadge band={rec.priority_band} />
        <TagChip tag={rec.impact_tag} />
      </div>
      <button type="button" onClick={() => onOpen(rec.id)} className="group px-4 pt-2.5 text-left">
        <h3 className="text-[15px] font-semibold leading-snug text-ink group-hover:underline">{text.title}</h3>
        <p className="mt-1 line-clamp-3 text-[13px] text-ink-2">{text.body}</p>
      </button>
      <dl className="mx-4 mt-4 grid grid-cols-3 gap-2 border-t border-line pt-3">
        <div>
          <dt className="text-xs text-ink-3">€ / {t('opportunities.perMonth')}</dt>
          <dd className="text-lg font-semibold tracking-[-0.01em] text-ink">{formatEur(rec.per_month.eur)}</dd>
        </div>
        <div>
          <dt className="text-xs text-ink-3">kWh</dt>
          <dd className="text-[15px] font-medium text-ink">{formatNumber(rec.per_month.kwh, 0)}</dd>
        </div>
        <div>
          <dt className="text-xs text-ink-3">CO₂e</dt>
          <dd className="text-[15px] font-medium text-ink">{formatCo2(rec.per_month.co2_kg)}</dd>
        </div>
      </dl>
      <p className="mt-auto px-4 pb-3 pt-3 text-xs text-ink-3">
        {t(`opportunities.effort.${rec.effort}`)} · {t(`opportunities.capex.${rec.capex}`)} · {t('opportunities.confidence', { pct: formatPercent(rec.confidence, 0) })}
      </p>
      <div className="flex flex-wrap items-center gap-2 border-t border-line px-4 py-3">
        <OpportunityActions rec={rec} onSimulate={onSimulate} />
      </div>
    </article>
  )
}

function EvidenceLine({ rec }) {
  const { t } = useTranslation()
  const e = rec.evidence ?? {}
  switch (rec.generator) {
    case 'AfterHoursSchedule':
      return t('opportunities.evidence.episodes', { count: e.episodes_14d, kwh: formatKwh(e.kwh_14d ?? 0) })
    case 'CompressedAirLeak':
      return t('opportunities.evidence.leak', { nights: e.nights_with_signature, share: formatPercent(e.leak_share ?? 0, 0) })
    case 'EfficiencyDrift':
      return t('opportunities.evidence.drift', { pct: formatNumber(e.deviation_pct, 1), ref: formatKw(e.reference_kw ?? 0), since: e.since ? formatDate(dayIso(e.since)) : '—' })
    case 'TouShift':
      return t('opportunities.evidence.tou', { kwh: formatKwh(e.day_tariff_kwh_30d ?? 0), total: formatKwh(e.total_kwh_30d ?? 0) })
    default:
      return null
  }
}

/** One opportunity in depth: evidence, the 30-day replay and its assumptions. */
export function OpportunityDrawer({ id, onClose, onSimulate }) {
  const { t } = useTranslation()
  const query = useRecommendation(id)
  const rec = query.data?.data
  const text = rec ? oppText(t, rec) : null
  return (
    <Drawer
      open={Boolean(id)}
      onClose={onClose}
      title={text?.title ?? t('common.loading')}
      subtitle={rec?.machine ? `${rec.machine.code} · ${rec.machine.name}` : null}
      badges={rec && (
        <>
          <BandBadge band={rec.priority_band} />
          <TagChip tag={rec.impact_tag} />
          <StatusChip status={rec.status} />
          {rec.evidence?.method && <MethodChip method={rec.evidence.method} />}
        </>
      )}
      footer={rec && <OpportunityActions rec={rec} onSimulate={onSimulate && ((r) => { onClose(); onSimulate(r) })} size="md" />}
    >
      {query.isPending ? (
        <Skeleton className="h-80" />
      ) : query.isError ? (
        <ErrorState error={query.error} onRetry={query.refetch} />
      ) : (
        <div className="flex flex-col gap-5">
          <p className="text-[13px] text-ink-2">{text.body}</p>
          {rec.status === 'dismissed' && <Callout tone="info">{t('opportunities.dismissed', { reason: rec.dismissed_reason })}</Callout>}
          {rec.accepted_by && <p className="text-[12.5px] text-ink-3">{t('opportunities.acceptedBy', { name: rec.accepted_by, date: formatDate(rec.accepted_at) })}</p>}
          <div className="grid grid-cols-3 gap-2">
            {[
              [`€ / ${t('opportunities.perMonth')}`, formatEur(rec.per_month.eur)],
              ['kWh', formatNumber(rec.per_month.kwh, 0)],
              ['CO₂e', formatCo2(rec.per_month.co2_kg)],
            ].map(([label, value]) => (
              <div key={label} className="rounded-md border border-line px-3 py-2.5">
                <p className="text-xs text-ink-2">{label}</p>
                <p className="mt-0.5 text-lg font-semibold tracking-[-0.01em] text-ink">{value}</p>
              </div>
            ))}
          </div>
          <section>
            <h3 className="mb-2 text-[13px] font-semibold text-ink">{t('opportunities.evidenceTitle')}</h3>
            <p className="border-l-2 border-line pl-3 text-[13px] text-ink-2"><EvidenceLine rec={rec} /></p>
          </section>
          {rec.replay && (
            <>
              <section>
                <h3 className="mb-2 text-[13px] font-semibold text-ink">{t('opportunities.replayTitle')}</h3>
                <ReplayChart daily={rec.replay.daily} height={180} />
              </section>
              <section>
                <h3 className="mb-2 text-[13px] font-semibold text-ink">{t('opportunities.assumptionsTitle')}</h3>
                <Assumptions result={rec.replay} />
              </section>
            </>
          )}
        </div>
      )}
    </Drawer>
  )
}

/** Compact row for accepted / implemented / dismissed opportunities. */
export function OpportunityRow({ rec, onOpen }) {
  const { t } = useTranslation()
  return (
    <li>
      <button type="button" onClick={() => onOpen(rec.id)} className={cn('flex w-full items-center gap-3 px-5 py-3 text-left hover:bg-surface-2')}>
        {rec.machine && <MachineIcon type={rec.machine.type} className="size-4 shrink-0 text-ink-3" />}
        <span className="min-w-0 flex-1">
          <span className="block truncate text-[13px] font-medium text-ink">{oppText(t, rec).title}</span>
          <span className="block text-xs text-ink-3">
            {formatEur(rec.per_month.eur)} / {t('opportunities.perMonth')}
            {rec.accepted_at && ` · ${formatDate(rec.accepted_at)}`}
          </span>
        </span>
        <StatusChip status={rec.status} />
      </button>
    </li>
  )
}
