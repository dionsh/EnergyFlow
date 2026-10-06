import { Link, useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { ArrowLeft, Info, Power } from 'lucide-react'
import { formatDate, formatNumber } from '../../lib/format'
import { Badge } from '../../components/ui/Badge'
import { Card, CardHeader } from '../../components/ui/Card'
import { StateBadge } from '../../components/ui/StateBadge'
import { Callout, EmptyState, ErrorState, Skeleton } from '../../components/ui/States'
import { useDevice } from '../data'
import { DeviceStatus } from './DevicesPage'

const value = (v, digits) => (v === null || v === undefined ? '—' : formatNumber(v, digits))

export function DeviceDetailPage() {
  const { t } = useTranslation()
  const { id } = useParams()
  const query = useDevice(id)

  if (query.isPending) return <Skeleton className="h-96" />
  if (query.isError) {
    return query.error.status === 404 ? <Card><EmptyState title={t('notFound.title')} /></Card> : <ErrorState error={query.error} onRetry={query.refetch} />
  }
  const device = query.data.data

  return (
    <>
      <Link to="/devices" className="mb-4 inline-flex items-center gap-1.5 text-[13px] text-ink-2 hover:text-ink">
        <ArrowLeft className="size-4" aria-hidden="true" />
        {t('devicesPage.back')}
      </Link>
      <div className="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div>
          <h1 className="font-mono text-xl font-semibold text-ink">{device.serial}</h1>
          <p className="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-[13px] text-ink-3">
            <span>{device.model}</span>
            {device.firmware_version && <span>{t('devicesPage.firmware', { version: device.firmware_version })}</span>}
            {device.installed_at && <span>{formatDate(device.installed_at)}</span>}
            <DeviceStatus device={device} />
          </p>
        </div>
        <Badge tone={device.simulated ? 'neutral' : 'brand'}>{device.simulated ? t('devicesPage.simulated') : t('devicesPage.hardware')}</Badge>
      </div>

      {device.simulated && (
        <Callout tone="info" icon={Info} className="mb-6">
          {t('devicesPage.simulatedNote')}
        </Callout>
      )}

      <Card className="overflow-hidden">
        <CardHeader title={t('devicesPage.channels')} />
        <div className="overflow-x-auto">
          <table className="w-full min-w-[860px] border-collapse text-[13px]">
            <thead>
              <tr className="border-b border-line text-left text-xs text-ink-3">
                <th className="px-4 py-2.5 font-medium">{t('devicesPage.table.channel')}</th>
                <th className="px-3 py-2.5 font-medium">{t('devicesPage.table.machine')}</th>
                <th className="px-3 py-2.5 text-right font-medium">{t('devicesPage.table.voltage')} (V)</th>
                <th className="px-3 py-2.5 text-right font-medium">{t('devicesPage.table.current')} (A)</th>
                <th className="px-3 py-2.5 text-right font-medium">{t('devicesPage.table.power')} (kW)</th>
                <th className="px-3 py-2.5 text-right font-medium">{t('devicesPage.table.pf')}</th>
                <th className="px-3 py-2.5 text-right font-medium">{t('devicesPage.table.frequency')} (Hz)</th>
                <th className="px-4 py-2.5 text-right font-medium">{t('devicesPage.table.temp')} (°C)</th>
              </tr>
            </thead>
            <tbody>
              {device.channels.map((c) => (
                <tr key={c.channel} className="border-b border-line last:border-0">
                  <td className="px-4 py-3">
                    <span className="font-mono text-ink">CH{c.channel}</span>
                    <span className="mt-0.5 flex items-center gap-1.5 text-[11.5px] text-ink-3">
                      {t(`devicesPage.modes.${c.measurement_mode}`)}
                      {c.has_relay && (
                        <span className="inline-flex items-center gap-1" title={t('devicesPage.relay')}>
                          · <Power className="size-3" aria-hidden="true" /> {t('devicesPage.relay')}
                        </span>
                      )}
                    </span>
                  </td>
                  <td className="px-3 py-3">
                    <Link to={`/machines/${c.machine.id}`} className="font-medium text-ink hover:underline">{c.machine.name}</Link>
                    {c.reading && <div className="mt-0.5"><StateBadge state={c.reading.state} /></div>}
                  </td>
                  <td className="px-3 py-3 text-right tabular text-ink">{value(c.reading?.voltage_v, 1)}</td>
                  <td className="px-3 py-3 text-right tabular text-ink">{value(c.reading?.current_a, 2)}</td>
                  <td className="px-3 py-3 text-right tabular text-ink">{value(c.reading?.power_kw, 3)}</td>
                  <td className="px-3 py-3 text-right tabular text-ink">{value(c.reading?.power_factor, 2)}</td>
                  <td className="px-3 py-3 text-right tabular text-ink">{value(c.reading?.frequency_hz, 2)}</td>
                  <td className="px-4 py-3 text-right tabular text-ink">{value(c.reading?.temperature_c, 1)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </Card>
    </>
  )
}
