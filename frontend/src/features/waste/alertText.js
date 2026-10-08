import { formatDate, formatDuration, formatEur, formatKwh, formatNumber } from '../../lib/format'

// The API stores alerts and notifications as i18n key + params (never sentences),
// so the same row reads correctly in Albanian and English.

const pct = (value) => `${formatNumber(value, 1)}%`

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
  }
}

/** { title, detail } for an alert row. */
export function alertText(t, alert) {
  const key = alert.title_key
  const v = values(t, alert.params)
  let detail = t(`alertText.${key}_detail`, { ...v, defaultValue: '' })
  if (key === 'after_hours' && alert.params?.leak) detail = `${detail} · ${t('alertText.leak')}`
  return { title: t(`alertText.${key}`, { ...v, defaultValue: key }), detail }
}

/** One line for the notification bell. title_key looks like "alert.after_hours.escalated". */
export function notificationText(t, notification) {
  const key = notification.title_key.replace(/\./g, '_')
  return t(`notifications.${key}`, { ...values(t, notification.params), defaultValue: notification.title_key })
}
