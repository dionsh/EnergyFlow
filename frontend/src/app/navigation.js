import {
  Activity,
  Cpu,
  Factory,
  FileText,
  LayoutDashboard,
  Leaf,
  Lightbulb,
  ScanLine,
  Settings,
  TrendingDown,
  TriangleAlert,
  Workflow,
} from 'lucide-react'

// The sidebar mirrors the product loop: Monitor → Optimize → Prove (docs/06 §2).
export const NAVIGATION = [
  { items: [{ to: '/', key: 'overview', icon: LayoutDashboard, end: true }] },
  {
    group: 'monitor',
    items: [
      { to: '/live', key: 'live', icon: Activity },
      { to: '/machines', key: 'machines', icon: Factory },
      { to: '/devices', key: 'devices', icon: Cpu },
      { to: '/scan', key: 'scan', icon: ScanLine },
    ],
  },
  {
    group: 'optimize',
    items: [
      { to: '/waste', key: 'waste', icon: TriangleAlert },
      { to: '/opportunities', key: 'opportunities', icon: Lightbulb },
      { to: '/automations', key: 'automations', icon: Workflow },
    ],
  },
  {
    group: 'prove',
    items: [
      { to: '/impact', key: 'impact', icon: TrendingDown },
      { to: '/carbon', key: 'carbon', icon: Leaf },
      { to: '/reports', key: 'reports', icon: FileText },
    ],
  },
]

export const FOOTER_NAVIGATION = [{ to: '/settings', key: 'settings', icon: Settings }]
