import { useTranslation } from 'react-i18next'
import { Circle, CircleCheck, CircleX, LoaderCircle } from 'lucide-react'
import { cn } from '../../lib/cn'
import { formatKw, formatTime } from '../../lib/format'

const STEPS = ['queued', 'sent', 'executed', 'verified']
const WAITING = { sent: 'waitingSent', executed: 'waitingExecuted', verified: 'waitingVerified' }

/** queued → sent → executed → verified, each with its time; the open step spins until the meter decides. */
export function CommandTimeline({ command }) {
  const { t } = useTranslation()
  const at = Object.fromEntries(command.timeline.map((s) => [s.step, s.at]))
  const failed = command.status === 'failed'
  const current = STEPS.find((step) => !at[step])
  const start = Date.parse(command.requested_at)
  const elapsed = (iso) => Math.max(0, Math.round((Date.parse(iso) - start) / 1000))

  return (
    <ol className="flex flex-col">
      {STEPS.map((step, index) => {
        const done = Boolean(at[step])
        const active = step === current
        const Icon = done ? CircleCheck : active ? (failed ? CircleX : LoaderCircle) : Circle
        const label = done
          ? t(`commandSteps.${step}`, { serial: `${command.device.serial} · CH${command.device.channel}`, kw: formatKw(command.verification.power_after_kw ?? 0) })
          : active && failed
            ? t('commandSteps.failed', { reason: t(`commandFailure.${command.failure_reason?.split(':')[0]}`, { defaultValue: command.failure_reason }) })
            : t(`commandSteps.${WAITING[step] ?? step}`)
        return (
          <li key={step} className="relative flex gap-3 pb-3 last:pb-0">
            {index < STEPS.length - 1 && <span className={cn('absolute left-[9px] top-6 h-[calc(100%-18px)] w-px', done ? 'bg-good' : 'bg-line')} aria-hidden="true" />}
            <Icon
              className={cn(
                'mt-0.5 size-[19px] shrink-0',
                done ? 'text-good' : active ? (failed ? 'text-critical' : 'animate-spin text-ink-3') : 'text-line-strong',
              )}
              aria-hidden="true"
            />
            <div className="flex min-w-0 flex-1 items-baseline justify-between gap-3 text-[13px]">
              <span className={cn(done ? 'text-ink' : active ? (failed ? 'text-critical-text' : 'text-ink-2') : 'text-ink-3')}>{label}</span>
              {done && (
                <span className="shrink-0 tabular text-xs text-ink-3">
                  {formatTime(at[step])}
                  {step !== 'queued' && <span className="ml-1.5">+{elapsed(at[step])} s</span>}
                </span>
              )}
            </div>
          </li>
        )
      })}
    </ol>
  )
}
