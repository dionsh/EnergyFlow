import { useTranslation } from 'react-i18next'
import { MessageSquareText } from 'lucide-react'
import { Button } from '../../components/ui/Button'
import { useAssistant } from './AssistantProvider'

/**
 * "Explain this" on an item (docs/06 §2): opens the assistant and asks about it
 * with the item's id as context. Drawers pass onBeforeAsk to close themselves,
 * so the panel isn't hidden behind them.
 */
export function AskButton({ question, context, onBeforeAsk, size = 'sm', className }) {
  const { t } = useTranslation()
  const { ask } = useAssistant()
  return (
    <Button
      size={size}
      variant="secondary"
      icon={MessageSquareText}
      className={className}
      onClick={() => {
        onBeforeAsk?.()
        ask(question, context)
      }}
    >
      {t('assistant.askAbout')}
    </Button>
  )
}
