import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { Moon, Sun, TriangleAlert } from 'lucide-react'
import { cn } from '../../lib/cn'
import { formatAgo, formatCo2, formatDuration, formatEur, formatKw, formatKwh, formatNumber, formatTime } from '../../lib/format'
import { MachineIcon } from '../../lib/machineTypes'
import { Badge } from '../../components/ui/Badge'
import { Callout } from '../../components/ui/States'
import { StateBadge } from '../../components/ui/StateBadge'
import { TurnOffButton } from '../control/TurnOff'

export function UpdatedLabel({ age }) {
  const { t } = useTranslation()
  if (age === null) return null
  const stale = age > 30
  return (
    <span className={cn('inline-flex items-center gap-1.5 text-[13px]', stale ? 'text-warning-text' : 'text-ink-3')}>
      <span className={cn('size-2 rounded-full', stale ? 'bg-warning' : 'bg-good')} aria-hidden="true" />
      {stale ? t('live.stale', { ago: formatAgo(age) }) : t('live.updated', { ago: formatAgo(age) })}
    </span>
  )
}

export function TariffChip({ period }) {
  const { t } = useTranslation()
  if (!period) return null
  const high = period === 'high'
  return (
    <Badge tone={high ? 'neutral' : 'info'} icon={high ? Sun : Moon}>
      {high ? t('live.tariffHigh') : t('live.tariffLow')}
    </Badge>
  )
}

export function WasteNow({ waste }) {
  const { t } = useTranslation()
  if (!waste) return null
  return (
    <Callout tone="warning" icon={TriangleAlert}>
      <p className="font-semibold">{t('live.wasteTitle', { count: waste.machines })}</p>
      <p className="text-ink-2">
        {t('live.wasteBody', {
          kwh: formatKwh(waste.kwh),
          eur: formatEur(waste.eur),
          co2: formatCo2(waste.co2_kg),
          time: formatTime(waste.since),
        })}
        <Link to="/waste" className="ml-2 font-medium text-ink underline-offset-2 hover:underline">
          {t('live.review')} →
        </Link>
      </p>
    </Callout>
  )
}

/**
 * Where the power goes now: the grid connection, split into each machine's share.
 * Bars are proportional to live kW (identity is by label, never by colour).
 */
export function PowerFlow({ live }) {
  const { t } = useTranslation()
  const machines = [...live.machines].filter((m) => (m.power_kw ?? 0) > 0.01).sort((a, b) => b.power_kw - a.power_kw)
  const site = live.site_kw || 0
  const unmonitored = Math.max(0, site - live.monitored_kw)
  const rows = [...machines.map((m) => ({ key: m.id, label: m.name, code: m.code, kw: m.power_kw, type: m.type, afterHours: Boolean(m.after_hours), to: `/machines/${m.id}` }))]
  if (unmonitored > 0.05) rows.push({ key: 'unmonitored', label: t('live.unmonitored'), kw: unmonitored, type: 'other' })

  return (
    <div className="flex flex-col gap-2.5">
      <div className="flex items-center justify-between rounded-sm bg-surface-2 px-3 py-2">
        <span className="text-[13px] font-medium text-ink-2">{t('live.flowGrid')}</span>
        <span className="text-sm font-semibold tabular text-ink">{formatKw(site)}</span>
      </div>
      <ul className="flex flex-col gap-1.5">
        {rows.map((row) => {
          const share = site > 0 ? row.kw / site : 0
          const content = (
            <>
              <MachineIcon type={row.type} className="size-4 shrink-0 text-ink-3" />
              <span className="w-40 min-w-0 truncate text-[13px] text-ink sm:w-56">
                {row.label}
                {row.afterHours && <TriangleAlert className="ml-1.5 inline size-3.5 text-warning-text" aria-label={t('live.offSchedule')} />}
              </span>
              <span className="relative h-2.5 flex-1 overflow-hidden rounded-full bg-surface-2" aria-hidden="true">
                <span
                  className={cn('absolute inset-y-0 left-0 rounded-full transition-[width] duration-300', row.afterHours ? 'bg-warning' : 'bg-[var(--series-1)]')}
                  style={{ width: `${Math.max(1.5, share * 100)}%` }}
                />
              </span>
              <span className="w-20 shrink-0 text-right text-[13px] tabular text-ink">{formatKw(row.kw)}</span>
              <span className="w-12 shrink-0 text-right text-xs tabular text-ink-3">{formatNumber(share * 100, 0)}%</span>
            </>
          )
          return (
            <li key={row.key}>
              {row.to ? (
                <Link to={row.to} className="flex items-center gap-3 rounded-sm px-1 py-0.5 hover:bg-surface-2">
                  {content}
                </Link>
              ) : (
                <div className="flex items-center gap-3 px-1 py-0.5">{content}</div>
              )}
            </li>
          )
        })}
      </ul>
    </div>
  )
}

export function MachineTable({ machines }) {
  const { t } = useTranslation()
  return (
    <div className="overflow-x-auto">
      <table className="w-full min-w-[860px] border-collapse text-[13px]">
        <thead>
          <tr className="border-b border-line text-left text-xs font-medium text-ink-3">
            <th className="px-4 py-2.5 font-medium">{t('live.table.machine')}</th>
            <th className="px-3 py-2.5 font-medium">{t('live.table.state')}</th>
            <th className="px-3 py-2.5 text-right font-medium">{t('live.table.power')} (kW)</th>
            <th className="px-3 py-2.5 text-right font-medium">{t('live.table.pf')}</th>
            <th className="px-3 py-2.5 text-right font-medium">{t('live.table.temp')} (°C)</th>
            <th className="px-3 py-2.5 text-right font-medium">{t('live.table.today')} (kWh)</th>
            <th className="px-3 py-2.5 font-medium">{t('live.table.schedule')}</th>
            <th className="px-3 py-2.5 font-medium">{t('live.table.afterHours')}</th>
            <th className="px-4 py-2.5 text-right font-medium"><span className="sr-only">{t('control.turnOff')}</span></th>
          </tr>
        </thead>
        <tbody>
          {machines.map((m) => {
            return (
              <tr key={m.id} className="border-b border-line last:border-0 hover:bg-surface-2">
                <td className="px-4 py-2.5">
                  <Link to={`/machines/${m.id}`} className="flex items-center gap-2.5">
                    <MachineIcon type={m.type} className="size-4 shrink-0 text-ink-3" />
                    <span className="min-w-0">
                      <span className="block truncate font-medium text-ink">{m.name}</span>
                      <span className="block font-mono text-[11px] text-ink-3">{m.code}</span>
                    </span>
                  </Link>
                </td>
                <td className="px-3 py-2.5"><StateBadge state={m.state} /></td>
                <td className="px-3 py-2.5 text-right tabular text-ink">{m.power_kw === null ? '—' : formatNumber(m.power_kw, 2)}</td>
                <td className="px-3 py-2.5 text-right tabular text-ink-2">{m.power_factor === null ? '—' : formatNumber(m.power_factor, 2)}</td>
                <td className="px-3 py-2.5 text-right tabular text-ink-2">{m.temperature_c === null ? '—' : formatNumber(m.temperature_c, 1)}</td>
                <td className="px-3 py-2.5 text-right tabular text-ink">{formatNumber(m.today_kwh, 1)}</td>
                <td className="px-3 py-2.5 text-ink-2">{m.scheduled_now ? t('live.scheduled') : t('live.offSchedule')}</td>
                <td className="px-3 py-2.5">
                  {m.after_hours ? (
                    <span className="inline-flex items-center gap-1.5 text-warning-text">
                      <TriangleAlert className="size-3.5" aria-hidden="true" />
                      {formatDuration(m.after_hours.duration_s)} · {formatEur(m.after_hours.eur)}
                    </span>
                  ) : (
                    <span className="text-ink-3">—</span>
                  )}
                </td>
                <td className="px-4 py-2 text-right"><TurnOffButton machine={m} variant="compact" /></td>
              </tr>
            )
          })}
        </tbody>
      </table>
    </div>
  )
}
