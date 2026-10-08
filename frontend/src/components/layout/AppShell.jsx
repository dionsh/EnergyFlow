import { useState } from 'react'
import { Outlet } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { Menu, X } from 'lucide-react'
import { useAuth } from '../../providers/AuthProvider'
import { IconButton } from '../ui/Button'
import { Sidebar } from './Sidebar'
import { LanguageSwitch, NotificationsButton, ThemeMenu, UserMenu } from './TopBarControls'
import { DemoDirector } from '../../features/demo/DemoDirector'

export function AppShell() {
  const { t } = useTranslation()
  const { company } = useAuth()
  const [mobileOpen, setMobileOpen] = useState(false)

  return (
    <div className="min-h-svh bg-bg">
      {/* Desktop sidebar */}
      <aside className="fixed inset-y-0 left-0 z-30 hidden w-60 border-r border-line bg-surface lg:block print:hidden">
        <Sidebar />
      </aside>

      {/* Mobile / tablet drawer */}
      {mobileOpen && (
        <div className="fixed inset-0 z-40 lg:hidden">
          <button type="button" aria-label={t('topbar.closeMenu')} className="absolute inset-0 bg-black/30" onClick={() => setMobileOpen(false)} />
          <aside className="absolute inset-y-0 left-0 w-64 border-r border-line bg-surface shadow-overlay">
            <Sidebar onNavigate={() => setMobileOpen(false)} />
            <IconButton label={t('topbar.closeMenu')} icon={X} className="absolute right-2 top-2.5" onClick={() => setMobileOpen(false)} />
          </aside>
        </div>
      )}

      <div className="lg:pl-60 print:pl-0">
        <header className="sticky top-0 z-20 flex h-14 items-center gap-3 border-b border-line bg-surface/95 px-4 backdrop-blur-sm sm:px-6 print:hidden">
          <IconButton label={t('topbar.openMenu')} icon={Menu} className="-ml-1.5 lg:hidden" onClick={() => setMobileOpen(true)} />
          <div className="min-w-0 flex-1">
            <p className="truncate text-sm font-medium text-ink">{company?.name}</p>
            {company?.city && <p className="truncate text-xs text-ink-3">{company.city}</p>}
          </div>
          <div className="flex items-center gap-1 sm:gap-2">
            <LanguageSwitch />
            <ThemeMenu />
            <NotificationsButton />
            <UserMenu />
          </div>
        </header>
        <main className="mx-auto w-full max-w-[1440px] px-4 py-6 sm:px-6 print:max-w-none print:p-0">
          <Outlet />
        </main>
      </div>
      <div className="print:hidden">
        <DemoDirector />
      </div>
    </div>
  )
}
