import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { BellRing, Check } from 'lucide-react'
import { api } from '../../lib/api'
import { cn } from '../../lib/cn'
import { formatDate } from '../../lib/format'
import { hasRole } from '../../lib/roles'
import { useAuth } from '../../providers/AuthProvider'
import { Button } from '../../components/ui/Button'
import { Card } from '../../components/ui/Card'
import { MethodChip, SeverityBadge } from '../../components/ui/Chips'
import { Segmented } from '../../components/ui/Tabs'
import { EmptyState, ErrorState, Skeleton } from '../../components/ui/States'
import { useAlerts } from '../data'
import { DETAIL_TYPES, alertText } from './alertText'

const FILTERS = ['active', 'resolved', 'all']

export function AlertsPanel({ onOpenEvent, onOpenAlert }) {
  const { t } = useTranslation()
  const { user } = useAuth()
  const queryClient = useQueryClient()
  const [filter, setFilter] = useState('active')
  const alerts = useAlerts(filter)
  const acknowledge = useMutation({
    mutationFn: (id) => api.post(`/alerts/${id}/acknowledge`),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['alerts'] }),
  })
  const canAct = hasRole(user, 'manager')

  return (
    <Card className="overflow-hidden">
      <div className="flex flex-wrap items-center justify-between gap-3 border-b border-line px-5 py-3">
        <Segmented items={FILTERS.map((key) => ({ key, label: t(`alertsTab.filters.${key}`) }))} value={filter} onChange={setFilter} />
      </div>
      {alerts.isPending ? (
        <div className="p-5"><Skeleton className="h-40" /></div>
      ) : alerts.isError ? (
        <ErrorState error={alerts.error} onRetry={alerts.refetch} />
      ) : alerts.data.data.length === 0 ? (
        <EmptyState icon={BellRing} title={t('alertsTab.emptyTitle')} body={t('alertsTab.emptyBody')} />
      ) : (
        <div className="overflow-x-auto">
          <table className="w-full min-w-[760px] border-collapse text-[13px]">
            <thead>
              <tr className="border-b border-line text-left text-xs text-ink-3">
                <th className="px-5 py-2.5 font-medium">{t('alertsTab.table.severity')}</th>
                <th className="px-3 py-2.5 font-medium">{t('alertsTab.table.alert')}</th>
                <th className="px-3 py-2.5 font-medium">{t('alertsTab.table.opened')}</th>
                <th className="px-3 py-2.5 font-medium">{t('alertsTab.table.status')}</th>
                <th className="px-5 py-2.5" aria-label="actions" />
              </tr>
            </thead>
            <tbody>
              {alerts.data.data.map((alert) => {
                const text = alertText(t, alert)
                const detail = alert.waste_event_id || DETAIL_TYPES.has(alert.type)
                const open = () => (alert.waste_event_id ? onOpenEvent(alert.waste_event_id) : DETAIL_TYPES.has(alert.type) && onOpenAlert(alert.id))
                return (
                  <tr key={alert.id} className={cn('border-b border-line last:border-0', detail && 'cursor-pointer hover:bg-surface-2')} onClick={open}>
                    <td className="px-5 py-3 align-top"><SeverityBadge severity={alert.severity} /></td>
                    <td className="px-3 py-3 align-top">
                      <p className="font-medium text-ink">{text.title}</p>
                      <p className="mt-0.5 flex flex-wrap items-center gap-2 text-ink-3">
                        {text.detail && <span>{text.detail}</span>}
                        <MethodChip method={alert.method} />
                      </p>
                    </td>
                    <td className="px-3 py-3 align-top tabular text-ink-2">{formatDate(alert.opened_at, 'dateTime')}</td>
                    <td className="px-3 py-3 align-top text-ink-2">
                      {t(`alertsTab.status.${alert.status}`)}
                      {alert.acknowledged_by && <span className="block text-xs text-ink-3">{t('alertsTab.acknowledgedBy', { name: alert.acknowledged_by })}</span>}
                    </td>
                    <td className="px-5 py-3 text-right align-top">
                      {canAct && alert.status === 'open' && (
                        <Button
                          size="sm"
                          variant="secondary"
                          icon={Check}
                          loading={acknowledge.isPending && acknowledge.variables === alert.id}
                          onClick={(event) => {
                            event.stopPropagation()
                            acknowledge.mutate(alert.id)
                          }}
                        >
                          {t('alertsTab.acknowledge')}
                        </Button>
                      )}
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>
      )}
    </Card>
  )
}
