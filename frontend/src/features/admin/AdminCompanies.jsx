import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Building2, Search, Users } from 'lucide-react'
import { formatAgo, formatDate, formatNumber } from '../../lib/format'
import { useDebounced } from '../../hooks/useDebounced'
import { useNow } from '../../hooks/useNow'
import { Badge } from '../../components/ui/Badge'
import { Button } from '../../components/ui/Button'
import { Card } from '../../components/ui/Card'
import { Input } from '../../components/ui/Field'
import { EmptyState, ErrorState, Skeleton } from '../../components/ui/States'
import { useAdminCompanies } from '../data'

export function AdminCompanies({ onOpenUsers }) {
  const { t } = useTranslation()
  const now = useNow(30_000)
  const [search, setSearch] = useState('')
  const q = useDebounced(search.trim(), 300)
  const companies = useAdminCompanies(q)
  const rows = companies.data?.data ?? []

  return (
    <>
      <div className="mb-4 flex flex-wrap items-center gap-3">
        <div className="relative w-full sm:w-80">
          <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-ink-3" aria-hidden="true" />
          <Input
            type="search"
            aria-label={t('admin.companies.search')}
            placeholder={t('admin.companies.search')}
            value={search}
            onChange={(event) => setSearch(event.target.value)}
            className="pl-9"
          />
        </div>
      </div>

      {companies.isPending ? (
        <Skeleton className="h-96" />
      ) : companies.isError ? (
        <ErrorState error={companies.error} onRetry={companies.refetch} />
      ) : (
        <Card className="overflow-hidden">
          {rows.length === 0 ? (
            <EmptyState icon={Building2} title={t('admin.companies.empty')} />
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full min-w-[1040px] border-collapse text-[13px]">
                <thead>
                  <tr className="border-b border-line text-left text-xs text-ink-3">
                    <th className="px-4 py-2.5 font-medium">{t('admin.companies.company')}</th>
                    <th className="px-3 py-2.5 font-medium">{t('admin.companies.owner')}</th>
                    <th className="px-3 py-2.5 text-right font-medium">{t('admin.companies.users')}</th>
                    <th className="px-3 py-2.5 text-right font-medium">{t('admin.companies.machines')}</th>
                    <th className="px-3 py-2.5 font-medium">{t('admin.companies.devices')}</th>
                    <th className="px-3 py-2.5 font-medium">{t('admin.companies.lastData')}</th>
                    <th className="px-3 py-2.5 text-right font-medium">{t('admin.companies.reports')}</th>
                    <th className="px-3 py-2.5 font-medium">{t('admin.companies.joined')}</th>
                    <th className="px-4 py-2.5" aria-hidden="true" />
                  </tr>
                </thead>
                <tbody>
                  {rows.map((c) => (
                    <tr key={c.id} className="border-b border-line last:border-0 hover:bg-surface-2">
                      <td className="px-4 py-2.5">
                        <span className="flex items-center gap-2">
                          <span className="truncate font-medium text-ink">{c.name}</span>
                          {c.demo && <Badge>{t('admin.companies.demo')}</Badge>}
                        </span>
                        <span className="block truncate text-xs text-ink-3">
                          {[c.city, c.legal_form, c.business_number && `NUI ${c.business_number}`].filter(Boolean).join(' · ')}
                        </span>
                      </td>
                      <td className="px-3 py-2.5 text-ink-2">{c.owner_email ?? <span className="text-ink-3">{t('admin.companies.noOwner')}</span>}</td>
                      <td className="px-3 py-2.5 text-right tabular text-ink">
                        {c.active_users === c.users ? formatNumber(c.users) : `${formatNumber(c.active_users)} / ${formatNumber(c.users)}`}
                      </td>
                      <td className="px-3 py-2.5 text-right tabular text-ink">{formatNumber(c.machines)}</td>
                      <td className="px-3 py-2.5 text-ink">
                        <span className="tabular">{formatNumber(c.devices)}</span>
                        {c.simulated_devices > 0 && <span className="ml-1.5 text-xs text-ink-3">{t('admin.companies.simulated', { count: c.simulated_devices })}</span>}
                      </td>
                      <td className="px-3 py-2.5 text-ink-2">
                        {c.last_data_at ? formatAgo((now - Date.parse(c.last_data_at)) / 1000) : <span className="text-ink-3">{t('admin.companies.noData')}</span>}
                      </td>
                      <td className="px-3 py-2.5 text-right tabular text-ink">{formatNumber(c.reports)}</td>
                      <td className="whitespace-nowrap px-3 py-2.5 text-ink-2">{formatDate(c.created_at)}</td>
                      <td className="px-4 py-2.5 text-right">
                        <Button variant="ghost" size="sm" icon={Users} onClick={() => onOpenUsers(c.id)}>
                          {t('admin.companies.viewUsers')}
                        </Button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </Card>
      )}
    </>
  )
}
