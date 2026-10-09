import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { ShieldAlert, ShieldCheck, Trash2, TriangleAlert } from 'lucide-react'
import { api } from '../../lib/api'
import { formatCo2, formatDate, formatEur, formatKwh, formatNumber, formatPercent } from '../../lib/format'
import { hasRole } from '../../lib/roles'
import { useAuth } from '../../providers/AuthProvider'
import { Badge } from '../../components/ui/Badge'
import { IconButton } from '../../components/ui/Button'
import { Card } from '../../components/ui/Card'
import { EmptyState, ErrorState, Skeleton } from '../../components/ui/States'
import { Tabs } from '../../components/ui/Tabs'
import { useBills, useFuelRecords, useMeterReadings } from '../data'

const VERDICT_BADGE = {
  consistent: { tone: 'good', icon: ShieldCheck },
  check: { tone: 'warning', icon: TriangleAlert },
  likely_fake: { tone: 'critical', icon: ShieldAlert },
}

function Remove({ path, queryKey }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const remove = useMutation({
    mutationFn: () => api.delete(path),
    onSuccess: () => queryClient.invalidateQueries({ queryKey }),
  })
  return <IconButton label={t('common.delete')} icon={Trash2} className="size-8" disabled={remove.isPending} onClick={() => remove.mutate()} />
}

function Table({ head, rows, align = [] }) {
  return (
    <div className="overflow-x-auto">
      <table className="w-full min-w-[640px] border-collapse text-[13px]">
        <thead>
          <tr className="border-b border-line text-left text-xs text-ink-3">
            {head.map((h, i) => <th key={i} className={`whitespace-nowrap px-4 py-2.5 font-medium ${align[i] === 'right' ? 'text-right' : ''}`}>{h}</th>)}
          </tr>
        </thead>
        <tbody>
          {rows.map((row, r) => (
            <tr key={r} className="border-b border-line last:border-0">
              {row.map((cell, i) => <td key={i} className={`whitespace-nowrap px-4 py-2.5 text-ink ${align[i] === 'right' ? 'text-right tabular' : ''}`}>{cell}</td>)}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

function Body({ query, empty, render }) {
  if (query.isPending) return <Skeleton className="h-32" />
  if (query.isError) return <ErrorState error={query.error} onRetry={query.refetch} />
  if (query.data.data.length === 0) return <Card><EmptyState title={empty.title} body={empty.body} /></Card>
  return <Card className="overflow-hidden">{render(query.data.data)}</Card>
}

/** Saved scans: bills against the meters ("utility bills → coverage"), Scope 1 fuel, meter readings. */
export function ScanHistory() {
  const { t } = useTranslation()
  const { user } = useAuth()
  const canEdit = hasRole(user, 'manager')
  const [tab, setTab] = useState('bills')
  const bills = useBills()
  const fuel = useFuelRecords()
  const readings = useMeterReadings()

  return (
    <section className="mt-10">
      <h2 className="mb-1 text-[15px] font-semibold text-ink">{t('scan.history.title')}</h2>
      <p className="mb-3 text-[13px] text-ink-2">{t('scan.history.subtitle')}</p>
      <Tabs
        value={tab}
        onChange={setTab}
        className="mb-4"
        items={[
          { key: 'bills', label: t('scan.history.bills'), count: bills.data?.data.length },
          { key: 'fuel', label: t('scan.history.fuel'), count: fuel.data?.data.length },
          { key: 'readings', label: t('scan.history.readings'), count: readings.data?.data.length },
        ]}
      />
      {tab === 'bills' && (
        <Body
          query={bills}
          empty={{ title: t('scan.history.billsEmpty'), body: t('scan.history.billsEmptyBody') }}
          render={(rows) => (
            <Table
              head={[t('scan.history.month'), t('scan.field.supplier'), t('scan.history.billed'), t('scan.history.measured'), t('scan.history.coverage'), t('scan.field.total_eur'), t('scan.history.verdict'), '']}
              align={['left', 'left', 'right', 'right', 'right', 'right', 'left', 'right']}
              rows={rows.map((b) => {
                const badge = VERDICT_BADGE[b.verdict]
                return [
                  formatDate(`${b.month}-15T12:00:00Z`, 'medium').replace(/^\d+\s/, ''),
                  b.supplier ?? '—',
                  formatKwh(b.kwh),
                  b.metered_kwh === null ? '—' : formatKwh(b.metered_kwh),
                  b.coverage === null ? '—' : formatPercent(b.coverage),
                  formatEur(b.total_eur, { compact: false }),
                  badge ? <Badge tone={badge.tone} icon={badge.icon}>{t(`scan.verdict.bill.${b.verdict}`)}</Badge> : '—',
                  canEdit ? <Remove path={`/bills/${b.id}`} queryKey={['bills']} /> : null,
                ]
              })}
            />
          )}
        />
      )}
      {tab === 'fuel' && (
        <Body
          query={fuel}
          empty={{ title: t('scan.history.fuelEmpty'), body: t('scan.history.fuelEmptyBody') }}
          render={(rows) => (
            <Table
              head={[t('scan.history.month'), t('scan.field.fuel'), t('scan.field.litres'), 'CO₂e', t('scan.derived.energy'), t('scan.history.note'), '']}
              align={['left', 'left', 'right', 'right', 'right', 'left', 'right']}
              rows={rows.map((r) => [
                formatDate(`${r.month}-15T12:00:00Z`, 'medium').replace(/^\d+\s/, ''),
                t(`scan.option.fuel.${r.fuel}`, { defaultValue: r.fuel }),
                `${formatNumber(r.litres, 2)} l`,
                formatCo2(r.co2_kg),
                r.energy_kwh === null ? '—' : formatKwh(r.energy_kwh),
                <span key="n" className="text-ink-2">{r.note ?? '—'}</span>,
                canEdit ? <Remove path={`/fuel-records/${r.id}`} queryKey={['fuel-records']} /> : null,
              ])}
            />
          )}
        />
      )}
      {tab === 'readings' && (
        <Body
          query={readings}
          empty={{ title: t('scan.history.readingsEmpty'), body: t('scan.history.readingsEmptyBody') }}
          render={(rows) => (
            <Table
              head={[t('scan.history.readAt'), t('scan.field.meter_serial'), t('scan.field.register'), t('scan.field.reading_kwh'), '']}
              align={['left', 'left', 'left', 'right', 'right']}
              rows={rows.map((r) => [
                formatDate(r.read_at, 'dateTime'),
                r.meter_serial ?? '—',
                r.register,
                formatKwh(r.reading_kwh),
                canEdit ? <Remove path={`/meter-readings/${r.id}`} queryKey={['meter-readings']} /> : null,
              ])}
            />
          )}
        />
      )}
    </section>
  )
}
