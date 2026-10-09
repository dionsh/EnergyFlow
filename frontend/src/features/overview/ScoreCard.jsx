import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { ArrowDownRight, ArrowRight, ArrowUpRight, Info } from 'lucide-react'
import { cn } from '../../lib/cn'
import { formatKw, formatKwh, formatNumber, formatPercent } from '../../lib/format'
import { Button } from '../../components/ui/Button'
import { Card, CardBody, CardHeader } from '../../components/ui/Card'
import { Modal } from '../../components/ui/Overlay'
import { ErrorState, Skeleton } from '../../components/ui/States'
import { useScore } from '../data'

const ORDER = ['waste', 'schedule', 'health', 'peak', 'follow_through', 'coverage']
/** Where to go to improve each part. */
const FIX = { waste: '/waste', schedule: '/automations', health: '/waste?tab=alerts', peak: '/opportunities', follow_through: '/opportunities', coverage: '/devices' }

const band = (value) => (value === null || value === undefined ? 'bg-line-strong' : value >= 75 ? 'bg-good' : value >= 50 ? 'bg-warning' : 'bg-critical')

function Meter({ value, className }) {
  return (
    <span className={cn('relative block h-1.5 overflow-hidden rounded-full bg-surface-2', className)} aria-hidden="true">
      <span className={cn('absolute inset-y-0 left-0 rounded-full', band(value))} style={{ width: `${Math.max(2, value ?? 0)}%` }} />
    </span>
  )
}

/** The numbers behind each part, in words. */
function detail(t, key, d) {
  switch (key) {
    case 'waste':
      return t('score.detail.waste', { kwh: formatKwh(d.kwh), share: formatPercent(d.share) })
    case 'schedule':
      return t('score.detail.schedule', { kwh: formatKwh(d.kwh), share: formatPercent(d.share) })
    case 'health':
      return t('score.detail.health', { count: d.machines, share: formatPercent(d.share) })
    case 'peak':
      return d.load_factor === null ? '—' : t('score.detail.peak', { lf: formatPercent(d.load_factor, 0), peak: formatKw(d.peak_kw) })
    case 'follow_through':
      return t('score.detail.followThrough', { acted: d.acted, opportunities: d.opportunities, resolved: d.resolved_24h, alerts: d.alerts })
    case 'coverage':
      return t('score.detail.coverage', { metered: formatPercent(d.metered_share, 0), complete: formatPercent(d.completeness, 1) })
    default:
      return ''
  }
}

function HowDialog({ open, onClose, score }) {
  const { t } = useTranslation()
  return (
    <Modal open={open} onClose={onClose} title={t('score.howTitle')} wide footer={<Button variant="secondary" onClick={onClose}>{t('common.close')}</Button>}>
      <p className="mb-4 text-[13px] text-ink-2">{t('score.howIntro', { days: score.window.days })}</p>
      <table className="w-full border-collapse text-[13px]">
        <thead>
          <tr className="border-b border-line text-left text-xs text-ink-3">
            <th className="py-2 pr-3 font-medium">{t('score.part')}</th>
            <th className="py-2 pr-3 text-right font-medium">{t('score.weight')}</th>
            <th className="py-2 pr-3 text-right font-medium">{t('score.value')}</th>
            <th className="py-2 font-medium">{t('score.basis')}</th>
          </tr>
        </thead>
        <tbody>
          {ORDER.filter((key) => score.parts[key]).map((key) => {
            const part = score.parts[key]
            return (
              <tr key={key} className="border-b border-line align-top last:border-0">
                <td className="py-2.5 pr-3">
                  <p className="font-medium text-ink">{t(`score.parts.${key}`)}</p>
                  <p className="mt-0.5 text-[12px] text-ink-3">{t(`score.definition.${key}`)}</p>
                </td>
                <td className="py-2.5 pr-3 text-right tabular text-ink-2">{formatPercent(part.weight, 0)}</td>
                <td className="py-2.5 pr-3 text-right tabular font-semibold text-ink">{part.value === null ? '—' : formatNumber(part.value, 0)}</td>
                <td className="py-2.5 text-[12.5px] text-ink-2">{detail(t, key, part.detail)}</td>
              </tr>
            )
          })}
        </tbody>
      </table>
      <p className="mt-4 text-[12px] text-ink-3">{t('score.scaleNote')}</p>
    </Modal>
  )
}

/** EnergyFlow Score (docs/06 §3.1): the number, its change, and the one part to fix next. */
export function ScoreCard() {
  const { t } = useTranslation()
  const query = useScore()
  const [how, setHow] = useState(false)

  if (query.isPending) return <Skeleton className="h-72 rounded-lg" />
  if (query.isError) return <ErrorState error={query.error} onRetry={query.refetch} />
  const score = query.data.data
  if (score.score === null) return null
  const change = score.change
  const Arrow = change === null || Math.abs(change) < 0.5 ? null : change > 0 ? ArrowUpRight : ArrowDownRight

  return (
    <Card className="flex flex-col">
      <CardHeader
        title={t('score.title')}
        actions={
          <button type="button" onClick={() => setHow(true)} className="inline-flex items-center gap-1 text-[12.5px] font-medium text-brand hover:underline">
            <Info className="size-3.5" aria-hidden="true" />
            {t('score.how')}
          </button>
        }
      />
      <CardBody className="flex flex-1 flex-col gap-4">
        <div className="flex items-end justify-between gap-3">
          <p className="text-[44px] font-semibold leading-none tracking-[-0.02em] text-ink">
            {formatNumber(score.score, 0)}
            <span className="ml-1 text-base font-medium text-ink-3">/ 100</span>
          </p>
          {change !== null && (
            <p className={cn('flex items-center gap-1 text-[13px] font-medium', !Arrow ? 'text-ink-3' : change > 0 ? 'text-good-text' : 'text-critical-text')}>
              {Arrow && <Arrow className="size-4" aria-hidden="true" />}
              {t('score.change', { points: `${change > 0 ? '+' : change < 0 ? '−' : ''}${formatNumber(Math.abs(change), 1)}` })}
            </p>
          )}
        </div>
        <Meter value={score.score} className="h-2" />
        <ul className="flex flex-col gap-2">
          {ORDER.filter((key) => score.parts[key]).map((key) => (
            <li key={key} className="grid grid-cols-[minmax(0,1fr)_72px_28px] items-center gap-3 text-[12.5px]">
              <span className="truncate text-ink-2">{t(`score.parts.${key}`)}</span>
              <Meter value={score.parts[key].value} />
              <span className="text-right tabular font-medium text-ink">{score.parts[key].value === null ? '—' : formatNumber(score.parts[key].value, 0)}</span>
            </li>
          ))}
        </ul>
        {score.next && (
          <Link to={FIX[score.next.key]} className="mt-auto flex items-center justify-between gap-2 rounded-md border border-line px-3 py-2.5 text-[13px] hover:bg-surface-2">
            <span className="min-w-0">
              <span className="block text-[11.5px] text-ink-3">{t('score.fixNext')}</span>
              <span className="block truncate font-medium text-ink">{t(`score.parts.${score.next.key}`)} · {t('score.points', { points: formatNumber(score.next.points, 0) })}</span>
            </span>
            <ArrowRight className="size-4 shrink-0 text-ink-3" aria-hidden="true" />
          </Link>
        )}
      </CardBody>
      <HowDialog open={how} onClose={() => setHow(false)} score={score} />
    </Card>
  )
}
