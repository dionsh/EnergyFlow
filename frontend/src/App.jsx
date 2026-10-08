import { BrowserRouter, Route, Routes } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { AuthProvider } from './providers/AuthProvider'
import { ThemeProvider } from './providers/ThemeProvider'
import { ToastProvider } from './providers/ToastProvider'
import { AppShell } from './components/layout/AppShell'
import { GuestOnly, RequireAuth } from './components/layout/RouteGuards'
import { LoginPage } from './features/auth/LoginPage'
import { RegisterPage } from './features/auth/RegisterPage'
import { OverviewPage } from './features/overview/OverviewPage'
import { LivePage } from './features/live/LivePage'
import { MachinesPage } from './features/machines/MachinesPage'
import { MachineDetailPage } from './features/machines/MachineDetailPage'
import { DevicesPage } from './features/devices/DevicesPage'
import { DeviceDetailPage } from './features/devices/DeviceDetailPage'
import { WastePage } from './features/waste/WastePage'
import { AutomationsPage } from './features/control/AutomationsPage'
import { OpportunitiesPage } from './features/opportunities/OpportunitiesPage'
import { ImpactPage } from './features/impact/ImpactPage'
import { CarbonPage } from './features/carbon/CarbonPage'
import { ReportsPage } from './features/reports/ReportsPage'
import { ReportView } from './features/reports/ReportView'
import { SettingsPage } from './features/settings/SettingsPage'
import { NotFoundPage } from './features/NotFoundPage'

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
