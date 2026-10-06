// Locale-aware formatting for every number EnergyFlow shows.
// Rules from docs/06-ux-design.md §4.7: units always shown, precision depends on magnitude.
import i18n from '../i18n'
import { intlLocale } from '../i18n/languages'

const locale = () => intlLocale(i18n.language)

function number(value, maximumFractionDigits = 0, minimumFractionDigits = 0) {
  if (value === null || value === undefined || Number.isNaN(Number(value))) return '—'
  return new Intl.NumberFormat(locale(), { maximumFractionDigits, minimumFractionDigits }).format(Number(value))
}

export const formatNumber = (value, digits = 0) => number(value, digits)

/** kW: 1 decimal, 2 below 1 kW. */
export const formatKw = (value) => `${number(value, Math.abs(value) < 1 ? 2 : 1, Math.abs(value) < 1 ? 2 : 1)} kW`

/** kWh: no decimals from 100 up, 1 decimal below. */
export const formatKwh = (value) => `${number(value, Math.abs(value) >= 100 ? 0 : 1)} kWh`

/** €: 2 decimals below 1,000; whole euros above (KPIs). */
export function formatEur(value, { compact = true } = {}) {
  if (value === null || value === undefined) return '—'
  const digits = compact && Math.abs(value) >= 1000 ? 0 : 2
  return new Intl.NumberFormat(locale(), {
    style: 'currency',
    currency: 'EUR',
    maximumFractionDigits: digits,
    minimumFractionDigits: digits,
  }).format(value)
}

/** CO₂e: kg below 1,000, tonnes (1 decimal) above. */
export function formatCo2(kg) {
  if (kg === null || kg === undefined) return '—'
  return Math.abs(kg) >= 1000 ? `${number(kg / 1000, 1, 1)} t CO₂e` : `${number(kg, kg < 10 ? 1 : 0)} kg CO₂e`
}

export const formatPercent = (ratio, digits = 1) =>
  new Intl.NumberFormat(locale(), { style: 'percent', maximumFractionDigits: digits }).format(ratio)

export function formatDate(iso, options = { dateStyle: 'medium' }) {
  if (!iso) return '—'
  return new Intl.DateTimeFormat(locale(), { timeZone: 'Europe/Belgrade', ...options }).format(new Date(iso))
}

export function formatDateTime(iso) {
  return formatDate(iso, { dateStyle: 'medium', timeStyle: 'short', hourCycle: 'h23' })
}

/** "3 s ago", "5 min ago" — used for live "Updated …" labels. */
export function formatRelative(iso, now = Date.now()) {
  if (!iso) return '—'
  const seconds = Math.round((new Date(iso).getTime() - now) / 1000)
  const rtf = new Intl.RelativeTimeFormat(locale(), { numeric: 'auto', style: 'short' })
  const abs = Math.abs(seconds)
  if (abs < 60) return rtf.format(seconds, 'second')
  if (abs < 3600) return rtf.format(Math.round(seconds / 60), 'minute')
  if (abs < 86400) return rtf.format(Math.round(seconds / 3600), 'hour')
  return rtf.format(Math.round(seconds / 86400), 'day')
}
