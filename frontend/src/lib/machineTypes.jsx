import { createElement } from 'react'
import { Cog, Droplets, Factory, Flame, Gauge, Lightbulb, Monitor, Plug, Refrigerator, Snowflake, Thermometer, Wind } from 'lucide-react'

// docs/06 §4.8 — one icon per machine type.
const ICONS = {
  compressor: Wind,
  injection_moulding: Factory,
  cnc: Cog,
  hvac: Thermometer,
  chiller: Snowflake,
  refrigeration: Refrigerator,
  pump: Droplets,
  lighting: Lightbulb,
  oven: Flame,
  office: Monitor,
  incomer: Gauge,
}

export function MachineIcon({ type, ...props }) {
  return createElement(ICONS[type] ?? Plug, { 'aria-hidden': true, ...props })
}
