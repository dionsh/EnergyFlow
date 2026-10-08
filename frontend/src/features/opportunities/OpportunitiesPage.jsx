import { useRef, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { Lightbulb } from 'lucide-react'
import { formatCo2, formatEur, formatKwh } from '../../lib/format'
import { PageHeader } from '../../components/layout/PageHeader'
import { Card, CardHeader } from '../../components/ui/Card'
import { EmptyState, ErrorState, Skeleton } from '../../components/ui/States'
import { useRecommendations } from '../data'
import { OpportunityCard, OpportunityDrawer, OpportunityRow } from './OpportunityParts'
import { WhatIfSimulator } from './WhatIfSimulator'

export function OpportunitiesPage() {
  const { t } = useTranslation()
  const [params, setParams] = useSearchParams()
  const recs = useRecommendations('all')
  const [simulation, setSimulation] = useState(null)
  const simulator = useRef(null)
  const openId = params.get('opportunity')

  const open = (id) => setParams((p) => {
    const next = new URLSearchParams(p)
    if (id) next.set('opportunity', String(id))
    else next.delete('opportunity')
    return next
  })
  const simulate = (rec) => {
    setSimulation({ key: Date.now(), machine_id: rec.machine.id, action: rec.what_if.action, params: rec.what_if.params })
    requestAnimationFrame(() => simulator.current?.scrollIntoView({ behavior: 'smooth', block: 'start' }))
  }

  const header = <PageHeader title={t('nav.opportunities')} subtitle={t('opportunities.subtitle')} />
  if (recs.isPending) {
    return (
      <>
        {header}
        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">{[0, 1, 2].map((i) => <Skeleton key={i} className="h-72" />)}</div>
      </>
    )
  }
  if (recs.isError) return <ErrorState error={recs.error} onRetry={recs.refetch} />

  const list = recs.data.data
  const potential = recs.data.meta.potential
  const proposed = list.filter((r) => r.status === 'proposed')
  const active = list.filter((r) => ['accepted', 'implemented', 'verified'].includes(r.status))
  const dismissed = list.filter((r) => r.status === 'dismissed')

  return (
    <>
      {header}
      <div className="flex flex-col gap-6">
        {potential.count > 0 && (
          <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1 rounded-md border border-line bg-surface px-5 py-4">
            <span className="text-[13px] font-medium text-ink-2">{t('opportunities.potential')}</span>
            <span className="text-[22px] font-semibold tracking-[-0.01em] text-ink">{formatEur(potential.eur)}</span>
            <span className="text-sm text-ink-2">
              {t('opportunities.perMonth')} · {formatKwh(potential.kwh)} · {formatCo2(potential.co2_kg)}
            </span>
            <span className="ml-auto text-[13px] text-ink-3">{t('opportunities.potentialContext', { count: potential.count })}</span>
          </div>
        )}

        <section>
          <h2 className="mb-3 text-base font-semibold text-ink">{t('opportunities.recommended')}</h2>
          {proposed.length === 0 ? (
            <Card><EmptyState icon={Lightbulb} title={t('modules.opportunities.emptyTitle')} body={t('opportunities.emptyOpen')} /></Card>
          ) : (
            <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
              {proposed.map((rec) => <OpportunityCard key={rec.id} rec={rec} onOpen={open} onSimulate={simulate} />)}
            </div>
          )}
        </section>

        <div ref={simulator} className="scroll-mt-20">
          <WhatIfSimulator key={simulation?.key ?? 'default'} initial={simulation} />
        </div>

        {active.length > 0 && (
          <Card className="overflow-hidden">
            <CardHeader title={t('opportunities.inProgress')} />
            <ul className="divide-y divide-line">{active.map((rec) => <OpportunityRow key={rec.id} rec={rec} onOpen={open} />)}</ul>
          </Card>
        )}
        {dismissed.length > 0 && (
          <Card className="overflow-hidden">
            <CardHeader title={t('opportunities.dismissedTitle')} />
            <ul className="divide-y divide-line">{dismissed.map((rec) => <OpportunityRow key={rec.id} rec={rec} onOpen={open} />)}</ul>
          </Card>
        )}
      </div>
      <OpportunityDrawer id={openId} onClose={() => open(null)} onSimulate={simulate} />
    </>
  )
}
