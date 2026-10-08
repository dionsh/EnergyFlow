import { useCallback, useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { CircleAlert, Info, LoaderCircle, Lock, Power, ShieldCheck, TriangleAlert } from 'lucide-react'
import { api } from '../../lib/api'
import { cn } from '../../lib/cn'
import { formatDuration, formatEur, formatTime } from '../../lib/format'
import { hasRole } from '../../lib/roles'
import { useApiErrors } from '../../hooks/useApiErrors'
import { useAuth } from '../../providers/AuthProvider'
import { useToast } from '../../providers/ToastProvider'
import { Badge } from '../../components/ui/Badge'
import { Button } from '../../components/ui/Button'
import { Modal } from '../../components/ui/Overlay'
import { Callout } from '../../components/ui/States'
import { useCommand } from '../data'
import { CommandTimeline } from './CommandTimeline'

const PENDING = ['queued', 'sent', 'acknowledged', 'executed']
const REFRESH = ['live', 'machines', 'machine', 'waste', 'alerts', 'notifications', 'commands', 'policies', 'overview']

/** Everything that shows machine data refreshes once the meter has spoken. */
function useRefresh() {
  const queryClient = useQueryClient()
  return useCallback(() => REFRESH.forEach((key) => queryClient.invalidateQueries({ queryKey: [key] })), [queryClient])
}

/**
 * Confirm → send → watch the meter. The dialog stays mounted (hidden) after it is
 * closed until the command is decided, so the result is always announced.
 */
function TurnOffDialog({ machine, open, commandId, onCommand, onClose, onSettled }) {
  const { t } = useTranslation()
  const toast = useToast()
  const refresh = useRefresh()
  const { errorMessage } = useApiErrors()
  const [confirmed, setConfirmed] = useState(false)
  const command = useCommand(commandId)
  const current = command.data?.data
  const settled = current && !PENDING.includes(current.status)
  const announced = useRef(null)

  const send = useMutation({
    mutationFn: () => api.post(`/machines/${machine.id}/commands`, { command: 'turn_off', confirm_scheduled: confirmed }),
    onSuccess: ({ data }) => {
      onCommand(data.id)
      refresh()
    },
  })

  useEffect(() => {
    if (!settled || announced.current === current.id) return
    announced.current = current.id
    if (current.status === 'verified') {
      toast({ tone: 'good', title: t('control.verifiedToast', { machine: machine.code }), body: t('control.verifiedToastBody', { seconds: current.verification.verified_after_s }) })
    } else {
      toast({ tone: 'critical', title: t('control.failedToast', { machine: machine.code }), body: t(`commandFailure.${current.failure_reason?.split(':')[0]}`, { defaultValue: current.failure_reason }) })
    }
    refresh()
    onSettled(current.id)
  }, [settled, current, machine.code, refresh, t, toast, onSettled])

  const control = machine.control ?? {}
  const needsConfirm = control.needs_confirm === 'machine_scheduled'
  const projection = machine.after_hours?.projection
  const close = () => onClose(Boolean(settled))

  return (
    <Modal
      open={open}
      onClose={close}
      title={t('control.dialogTitle', { machine: `${machine.code} · ${machine.name}` })}
      footer={
        commandId ? (
          <Button variant="secondary" onClick={close}>{settled ? t('control.done') : t('common.close')}</Button>
        ) : (
          <>
            <Button variant="secondary" onClick={close}>{t('common.cancel')}</Button>
            <Button variant="confirmDanger" icon={Power} loading={send.isPending} disabled={needsConfirm && !confirmed} onClick={() => send.mutate()}>
              {t('control.turnOff')}
            </Button>
          </>
        )
      }
    >
      {commandId ? (
        current ? <CommandTimeline command={current} /> : <LoaderCircle className="mx-auto my-6 size-5 animate-spin text-ink-3" aria-hidden="true" />
      ) : (
        <div className="flex flex-col gap-3">
          <p className="text-ink">{t(`control.consequence.${machine.type}`, { defaultValue: t('control.consequence.default') })}</p>
          {machine.after_hours && (
            <p>
              {t('control.afterHours', {
                time: formatTime(machine.after_hours.since),
                duration: formatDuration(machine.after_hours.duration_s),
                eur: formatEur(machine.after_hours.eur),
              })}
              {projection && <> {t('control.projection', { time: formatTime(projection.until), eur: formatEur(projection.eur) })}</>}
            </p>
          )}
          {needsConfirm && (
            <Callout tone="warning" icon={TriangleAlert}>
              <p>{t('control.scheduledWarning')}</p>
              <label className="mt-2 flex items-center gap-2 font-medium text-ink">
                <input type="checkbox" checked={confirmed} onChange={(e) => setConfirmed(e.target.checked)} className="size-4 accent-[var(--brand)]" />
                {t('control.confirmScheduled')}
              </label>
            </Callout>
          )}
          {control.relay && (
            <p className="flex items-start gap-2 text-[13px] text-ink-2">
              <ShieldCheck className="mt-0.5 size-4 shrink-0 text-ink-3" aria-hidden="true" />
              {t('control.safety', { serial: control.relay.serial, channel: control.relay.channel })}
            </p>
          )}
          {machine.device?.simulated && (
            <p className="flex items-start gap-2 text-[12.5px] text-ink-3">
              <Info className="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />
              {t('control.simulatedNode')}
            </p>
          )}
          {send.isError && <Callout tone="critical" icon={CircleAlert}>{errorMessage(send.error)}</Callout>}
        </div>
      )}
    </Modal>
  )
}

/**
 * Turn Off for one machine, with the safety rule explained when it isn't possible.
 * `machine` is a live machine row (control, command, after_hours, device).
 * variant: 'prominent' (machine header, drawer) | 'compact' (tables: only where it applies).
 */
export function TurnOffButton({ machine, variant = 'prominent', className }) {
  const { t } = useTranslation()
  const { user } = useAuth()
  const [open, setOpen] = useState(false)
  const [commandId, setCommandId] = useState(null)
  const [settledId, setSettledId] = useState(null)
  const openRef = useRef(open)
  useEffect(() => {
    openRef.current = open
  }, [open])
  // Decided while the dialog was closed: forget it. If it is open, the user reads the result first.
  const settle = useCallback((id) => {
    setSettledId(id)
    if (!openRef.current) setCommandId(null)
  }, [])
  const control = machine?.control
  if (!control) return null

  const pendingId = machine.command && PENDING.includes(machine.command.status) ? machine.command.id : null
  const canAct = hasRole(user, 'manager')
  const reason = canAct ? control.reason : 'read_only'
  const compact = variant === 'compact'

  let trigger = null
  if (pendingId || (commandId && commandId !== settledId)) {
    trigger = (
      <button type="button" onClick={() => setOpen(true)} className={className}>
        <Badge tone="info" icon={LoaderCircle} className="[&>svg]:animate-spin">{t('control.inProgress')}</Badge>
      </button>
    )
  } else if (!control.can_turn_off || !canAct) {
    if (!compact) {
      trigger = (
        <span className={cn('inline-flex flex-col items-end gap-1', className)}>
          <Button variant="danger" icon={Power} disabled>{t('control.turnOff')}</Button>
          <span className="max-w-64 text-right text-xs text-ink-3">{t(`control.reasons.${reason}`, { defaultValue: '' })}</span>
        </span>
      )
    } else if (reason === 'machine_critical' || reason === 'control_monitor_only') {
      trigger = (
        <span title={t(`control.reasons.${reason}`)} className={cn('inline-flex text-ink-3', className)}>
          <Lock className="size-4" aria-label={t(`control.reasons.${reason}`)} />
        </span>
      )
    }
  } else {
    const loud = !compact || Boolean(machine.after_hours)
    trigger = (
      <Button
        variant={loud ? 'danger' : 'ghost'}
        size={compact ? 'sm' : 'md'}
        icon={Power}
        onClick={() => setOpen(true)}
        title={t('control.turnOffMachine', { machine: machine.code })}
        className={className}
      >
        {loud ? t('control.turnOff') : <span className="sr-only">{t('control.turnOff')}</span>}
      </Button>
    )
  }

  return (
    <>
      {trigger}
      {(open || commandId || pendingId) && (
        <TurnOffDialog
          machine={machine}
          open={open}
          commandId={commandId ?? pendingId}
          onCommand={setCommandId}
          onSettled={settle}
          onClose={(settledNow) => {
            setOpen(false)
            if (settledNow) setCommandId(null)
          }}
        />
      )}
    </>
  )
}
