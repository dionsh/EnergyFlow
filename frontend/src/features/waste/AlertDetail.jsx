import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { Bar, BarChart, CartesianGrid, Cell, ReferenceLine, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { ArrowRight, CircleAlert } from 'lucide-react'
import { formatDate, formatDuration, formatEur, formatKw, formatNumber, formatTime } from '../../lib/format'
import { Button } from '../../components/ui/Button'
import { MethodChip, SeverityBadge } from '../../components/ui/Chips'
import { Modal } from '../../components/ui/Overlay'
import { Callout, ErrorState, Skeleton } from '../../components/ui/States'
import { ChartTooltip } from '../../components/charts/ChartTooltip'
import { useAlert } from '../data'
import { alertText, monthLabel } from './alertText'

const axis = { fontSize: 12, fill: 'var(--text-3)' }
const dayIso = (date) => `${date}T12:00:00Z`

function Figure({ label, value, hint }) {
  return (
    <div className="rounded-md border border-line px-3 py-2.5">
      <p className="text-xs text-ink-2">{label}</p>
      <p className="mt-0.5 text-lg font-semibold tracking-[-0.01em] text-ink">{value}</p>
      {hint && <p className="text-[11.5px] text-ink-3">{hint}</p>}
    </div>
  )
}

/** Peak power per minute around the spike, against the statistical limit and the nameplate. */
function SpikeChart({ series, limit, overload }) {
  const { t } = useTranslation()
  const top = Math.max(limit, overload ?? 0, ...series.map((m) => m.peak_kw))
  return (
    <ResponsiveContainer width="100%" height={220}>
      <BarChart data={series} margin={{ top: 18, right: 8, bottom: 0, left: 0 }}>
        <CartesianGrid vertical={false} stroke="var(--border)" />
        <XAxis dataKey="t" tickFormatter={formatTime} tick={axis} tickLine={false} axisLine={{ stroke: 'var(--border-strong)' }} minTickGap={28} />
        <YAxis tick={axis} tickLine={false} axisLine={false} width={40} domain={[0, Math.ceil(top * 1.1)]} tickFormatter={(v) => formatNumber(v, 0)} />
        <Tooltip
          cursor={{ fill: 'var(--surface-2)' }}
          content={
            <ChartTooltip
              formatLabel={formatTime}
              rows={(m) => [
                { label: t('alertDetail.peakMinute'), value: formatKw(m.peak_kw), swatch: m.spike ? 'var(--critical)' : 'var(--series-1)' },
                { label: t('alertDetail.avgMinute'), value: formatKw(m.avg_kw) },
              ]}
            />
          }
        />
        <Bar dataKey="peak_kw" isAnimationActive={false} radius={[2, 2, 0, 0]}>
          {series.map((m) => <Cell key={m.t} fill={m.spike ? 'var(--critical)' : 'var(--series-1)'} fillOpacity={m.spike ? 0.85 : 0.45} />)}
        </Bar>
        <ReferenceLine y={limit} stroke="var(--text-2)" strokeDasharray="4 3" />
        {overload && <ReferenceLine y={overload} stroke="var(--critical)" strokeDasharray="2 3" />}
      </BarChart>
    </ResponsiveContainer>
  )
}

function Key({ swatch, children }) {
  return (
    <span className="inline-flex items-center gap-1.5">
      {swatch}
      {children}
    </span>
  )
}

function ChartKey({ limit, overload }) {
  const { t } = useTranslation()
  const line = (color, dash) => <svg width="18" height="6" aria-hidden="true"><line x1="0" y1="3" x2="18" y2="3" stroke={color} strokeWidth="1.5" strokeDasharray={dash} /></svg>
  const box = (color, opacity) => <span className="size-2.5 rounded-[2px]" style={{ background: color, opacity }} aria-hidden="true" />
  return (
    <p className="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-[11.5px] text-ink-3">
      <Key swatch={box('var(--critical)', 0.85)}>{t('alertDetail.keySpike')}</Key>
      <Key swatch={box('var(--series-1)', 0.45)}>{t('alertDetail.keyNormal')}</Key>
      <Key swatch={line('var(--text-2)', '4 3')}>{t('alertDetail.limitLine', { kw: formatKw(limit) })}</Key>
      {overload && <Key swatch={line('var(--critical)', '2 3')}>{t('alertDetail.overloadLine', { kw: formatKw(overload) })}</Key>}
    </p>
  )
}

/**
 * "Why am I seeing this?" for an alert that is not a waste episode: power spikes,
 * low power factor and peak coincidence. Everything shown comes from the alert's
 * evidence object.
 */
export function AlertDetail({ alertId, onClose }) {
  const { t } = useTranslation()
  const query = useAlert(alertId)
  const alert = query.data?.data
  const text = alert ? alertText(t, alert) : null

  return (
    <Modal
      open={Boolean(alertId)}
      onClose={onClose}
      wide
      title={text?.title ?? t('alertDetail.title')}
      footer={
        <>
          {alert?.machine && (
            <Link to={`/machines/${alert.machine.id}`} onClick={onClose} className="mr-auto inline-flex items-center gap-1 text-[13px] font-medium text-brand hover:underline">
              {t('alertDetail.openMachine', { code: alert.machine.code })}
              <ArrowRight className="size-3.5" aria-hidden="true" />
            </Link>
          )}
          <Button variant="secondary" onClick={onClose}>{t('common.close')}</Button>
        </>
      }
    >
      {query.isPending ? (
        <Skeleton className="h-80" />
      ) : query.isError ? (
        <ErrorState error={query.error} onRetry={query.refetch} />
      ) : alert.type === 'LOW_PF' ? (
        <PowerFactorBody alert={alert} />
      ) : alert.type === 'PEAK_COINCIDENCE' ? (
        <PeakBody alert={alert} />
      ) : (
        <SpikeBody alert={alert} />
      )}
    </Modal>
  )
}

function SpikeBody({ alert }) {
  const { t } = useTranslation()
  const p = alert.params
  const e = alert.evidence
  const b = e.baseline ?? {}
  const overloadKw = e.rated_kw ? e.rated_kw * (e.thresholds?.overload_pct ?? 110) / 100 : null
  const slot = b.hour_of_day === null || b.hour_of_day === undefined
    ? t('alertDetail.allHours', { days: t(`alertDetail.dayType.${b.day_type}`) })
    : t('alertDetail.slot', { days: t(`alertDetail.dayType.${b.day_type}`), from: String(b.hour_of_day).padStart(2, '0'), to: String((b.hour_of_day + 1) % 24).padStart(2, '0') })

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap items-center gap-2 text-[13px] text-ink-2">
        <SeverityBadge severity={alert.severity} />
        <MethodChip method={alert.method} />
        <span>{t(`alertsTab.status.${alert.status}`)}</span>
        <span className="text-ink-3">· {formatDate(p.since, 'dateTime')}</span>
      </div>

      <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
        <Figure label={t('alertDetail.peak')} value={formatKw(p.peak_kw)} hint={t('alertDetail.z', { z: formatNumber(p.z, 1) })} />
        <Figure label={t('alertDetail.normal')} value={formatKw(p.normal_kw)} hint={slot} />
        <Figure label={t('alertDetail.rated')} value={p.rated_kw ? formatKw(p.rated_kw) : '—'} hint={p.rated_pct ? t('alertDetail.ofRating', { pct: `${formatNumber(p.rated_pct, 0)}%` }) : null} />
        <Figure label={t('alertDetail.duration')} value={formatDuration(p.duration_s)} hint={alert.status === 'resolved' ? null : t('alertDetail.ongoing')} />
      </div>

      {e.series?.length > 0 && (
        <div>
          <p className="mb-1 text-xs font-medium text-ink-2">{t('alertDetail.chartTitle')}</p>
          <SpikeChart series={e.series} limit={e.limit_kw} overload={overloadKw} />
          <ChartKey limit={e.limit_kw} overload={overloadKw} />
        </div>
      )}

      {p.overload && <Callout tone="critical">{t('alertDetail.overloadAdvice', { pct: `${formatNumber(p.rated_pct, 0)}%` })}</Callout>}

      <div className="rounded-md bg-surface-2 px-4 py-3 text-[12.5px] text-ink-2">
        <p className="font-medium text-ink">{t('alertDetail.whyTitle')}</p>
        <p className="mt-1">{t('alertDetail.rule', { z: formatNumber(e.thresholds?.z, 0), min: e.thresholds?.min_minutes, clear: e.thresholds?.clear_minutes, overload: formatNumber(e.thresholds?.overload_pct, 0) })}</p>
        <p className="mt-1">
          {t('alertDetail.baseline', {
            median: formatKw(b.median_kw),
            mad: formatKw(b.mad_kw),
            samples: b.samples,
            slot,
            from: b.window ? formatDate(dayIso(b.window[0])) : '',
            to: b.window ? formatDate(dayIso(b.window[1])) : '',
          })}
        </p>
      </div>
    </div>
  )
}

function AlertMeta({ alert, children }) {
  const { t } = useTranslation()
  return (
    <div className="flex flex-wrap items-center gap-2 text-[13px] text-ink-2">
      <SeverityBadge severity={alert.severity} />
      <MethodChip method={alert.method} />
      <span>{t(`alertsTab.status.${alert.status}`)}</span>
      {children && <span className="text-ink-3">· {children}</span>}
    </div>
  )
}

function Why({ children }) {
  const { t } = useTranslation()
  return (
    <div className="rounded-md bg-surface-2 px-4 py-3 text-[12.5px] text-ink-2">
      <p className="font-medium text-ink">{t('alertDetail.whyTitle')}</p>
      {children}
    </div>
  )
}

/** Site cos φ over the billing month: what ERO charges, who draws it, and the correction that removes it. */
function PowerFactorBody({ alert }) {
  const { t } = useTranslation()
  const p = alert.params
  const e = alert.evidence
  const o = e.observed ?? {}
  const c = e.compensation ?? {}
  const cos = (value) => formatNumber(value, 3, 3)
  const kvarh = (value) => `${formatNumber(value, 0)} kVArh`
  const target = formatNumber(e.thresholds?.cos_phi, 2, 2)
  const poor = formatNumber(e.thresholds?.poor_cos_phi, 2, 2)
  const chargeHint = !p.billed
    ? t('alertDetail.pf.notCharged')
    : e.charge?.projected_eur
      ? t('alertDetail.pf.projected', { eur: formatEur(e.charge.projected_eur) })
      : t('alertDetail.pf.chargeHint', { rate: formatNumber((e.charge?.rate_eur_kvarh ?? 0) * 100, 2, 2) })

  return (
    <div className="flex flex-col gap-4">
      <AlertMeta alert={alert}>{monthLabel(t, e.period?.month)}</AlertMeta>

      <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
        <Figure label={t('alertDetail.pf.cos')} value={cos(o.cos_phi)} hint={t('alertDetail.pf.cosHint', { threshold: target })} />
        <Figure label={t('alertDetail.pf.excess')} value={kvarh(o.excess_kvarh)} hint={t('alertDetail.pf.excessHint', { kvarh: kvarh(o.kvarh), allowed: kvarh(o.allowed_kvarh) })} />
        <Figure label={t('alertDetail.pf.charge')} value={p.billed ? formatEur(e.charge?.eur) : '—'} hint={chargeHint} />
        <Figure label={t('alertDetail.pf.capacitor')} value={`${formatNumber(c.kvar, 1)} kvar`} hint={t('alertDetail.pf.capacitorHint', { target, kw: formatKw(c.working_kw) })} />
      </div>

      {e.machines?.length > 0 && (
        <div>
          <p className="mb-1.5 text-xs font-medium text-ink-2">{t('alertDetail.pf.machinesTitle')}</p>
          <table className="w-full border-collapse text-[13px]">
            <thead>
              <tr className="border-b border-line text-left text-xs text-ink-3">
                <th className="py-1.5 pr-3 font-medium">{t('alertDetail.pf.machine')}</th>
                <th className="px-3 py-1.5 text-right font-medium">cos φ</th>
                <th className="px-3 py-1.5 text-right font-medium">kVArh</th>
                <th className="py-1.5 pl-3 text-right font-medium">{t('alertDetail.pf.share')}</th>
              </tr>
            </thead>
            <tbody>
              {e.machines.map((m) => (
                <tr key={m.code} className="border-b border-line last:border-0">
                  <td className="py-1.5 pr-3">
                    <span className="font-medium text-ink">{m.code}</span> <span className="text-ink-3">{m.name}</span>
                  </td>
                  <td className="whitespace-nowrap px-3 py-1.5 text-right tabular text-ink">
                    {m.poor && <CircleAlert className="mr-1 inline size-3.5 -translate-y-px text-warning-text" aria-label={t('alertDetail.pf.poor')} />}
                    {cos(m.cos_phi)}
                  </td>
                  <td className="px-3 py-1.5 text-right tabular text-ink-2">{formatNumber(m.kvarh, 0)}</td>
                  <td className="py-1.5 pl-3 text-right tabular text-ink-2">{formatNumber(m.share * 100, 0)}%</td>
                </tr>
              ))}
            </tbody>
          </table>
          <p className="mt-1.5 text-[11.5px] text-ink-3">
            {e.machines.some((m) => m.poor) && `${t('alertDetail.pf.poorKey', { poor })} `}
            {e.unmonitored_kvarh > 0 && t('alertDetail.pf.unmonitored', { kvarh: kvarh(e.unmonitored_kvarh) })}
          </p>
        </div>
      )}

      <Callout tone={p.billed ? 'warning' : 'info'}>
        {p.billed ? t('alertDetail.pf.advice', { kvar: `${formatNumber(c.kvar, 1)} kvar`, target }) : t('alertDetail.pf.unbilledAdvice')}
      </Callout>

      <Why>
        <p className="mt-1">{t('alertDetail.pf.rule', { threshold: target, poor })}</p>
        <p className="mt-1">{t('alertDetail.pf.formula')}</p>
        <p className="mt-1 text-ink-3">{t('alertDetail.pf.source')}</p>
      </Why>
    </div>
  )
}

/** Site power per quarter-hour around the month's peak: the flexible loads stacked on the rest. */
function PeakChart({ series, achievable }) {
  const { t } = useTranslation()
  const data = series.map((q) => ({ ...q, rest: Math.max(0, q.kw - q.flexible_kw) }))
  return (
    <ResponsiveContainer width="100%" height={220}>
      <BarChart data={data} margin={{ top: 12, right: 8, bottom: 0, left: 0 }}>
        <CartesianGrid vertical={false} stroke="var(--border)" />
        <XAxis dataKey="t" tickFormatter={formatTime} tick={axis} tickLine={false} axisLine={{ stroke: 'var(--border-strong)' }} minTickGap={28} />
        <YAxis tick={axis} tickLine={false} axisLine={false} width={40} domain={[0, 'auto']} tickFormatter={(v) => formatNumber(v, 0)} />
        <Tooltip
          cursor={{ fill: 'var(--surface-2)' }}
          content={
            <ChartTooltip
              formatLabel={(iso) => formatDate(iso, 'dateTime')}
              rows={(q) => [
                { label: t('alertDetail.peak.site'), value: formatKw(q.kw) },
                { label: t('alertDetail.peak.keyRest'), value: formatKw(q.rest), swatch: 'var(--series-1)' },
                { label: t('alertDetail.peak.keyFlexible'), value: formatKw(q.flexible_kw), swatch: 'var(--series-2)' },
              ]}
            />
          }
        />
        <Bar dataKey="rest" stackId="site" fill="var(--series-1)" stroke="var(--surface)" strokeWidth={1} isAnimationActive={false} />
        <Bar dataKey="flexible_kw" stackId="site" fill="var(--series-2)" stroke="var(--surface)" strokeWidth={1} radius={[2, 2, 0, 0]} isAnimationActive={false} />
        <ReferenceLine y={achievable} stroke="var(--text-2)" strokeDasharray="4 3" />
      </BarChart>
    </ResponsiveContainer>
  )
}

/** The quarter-hour that sets the month's engaged-power charge, and what the flexible loads added to it. */
function PeakBody({ alert }) {
  const { t } = useTranslation()
  const p = alert.params
  const e = alert.evidence
  const box = (color) => <span className="size-2.5 rounded-[2px]" style={{ background: color }} aria-hidden="true" />
  const dashed = (
    <svg width="18" height="6" aria-hidden="true">
      <line x1="0" y1="3" x2="18" y2="3" stroke="var(--text-2)" strokeWidth="1.5" strokeDasharray="4 3" />
    </svg>
  )
  const tag = 'rounded-[3px] bg-surface-2 px-1 text-[11px] text-ink-2'

  return (
    <div className="flex flex-col gap-4">
      <AlertMeta alert={alert}>{monthLabel(t, e.period?.month)}</AlertMeta>

      <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
        <Figure label={t('alertDetail.peak.peak')} value={formatKw(e.peak?.kw)} hint={formatDate(e.peak?.t, 'dateTime')} />
        <Figure label={t('alertDetail.peak.billed')} value={formatEur(e.charge?.billed_eur)} hint={t('alertDetail.peak.billedHint', { rate: formatEur(e.charge?.rate_eur_kw_month) })} />
        <Figure label={t('alertDetail.peak.without')} value={formatKw(e.achievable?.kw)} hint={formatDate(e.achievable?.t, 'dateTime')} />
        <Figure label={t('alertDetail.peak.avoidable')} value={formatKw(e.avoidable_kw)} hint={t('alertDetail.peak.avoidableHint', { eur: formatEur(e.charge?.avoidable_eur) })} />
      </div>

      {e.series?.length > 0 && (
        <div>
          <p className="mb-1 text-xs font-medium text-ink-2">{t('alertDetail.peak.chartTitle')}</p>
          <PeakChart series={e.series} achievable={e.achievable?.kw ?? 0} />
          <p className="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-[11.5px] text-ink-3">
            <Key swatch={box('var(--series-1)')}>{t('alertDetail.peak.keyRest')}</Key>
            <Key swatch={box('var(--series-2)')}>{t('alertDetail.peak.keyFlexible')}</Key>
            <Key swatch={dashed}>{t('alertDetail.peak.achievableLine', { kw: formatKw(e.achievable?.kw) })}</Key>
          </p>
        </div>
      )}

      {e.loads?.length > 0 && (
        <div>
          <p className="mb-1.5 text-xs font-medium text-ink-2">{t('alertDetail.peak.loadsTitle', { time: formatDate(e.peak?.t, 'dateTime') })}</p>
          <ul className="flex flex-wrap gap-2 text-[12.5px]">
            {e.loads.map((l) => (
              <li key={l.code} className="inline-flex items-center gap-1.5 rounded-sm border border-line px-2 py-1">
                <span className="font-medium text-ink">{l.code}</span>
                <span className="tabular text-ink-2">{formatKw(l.kw)}</span>
                {l.flexible && <span className={tag}>{t('alertDetail.peak.flexible')}</span>}
                {l.started && <span className={tag}>{t('alertDetail.peak.started')}</span>}
              </li>
            ))}
            {e.unmonitored_kw > 0 && (
              <li className="inline-flex items-center gap-1.5 rounded-sm border border-dashed border-line px-2 py-1 text-ink-3">
                {t('alertDetail.peak.unmonitored')} <span className="tabular">{formatKw(e.unmonitored_kw)}</span>
              </li>
            )}
          </ul>
        </div>
      )}

      <Callout tone={alert.severity === 'warning' ? 'warning' : 'info'}>
        {t('alertDetail.peak.advice', { flexible: p.flexible, kw: formatKw(e.achievable?.kw), eur: formatEur(e.charge?.avoidable_eur) })}
      </Callout>

      <Why>
        <p className="mt-1">{t('alertDetail.peak.rule', { min: formatKw(e.thresholds?.min_avoidable_kw) })}</p>
        <p className="mt-1">{t('alertDetail.peak.p95', { kw: formatKw(e.p95_kw) })}</p>
        <p className="mt-1 text-ink-3">{t('alertDetail.peak.source')}</p>
      </Why>
    </div>
  )
}
