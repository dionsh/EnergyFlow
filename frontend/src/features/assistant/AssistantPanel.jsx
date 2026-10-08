import { useEffect, useLayoutEffect, useRef, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import {
  ArrowUp, ArrowUpRight, Ban, ChevronLeft, CircleAlert, Database, History, Info, MessageSquareText, Power, ShieldCheck, SquarePen, Trash2,
  TriangleAlert, X,
} from 'lucide-react'
import { api } from '../../lib/api'
import { cn } from '../../lib/cn'
import { formatDate } from '../../lib/format'
import { useApiErrors } from '../../hooks/useApiErrors'
import { Button, IconButton } from '../../components/ui/Button'
import { Skeleton } from '../../components/ui/States'
import { CommandTimeline } from '../control/CommandTimeline'
import { useAssistantSuggestions, useCommand, useConversation, useConversations } from '../data'
import { MarkdownLite } from './MarkdownLite'
import { useAssistant } from './AssistantProvider'
import { usePageContext } from './usePageContext'

const MAX_CHARS = 1000

/** How an answer was made, always visible (docs/06 §3: every insight has a method chip). */
const SOURCE = {
  data: { icon: Database, className: 'border-line-strong text-ink-2' },
  ai: { icon: ShieldCheck, className: 'border-line-strong text-ink-2' },
  aiUnverified: { icon: TriangleAlert, className: 'border-warning/60 bg-warning-subtle text-warning-text' },
  refusal: { icon: Ban, className: 'border-line-strong text-ink-3' },
  fallback: { icon: Info, className: 'border-line-strong text-ink-3' },
}

function SourceChip({ message }) {
  const { t } = useTranslation()
  const key = message.source === 'ai' && message.grounded === false ? 'aiUnverified' : message.source ?? 'data'
  const style = SOURCE[key] ?? SOURCE.data
  const Icon = style.icon
  return (
    <span title={t(`assistant.sourceHint.${key}`)} className={cn('inline-flex h-5 items-center gap-1 whitespace-nowrap rounded-sm border px-1.5 text-[11px] font-medium', style.className)}>
      <Icon className="size-3" aria-hidden="true" />
      {t(`assistant.source.${key}`)}
    </span>
  )
}

function useConversationCache() {
  const queryClient = useQueryClient()
  const { conversationId } = useAssistant()
  return (updater) => queryClient.setQueryData(['assistant', 'conversation', conversationId], (old) => (old ? { ...old, data: updater(old.data) } : old))
}

/** Yes / No on a Turn Off the assistant proposed; Yes goes through the same API as the Live button. */
function TurnOffCard({ message, action }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const { errorMessage } = useApiErrors()
  const updateCache = useConversationCache()
  const [commandId, setCommandId] = useState(action.command_id ?? null)
  const command = useCommand(commandId)
  const record = (state, id = null) => updateCache((conversation) => ({
    ...conversation,
    messages: conversation.messages.map((m) => (m.id === message.id
      ? { ...m, actions: m.actions.map((a) => (a.type === 'turn_off' ? { ...a, state, command_id: id } : a)) }
      : m)),
  }))
  const confirm = useMutation({
    mutationFn: async () => {
      const { data } = await api.post(`/machines/${action.machine.id}/commands`, {
        command: 'turn_off',
        reason: t('assistant.turnOff.reason'),
        confirm_scheduled: action.needs_confirm === 'machine_scheduled',
      })
      await api.post(`/assistant/messages/${message.id}/action`, { state: 'confirmed', command_id: data.id })
      return data
    },
    onSuccess: (data) => {
      setCommandId(data.id)
      record('confirmed', data.id)
      queryClient.invalidateQueries({ queryKey: ['live'] })
    },
  })
  const cancel = useMutation({
    mutationFn: () => api.post(`/assistant/messages/${message.id}/action`, { state: 'cancelled' }),
    onSuccess: () => record('cancelled'),
  })

  if (action.state === 'cancelled') {
    return <p className="mt-3 text-[12.5px] text-ink-3">{t('assistant.turnOff.cancelled')}</p>
  }
  if (commandId) {
    return (
      <div className="mt-3 rounded-md border border-line bg-surface-2/60 px-3 py-3">
        <p className="mb-2.5 text-[12px] font-medium text-ink-2">{t('assistant.turnOff.sent', { code: action.machine.code })}</p>
        {command.data ? <CommandTimeline command={command.data.data} /> : <Skeleton className="h-24" />}
      </div>
    )
  }
  return (
    <div className="mt-3">
      <div className="flex flex-wrap gap-2">
        <Button size="sm" variant="confirmDanger" icon={Power} loading={confirm.isPending} disabled={cancel.isPending} onClick={() => confirm.mutate()}>
          {t('assistant.turnOff.yes')}
        </Button>
        <Button size="sm" variant="secondary" disabled={confirm.isPending} loading={cancel.isPending} onClick={() => cancel.mutate()}>
          {t('assistant.turnOff.no')}
        </Button>
      </div>
      {confirm.isError && (
        <p className="mt-2 flex items-start gap-1.5 text-[12.5px] text-critical-text">
          <CircleAlert className="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />
          {errorMessage(confirm.error)}
        </p>
      )}
    </div>
  )
}

function AssistantMessage({ message, onAsk, onNavigate, busy, fresh }) {
  const { t } = useTranslation()
  const navigateActions = message.actions.filter((a) => a.type === 'navigate')
  const asks = message.actions.filter((a) => a.type === 'ask')
  const turnOff = message.actions.find((a) => a.type === 'turn_off')
  return (
    <div className={cn('flex flex-col gap-2', fresh && 'animate-pop')}>
      <div className="rounded-lg border border-line bg-surface px-3.5 py-3 text-[13.5px] leading-relaxed text-ink">
        <MarkdownLite text={message.content} />
        {turnOff && <TurnOffCard message={message} action={turnOff} />}
        <div className="mt-3 flex flex-wrap items-center gap-x-2 gap-y-1.5 border-t border-line pt-2.5">
          <SourceChip message={message} />
          {message.sources.length > 0 && (
            <span className="flex min-w-0 flex-wrap items-center gap-x-1.5 gap-y-1 text-[11.5px] text-ink-3">
              <span>{t('assistant.sources')}:</span>
              {message.sources.map((source, i) => (
                <span key={i} className="inline-flex items-center">
                  {source.to ? (
                    <Link to={source.to} onClick={onNavigate} className="text-ink-2 underline decoration-line-strong underline-offset-2 hover:text-ink">{source.label}</Link>
                  ) : (
                    <span>{source.label}</span>
                  )}
                  {i < message.sources.length - 1 && <span className="ml-1.5" aria-hidden="true">·</span>}
                </span>
              ))}
            </span>
          )}
        </div>
      </div>
      {navigateActions.length > 0 && (
        <div className="flex flex-wrap gap-1.5">
          {navigateActions.map((a) => (
            <Button key={a.to} size="sm" variant="secondary" icon={ArrowUpRight} onClick={() => onNavigate(a.to)}>
              {a.page === 'machine' ? t('assistant.openMachine', { code: a.label }) : t('assistant.openPage', { page: t(`nav.${a.page}`, { defaultValue: a.page }) })}
            </Button>
          ))}
        </div>
      )}
      {asks.length > 0 && <Chips items={asks.map((a) => a.text)} onAsk={onAsk} disabled={busy} />}
    </div>
  )
}

function Chips({ items, onAsk, disabled }) {
  return (
    <div className="flex flex-wrap gap-1.5">
      {items.map((text) => (
        <button
          key={text}
          type="button"
          disabled={disabled}
          onClick={() => onAsk(text)}
          className="rounded-full border border-line-strong bg-surface px-3 py-1 text-left text-[12.5px] text-ink-2 transition-colors hover:border-brand hover:text-ink disabled:cursor-not-allowed disabled:opacity-55"
        >
          {text}
        </button>
      ))}
    </div>
  )
}

function UserMessage({ text, failed, onRetry }) {
  const { t } = useTranslation()
  return (
    <div className="flex flex-col items-end gap-1">
      <div className="max-w-[85%] whitespace-pre-wrap break-words rounded-lg rounded-br-sm bg-brand-subtle px-3.5 py-2.5 text-[13.5px] text-ink">{text}</div>
      {failed && (
        <p className="flex items-center gap-1.5 text-[12px] text-critical-text">
          <CircleAlert className="size-3.5" aria-hidden="true" />
          {t('assistant.error')}
          <button type="button" onClick={onRetry} className="font-medium underline underline-offset-2">{t('assistant.retry')}</button>
        </p>
      )}
    </div>
  )
}

function Thinking() {
  const { t } = useTranslation()
  return (
    <div className="flex items-center gap-2.5 rounded-lg border border-line bg-surface px-3.5 py-3 text-[13px] text-ink-2" role="status">
      <span className="flex gap-1" aria-hidden="true">
        {[0, 1, 2].map((i) => <span key={i} className="size-1.5 animate-pulse rounded-full bg-brand" style={{ animationDelay: `${i * 160}ms` }} />)}
      </span>
      {t('assistant.thinking')}
    </div>
  )
}

function Welcome({ onAsk, busy }) {
  const { t, i18n } = useTranslation()
  const context = usePageContext()
  const suggestions = useAssistantSuggestions(context, i18n.language)
  return (
    <div className="flex flex-col gap-4 pt-2">
      <div>
        <h3 className="text-[15px] font-semibold text-ink">{t('assistant.welcomeTitle')}</h3>
        <p className="mt-1 text-[13px] leading-relaxed text-ink-2">{t('assistant.welcomeBody')}</p>
      </div>
      {suggestions.isPending ? (
        <div className="flex flex-col gap-1.5">{[0, 1, 2].map((i) => <Skeleton key={i} className="h-7 w-3/4 rounded-full" />)}</div>
      ) : (
        <Chips items={suggestions.data?.data ?? []} onAsk={onAsk} disabled={busy} />
      )}
      <p className="flex items-start gap-1.5 text-[12px] text-ink-3">
        <ShieldCheck className="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />
        {t('assistant.scope')}
      </p>
    </div>
  )
}

function Conversations({ onPick }) {
  const { t } = useTranslation()
  const queryClient = useQueryClient()
  const { conversationId, setConversationId } = useAssistant()
  const list = useConversations(true)
  const remove = useMutation({
    mutationFn: (id) => api.delete(`/assistant/conversations/${id}`),
    onSuccess: (_, id) => {
      if (id === conversationId) setConversationId(null)
      queryClient.invalidateQueries({ queryKey: ['assistant', 'conversations'] })
    },
  })
  if (list.isPending) return <div className="flex flex-col gap-2">{[0, 1, 2].map((i) => <Skeleton key={i} className="h-12" />)}</div>
  const items = list.data?.data ?? []
  if (items.length === 0) return <p className="text-[13px] text-ink-3">{t('assistant.noHistory')}</p>
  return (
    <ul className="flex flex-col gap-1">
      {items.map((c) => (
        <li key={c.id} className={cn('group flex items-center gap-1 rounded-md hover:bg-surface-2', c.id === conversationId && 'bg-surface-2')}>
          <button type="button" onClick={() => onPick(c.id)} className="min-w-0 flex-1 px-3 py-2 text-left">
            <span className="block truncate text-[13px] text-ink">{c.title ?? t('assistant.newChat')}</span>
            <span className="block text-[11.5px] text-ink-3">{formatDate(c.updated_at, 'dateTime')}</span>
          </button>
          <IconButton label={t('assistant.deleteChat')} icon={Trash2} className="mr-1 size-8 opacity-60 group-hover:opacity-100" onClick={() => remove.mutate(c.id)} />
        </li>
      ))}
    </ul>
  )
}

function Composer({ onSend, busy }) {
  const { t } = useTranslation()
  const [text, setText] = useState('')
  const ref = useRef(null)
  useEffect(() => ref.current?.focus(), [])
  useLayoutEffect(() => {
    const el = ref.current
    if (!el) return
    el.style.height = 'auto'
    el.style.height = `${Math.min(el.scrollHeight, 140)}px`
  }, [text])
  const submit = () => {
    const value = text.trim()
    if (!value || busy) return
    onSend(value)
    setText('')
  }
  return (
    <div className="border-t border-line px-3 pb-3 pt-2.5">
      <div className="flex items-end gap-2 rounded-lg border border-line-strong bg-surface px-3 py-2 focus-within:border-brand">
        <textarea
          ref={ref}
          rows={1}
          value={text}
          maxLength={MAX_CHARS}
          onChange={(e) => setText(e.target.value)}
          onKeyDown={(e) => {
            if (e.key === 'Enter' && !e.shiftKey && !e.nativeEvent.isComposing) {
              e.preventDefault()
              submit()
            }
          }}
          placeholder={t('assistant.placeholder')}
          aria-label={t('assistant.placeholder')}
          className="max-h-[140px] min-h-[24px] flex-1 resize-none bg-transparent text-[13.5px] leading-6 text-ink outline-none placeholder:text-ink-3"
        />
        <button
          type="button"
          onClick={submit}
          disabled={busy || text.trim() === ''}
          aria-label={t('assistant.send')}
          title={t('assistant.send')}
          className="inline-flex size-8 shrink-0 items-center justify-center rounded-md bg-brand text-on-brand transition-colors hover:bg-brand-hover disabled:cursor-not-allowed disabled:opacity-40"
        >
          <ArrowUp className="size-4" aria-hidden="true" />
        </button>
      </div>
      <div className="mt-1.5 flex items-center justify-between gap-3 text-[11px] text-ink-3">
        <span>{t('assistant.footer')}</span>
        {text.length > MAX_CHARS * 0.8 && <span className="tabular">{t('assistant.chars', { count: text.length })}</span>}
      </div>
    </div>
  )
}

/** The 420 px "Ask EnergyFlow" panel (docs/06-ux-design.md §2), docked beside the page on wide screens. */
export default function AssistantPanel() {
  const { t, i18n } = useTranslation()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { setOpen, conversationId, setConversationId, pending, clearPending } = useAssistant()
  const context = usePageContext()
  const conversation = useConversation(conversationId)
  const [optimistic, setOptimistic] = useState(null)
  const [showHistory, setShowHistory] = useState(false)
  const [fresh, setFresh] = useState(null)
  const panel = useRef(null)
  const body = useRef(null)
  const stick = useRef(true)

  // A conversation that no longer exists (deleted, other session) starts fresh.
  useEffect(() => {
    if (conversation.error?.status === 404) setConversationId(null)
  }, [conversation.error, setConversationId])

  const { mutate: send, isPending: busy } = useMutation({
    mutationFn: async ({ text, extra }) => {
      let id = conversationId
      if (!id) {
        const created = await api.post('/assistant/conversations')
        id = created.data.id
        queryClient.setQueryData(['assistant', 'conversation', id], { data: created.data, meta: {} })
        setConversationId(id)
      }
      const { data } = await api.post(`/assistant/conversations/${id}/messages`, { content: text, language: i18n.language, context: { ...context, ...extra } })
      return { id, data }
    },
    onMutate: ({ text, extra }) => setOptimistic({ text, extra, failed: false }),
    onSuccess: ({ id, data }) => {
      queryClient.setQueryData(['assistant', 'conversation', id], (old) => {
        const previous = old?.data ?? { id, title: null, messages: [] }
        const known = new Set(previous.messages.map((m) => m.id))
        const added = [data.user, data.assistant].filter((m) => !known.has(m.id))
        return { ...(old ?? { meta: {} }), data: { ...previous, title: data.conversation.title, messages: [...previous.messages, ...added] } }
      })
      queryClient.invalidateQueries({ queryKey: ['assistant', 'conversations'] })
      setOptimistic(null)
      setFresh(data.assistant.id)
      const auto = data.assistant.actions.find((a) => a.type === 'navigate' && a.auto)
      if (auto) navigate(auto.to)
    },
    onError: (_, variables) => setOptimistic({ ...variables, failed: true }),
  })
  const ask = (text, extra = {}) => {
    setShowHistory(false)
    stick.current = true
    send({ text, extra })
  }

  // A question handed over from a page ("Ask EnergyFlow" on a waste event).
  useEffect(() => {
    if (!pending) return
    stick.current = true
    send({ text: pending.text, extra: pending.context })
    clearPending()
  }, [pending, send, clearPending])

  const messages = conversation.data?.data?.messages ?? []

  // Stick to the bottom: new answers and content that grows later (a Turn Off
  // timeline) stay in view while the reader is at the bottom; someone reading
  // further up isn't pulled down.
  useEffect(() => {
    const el = body.current
    if (!el || typeof ResizeObserver === 'undefined') return undefined
    const resize = new ResizeObserver(() => {
      if (stick.current) el.scrollTop = el.scrollHeight
    })
    const observeChildren = () => {
      resize.disconnect()
      for (const child of el.children) resize.observe(child)
    }
    observeChildren()
    const children = new MutationObserver(observeChildren)
    children.observe(el, { childList: true })
    return () => {
      resize.disconnect()
      children.disconnect()
    }
  }, [])
  const onScroll = () => {
    const el = body.current
    stick.current = el.scrollHeight - el.scrollTop - el.clientHeight < 80
  }

  // Escape closes the panel when focus is inside it (drawers handle their own Escape).
  useEffect(() => {
    const onKey = (event) => {
      if (event.key === 'Escape' && panel.current?.contains(document.activeElement)) setOpen(false)
    }
    document.addEventListener('keydown', onKey)
    return () => document.removeEventListener('keydown', onKey)
  }, [setOpen])

  // On narrow screens the panel covers the page: following a link closes it.
  const follow = (to) => {
    if (typeof to === 'string') navigate(to)
    if (window.matchMedia('(max-width: 1535px)').matches) setOpen(false)
  }
  const startNew = () => {
    setConversationId(null)
    setOptimistic(null)
    setShowHistory(false)
  }
  const empty = messages.length === 0 && !optimistic
  // A question in flight always shows the conversation it belongs to.
  const history = showHistory && !busy && !optimistic

  return (
    <aside
      ref={panel}
      aria-label={t('assistant.title')}
      className="animate-drawer fixed bottom-0 right-0 top-0 z-40 flex w-full flex-col border-l border-line bg-surface shadow-overlay sm:top-14 sm:w-[420px] 2xl:top-0 2xl:shadow-none print:hidden"
    >
      <header className="flex h-14 shrink-0 items-center gap-2.5 border-b border-line pl-4 pr-2">
        {history ? (
          <IconButton label={t('assistant.backToChat')} icon={ChevronLeft} className="-ml-2 size-8" onClick={() => setShowHistory(false)} />
        ) : (
          <span className="flex size-8 items-center justify-center rounded-md bg-brand-subtle text-brand" aria-hidden="true">
            <MessageSquareText className="size-[18px]" />
          </span>
        )}
        <div className="min-w-0 flex-1">
          <h2 className="truncate text-sm font-semibold text-ink">{history ? t('assistant.history') : t('assistant.title')}</h2>
          {!history && <p className="truncate text-[11.5px] text-ink-3">{t('assistant.subtitle')}</p>}
        </div>
        <IconButton label={t('assistant.history')} icon={History} className="size-8" onClick={() => setShowHistory(!history)} />
        <IconButton label={t('assistant.newChat')} icon={SquarePen} className="size-8" onClick={startNew} />
        <IconButton label={t('common.close')} icon={X} className="size-8" onClick={() => setOpen(false)} />
      </header>

      <div ref={body} onScroll={onScroll} className="min-h-0 flex-1 overflow-y-auto px-4 py-4">
        {history ? (
          <Conversations onPick={(id) => { setConversationId(id); setOptimistic(null); setShowHistory(false) }} />
        ) : conversationId && conversation.isPending ? (
          <div className="flex flex-col gap-3"><Skeleton className="ml-auto h-10 w-2/3" /><Skeleton className="h-28" /></div>
        ) : empty ? (
          <Welcome onAsk={ask} busy={busy} />
        ) : (
          <div className="flex flex-col gap-4">
            {messages.map((m) => (m.role === 'user'
              ? <UserMessage key={m.id} text={m.content} />
              : <AssistantMessage key={m.id} message={m} onAsk={ask} onNavigate={follow} busy={busy} fresh={m.id === fresh} />))}
            {optimistic && <UserMessage text={optimistic.text} failed={optimistic.failed} onRetry={() => send({ text: optimistic.text, extra: optimistic.extra })} />}
            {busy && <Thinking />}
          </div>
        )}
      </div>

      {!history && <Composer onSend={ask} busy={busy} />}
    </aside>
  )
}
