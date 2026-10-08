import { useCallback, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Bell, Check, ChevronDown, CircleAlert, CircleCheck, Info, LogOut, MessageSquareText, Monitor, Moon, Sun, TriangleAlert } from 'lucide-react'
import { api } from '../../lib/api'
import { cn } from '../../lib/cn'
import { formatDate } from '../../lib/format'
import { useNotifications } from '../../features/data'
import { notificationText } from '../../features/waste/alertText'
import { LANGUAGES } from '../../i18n/languages'
import { useAuth } from '../../providers/AuthProvider'
import { useTheme } from '../../providers/ThemeProvider'
import { useDismiss } from '../../hooks/useDismiss'
import { useAssistant } from '../../features/assistant/AssistantProvider'
import { IconButton } from '../ui/Button'

function Popover({ open, onClose, align = 'right', className, children }) {
  const ref = useRef(null)
  useDismiss(ref, onClose, open)
  if (!open) return null
  return (
    <div
      ref={ref}
      className={cn(
        'absolute top-full z-40 mt-2 min-w-48 rounded-lg border border-line bg-surface p-1 shadow-overlay',
        align === 'right' ? 'right-0' : 'left-0',
        className,
      )}
    >
      {children}
    </div>
  )
}

function MenuItem({ icon: Icon, selected, onClick, children }) {
  return (
    <button
      type="button"
      onClick={onClick}
      className="flex h-9 w-full items-center gap-2.5 rounded-sm px-2.5 text-left text-sm text-ink hover:bg-surface-2"
    >
      {Icon && <Icon className="size-4 text-ink-2" aria-hidden="true" />}
      <span className="flex-1">{children}</span>
      {selected && <Check className="size-4 text-brand" aria-hidden="true" />}
    </button>
  )
}

/** SQ | EN segmented control. Saves the preference to the account when signed in. */
export function LanguageSwitch() {
  const { t, i18n } = useTranslation()
  const { user, setSession } = useAuth()

  async function choose(code) {
    if (code === i18n.language) return
    await i18n.changeLanguage(code)
    if (user) {
      try {
        const { data } = await api.patch('/auth/me', { locale: code })
        setSession(data)
      } catch {
        // The UI language already changed; saving the preference can be retried later.
      }
    }
  }

  return (
    <div role="group" aria-label={t('topbar.language')} className="flex h-8 items-center rounded-sm border border-line-strong p-0.5">
      {LANGUAGES.map((language) => (
        <button
          key={language.code}
          type="button"
          lang={language.code}
          title={language.label}
          aria-pressed={i18n.language === language.code}
          onClick={() => choose(language.code)}
          className={cn(
            'h-full rounded-[3px] px-2 text-xs font-semibold transition-colors',
            i18n.language === language.code ? 'bg-surface-2 text-ink' : 'text-ink-3 hover:text-ink',
          )}
        >
          {language.short}
        </button>
      ))}
    </div>
  )
}

const THEME_ICONS = { light: Sun, dark: Moon, system: Monitor }

export function ThemeMenu() {
  const { t } = useTranslation()
  const { preference, resolved, themes, setPreference } = useTheme()
  const [open, setOpen] = useState(false)
  const close = useCallback(() => setOpen(false), [])
  return (
    <div className="relative">
      <IconButton
        label={t('topbar.theme')}
        icon={resolved === 'dark' ? Moon : Sun}
        aria-expanded={open}
        aria-haspopup="menu"
        onClick={() => setOpen((v) => !v)}
      />
      <Popover open={open} onClose={close}>
        {themes.map((theme) => (
          <MenuItem
            key={theme}
            icon={THEME_ICONS[theme]}
            selected={preference === theme}
            onClick={() => {
              setPreference(theme)
              close()
            }}
          >
            {t(`topbar.themes.${theme}`)}
          </MenuItem>
        ))}
      </Popover>
    </div>
  )
}

const CATEGORY_ICONS = {
  critical: { icon: CircleAlert, className: 'text-critical-text' },
  warning: { icon: TriangleAlert, className: 'text-warning-text' },
  insight: { icon: Info, className: 'text-info-text' },
  achievement: { icon: CircleCheck, className: 'text-good-text' },
}

const IS_MAC = typeof navigator !== 'undefined' && /Mac|iPhone|iPad/.test(navigator.platform)

/** "Ask EnergyFlow" (Ctrl/⌘ K) opens the assistant panel. */
export function AssistantButton() {
  const { t } = useTranslation()
  const { open, setOpen } = useAssistant()
  return (
    <button
      type="button"
      onClick={() => setOpen(!open)}
      aria-pressed={open}
      title={`${t('assistant.button')} (${IS_MAC ? '⌘K' : t('assistant.shortcut')})`}
      className={cn(
        'inline-flex h-9 items-center gap-2 rounded-sm border px-2.5 text-sm transition-colors',
        open ? 'border-brand bg-brand-subtle text-ink' : 'border-line-strong bg-surface text-ink-2 hover:bg-surface-2 hover:text-ink',
      )}
    >
      <MessageSquareText className="size-[18px] text-brand" aria-hidden="true" />
      <span className="hidden md:inline">{t('assistant.button')}</span>
      <kbd className="hidden rounded-sm border border-line px-1 font-mono text-[10.5px] leading-4 text-ink-3 lg:inline">{IS_MAC ? '⌘K' : t('assistant.shortcut')}</kbd>
    </button>
  )
}

export function NotificationsButton() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [open, setOpen] = useState(false)
  const close = useCallback(() => setOpen(false), [])
  const notifications = useNotifications()
  const refresh = () => queryClient.invalidateQueries({ queryKey: ['notifications'] })
  const read = useMutation({ mutationFn: (id) => api.post(`/notifications/${id}/read`), onSuccess: refresh })
  const readAll = useMutation({ mutationFn: () => api.post('/notifications/read-all'), onSuccess: refresh })
  const { items = [], unread = 0 } = notifications.data?.data ?? {}

  return (
    <div className="relative">
      <IconButton
        label={unread > 0 ? `${t('topbar.notifications')} (${unread})` : t('topbar.notifications')}
        icon={Bell}
        aria-expanded={open}
        onClick={() => setOpen((v) => !v)}
      />
      {unread > 0 && (
        <span className="pointer-events-none absolute right-1 top-1 flex h-4 min-w-4 items-center justify-center rounded-full bg-brand px-1 text-[10px] font-semibold tabular text-on-brand" aria-hidden="true">
          {unread > 9 ? '9+' : unread}
        </span>
      )}
      <Popover open={open} onClose={close} className="w-[min(22rem,calc(100vw-2rem))] p-0">
        <div className="flex items-center justify-between gap-3 border-b border-line px-4 py-3">
          <p className="text-sm font-semibold text-ink">{t('topbar.notifications')}</p>
          {unread > 0 && (
            <button type="button" onClick={() => readAll.mutate()} className="text-[12.5px] font-medium text-brand hover:underline">
              {t('notifications.markAllRead')}
            </button>
          )}
        </div>
        {items.length === 0 ? (
          <p className="px-4 py-6 text-center text-sm text-ink-3">{t('topbar.noNotifications')}</p>
        ) : (
          <ul className="max-h-96 divide-y divide-line overflow-y-auto">
            {items.map((item) => {
              const style = CATEGORY_ICONS[item.category] ?? CATEGORY_ICONS.insight
              const Icon = style.icon
              return (
                <li key={item.id}>
                  <button
                    type="button"
                    onClick={() => {
                      if (!item.read) read.mutate(item.id)
                      close()
                      if (item.link) navigate(item.link)
                    }}
                    className="flex w-full items-start gap-2.5 px-4 py-3 text-left hover:bg-surface-2"
                  >
                    <Icon className={cn('mt-0.5 size-4 shrink-0', style.className)} aria-hidden="true" />
                    <span className="min-w-0 flex-1">
                      <span className={cn('block text-[13px]', item.read ? 'text-ink-2' : 'font-medium text-ink')}>{notificationText(t, item)}</span>
                      <span className="mt-0.5 block text-xs tabular text-ink-3">{formatDate(item.created_at, 'dateTime')}</span>
                    </span>
                    {!item.read && <span className="mt-1.5 size-2 shrink-0 rounded-full bg-brand" aria-label="unread" />}
                  </button>
                </li>
              )
            })}
          </ul>
        )}
      </Popover>
    </div>
  )
}

export function UserMenu() {
  const { t } = useTranslation()
  const { user, logout } = useAuth()
  const [open, setOpen] = useState(false)
  const close = useCallback(() => setOpen(false), [])
  if (!user) return null

  const initials = user.full_name
    .split(/\s+/)
    .map((part) => part[0])
    .slice(0, 2)
    .join('')
    .toUpperCase()

  return (
    <div className="relative">
      <button
        type="button"
        onClick={() => setOpen((v) => !v)}
        aria-expanded={open}
        aria-haspopup="menu"
        aria-label={t('topbar.account')}
        className="flex h-9 items-center gap-2 rounded-sm px-1.5 hover:bg-surface-2"
      >
        <span className="flex size-7 items-center justify-center rounded-full bg-brand-subtle text-xs font-semibold text-brand">
          {initials}
        </span>
        <ChevronDown className="hidden size-4 text-ink-3 sm:block" aria-hidden="true" />
      </button>
      <Popover open={open} onClose={close} className="w-64">
        <div className="border-b border-line px-2.5 pb-2.5 pt-1.5">
          <p className="truncate text-sm font-medium text-ink">{user.full_name}</p>
          <p className="truncate text-[13px] text-ink-3">{user.email}</p>
          <p className="mt-1 text-xs text-ink-2">{t(`roles.${user.role}`)}</p>
        </div>
        <div className="pt-1">
          <MenuItem
            icon={LogOut}
            onClick={() => {
              close()
              logout()
            }}
          >
            {t('topbar.signOut')}
          </MenuItem>
        </div>
      </Popover>
    </div>
  )
}
