import { useTranslation } from 'react-i18next'
import { FileText } from 'lucide-react'
import { PageHeader } from '../../components/layout/PageHeader'
import { Card, CardBody } from '../../components/ui/Card'
import { EmptyState, ErrorState, Skeleton } from '../../components/ui/States'
import { StatTile } from '../../components/ui/StatTile'
import { formatCo2, formatDate, formatEur, formatNumber } from '../../lib/format'
import { useAuth } from '../../providers/AuthProvider'
import { useMachines, useOverview } from '../data'
import { ReportExport } from './ReportExport'

export function ReportsPage() {
  const { t } = useTranslation()
  const { company } = useAuth()
  const overview = useOverview()
  const machines = useMachines()

  if (overview.isPending) {
    return <><PageHeader title={t('nav.reports')} subtitle={t('modules.reports.description')} /><Skeleton className="h-52" /></>
  }
  if (overview.isError) return <ErrorState error={overview.error} onRetry={overview.refetch} />

  const data = overview.data.data
  return (
    <>
      <PageHeader
        title={t('nav.reports')}
        subtitle={t('modules.reports.description')}
        actions={<ReportExport overview={data.has_data ? data : null} machines={machines.data?.data ?? []} company={company} disabled={!data.has_data} />}
      />
      {!data.has_data ? (
        <Card><EmptyState icon={FileText} title={t('modules.reports.emptyTitle')} body={t('modules.reports.emptyBody')} /></Card>
      ) : (
        <Card>
          <CardBody className="flex flex-col gap-5">
            <div>
              <h2 className="text-base font-semibold text-ink">{t('report.title')}</h2>
              <p className="mt-1 text-sm text-ink-2">{company?.name} · {formatDate(data.now)}</p>
            </div>
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
              <StatTile label={t('report.totalEnergy')} value={formatNumber(data.month.kwh, 1)} unit="kWh" />
              <StatTile label={t('report.totalCost')} value={formatEur(data.month.bill_so_far?.subtotal)} context={t('report.costNote')} />
              <StatTile label={t('report.totalCo2')} value={formatCo2(data.month.co2_kg)} />
            </div>
          </CardBody>
        </Card>
      )}
    </>
  )
}
