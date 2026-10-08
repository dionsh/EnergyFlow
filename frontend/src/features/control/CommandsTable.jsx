import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { CircleCheck, CircleX, LoaderCircle } from 'lucide-react'
import { formatDate, formatKw } from '../../lib/format'
import { MachineIcon } from '../../lib/machineTypes'
import { Badge } from '../../components/ui/Badge'

export function CommandStatus({ command }) {
  const { t } = useTranslation()
  if (command.status === 'verified') {
    return (
      <span className="inline-flex flex-col items-start gap-0.5">
        <Badge tone="good" icon={CircleCheck}>{t('commandStatus.verified')}</Badge>
        {command.verification.verified_after_s !== null && (
          <span className="text-xs text-ink-3">{t('control.verifiedIn', { seconds: command.verification.verified_after_s })}</span>
        )}
      </span>
    )
  }
  if (command.status === 'failed') {
    return (
      <span className="inline-flex flex-col items-start gap-0.5">
        <Badge tone="critical" icon={CircleX}>{t('commandStatus.failed')}</Badge>
        <span className="text-xs text-ink-3">{t(`commandFailure.${command.failure_reason?.split(':')[0]}`, { defaultValue: command.failure_reason })}</span>
      </span>
    )
  }
  if (command.status === 'cancelled') return <Badge>{t('commandStatus.cancelled')}</Badge>
  return <Badge tone="info" icon={LoaderCircle} className="[&>svg]:animate-spin">{t(`commandStatus.${command.status}`)}</Badge>
}

/** Every command with who/what sent it and what the meter saw before and after. */
export function CommandsTable({ commands, showMachine = true }) {
  const { t } = useTranslation()
  return (
    <div className="overflow-x-auto">
      <table className="w-full min-w-[720px] border-collapse text-[13px]">
        <thead>
          <tr className="border-b border-line text-left text-xs text-ink-3">
            <th className="px-5 py-2.5 font-medium">{t('automationsPage.commandTable.time')}</th>
            {showMachine && <th className="px-3 py-2.5 font-medium">{t('automationsPage.commandTable.machine')}</th>}
            <th className="px-3 py-2.5 font-medium">{t('automationsPage.commandTable.source')}</th>
            <th className="px-3 py-2.5 font-medium">{t('automationsPage.commandTable.status')}</th>
            <th className="px-5 py-2.5 text-right font-medium">{t('automationsPage.commandTable.power')}</th>
          </tr>
        </thead>
        <tbody>
          {commands.map((c) => (
            <tr key={c.id} className="border-b border-line align-top last:border-0">
              <td className="whitespace-nowrap px-5 py-2.5 tabular text-ink-2">{formatDate(c.requested_at, 'dateTime')}</td>
              {showMachine && (
                <td className="px-3 py-2.5">
                  <Link to={`/machines/${c.machine.id}`} className="flex items-center gap-2 hover:underline">
                    <MachineIcon type={c.machine.type} className="size-4 shrink-0 text-ink-3" />
                    <span className="font-medium text-ink">{c.machine.code}</span>
                    <span className="hidden truncate text-ink-3 lg:inline">{c.machine.name}</span>
                  </Link>
                </td>
              )}
              <td className="px-3 py-2.5 text-ink-2">
                {c.source === 'policy' ? t('automationsPage.sourcePolicy') : t('automationsPage.sourceUser', { name: c.requested_by ?? '—' })}
                <span className="block font-mono text-[11px] text-ink-3">
                  {c.device.serial} · CH{c.device.channel}
                  {c.verification.method === 'simulated_history' && ` · ${t('automationsPage.simulatedHistory')}`}
                </span>
              </td>
              <td className="px-3 py-2.5"><CommandStatus command={c} /></td>
              <td className="whitespace-nowrap px-5 py-2.5 text-right tabular text-ink">
                {c.verification.power_before_kw === null ? '—' : formatKw(c.verification.power_before_kw)}
                <span className="mx-1.5 text-ink-3">→</span>
                {c.verification.power_after_kw === null ? '—' : formatKw(c.verification.power_after_kw)}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}
