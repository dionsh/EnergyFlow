import { lazy, Suspense, useEffect, useState } from 'react'
import { Outlet } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { Menu, X } from 'lucide-react'
import { cn } from '../../lib/cn'
import { useAuth } from '../../providers/AuthProvider'
import { IconButton } from '../ui/Button'
import { PageSkeleton } from '../ui/States'
import { prefetchPages } from '../../app/pageLoaders'
import { Sidebar } from './Sidebar'
import { AssistantButton, LanguageSwitch, NotificationsButton, ThemeMenu, UserMenu } from './TopBarControls'
import { DemoDirector } from '../../features/demo/DemoDirector'
import { AssistantProvider, useAssistant } from '../../features/assistant/AssistantProvider'

// The assistant's code loads the first time it is opened.
const AssistantPanel = lazy(() => import('../../features/assistant/AssistantPanel'))

function Shell() {
  const { t } = useTranslation()
  const { company } = useAuth()
  const assistant = useAssistant()
  const [mobileOpen, setMobileOpen] = useState(false)

  // Signed in: warm the most visited pages while the browser is idle.
  useEffect(() => prefetchPages(), [])

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

      {/* From 1536 px the assistant docks beside the page; narrower, it overlays the right side (docking would squeeze page layouts built for the full width). */}
      <div className={cn('lg:pl-60 print:pl-0', assistant.open && '2xl:pr-[420px] print:pr-0')}>
        <header className="sticky top-0 z-20 flex h-14 items-center gap-3 border-b border-line bg-surface/95 px-4 backdrop-blur-sm sm:px-6 print:hidden">
          <IconButton label={t('topbar.openMenu')} icon={Menu} className="-ml-1.5 lg:hidden" onClick={() => setMobileOpen(true)} />
          <div className="min-w-0 flex-1">
            <p className="truncate text-sm font-medium text-ink">{company?.name}</p>
            {company?.city && <p className="truncate text-xs text-ink-3">{company.city}</p>}
          </div>
          <div className="flex items-center gap-1 sm:gap-2">
            <AssistantButton />
            <LanguageSwitch />
            <ThemeMenu />
            <NotificationsButton />
            <UserMenu />
          </div>
        </header>
        <main className="mx-auto w-full max-w-[1440px] px-4 py-6 sm:px-6 print:max-w-none print:p-0">
          <Suspense fallback={<PageSkeleton />}>
            <Outlet />
          </Suspense>
        </main>
      </div>
      {assistant.open && (
        <Suspense fallback={<div className="fixed bottom-0 right-0 top-0 z-40 w-full border-l border-line bg-surface sm:top-14 sm:w-[420px] 2xl:top-0" aria-hidden="true" />}>
          <AssistantPanel />
        </Suspense>
      )}
      <div className="print:hidden">
        <DemoDirector />
      </div>
    </div>
  )
}

export function AppShell() {
  return (
    <AssistantProvider>
      <Shell />
    </AssistantProvider>
  )
}
