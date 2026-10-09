// Every page is its own chunk (docs: frontend bundle). The sign-in screen and the
// app shell load first; a page's code — and heavy libraries such as the charts —
// only when it is opened, or earlier while the browser is idle (prefetchPages).
export const pageLoaders = {
  overview: () => import('../features/overview/OverviewPage'),
  live: () => import('../features/live/LivePage'),
  machines: () => import('../features/machines/MachinesPage'),
  machineDetail: () => import('../features/machines/MachineDetailPage'),
  devices: () => import('../features/devices/DevicesPage'),
  scan: () => import('../features/scan/ScanPage'),
  deviceDetail: () => import('../features/devices/DeviceDetailPage'),
  waste: () => import('../features/waste/WastePage'),
  opportunities: () => import('../features/opportunities/OpportunitiesPage'),
  automations: () => import('../features/control/AutomationsPage'),
  impact: () => import('../features/impact/ImpactPage'),
  carbon: () => import('../features/carbon/CarbonPage'),
  reports: () => import('../features/reports/ReportsPage'),
  reportView: () => import('../features/reports/ReportView'),
  settings: () => import('../features/settings/SettingsPage'),
  notFound: () => import('../features/NotFoundPage'),
}

/** The pages people open most, warmed in the background once the app is idle. */
const WARM = ['overview', 'live', 'machines', 'machineDetail', 'waste', 'opportunities']

export function prefetchPages() {
  const run = () => WARM.forEach((key) => pageLoaders[key]().catch(() => {}))
  if (typeof window.requestIdleCallback === 'function') window.requestIdleCallback(run, { timeout: 4000 })
  else window.setTimeout(run, 1500)
}
