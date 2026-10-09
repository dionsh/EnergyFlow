import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Clapperboard, RotateCcw, X, Zap } from 'lucide-react'
import { api } from '../../lib/api'
import { formatDate, formatKw } from '../../lib/format'
import { useAuth } from '../../providers/AuthProvider'
import { Button, IconButton } from '../../components/ui/Button'

/**
 * Floating control panel for presenting the demo: shows the virtual clock and can
 * fast-forward it, inject a fault for the detectors to find, or rebuild the whole
 * story. Only demo-company admins see it.
 */
export function DemoDirector() {
  const { t } = useTranslation()
  const { user, company, setSession } = useAuth()
  const queryClient = useQueryClient()
  const [open, setOpen] = useState(false)
  const allowed = company?.is_demo && (user?.role === 'owner' || user?.role === 'admin')

  const state = useQuery({
    queryKey: ['demo', 'state'],
    queryFn: async () => (await api.get('/demo/state')).data,
    enabled: Boolean(allowed && open),
    refetchInterval: open ? 5_000 : false,
  })

  const refreshAll = () => queryClient.invalidateQueries()
  const advance = useMutation({
    mutationFn: (step) => api.post('/demo/advance', step),
    onSuccess: refreshAll,
  })
  const spike = useMutation({
    mutationFn: () => api.post('/demo/spike', {}),
    onSuccess: refreshAll,
  })
  const reset = useMutation({
    mutationFn: () => api.post('/demo/reset', { scene: 'weekday_evening' }),
    onSuccess: ({ data }) => {
      setSession(data)
      refreshAll()
    },
  })
  const busy = advance.isPending || reset.isPending || spike.isPending

  if (!allowed) return null

  if (!open) {
    return (
      <button
        type="button"
        onClick={() => setOpen(true)}
        className="fixed bottom-4 right-4 z-30 inline-flex h-10 sm:right-[calc(1rem+var(--assistant-offset,0px))] items-center gap-2 rounded-md border border-line-strong bg-surface px-3.5 text-sm font-medium text-ink shadow-overlay hover:bg-surface-2"
        aria-label={t('demo.open')}
      >
        <Clapperboard className="size-4" aria-hidden="true" />
        Demo
      </button>
    )
  }

  return (
    <div className="fixed bottom-4 right-4 z-30 w-80 rounded-lg sm:right-[calc(1rem+var(--assistant-offset,0px))] border border-line bg-surface shadow-overlay" role="dialog" aria-label={t('demo.director')}>
      <div className="flex items-center justify-between border-b border-line px-4 py-2.5">
        <p className="flex items-center gap-2 text-sm font-semibold text-ink">
          <Clapperboard className="size-4" aria-hidden="true" />
          {t('demo.director')}
        </p>
        <IconButton label={t('demo.close')} icon={X} onClick={() => setOpen(false)} className="size-8" />
      </div>
      <div className="flex flex-col gap-3 px-4 py-3">
        <div>
          <p className="text-xs text-ink-3">{t('demo.virtualTime')}</p>
          <p className="text-sm font-medium tabular text-ink">
            {state.data ? formatDate(state.data.virtual_now, 'weekdayTime') : '—'}
          </p>
        </div>
        <div className="grid grid-cols-4 gap-1.5">
          <Button variant="secondary" size="sm" disabled={busy} onClick={() => advance.mutate({ minutes: 5 })} className="px-1.5">{t('demo.advanceMinutes')}</Button>
          <Button variant="secondary" size="sm" disabled={busy} onClick={() => advance.mutate({ hours: 1 })} className="px-1.5">{t('demo.advanceHour')}</Button>
          <Button variant="secondary" size="sm" disabled={busy} onClick={() => advance.mutate({ hours: 24 })} className="px-1.5">{t('demo.advanceDay')}</Button>
          <Button variant="secondary" size="sm" disabled={busy} onClick={() => advance.mutate({ hours: 168 })} className="px-1.5">{t('demo.advanceWeek')}</Button>
        </div>
        <div className="border-t border-line pt-3">
          <p className="mb-1.5 text-xs text-ink-3">{t('demo.faults')}</p>
          <Button variant="secondary" size="sm" icon={Zap} loading={spike.isPending} disabled={busy} onClick={() => spike.mutate()}>{t('demo.spike')}</Button>
          <p className="mt-1 text-[11.5px] text-ink-3" role="status">
            {spike.isError
              ? t(`demo.spikeError.${spike.error?.code}`, { defaultValue: spike.error?.message })
              : spike.data
                ? t('demo.spikeDone', { code: spike.data.data.machine.code, pct: spike.data.data.percent, kw: formatKw(spike.data.data.kw) })
                : t('demo.spikeHint')}
          </p>
        </div>
        <div className="border-t border-line pt-3">
          <Button variant="ghost" size="sm" icon={RotateCcw} loading={reset.isPending} disabled={busy} onClick={() => reset.mutate()}>
            {busy ? t('demo.working') : t('demo.reset')}
          </Button>
          <p className="mt-1 text-[11.5px] text-ink-3">{t('demo.resetHint')}</p>
        </div>
      </div>
    </div>
  )
}
