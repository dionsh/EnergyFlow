import { useTranslation } from 'react-i18next'
import { CircleAlert, CircleCheck, CircleMinus } from 'lucide-react'
import { formatAgo, formatDate, formatNumber } from '../../lib/format'
import { useNow } from '../../hooks/useNow'
import { Badge } from '../../components/ui/Badge'
import { Card, CardBody, CardHeader } from '../../components/ui/Card'
import { StatTile } from '../../components/ui/StatTile'
import { EmptyState, ErrorState, Skeleton } from '../../components/ui/States'
import { useAdminOverview } from '../data'
import { SignupsChart } from './SignupsChart'

const secondsSince = (iso, now) => (iso ? (now - Date.parse(iso)) / 1000 : null)

function SystemRow({ label, children }) {
  return (
    <div className="flex items-center justify-between gap-4 border-b border-line py-2.5 last:border-0">
      <dt className="text-[13px] text-ink-2">{label}</dt>
      <dd className="text-right text-[13px] font-medium text-ink">{children}</dd>
    </div>
  )
}

function Configured({ on }) {
  const { t } = useTranslation()
  return on ? (
    <Badge tone="good" icon={CircleCheck}>{t('admin.system.configured')}</Badge>
  ) : (
    <Badge tone="warning" icon={CircleMinus}>{t('admin.system.notConfigured')}</Badge>
  )
}

/** One line per admin action, written so it still reads after the user or company is gone. */
function activityText(t, entry) {
  return t(`admin.activity.actions.${entry.action}`, { email: entry.data.email, company: entry.data.company, defaultValue: entry.action })
}

export function AdminOverview({ onOpenUsers }) {
  const { t } = useTranslation()
  const now = useNow(30_000)
  const overview = useAdminOverview()

  if (overview.isPending) {
    return (
      <div className="flex flex-col gap-6">
        <div className="grid gap-3 sm:grid-cols-3">{Array.from({ length: 9 }, (_, i) => <Skeleton key={i} className="h-[104px]" />)}</div>
        <Skeleton className="h-72" />
      </div>
    )
  }
  if (overview.isError) return <ErrorState error={overview.error} onRetry={overview.refetch} />

  const o = overview.data.data
  const n = (value) => formatNumber(value)
  return (
    <div className="flex flex-col gap-6">
      <div>
        <div className="grid gap-3 sm:grid-cols-3">
          <StatTile label={t('admin.stats.companies')} value={n(o.companies.total)} context={t('admin.stats.companiesContext', { count: o.companies.new_30d, sending: o.companies.sending_data_24h })} />
          <StatTile label={t('admin.stats.users')} value={n(o.users.total)} context={t('admin.stats.usersContext', { count: o.users.new_30d, disabled: o.users.disabled })} />
          <StatTile label={t('admin.stats.active')} value={n(o.users.active_7d)} context={t('admin.stats.activeContext', { day: o.users.active_24h })} />
          <StatTile label={t('admin.stats.devices')} value={n(o.devices.hardware)} context={t('admin.stats.devicesContext', { online: o.devices.online, simulated: o.devices.simulated })} />
          <StatTile label={t('admin.stats.machines')} value={n(o.machines)} context={t('admin.stats.machinesContext')} />
          <StatTile label={t('admin.stats.demo')} value={n(o.demo.sessions_7d)} context={t('admin.stats.demoContext')} />
          <StatTile label={t('admin.stats.reports')} value={n(o.activity.reports_30d)} context={t('admin.stats.reportsContext')} />
          <StatTile label={t('admin.stats.questions')} value={n(o.activity.assistant_questions_7d)} context={t('admin.stats.questionsContext')} />
          <StatTile label={t('admin.stats.turnOffs')} value={n(o.activity.verified_turn_offs_30d)} context={t('admin.stats.turnOffsContext')} />
        </div>
        <p className="mt-2 text-xs text-ink-3">{t('admin.demoExcluded')}</p>
      </div>

      <div className="grid gap-6 lg:grid-cols-3">
        <Card className="lg:col-span-2">
          <CardHeader title={t('admin.signups.title')} description={t('admin.signups.description')} />
          <CardBody>
            <SignupsChart weeks={o.signups} />
          </CardBody>
        </Card>
        <Card>
          <CardHeader title={t('admin.system.title')} />
          <CardBody className="py-1.5">
            <dl>
              <SystemRow label={t('admin.system.environment')}><span className="font-mono text-[12.5px]">{o.system.environment}</span></SystemRow>
              <SystemRow label={t('admin.system.migration')}><span className="font-mono text-[12.5px]">{o.system.migration ?? '—'}</span></SystemRow>
              <SystemRow label={t('admin.system.database')}>{formatNumber(o.system.database_mb, 1)} MB</SystemRow>
              <SystemRow label={t('admin.system.php')}><span className="font-mono text-[12.5px]">{o.system.php}</span></SystemRow>
              <SystemRow label={t('admin.system.ai')}><Configured on={o.system.ai_configured} /></SystemRow>
              <SystemRow label={t('admin.system.mail')}><Configured on={o.system.mail_configured} /></SystemRow>
            </dl>
          </CardBody>
        </Card>
      </div>

      <div className="grid gap-6 lg:grid-cols-2">
        <Card>
          <CardHeader title={t('admin.recent.title')} />
          {o.recent_companies.length === 0 ? (
            <EmptyState title={t('admin.recent.empty')} />
          ) : (
            <ul>
              {o.recent_companies.map((c) => (
                <li key={c.id} className="border-b border-line last:border-0">
                  <button type="button" onClick={() => onOpenUsers(c.id)} className="flex w-full items-center justify-between gap-4 px-5 py-3 text-left hover:bg-surface-2">
                    <span className="min-w-0">
                      <span className="block truncate text-[13px] font-medium text-ink">{c.name}</span>
                      <span className="block truncate text-xs text-ink-3">{[c.city, c.owner_email].filter(Boolean).join(' · ')}</span>
                    </span>
                    <span className="shrink-0 text-xs text-ink-3">{formatDate(c.created_at)}</span>
                  </button>
                </li>
              ))}
            </ul>
          )}
        </Card>
        <Card>
          <CardHeader title={t('admin.activity.title')} />
          {o.admin_activity.length === 0 ? (
            <EmptyState title={t('admin.activity.empty')} />
          ) : (
            <ul>
              {o.admin_activity.map((a) => (
                <li key={a.id} className="flex items-start justify-between gap-4 border-b border-line px-5 py-3 last:border-0">
                  <p className="min-w-0 text-[13px] text-ink-2">
                    <span className="font-medium text-ink">{a.admin_email === 'command line' ? t('admin.activity.commandLine') : a.admin_email}</span>{' '}
                    {activityText(t, a)}
                  </p>
                  <span className="shrink-0 text-xs text-ink-3">{formatAgo(secondsSince(a.created_at, now))}</span>
                </li>
              ))}
            </ul>
          )}
        </Card>
      </div>

      <Card className="overflow-hidden">
        <CardHeader title={t('admin.jobs.title')} description={t('admin.jobs.description')} />
        {o.jobs.length === 0 ? (
          <EmptyState title={t('admin.jobs.empty')} />
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full min-w-[560px] border-collapse text-[13px]">
              <thead>
                <tr className="border-b border-line text-left text-xs text-ink-3">
                  <th className="px-5 py-2.5 font-medium">{t('admin.jobs.job')}</th>
                  <th className="px-3 py-2.5 text-right font-medium">{t('admin.jobs.companies')}</th>
                  <th className="px-3 py-2.5 font-medium">{t('admin.jobs.lastRun')}</th>
                  <th className="px-5 py-2.5 font-medium">{t('admin.jobs.status')}</th>
                </tr>
              </thead>
              <tbody>
                {o.jobs.map((job) => (
                  <tr key={job.key} className="border-b border-line align-top last:border-0">
                    <td className="px-5 py-2.5 font-mono text-[12.5px] text-ink">{job.key}</td>
                    <td className="px-3 py-2.5 text-right tabular text-ink-2">{formatNumber(job.companies)}</td>
                    <td className="px-3 py-2.5 text-ink-2">{job.last_finished_at ? formatAgo(secondsSince(job.last_finished_at, now)) : t('admin.jobs.never')}</td>
                    <td className="px-5 py-2.5">
                      {job.errors > 0 ? (
                        <div className="flex flex-col gap-1">
                          <Badge tone="critical" icon={CircleAlert}>{t('admin.jobs.errors', { count: job.errors })}</Badge>
                          {job.last_error && <p className="max-w-md break-words font-mono text-[11.5px] text-ink-3">{job.last_error}</p>}
                        </div>
                      ) : (
                        <Badge tone="good" icon={CircleCheck}>{t('admin.jobs.ok')}</Badge>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>
    </div>
  )
}
