import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { Factory } from 'lucide-react'
import { formatCo2, formatEur, formatKw, formatKwh, formatNumber } from '../../lib/format'
import { MachineIcon } from '../../lib/machineTypes'
import { PageHeader } from '../../components/layout/PageHeader'
import { Card } from '../../components/ui/Card'
import { StateBadge } from '../../components/ui/StateBadge'
import { EmptyState, ErrorState, Skeleton } from '../../components/ui/States'
import { useMachines } from '../data'

export function MachinesPage() {
  const { t } = useTranslation()
  const machines = useMachines()

  if (machines.isPending) {
    return (
      <>
        <PageHeader title={t('nav.machines')} subtitle={t('machinesPage.subtitle')} />
        <Skeleton className="h-96" />
      </>
    )
  }
  if (machines.isError) return <ErrorState error={machines.error} onRetry={machines.refetch} />

  const rows = [...machines.data.data].sort((a, b) => b.month.kwh - a.month.kwh)
  if (rows.length === 0) {
    return (
      <>
        <PageHeader title={t('nav.machines')} subtitle={t('modules.machines.description')} />
        <Card>
          <EmptyState icon={Factory} title={t('modules.machines.emptyTitle')} body={t('modules.machines.emptyBody')} />
        </Card>
      </>
    )
  }

  return (
    <>
      <PageHeader title={t('nav.machines')} subtitle={t('machinesPage.subtitle')} />
      <Card className="overflow-hidden">
        {/* Phones: one card per machine instead of an 820 px table. */}
        <ul className="divide-y divide-line md:hidden">
          {rows.map((m) => (
            <li key={m.id}>
              <Link to={`/machines/${m.id}`} className="flex flex-col gap-2.5 px-4 py-3.5 hover:bg-surface-2">
                <span className="flex items-start gap-2.5">
                  <MachineIcon type={m.type} className="mt-0.5 size-4 shrink-0 text-ink-3" />
                  <span className="min-w-0 flex-1">
                    <span className="block font-medium text-ink">{m.name}</span>
                    <span className="block text-[12px] text-ink-3"><span className="font-mono">{m.code}</span> · {t(`machineTypes.${m.type}`, { defaultValue: m.type })}</span>
                  </span>
                  <StateBadge state={m.state} className="shrink-0" />
                </span>
                <dl className="grid grid-cols-3 gap-2 text-[12.5px]">
                  <div className="min-w-0">
                    <dt className="truncate text-ink-3">{t('machinesPage.now')}</dt>
                    <dd className="tabular text-ink">{m.power_kw === null ? '—' : formatKw(m.power_kw)}</dd>
                  </div>
                  <div className="min-w-0">
                    <dt className="truncate text-ink-3">{t('machinesPage.monthKwh')}</dt>
                    <dd className="tabular text-ink">{formatKwh(m.month.kwh)}</dd>
                  </div>
                  <div className="min-w-0">
                    <dt className="truncate text-ink-3">{t('machinesPage.monthEur')}</dt>
                    <dd className="tabular text-ink">{formatEur(m.month.eur)}</dd>
                  </div>
                </dl>
                <span className="flex items-center gap-2 text-[12.5px]">
                  <span className="relative h-2 min-w-0 flex-1 overflow-hidden rounded-full bg-surface-2" aria-hidden="true">
                    <span className="absolute inset-y-0 left-0 rounded-full bg-[var(--series-1)]" style={{ width: `${m.month.share * 100}%` }} />
                  </span>
                  <span className="shrink-0 tabular text-ink-2">{t('machinesPage.share')} {formatNumber(m.month.share * 100, 0)}%</span>
                </span>
              </Link>
            </li>
          ))}
        </ul>
        <div className="relative hidden overflow-x-auto md:block">
          <table className="w-full min-w-[820px] border-collapse text-[13px]">
            <thead>
              <tr className="border-b border-line text-left text-xs text-ink-3">
                <th className="px-4 py-2.5 font-medium">{t('live.table.machine')}</th>
                <th className="px-3 py-2.5 font-medium">{t('machinesPage.type')}</th>
                <th className="px-3 py-2.5 font-medium">{t('live.table.state')}</th>
                <th className="px-3 py-2.5 text-right font-medium">{t('machinesPage.now')} (kW)</th>
                <th className="px-3 py-2.5 text-right font-medium">{t('machinesPage.monthKwh')} (kWh)</th>
                <th className="px-3 py-2.5 text-right font-medium">{t('machinesPage.monthEur')}</th>
                <th className="px-3 py-2.5 text-right font-medium">{t('machinesPage.co2')}</th>
                <th className="px-4 py-2.5 font-medium">{t('machinesPage.share')}</th>
              </tr>
            </thead>
            <tbody>
              {rows.map((m) => {
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
                    <td className="px-3 py-2.5 text-ink-2">{t(`machineTypes.${m.type}`, { defaultValue: m.type })}</td>
                    <td className="px-3 py-2.5"><StateBadge state={m.state} /></td>
                    <td className="px-3 py-2.5 text-right tabular text-ink">{m.power_kw === null ? '—' : formatNumber(m.power_kw, 2)}</td>
                    <td className="px-3 py-2.5 text-right tabular text-ink">{formatNumber(m.month.kwh, 0)}</td>
                    <td className="px-3 py-2.5 text-right tabular text-ink">{formatEur(m.month.eur)}</td>
                    <td className="px-3 py-2.5 text-right tabular text-ink-2">{formatCo2(m.month.co2_kg)}</td>
                    <td className="px-4 py-2.5">
                      <div className="flex items-center gap-2">
                        <span className="relative h-2 w-28 overflow-hidden rounded-full bg-surface-2" aria-hidden="true">
                          <span className="absolute inset-y-0 left-0 rounded-full bg-[var(--series-1)]" style={{ width: `${m.month.share * 100}%` }} />
                        </span>
                        <span className="w-10 text-right tabular text-ink-2">{formatNumber(m.month.share * 100, 0)}%</span>
                      </div>
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>
      </Card>
    </>
  )
}
