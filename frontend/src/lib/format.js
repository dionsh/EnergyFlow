// Locale-aware formatting for every number and date EnergyFlow shows.
// Rules from docs/06-ux-design.md §4.7: units always shown, precision depends on magnitude.
// Separators come from i18n/languages.js and month/weekday names from the locale files,
// so Albanian renders correctly even in browsers with incomplete Intl data.
import i18n from '../i18n'
import { languageFor } from '../i18n/languages'

const TIMEZONE = 'Europe/Belgrade' // Kosovo local time

const spec = () => languageFor(i18n.language).number

function number(value, maximumFractionDigits = 0, minimumFractionDigits = 0) {
  if (value === null || value === undefined || Number.isNaN(Number(value))) return '—'
  const { group, decimal } = spec()
  const [whole, fraction] = Number(value).toLocaleString('en-US', { maximumFractionDigits, minimumFractionDigits }).split('.')
  const grouped = whole.replace(/,/g, group)
  return fraction === undefined ? grouped : `${grouped}${decimal}${fraction}`
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
  const amount = number(Math.abs(value), digits, digits)
  const sign = value < 0 ? '−' : ''
  return spec().currency === 'prefix' ? `${sign}€${amount}` : `${sign}${amount} €`
}

/** CO₂e: kg below 1,000, tonnes (1 decimal) above. */
export function formatCo2(kg) {
  if (kg === null || kg === undefined) return '—'
  return Math.abs(kg) >= 1000 ? `${number(kg / 1000, 1, 1)} t CO₂e` : `${number(kg, kg < 10 ? 1 : 0)} kg CO₂e`
}

export const formatPercent = (ratio, digits = 1) => (ratio === null || ratio === undefined ? '—' : `${number(ratio * 100, digits)}${spec().percentSpace ? ' ' : ''}%`)

/** Calendar parts of a moment in Kosovo local time. */
function parts(iso) {
  const values = {}
  for (const part of new Intl.DateTimeFormat('en-US', {
    timeZone: TIMEZONE,
    year: 'numeric',
    month: 'numeric',
    day: 'numeric',
    weekday: 'short',
    hour: '2-digit',
    minute: '2-digit',
    hourCycle: 'h23',
  }).formatToParts(new Date(iso))) {
    values[part.type] = part.value
  }
  const weekday = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'].indexOf(values.weekday)
  return { year: values.year, month: Number(values.month), day: Number(values.day), weekday, time: `${values.hour}:${values.minute}` }
}

const calendar = (key) => i18n.t(`calendar.${key}`, { returnObjects: true })
const pad = (n) => String(n).padStart(2, '0')

/**
 * Dates in local time. Variants:
 *   'medium'       23 Jul 2026 · 23 korrik 2026
 *   'dayMonth'     23.07
 *   'weekdayDay'   Thu 23 · enj 23
 *   'dateTime'     23 Jul 2026, 21:40
 *   'weekdayTime'  Thursday 23 Jul, 21:40
 */
export function formatDate(iso, variant = 'medium') {
  if (!iso) return '—'
  const p = parts(iso)
  const month = calendar(i18n.language === 'en' ? 'monthsShort' : 'months')[p.month - 1]
  switch (variant) {
    case 'dayMonth':
      return `${pad(p.day)}.${pad(p.month)}`
    case 'weekdayDay':
      return `${calendar('weekdaysShort')[p.weekday]} ${pad(p.day)}`
    case 'dateTime':
      return `${p.day} ${month} ${p.year}, ${p.time}`
    case 'weekdayTime':
      return `${calendar('weekdays')[p.weekday]} ${p.day} ${month}, ${p.time}`
    default:
      return `${p.day} ${month} ${p.year}`
  }
}

/** Local clock time (24 h) in Kosovo time. */
export function formatTime(iso) {
  return iso ? parts(iso).time : '—'
}

/** "2 h 37 min", "45 min", "3 d 4 h" from seconds. */
export function formatDuration(seconds) {
  if (seconds === null || seconds === undefined) return '—'
  const minutes = Math.max(0, Math.round(seconds / 60))
  const days = Math.floor(minutes / 1440)
  const hours = Math.floor((minutes % 1440) / 60)
  const mins = minutes % 60
  const t = i18n.t.bind(i18n)
  if (days > 0) return `${days} ${t('units.d')} ${hours} ${t('units.h')}`
  if (hours > 0) return `${hours} ${t('units.h')} ${mins} ${t('units.min')}`
  return `${mins} ${t('units.min')}`
}

/** "12 s ago" / "12 sekonda më parë" from an age in seconds. */
export function formatAgo(seconds) {
  if (seconds === null || seconds === undefined) return '—'
  const t = i18n.t.bind(i18n)
  const s = Math.max(0, Math.round(seconds))
  if (s < 5) return t('time.justNow')
  if (s < 60) return t('time.secondsAgo', { count: s })
  if (s < 3600) return t('time.minutesAgo', { count: Math.round(s / 60) })
  if (s < 86400) return t('time.hoursAgo', { count: Math.round(s / 3600) })
  return t('time.daysAgo', { count: Math.round(s / 86400) })
}
