import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { History, Workflow } from 'lucide-react'
import { api } from '../../lib/api'
import { cn } from '../../lib/cn'
import { formatDate, formatEur, formatKwh } from '../../lib/format'
import { hasRole } from '../../lib/roles'
import { MachineIcon } from '../../lib/machineTypes'
import { useAuth } from '../../providers/AuthProvider'
import { PageHeader } from '../../components/layout/PageHeader'
import { Card, CardHeader } from '../../components/ui/Card'
import { EmptyState, ErrorState, Skeleton } from '../../components/ui/States'
import { useCommands, usePolicies } from '../data'
import { CommandsTable } from './CommandsTable'

function Toggle({ checked, disabled, onChange, label }) {
  return (
    <button
      type="button"
      role="switch"
      aria-checked={checked}
      aria-label={label}
      title={label}
      disabled={disabled}
      onClick={() => onChange(!checked)}
      className={cn(
        'relative inline-flex h-5 w-9 shrink-0 items-center rounded-full border transition-colors disabled:cursor-not-allowed disabled:opacity-55',
        checked ? 'border-brand bg-brand' : 'border-line-strong bg-surface-2',
      )}
    >
      <span className={cn('inline-block size-3.5 rounded-full bg-surface shadow-sm transition-transform', checked ? 'translate-x-[18px]' : 'translate-x-[2px]')} />
    </button>
  )
}

function PoliciesCard() {
  const { t } = useTranslation()
  const { user } = useAuth()
  const queryClient = useQueryClient()
  const policies = usePolicies()
  const toggle = useMutation({
    mutationFn: ({ id, active }) => api.patch(`/policies/${id}`, { active }),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['policies'] }),
  })
  const canAct = hasRole(user, 'manager')

  return (
    <Card className="overflow-hidden">
      <CardHeader title={t('automationsPage.policies')} />
      {policies.isPending ? (
        <div className="p-5"><Skeleton className="h-24" /></div>
      ) : policies.isError ? (
        <ErrorState error={policies.error} onRetry={policies.refetch} />
      ) : policies.data.data.length === 0 ? (
        <EmptyState icon={Workflow} title={t('modules.automations.emptyTitle')} body={t('automationsPage.noPolicies')} />
      ) : (
        <div className="overflow-x-auto">
          <table className="w-full min-w-[820px] border-collapse text-[13px]">
            <thead>
              <tr className="border-b border-line text-left text-xs text-ink-3">
                <th className="px-5 py-2.5 font-medium">{t('automationsPage.policyTable.machine')}</th>
                <th className="px-3 py-2.5 font-medium">{t('automationsPage.policyTable.rule')}</th>
                <th className="px-3 py-2.5 font-medium">{t('automationsPage.policyTable.mode')}</th>
                <th className="px-3 py-2.5 font-medium">{t('automationsPage.policyTable.since')}</th>
                <th className="px-3 py-2.5 text-right font-medium">{t('automationsPage.policyTable.triggered')}</th>
                <th className="px-3 py-2.5 text-right font-medium">{t('automationsPage.policyTable.savings')}</th>
                <th className="px-5 py-2.5 text-right font-medium">{t('automationsPage.policyTable.active')}</th>
              </tr>
            </thead>
            <tbody>
              {policies.data.data.map((p) => (
                <tr key={p.id} className={cn('border-b border-line last:border-0', !p.active && 'text-ink-3')}>
                  <td className="px-5 py-3">
                    <Link to={`/machines/${p.machine.id}`} className="flex items-center gap-2.5">
                      <MachineIcon type={p.machine.type} className="size-4 shrink-0 text-ink-3" />
                      <span className="min-w-0">
                        <span className="block truncate font-medium text-ink">{p.machine.name}</span>
                        <span className="block font-mono text-[11px] text-ink-3">{p.machine.code}</span>
                      </span>
                    </Link>
                  </td>
                  <td className="px-3 py-3 text-ink-2">{t(`automationsPage.rule.${p.type}`, { min: p.params.grace_min ?? 15 })}</td>
                  <td className="px-3 py-3 text-ink-2">{t(`automationsPage.modes.${p.mode}`)}</td>
                  <td className="whitespace-nowrap px-3 py-3 tabular text-ink-2">{formatDate(p.effective_from)}</td>
                  <td className="px-3 py-3 text-right tabular text-ink">
                    {p.triggered}
                    {p.last_triggered_at && <span className="block text-xs text-ink-3">{formatDate(p.last_triggered_at, 'dayMonth')}</span>}
                  </td>
                  <td className="whitespace-nowrap px-3 py-3 text-right tabular text-ink">
                    {p.impact ? (
                      <>
                        {formatEur(Number(p.impact.savings_eur))}
                        <span className="block text-xs text-ink-3">{formatKwh(Number(p.impact.savings_kwh))}</span>
                      </>
                    ) : (
                      <span className="text-ink-3">{t('automationsPage.collecting')}</span>
                    )}
                  </td>
                  <td className="px-5 py-3 text-right">
                    <Toggle
                      checked={p.active}
                      disabled={!canAct || toggle.isPending}
                      label={p.active ? t('automationsPage.toggleOff') : t('automationsPage.toggleOn')}
                      onChange={(active) => toggle.mutate({ id: p.id, active })}
                    />
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </Card>
  )
}

export function AutomationsPage() {
  const { t } = useTranslation()
  const commands = useCommands({ limit: 100 })
  return (
    <>
      <PageHeader title={t('nav.automations')} subtitle={t('automationsPage.subtitle')} />
      <div className="flex flex-col gap-6">
        <PoliciesCard />
        <Card className="overflow-hidden">
          <CardHeader title={t('automationsPage.commands')} />
          {commands.isPending ? (
            <div className="p-5"><Skeleton className="h-48" /></div>
          ) : commands.isError ? (
            <ErrorState error={commands.error} onRetry={commands.refetch} />
          ) : commands.data.data.length === 0 ? (
            <EmptyState icon={History} title={t('automationsPage.commands')} body={t('automationsPage.noCommands')} />
          ) : (
            <CommandsTable commands={commands.data.data} />
          )}
        </Card>
      </div>
    </>
  )
}
