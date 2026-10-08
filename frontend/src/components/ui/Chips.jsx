import { useTranslation } from 'react-i18next'
import { CircleAlert, Info, TriangleAlert } from 'lucide-react'
import { cn } from '../../lib/cn'
import { Badge } from './Badge'

const SEVERITY = {
  critical: { tone: 'critical', icon: CircleAlert },
  warning: { tone: 'warning', icon: TriangleAlert },
  info: { tone: 'info', icon: Info },
}

/** ● Critical · ▲ Warning · i Info — always icon + label, never colour alone (docs/06 §4.6). */
export function SeverityBadge({ severity, className }) {
  const { t } = useTranslation()
  const style = SEVERITY[severity] ?? SEVERITY.info
  return (
    <Badge tone={style.tone} icon={style.icon} className={className}>
      {t(`severity.${severity}`)}
    </Badge>
  )
}

/** How a finding was made: Rule, Statistical, Pattern, ML, LLM, Data. Neutral outline — it informs, it doesn't alarm. */
export function MethodChip({ method, detail, className }) {
  const { t } = useTranslation()
  return (
    <span className={cn('inline-flex h-5 items-center whitespace-nowrap rounded-sm border border-line-strong px-1.5 text-[11px] font-medium text-ink-2', className)}>
      {t(`method.${method}`, { defaultValue: method })}
      {detail && <span className="ml-1 text-ink-3">· {detail}</span>}
    </span>
  )
}
