import { useCallback, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Bell, Check, ChevronDown, LogOut, Monitor, Moon, Sun } from 'lucide-react'
import { api } from '../../lib/api'
import { cn } from '../../lib/cn'
import { LANGUAGES } from '../../i18n/languages'
import { useAuth } from '../../providers/AuthProvider'
import { useTheme } from '../../providers/ThemeProvider'
import { useDismiss } from '../../hooks/useDismiss'
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

export function NotificationsButton() {
  const { t } = useTranslation()
  const [open, setOpen] = useState(false)
  const close = useCallback(() => setOpen(false), [])
  return (
    <div className="relative">
      <IconButton label={t('topbar.notifications')} icon={Bell} aria-expanded={open} onClick={() => setOpen((v) => !v)} />
      <Popover open={open} onClose={close} className="w-72 p-0">
        <p className="border-b border-line px-4 py-3 text-sm font-semibold text-ink">{t('topbar.notifications')}</p>
        <p className="px-4 py-6 text-center text-sm text-ink-3">{t('topbar.noNotifications')}</p>
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
