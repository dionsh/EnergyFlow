import { useMemo, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { CircleAlert, FilePlus2, FileText } from 'lucide-react'
import { api } from '../../lib/api'
import { formatCo2, formatDate, formatEur, formatKwh, localDate } from '../../lib/format'
import { hasRole } from '../../lib/roles'
import { LANGUAGES } from '../../i18n/languages'
import { useApiErrors } from '../../hooks/useApiErrors'
import { useAuth } from '../../providers/AuthProvider'
import { PageHeader } from '../../components/layout/PageHeader'
import { Badge } from '../../components/ui/Badge'
import { Button } from '../../components/ui/Button'
import { Card } from '../../components/ui/Card'
import { Select } from '../../components/ui/Field'
import { Modal } from '../../components/ui/Overlay'
import { Callout, EmptyState, ErrorState, Skeleton } from '../../components/ui/States'
import { useLive, useReports } from '../data'

/**
 * Today's date on the company clock (Kosovo time), as a UTC midnight so the
 * day arithmetic below is plain calendar math.
 */
function companyToday(nowIso) {
  const [year, month, day] = localDate(nowIso ?? new Date().toISOString()).split('-').map(Number)
  return new Date(Date.UTC(year, month - 1, day))
}

const isoDay = (date) => date.toISOString().slice(0, 10)
const dayLabel = (key) => formatDate(`${key}T12:00:00Z`)

/** The last 12 months on the company clock, newest first; the current one is "so far". */
function useMonths() {
  const { t } = useTranslation()
  const nowIso = useLive().data?.data?.now
  return useMemo(() => {
    const today = companyToday(nowIso)
    const names = t('calendar.months', { returnObjects: true })
    return Array.from({ length: 12 }, (_, i) => {
      const d = new Date(Date.UTC(today.getUTCFullYear(), today.getUTCMonth() - i, 1))
      const key = `${d.getUTCFullYear()}-${String(d.getUTCMonth() + 1).padStart(2, '0')}`
      const label = `${names[d.getUTCMonth()]} ${d.getUTCFullYear()}`
      return { key, label: i === 0 ? t('reportsPage.monthPartial', { month: label }) : label }
    })
  }, [nowIso, t])
}

function usePeriods(type) {
  const { t } = useTranslation()
  const nowIso = useLive().data?.data?.now
  const months = useMonths()
  return useMemo(() => {
    if (type === 'monthly') return months.map((m) => ({ key: m.key, label: m.label }))
    const today = companyToday(nowIso)
    if (type === 'daily') {
      return Array.from({ length: 14 }, (_, i) => {
        const d = new Date(today)
        d.setUTCDate(d.getUTCDate() - i)
        const key = isoDay(d)
        return { key, label: i === 0 ? t('reportsPage.today', { date: dayLabel(key) }) : dayLabel(key) }
      })
    }
    const monday = new Date(today)
    monday.setUTCDate(monday.getUTCDate() - ((monday.getUTCDay() + 6) % 7))
    return Array.from({ length: 12 }, (_, i) => {
      const from = new Date(monday)
      from.setUTCDate(from.getUTCDate() - i * 7)
      const to = new Date(from)
      to.setUTCDate(to.getUTCDate() + 6)
      const label = `${dayLabel(isoDay(from))} – ${dayLabel(isoDay(to))}`
      return { key: isoDay(from), label: i === 0 ? t('reportsPage.thisWeek', { range: label }) : label }
    })
  }, [months, nowIso, t, type])
}

function NewReportDialog({ open, onClose }) {
  const { t, i18n } = useTranslation()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { errorMessage } = useApiErrors()
  const [type, setType] = useState('monthly')
  const periods = usePeriods(type)
  const [month, setMonth] = useState(null)
  const [language, setLanguage] = useState(i18n.language)
  // Default to the last complete month, week or day.
  const chosen = periods.some((p) => p.key === month) ? month : (periods[1] ?? periods[0])?.key
  const create = useMutation({
    mutationFn: () => api.post('/reports', { type, period: chosen, language }),
    onSuccess: ({ data }) => {
      queryClient.invalidateQueries({ queryKey: ['reports'] })
      navigate(`/reports/${data.id}`)
    },
  })
  return (
    <Modal
      open={open}
      onClose={onClose}
      title={t('reportsPage.newTitle', { type: t(`reportsPage.types.${type}`) })}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>{t('common.cancel')}</Button>
          <Button icon={FilePlus2} loading={create.isPending} onClick={() => create.mutate()}>
            {create.isPending ? t('reportsPage.creating') : t('reportsPage.create')}
          </Button>
        </>
      }
    >
      <div className="grid gap-4 sm:grid-cols-2">
        <div>
          <label htmlFor="report-type" className="mb-1.5 block text-[13px] font-medium text-ink">{t('reportsPage.type')}</label>
          <Select id="report-type" value={type} onChange={(e) => { setType(e.target.value); setMonth(null) }}>
            {['daily', 'weekly', 'monthly'].map((value) => <option key={value} value={value}>{t(`reportsPage.types.${value}`)}</option>)}
          </Select>
        </div>
        <div>
          <label htmlFor="report-period" className="mb-1.5 block text-[13px] font-medium text-ink">{t('reportsPage.period')}</label>
          <Select id="report-period" value={chosen} onChange={(e) => setMonth(e.target.value)}>
            {periods.map((p) => <option key={p.key} value={p.key}>{p.label}</option>)}
          </Select>
        </div>
        <div>
          <label htmlFor="report-language" className="mb-1.5 block text-[13px] font-medium text-ink">{t('reportsPage.language')}</label>
          <Select id="report-language" value={language} onChange={(e) => setLanguage(e.target.value)}>
            {LANGUAGES.map((l) => <option key={l.code} value={l.code}>{l.label}</option>)}
          </Select>
        </div>
      </div>
      <p className="mt-3 text-[13px] text-ink-3">{t('reportsPage.createHint')}</p>
      {create.isError && <Callout tone="critical" icon={CircleAlert} className="mt-3">{errorMessage(create.error)}</Callout>}
    </Modal>
  )
}

export function ReportsPage() {
  const { t } = useTranslation()
  const { user } = useAuth()
  const reports = useReports()
  const [creating, setCreating] = useState(false)
  const canCreate = hasRole(user, 'manager')
  const action = canCreate && <Button icon={FilePlus2} onClick={() => setCreating(true)}>{t('reportsPage.new')}</Button>

  let body
  if (reports.isPending) body = <Skeleton className="h-48" />
  else if (reports.isError) body = <ErrorState error={reports.error} onRetry={reports.refetch} />
  else if (reports.data.data.length === 0) {
    body = <Card><EmptyState icon={FileText} title={t('reportsPage.emptyTitle')} body={t('reportsPage.emptyBody')} actions={action} /></Card>
  } else {
    body = (
      <Card className="overflow-hidden">
        <div className="overflow-x-auto">
          <table className="w-full min-w-[720px] border-collapse text-[13px]">
            <thead>
              <tr className="border-b border-line text-left text-xs text-ink-3">
                <th className="px-5 py-2.5 font-medium">{t('reportsPage.table.period')}</th>
                <th className="px-3 py-2.5 font-medium">{t('reportsPage.table.language')}</th>
                <th className="px-3 py-2.5 text-right font-medium">{t('reportsPage.table.energy')}</th>
                <th className="px-3 py-2.5 text-right font-medium">{t('reportsPage.table.co2')}</th>
                <th className="px-3 py-2.5 font-medium">{t('reportsPage.table.status')}</th>
                <th className="px-5 py-2.5 font-medium">{t('reportsPage.table.created')}</th>
              </tr>
            </thead>
            <tbody>
              {reports.data.data.map((r) => (
                <tr key={r.id} className="border-b border-line last:border-0 hover:bg-surface-2">
                  <td className="px-5 py-3">
                    <Link to={`/reports/${r.id}`} className="flex items-center gap-2.5 font-medium text-ink hover:underline">
                      <FileText className="size-4 shrink-0 text-ink-3" aria-hidden="true" />
                      {formatDate(`${r.period_start}T12:00:00Z`)} – {formatDate(`${r.period_end}T12:00:00Z`)}
                    </Link>
                  </td>
                  <td className="px-3 py-3 uppercase text-ink-2">{r.language}</td>
                  <td className="whitespace-nowrap px-3 py-3 text-right tabular text-ink">
                    {formatKwh(r.headline.kwh)}
                    <span className="block text-xs text-ink-3">{formatEur(r.headline.eur)}</span>
                  </td>
                  <td className="whitespace-nowrap px-3 py-3 text-right tabular text-ink-2">{formatCo2(r.headline.co2_kg)}</td>
                  <td className="px-3 py-3"><Badge tone={r.status === 'final' ? 'good' : 'neutral'}>{t(`reportsPage.status.${r.status}`)}</Badge></td>
                  <td className="px-5 py-3 text-ink-2">
                    {formatDate(r.created_at, 'dateTime')}
                    {r.created_by && <span className="block text-xs text-ink-3">{r.created_by}</span>}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </Card>
    )
  }

  return (
    <>
      <PageHeader title={t('nav.reports')} subtitle={t('reportsPage.subtitle')} actions={reports.data?.data.length ? action : null} />
      {body}
      {creating && <NewReportDialog open={creating} onClose={() => setCreating(false)} />}
    </>
  )
}
