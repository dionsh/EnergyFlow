import { formatDate, formatDuration, formatEur, formatKw, formatKwh, formatNumber } from '../../lib/format'

// The API stores alerts and notifications as i18n key + params (never sentences),
// so the same row reads correctly in Albanian and English.

const pct = (value) => `${formatNumber(value, 1)}%`

/** Alerts that are not waste episodes but have their own "Why am I seeing this?" dialog. */
export const DETAIL_TYPES = new Set(['SPIKE', 'LOW_PF', 'PEAK_COINCIDENCE'])

/** "2026-09" → "September 2026" in the UI language. */
export function monthLabel(t, month) {
  if (!month) return ''
  const [year, m] = month.split('-').map(Number)
  return `${t('calendar.months', { returnObjects: true })[m - 1]} ${year}`
}

function values(t, params = {}) {
  return {
    ...params,
    machine: params.machine,
    serial: params.serial,
    duration: formatDuration(params.duration_s),
    kwh: params.kwh === undefined ? '' : formatKwh(params.kwh),
    eur: params.eur === undefined || params.eur === null ? '' : formatEur(params.eur),
    pct: params.deviation_pct === undefined ? '' : pct(params.deviation_pct),
    date: params.since ? formatDate(params.since, 'dateTime') : '',
    reason: params.reason ? t(`commandFailure.${params.reason}`, { defaultValue: params.reason }) : '',
    seconds: params.seconds ?? '',
    peak: params.peak_kw === undefined ? '' : formatKw(params.peak_kw),
    normal: params.normal_kw === undefined ? '' : formatKw(params.normal_kw),
    ratedPct: params.rated_pct === undefined || params.rated_pct === null ? '' : `${formatNumber(params.rated_pct, 0)}%`,
    month: monthLabel(t, params.month),
    cos: params.cos_phi === undefined ? '' : formatNumber(params.cos_phi, 2, 2),
    threshold: params.threshold === undefined ? '' : formatNumber(params.threshold, 2, 2),
    excess: params.excess_kvarh === undefined ? '' : `${formatNumber(params.excess_kvarh, 0)} kVArh`,
    projected: params.projected_eur === undefined || params.projected_eur === null ? '' : formatEur(params.projected_eur),
    avoidable: params.avoidable_kw === undefined ? '' : formatKw(params.avoidable_kw),
    peakAt: params.peak_at ? formatDate(params.peak_at, 'dateTime') : '',
  }
}

/** { title, detail } for an alert row. */
export function alertText(t, alert) {
  const key = alert.title_key
  const v = values(t, alert.params)
  let detail = t(`alertText.${key}_detail`, { ...v, defaultValue: '' })
  if (key === 'after_hours' && alert.params?.leak) detail = `${detail} · ${t('alertText.leak')}`
  if (key === 'spike' && alert.params?.overload) detail = `${detail} · ${t('alertText.overload', v)}`
  if (key === 'low_pf' && !alert.params?.billed) detail = t('alertText.low_pf_unbilled', v)
  if (key === 'low_pf' && alert.params?.billed && v.projected) detail = `${detail} · ${t('alertText.atThisPace', v)}`
  return { title: t(`alertText.${key}`, { ...v, defaultValue: key }), detail }
}

/** One line for the notification bell. title_key looks like "alert.after_hours.escalated". */
export function notificationText(t, notification) {
  const key = notification.title_key.replace(/\./g, '_')
  return t(`notifications.${key}`, { ...values(t, notification.params), defaultValue: notification.title_key })
}
