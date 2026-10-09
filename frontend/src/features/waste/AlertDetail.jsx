import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { Bar, BarChart, CartesianGrid, Cell, ReferenceLine, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { ArrowRight } from 'lucide-react'
import { formatDate, formatDuration, formatKw, formatNumber, formatTime } from '../../lib/format'
import { Button } from '../../components/ui/Button'
import { MethodChip, SeverityBadge } from '../../components/ui/Chips'
import { Modal } from '../../components/ui/Overlay'
import { Callout, ErrorState, Skeleton } from '../../components/ui/States'
import { ChartTooltip } from '../../components/charts/ChartTooltip'
import { useAlert } from '../data'
import { alertText } from './alertText'

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
 * "Why am I seeing this?" for an alert that is not a waste episode (today: power
 * spikes). Everything shown comes from the alert's evidence object.
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
