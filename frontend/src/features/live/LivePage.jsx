import { useTranslation } from 'react-i18next'
import { Activity } from 'lucide-react'
import { formatKw } from '../../lib/format'
import { useNow } from '../../hooks/useNow'
import { useAuth } from '../../providers/AuthProvider'
import { PageHeader } from '../../components/layout/PageHeader'
import { Badge } from '../../components/ui/Badge'
import { Card, CardBody, CardHeader } from '../../components/ui/Card'
import { EmptyState, ErrorState, Skeleton } from '../../components/ui/States'
import { readingAge, useLive } from '../data'
import { MachineTable, PowerFlow, TariffChip, UpdatedLabel, WasteNow } from './LiveParts'

export function LivePage() {
  const { t } = useTranslation()
  const { company } = useAuth()
  const live = useLive()
  const now = useNow()

  if (live.isPending) {
    return (
      <>
        <PageHeader title={t('nav.live')} subtitle={t('live.subtitle')} />
        <div className="grid gap-6 lg:grid-cols-[minmax(0,4fr)_minmax(0,7fr)]">
          <Skeleton className="h-44" />
          <Skeleton className="h-44" />
        </div>
      </>
    )
  }
  if (live.isError) return <ErrorState error={live.error} onRetry={live.refetch} />

  const data = live.data.data
  if (data.machines.length === 0) {
    return (
      <>
        <PageHeader title={t('nav.live')} subtitle={t('modules.live.description')} />
        <Card>
          <EmptyState icon={Activity} title={t('modules.live.emptyTitle')} body={t('modules.live.emptyBody')} />
        </Card>
      </>
    )
  }

  return (
    <>
      <PageHeader
        title={t('nav.live')}
        subtitle={t('live.subtitle')}
        actions={
          <div className="flex flex-wrap items-center gap-2">
            {company?.is_demo && <Badge tone="info">{t('demo.badge')}</Badge>}
            <UpdatedLabel age={readingAge(live, now)} />
          </div>
        }
      />

      <div className="flex flex-col gap-6">
        <WasteNow waste={data.waste_now} />

        <div className="grid gap-6 lg:grid-cols-[minmax(0,4fr)_minmax(0,7fr)]">
          <Card>
            <CardBody className="flex h-full flex-col justify-between gap-4 py-5">
              <div>
                <p className="text-[13px] font-medium text-ink-2">{t('live.sitePower')}</p>
                <p className="mt-1 text-5xl font-semibold tracking-[-0.02em] text-ink">{formatKw(data.site_kw)}</p>
                <p className="mt-2 text-[13px] text-ink-3">{t('live.monitored', { kw: formatKw(data.monitored_kw) })}</p>
              </div>
              <div className="flex flex-wrap items-center gap-2">
                <TariffChip period={data.tariff_period} />
                <Badge>{t('live.runningCount', { count: data.on })}</Badge>
                <Badge>{t('live.devicesOnline', { online: data.devices.online, total: data.devices.total })}</Badge>
              </div>
            </CardBody>
          </Card>
          <Card>
            <CardHeader title={t('live.flowTitle')} />
            <CardBody>
              <PowerFlow live={data} />
            </CardBody>
          </Card>
        </div>

        <Card className="overflow-hidden">
          <MachineTable machines={data.machines} />
        </Card>
      </div>
    </>
  )
}
