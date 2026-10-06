import { NavLink } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { FOOTER_NAVIGATION, NAVIGATION } from '../../app/navigation'
import { cn } from '../../lib/cn'
import { Logo } from '../ui/Logo'

function NavItem({ item, onNavigate }) {
  const { t } = useTranslation()
  const Icon = item.icon
  return (
    <NavLink
      to={item.to}
      end={item.end}
      onClick={onNavigate}
      className={({ isActive }) =>
        cn(
          'relative flex h-9 items-center gap-3 rounded-sm px-3 text-sm transition-colors',
          isActive
            ? 'bg-surface-2 font-medium text-ink before:absolute before:inset-y-1.5 before:left-0 before:w-0.5 before:rounded-full before:bg-brand'
            : 'text-ink-2 hover:bg-surface-2 hover:text-ink',
        )
      }
    >
      <Icon className="size-[18px] shrink-0" aria-hidden="true" />
      <span className="truncate">{t(`nav.${item.key}`)}</span>
    </NavLink>
  )
}

export function Sidebar({ onNavigate }) {
  const { t } = useTranslation()
  return (
    <div className="flex h-full flex-col">
      <div className="flex h-14 shrink-0 items-center border-b border-line px-4">
        <Logo />
      </div>
      <nav className="flex-1 overflow-y-auto px-3 py-4" aria-label="Main">
        {NAVIGATION.map((section, index) => (
          <div key={section.group ?? index} className={cn(index > 0 && 'mt-5')}>
            {section.group && (
              <p className="mb-1.5 px-3 text-[11px] font-semibold uppercase tracking-[0.04em] text-ink-3">
                {t(`nav.groups.${section.group}`)}
              </p>
            )}
            <ul className="flex flex-col gap-0.5">
              {section.items.map((item) => (
                <li key={item.to}>
                  <NavItem item={item} onNavigate={onNavigate} />
                </li>
              ))}
            </ul>
          </div>
        ))}
      </nav>
      <div className="border-t border-line px-3 py-3">
        {FOOTER_NAVIGATION.map((item) => (
          <NavItem key={item.to} item={item} onNavigate={onNavigate} />
        ))}
      </div>
    </div>
  )
}
