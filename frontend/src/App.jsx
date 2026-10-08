import { lazy } from 'react'
import { BrowserRouter, Route, Routes } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { AuthProvider } from './providers/AuthProvider'
import { ThemeProvider } from './providers/ThemeProvider'
import { ToastProvider } from './providers/ToastProvider'
import { AppShell } from './components/layout/AppShell'
import { GuestOnly, RequireAuth } from './components/layout/RouteGuards'
import { LoginPage } from './features/auth/LoginPage'
import { RegisterPage } from './features/auth/RegisterPage'
import { pageLoaders } from './app/pageLoaders'

const page = (load, name) => lazy(() => load().then((module) => ({ default: module[name] })))

// Pages load on demand (the shell shows a skeleton meanwhile, see AppShell).
const OverviewPage = page(pageLoaders.overview, 'OverviewPage')
const LivePage = page(pageLoaders.live, 'LivePage')
const MachinesPage = page(pageLoaders.machines, 'MachinesPage')
const MachineDetailPage = page(pageLoaders.machineDetail, 'MachineDetailPage')
const DevicesPage = page(pageLoaders.devices, 'DevicesPage')
const DeviceDetailPage = page(pageLoaders.deviceDetail, 'DeviceDetailPage')
const WastePage = page(pageLoaders.waste, 'WastePage')
const OpportunitiesPage = page(pageLoaders.opportunities, 'OpportunitiesPage')
const AutomationsPage = page(pageLoaders.automations, 'AutomationsPage')
const ImpactPage = page(pageLoaders.impact, 'ImpactPage')
const CarbonPage = page(pageLoaders.carbon, 'CarbonPage')
const ReportsPage = page(pageLoaders.reports, 'ReportsPage')
const ReportView = page(pageLoaders.reportView, 'ReportView')
const SettingsPage = page(pageLoaders.settings, 'SettingsPage')
const NotFoundPage = page(pageLoaders.notFound, 'NotFoundPage')

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      staleTime: 30_000,
      retry: (failureCount, error) => error?.status >= 500 && failureCount < 2,
      refetchOnWindowFocus: false,
      refetchIntervalInBackground: false,
    },
  },
})

export default function App() {
  return (
    <QueryClientProvider client={queryClient}>
      <ThemeProvider>
        <ToastProvider>
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
                    <Route path="live" element={<LivePage />} />
                    <Route path="machines" element={<MachinesPage />} />
                    <Route path="machines/:id" element={<MachineDetailPage />} />
                    <Route path="devices" element={<DevicesPage />} />
                    <Route path="devices/:id" element={<DeviceDetailPage />} />
                    <Route path="waste" element={<WastePage />} />
                    <Route path="opportunities" element={<OpportunitiesPage />} />
                    <Route path="automations" element={<AutomationsPage />} />
                    <Route path="impact" element={<ImpactPage />} />
                    <Route path="carbon" element={<CarbonPage />} />
                    <Route path="reports" element={<ReportsPage />} />
                    <Route path="reports/:id" element={<ReportView />} />
                    <Route path="settings" element={<SettingsPage />} />
                    <Route path="*" element={<NotFoundPage />} />
                  </Route>
                </Route>
              </Routes>
            </AuthProvider>
          </BrowserRouter>
        </ToastProvider>
      </ThemeProvider>
    </QueryClientProvider>
  )
}
