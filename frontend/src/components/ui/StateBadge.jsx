import { useTranslation } from 'react-i18next'
import { cn } from '../../lib/cn'

// State is shown with a shape + label, never colour alone (docs/06 §4.6).
const STYLES = {
  running: { dot: 'bg-brand', ring: '', text: 'text-ink' },
  idle: { dot: 'bg-surface border-2 border-brand', ring: '', text: 'text-ink' },
  off: { dot: 'bg-surface border-2 border-line-strong', ring: '', text: 'text-ink-3' },
  unknown: { dot: 'bg-line-strong', ring: '', text: 'text-ink-3' },
  abnormal: { dot: 'bg-critical', ring: '', text: 'text-critical-text' },
}

export function StateBadge({ state, className }) {
  const { t } = useTranslation()
  const style = STYLES[state] ?? STYLES.unknown
  return (
    <span className={cn('inline-flex items-center gap-1.5 whitespace-nowrap text-[13px]', style.text, className)}>
      <span className={cn('inline-block size-2.5 rounded-full', style.dot)} aria-hidden="true" />
      {t(`machineState.${state ?? 'unknown'}`)}
    </span>
  )
}
