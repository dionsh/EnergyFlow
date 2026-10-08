import { createContext, use, useCallback, useEffect, useMemo, useState } from 'react'

const AssistantContext = createContext(null)
const STORAGE_KEY = 'ef-assistant-conversation'

function readStored() {
  try {
    const value = Number(sessionStorage.getItem(STORAGE_KEY))
    return Number.isInteger(value) && value > 0 ? value : null
  } catch {
    return null
  }
}

/**
 * Ask EnergyFlow's state for the whole app: whether the panel is open, the
 * current conversation (kept for the browser tab), and questions handed over
 * from a page ("Ask EnergyFlow" on a waste event) with that page's context.
 * Ctrl/⌘ K toggles the panel (docs/06-ux-design.md §2).
 */
export function AssistantProvider({ children }) {
  const [open, setOpen] = useState(false)
  const [conversationId, setConversationIdState] = useState(readStored)
  const [pending, setPending] = useState(null)

  const setConversationId = useCallback((id) => {
    setConversationIdState(id)
    try {
      if (id) sessionStorage.setItem(STORAGE_KEY, String(id))
      else sessionStorage.removeItem(STORAGE_KEY)
    } catch {
      // Private mode: the conversation simply isn't remembered across reloads.
    }
  }, [])

  /** Opens the panel and asks `text` with the given page context. */
  const ask = useCallback((text, context = {}) => {
    setPending({ text, context, key: Date.now() })
    setOpen(true)
  }, [])
  const clearPending = useCallback(() => setPending(null), [])

  // Floating elements (toasts, the Demo Director) move left of the docked panel.
  useEffect(() => {
    document.documentElement.style.setProperty('--assistant-offset', open ? '420px' : '0px')
    return () => document.documentElement.style.removeProperty('--assistant-offset')
  }, [open])

  useEffect(() => {
    const onKey = (event) => {
      if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
        event.preventDefault()
        setOpen((value) => !value)
      }
    }
    document.addEventListener('keydown', onKey)
    return () => document.removeEventListener('keydown', onKey)
  }, [])

  const value = useMemo(
    () => ({ open, setOpen, ask, pending, clearPending, conversationId, setConversationId }),
    [open, ask, pending, clearPending, conversationId, setConversationId],
  )
  return <AssistantContext value={value}>{children}</AssistantContext>
}

export function useAssistant() {
  const context = use(AssistantContext)
  if (!context) throw new Error('useAssistant must be used inside <AssistantProvider>')
  return context
}
