import { BrowserRouter, Route, Routes } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { Activity, Cpu, Factory, FileText, Leaf, Lightbulb, TrendingDown, TriangleAlert, Workflow } from 'lucide-react'
import { AuthProvider } from './providers/AuthProvider'
import { ThemeProvider } from './providers/ThemeProvider'
import { AppShell } from './components/layout/AppShell'
import { GuestOnly, RequireAuth } from './components/layout/RouteGuards'
import { LoginPage } from './features/auth/LoginPage'
import { RegisterPage } from './features/auth/RegisterPage'
import { OverviewPage } from './features/overview/OverviewPage'
import { ModulePage } from './features/modules/ModulePage'
import { SettingsPage } from './features/settings/SettingsPage'
import { NotFoundPage } from './features/NotFoundPage'

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      staleTime: 30_000,
      retry: (failureCount, error) => error?.status >= 500 && failureCount < 2,
      refetchOnWindowFocus: false,
    },
  },
})

// Modules whose data pipeline lands in the next milestones. Each shows an honest empty state.
const MODULES = [
  ['live', Activity],
  ['machines', Factory],
  ['devices', Cpu],
  ['waste', TriangleAlert],
  ['opportunities', Lightbulb],
  ['automations', Workflow],
  ['impact', TrendingDown],
  ['carbon', Leaf],
  ['reports', FileText],
]

export default function App() {
  return (
    <QueryClientProvider client={queryClient}>
      <ThemeProvider>
        <BrowserRouter>
          <AuthProvider>
            <Routes>
              <Route element={<GuestOnly />}>
                <Route path="/login" element={<LoginPage />} />
                <Route path="/register" element={<RegisterPage />} />
              </Route>
              <Route element={<RequireAuth />}>
                <Route element={<AppShell />}>
                  <Route index element={<OverviewPage />} />
                  {MODULES.map(([module, icon]) => (
                    <Route key={module} path={module} element={<ModulePage module={module} icon={icon} />} />
                  ))}
                  <Route path="settings" element={<SettingsPage />} />
                  <Route path="*" element={<NotFoundPage />} />
                </Route>
              </Route>
            </Routes>
          </AuthProvider>
        </BrowserRouter>
      </ThemeProvider>
    </QueryClientProvider>
  )
}
