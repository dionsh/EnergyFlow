import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useQuery } from '@tanstack/react-query'
import { ArrowRight, Building2, CalendarClock, CircleCheck, Cpu, Gauge, Receipt } from 'lucide-react'
import { api } from '../../lib/api'
import { cn } from '../../lib/cn'
import { useAuth } from '../../providers/AuthProvider'
import { PageHeader } from '../../components/layout/PageHeader'
import { Card, CardHeader } from '../../components/ui/Card'
import { EmptyState, ErrorState, Skeleton } from '../../components/ui/States'

const STEP_META = {
  company: { icon: Building2, to: '/settings' },
  schedule: { icon: CalendarClock, to: '/settings' },
  tariff: { icon: Receipt, to: '/settings' },
  device: { icon: Cpu, to: '/devices' },
}

function SetupChecklist() {
  const { t } = useTranslation()
  const onboarding = useQuery({
    queryKey: ['onboarding'],
    queryFn: async () => (await api.get('/onboarding')).data.steps,
  })

  if (onboarding.isPending) {
    return (
      <div className="flex flex-col gap-3 p-5">
        {[0, 1, 2, 3].map((index) => (
          <Skeleton key={index} className="h-12" />
        ))}
      </div>
    )
  }
  if (onboarding.isError) return <ErrorState error={onboarding.error} onRetry={onboarding.refetch} />

  const steps = onboarding.data
  const done = steps.filter((step) => step.done).length

  return (
    <>
      <CardHeader title={t('overview.setup.title')} actions={<span className="text-[13px] text-ink-2">{t('overview.setup.progress', { done, total: steps.length })}</span>} />
      <div className="h-1 bg-surface-2" aria-hidden="true">
        <div className="h-full bg-brand transition-[width]" style={{ width: `${(done / steps.length) * 100}%` }} />
      </div>
      <ol className="divide-y divide-line">
        {steps.map((step) => {
          const meta = STEP_META[step.key]
          const Icon = step.done ? CircleCheck : meta.icon
          return (
            <li key={step.key}>
              <Link to={meta.to} className="group flex items-center gap-4 px-5 py-3.5 hover:bg-surface-2">
                <Icon className={cn('size-5 shrink-0', step.done ? 'text-good' : 'text-ink-3')} aria-hidden="true" />
                <div className="min-w-0 flex-1">
                  <p className={cn('text-sm font-medium', step.done ? 'text-ink-2' : 'text-ink')}>{t(`overview.setup.${step.key}.title`)}</p>
                  <p className="text-[13px] text-ink-3">{t(`overview.setup.${step.key}.body`)}</p>
                </div>
                <span className={cn('text-xs font-medium', step.done ? 'text-good-text' : 'text-ink-3')}>
                  {step.done ? t('common.done') : t('common.todo')}
                </span>
                <ArrowRight className="size-4 shrink-0 text-ink-3 opacity-0 transition-opacity group-hover:opacity-100" aria-hidden="true" />
              </Link>
            </li>
          )
        })}
      </ol>
    </>
  )
}

export function OverviewPage() {
  const { t } = useTranslation()
  const { company } = useAuth()

  return (
    <>
      <PageHeader title={t('overview.title')} subtitle={t('overview.subtitle', { company: company?.name })} />
      <div className="grid gap-6 xl:grid-cols-[minmax(0,7fr)_minmax(0,5fr)]">
        <Card>
          <EmptyState icon={Gauge} title={t('overview.emptyTitle')} body={t('overview.emptyBody')} />
        </Card>
        <Card className="overflow-hidden">
          <SetupChecklist />
        </Card>
      </div>
    </>
  )
}
