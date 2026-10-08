import { useId, useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { CircleAlert, FlaskConical, LoaderCircle } from 'lucide-react'
import { formatNumber } from '../../lib/format'
import { useDebounced } from '../../hooks/useDebounced'
import { Card, CardBody, CardHeader } from '../../components/ui/Card'
import { Select } from '../../components/ui/Field'
import { Segmented } from '../../components/ui/Tabs'
import { Callout, EmptyState, Skeleton } from '../../components/ui/States'
import { useWhatIf, useWhatIfOptions } from '../data'
import { WhatIfResult } from './WhatIfParts'

const GRACE = [0, 15, 30, 60]
const WINDOWS = [14, 30, 60]
const DEFAULTS = { auto_off_after_schedule: { grace_min: 15 }, leak_repair: { repair_share: 0.5 }, efficiency_restore: {}, tou_shift: { shift_share: 0.5 } }

function Label({ htmlFor, children }) {
  return <label htmlFor={htmlFor} className="mb-1.5 block text-[13px] font-medium text-ink">{children}</label>
}

function Slider({ id, label, value, min, max, step, format, onChange }) {
  return (
    <div>
      <Label htmlFor={id}>{label}</Label>
      <div className="flex h-9 items-center gap-3">
        <input id={id} type="range" min={min} max={max} step={step} value={value} onChange={(e) => onChange(Number(e.target.value))} className="w-full accent-[var(--brand)]" />
        <span className="w-12 shrink-0 text-right text-[13px] font-medium tabular text-ink">{format(value)}</span>
      </div>
    </div>
  )
}

/**
 * Pick a machine and a change; EnergyFlow replays that machine's own history with
 * the change applied. `initial` ({ machine_id, action, params }) pre-fills it from an opportunity.
 */
export function WhatIfSimulator({ initial }) {
  const { t } = useTranslation()
  const ids = { machine: useId(), action: useId(), param: useId() }
  const options = useWhatIfOptions()
  const machines = useMemo(() => options.data?.data ?? [], [options.data])
  const [machineId, setMachineId] = useState(initial?.machine_id ?? null)
  const [action, setAction] = useState(initial?.action ?? null)
  const [params, setParams] = useState(initial?.params ?? {})
  const [windowDays, setWindowDays] = useState(30)

  const machine = machines.find((m) => m.id === machineId) ?? machines[0]
  const currentAction = machine?.actions.includes(action) ? action : machine?.actions[0]
  const input = useDebounced(machine ? { machine_id: machine.id, action: currentAction, params, window_days: windowDays } : null)
  const result = useWhatIf(input)
  const data = result.data?.data
  const effective = { ...DEFAULTS[currentAction], ...(data && data.action === currentAction ? data.params : {}), ...params }

  const chooseMachine = (id) => {
    setMachineId(id)
    setParams({})
  }
  const chooseAction = (next) => {
    setAction(next)
    setParams({})
  }
  const set = (key, value) => setParams((p) => ({ ...p, [key]: value }))

  return (
    <Card>
      <CardHeader
        title={t('whatIf.title')}
        description={t('whatIf.subtitle', { days: windowDays })}
        actions={result.isFetching && <LoaderCircle className="size-4 animate-spin text-ink-3" aria-hidden="true" />}
      />
      <CardBody>
        {options.isPending ? (
          <Skeleton className="h-64" />
        ) : machines.length === 0 ? (
          <EmptyState icon={FlaskConical} title={t('whatIf.title')} body={t('whatIf.pick')} />
        ) : (
          <div className="grid gap-6 xl:grid-cols-[minmax(0,4fr)_minmax(0,8fr)]">
            <div className="flex flex-col gap-4">
              <div>
                <Label htmlFor={ids.machine}>{t('whatIf.machine')}</Label>
                <Select id={ids.machine} value={machine.id} onChange={(e) => chooseMachine(Number(e.target.value))}>
                  {machines.map((m) => <option key={m.id} value={m.id}>{m.code} · {m.name}</option>)}
                </Select>
              </div>
              <div>
                <Label htmlFor={ids.action}>{t('whatIf.action')}</Label>
                <Select id={ids.action} value={currentAction} onChange={(e) => chooseAction(e.target.value)}>
                  {machine.actions.map((a) => <option key={a} value={a}>{t(`whatIf.actions.${a}`)}</option>)}
                </Select>
              </div>
              {currentAction === 'auto_off_after_schedule' && (
                <div>
                  <p className="mb-1.5 text-[13px] font-medium text-ink">{t('whatIf.params.grace_min')}</p>
                  <Segmented items={GRACE.map((g) => ({ key: g, label: t('whatIf.minutes', { count: g }) }))} value={effective.grace_min} onChange={(g) => set('grace_min', g)} className="w-fit" />
                </div>
              )}
              {currentAction === 'leak_repair' && (
                <Slider id={ids.param} label={t('whatIf.params.repair_share')} value={effective.repair_share ?? 0.5} min={0.1} max={0.9} step={0.1} format={(v) => `${formatNumber(v * 100, 0)}%`} onChange={(v) => set('repair_share', v)} />
              )}
              {currentAction === 'efficiency_restore' && (
                <Slider id={ids.param} label={t('whatIf.params.improvement_pct')} value={effective.improvement_pct ?? 5} min={1} max={40} step={0.5} format={(v) => `${formatNumber(v, 1)}%`} onChange={(v) => set('improvement_pct', v)} />
              )}
              {currentAction === 'tou_shift' && (
                <Slider id={ids.param} label={t('whatIf.params.shift_share')} value={effective.shift_share ?? 0.5} min={0.1} max={1} step={0.1} format={(v) => `${formatNumber(v * 100, 0)}%`} onChange={(v) => set('shift_share', v)} />
              )}
              <div>
                <p className="mb-1.5 text-[13px] font-medium text-ink">{t('whatIf.window')}</p>
                <Segmented items={WINDOWS.map((d) => ({ key: d, label: t('whatIf.days', { count: d }) }))} value={windowDays} onChange={setWindowDays} className="w-fit" />
              </div>
            </div>
            <div className="min-w-0">
              {result.isError ? (
                <Callout tone="warning" icon={CircleAlert}>{t(`whatIf.error.${result.error.code}`, { defaultValue: t('errors.generic') })}</Callout>
              ) : !data ? (
                <Skeleton className="h-80" />
              ) : (
                <WhatIfResult result={data} />
              )}
            </div>
          </div>
        )}
      </CardBody>
    </Card>
  )
}
