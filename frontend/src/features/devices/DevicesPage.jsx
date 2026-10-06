import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { Cpu, Wifi } from 'lucide-react'
import { cn } from '../../lib/cn'
import { formatAgo, formatKw } from '../../lib/format'
import { PageHeader } from '../../components/layout/PageHeader'
import { Badge } from '../../components/ui/Badge'
import { Card } from '../../components/ui/Card'
import { EmptyState, ErrorState, Skeleton } from '../../components/ui/States'
import { useDevices } from '../data'

export function DeviceStatus({ device }) {
  const { t } = useTranslation()
  const online = device.status === 'online'
  return (
    <span className={cn('inline-flex items-center gap-1.5 text-[13px]', online ? 'text-good-text' : 'text-ink-3')}>
      <span className={cn('size-2.5 rounded-full', online ? 'bg-good' : 'border-2 border-line-strong')} aria-hidden="true" />
      {t(`devicesPage.status.${device.status}`)}
    </span>
  )
}

export function DevicesPage() {
  const { t } = useTranslation()
  const devices = useDevices()

  if (devices.isPending) {
    return (
      <>
        <PageHeader title={t('nav.devices')} subtitle={t('devicesPage.subtitle')} />
        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
          {[0, 1, 2].map((i) => <Skeleton key={i} className="h-48" />)}
        </div>
      </>
    )
  }
  if (devices.isError) return <ErrorState error={devices.error} onRetry={devices.refetch} />
  const list = devices.data.data

  if (list.length === 0) {
    return (
      <>
        <PageHeader title={t('nav.devices')} subtitle={t('modules.devices.description')} />
        <Card>
          <EmptyState icon={Cpu} title={t('modules.devices.emptyTitle')} body={t('modules.devices.emptyBody')} />
        </Card>
      </>
    )
  }

  return (
    <>
      <PageHeader title={t('nav.devices')} subtitle={t('devicesPage.subtitle')} />
      <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        {list.map((device) => (
          <Link key={device.id} to={`/devices/${device.id}`} className="block rounded-md border border-line bg-surface p-4 transition-colors hover:border-line-strong">
            <div className="flex items-start justify-between gap-3">
              <div>
                <p className="font-mono text-[15px] font-semibold text-ink">{device.serial}</p>
                <p className="text-[13px] text-ink-3">{device.model}</p>
              </div>
              <Badge tone={device.simulated ? 'neutral' : 'brand'}>{device.simulated ? t('devicesPage.simulated') : t('devicesPage.hardware')}</Badge>
            </div>
            <div className="mt-3 flex flex-wrap items-center gap-x-3 gap-y-1">
              <DeviceStatus device={device} />
              {device.last_seen_ago_s !== null && (
                <span className="text-[12.5px] text-ink-3">{t('devicesPage.lastReading', { ago: formatAgo(device.last_seen_ago_s) })}</span>
              )}
              {device.signal_dbm !== null && (
                <span className="inline-flex items-center gap-1 text-[12.5px] text-ink-3">
                  <Wifi className="size-3.5" aria-hidden="true" />
                  {t('devicesPage.signal', { dbm: device.signal_dbm })}
                </span>
              )}
            </div>
            <ul className="mt-4 flex flex-col gap-1.5 border-t border-line pt-3">
              {device.channels.map((channel) => (
                <li key={channel.channel} className="flex items-center justify-between gap-3 text-[13px]">
                  <span className="flex min-w-0 items-center gap-2">
                    <span className="font-mono text-xs text-ink-3">CH{channel.channel}</span>
                    <span className="truncate text-ink">{channel.machine.name}</span>
                  </span>
                  <span className="shrink-0 tabular text-ink-2">{channel.reading ? formatKw(channel.reading.power_kw) : '—'}</span>
                </li>
              ))}
            </ul>
          </Link>
        ))}
      </div>
    </>
  )
}
