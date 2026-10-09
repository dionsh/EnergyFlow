import { formatCo2, formatDate, formatEur, formatKw, formatKwh, formatNumber, formatPercent } from '../../lib/format'

const signed = (ratio) => `${ratio >= 0 ? '+' : '−'}${formatPercent(Math.abs(ratio))}`
const day = (date) => (date ? formatDate(`${date}T12:00:00Z`) : '—')
// Bills are checked to the cent, so amounts always show their cents.
const eur = (v) => formatEur(v, { compact: false })

/** How each check's parameters are written (everything else is shown as is). */
const FORMAT = {
  kwh_sum: { high: formatKwh, low: formatKwh, total: formatKwh },
  kwh_sum_wrong: { sum: formatKwh, total: formatKwh },
  meter_backwards: { start: (v) => formatNumber(v, 1), end: (v) => formatNumber(v, 1) },
  meter_diff_ok: { diff: formatKwh },
  meter_diff_wrong: { diff: formatKwh, kwh: formatKwh },
  money_sum_with_debt: { debt: eur },
  money_sum_wrong: { net: eur, vat: eur, total: eur },
  vat_rate: { rate: formatPercent },
  vat_rate_wrong: { rate: formatPercent, expected: (v) => formatPercent(v, 0) },
  metered_match: { billed: formatKwh, metered: formatKwh, diff: signed },
  metered_diff: { billed: formatKwh, metered: formatKwh, diff: signed },
  metered_diff_large: { billed: formatKwh, metered: formatKwh, diff: signed },
  tariff_match: { billed: eur, expected: eur, diff: signed },
  tariff_diff: { billed: eur, expected: eur, diff: signed },
  tariff_diff_large: { billed: eur, expected: eur, diff: signed },
  date_in_future: { date: day },
  period_in_future: { date: day },
  issued_before_period_end: { issue: day, end: day },
  reading_backwards: { previous: (v) => formatNumber(v, 1), reading: (v) => formatNumber(v, 1) },
  meter_matches: { meter: formatKwh, metered: formatKwh, diff: signed },
  meter_differs: { meter: formatKwh, metered: formatKwh, diff: signed },
  meter_differs_large: { meter: formatKwh, metered: formatKwh, diff: signed },
  rated_power: { kw: formatKw },
  nameplate_consistent: { input_kw: formatKw, efficiency: (v) => formatPercent(v, 0) },
  nameplate_inconsistent: { input_kw: formatKw, kw: formatKw },
  running_above_rating: { p95_kw: formatKw, load: (v) => formatPercent(v, 0) },
  oversized: { p95_kw: formatKw, load: (v) => formatPercent(v, 0) },
  load_ok: { p95_kw: formatKw, load: (v) => formatPercent(v, 0) },
  rated_power_changes: { old: formatKw, new: formatKw },
  receipt_sum_wrong: { litres: (v) => formatNumber(v, 2), price: eur, total: eur },
  scope1_factor: { value: (v) => formatNumber(v, 3), co2_kg: formatCo2 },
  label_cost: { eur: eur, co2_kg: formatCo2 },
}

export function checkText(t, check) {
  const format = FORMAT[check.key] ?? {}
  const params = Object.fromEntries(Object.entries(check.params ?? {}).map(([k, v]) => [k, format[k] && v !== null && v !== undefined ? format[k](v) : v]))
  if (check.key === 'fields_missing') params.fields = (check.params.fields ?? []).map((f) => t(`scan.field.${f}`)).join(', ')
  if (check.key === 'label_cost') params.unit = t(`scan.labelUnit.${unitKey(check.params.unit)}`)
  if (check.key === 'tariff_match' || check.key === 'tariff_diff' || check.key === 'tariff_diff_large') {
    params.note = check.params.split === 'all_high' ? t('scan.check.tariffAllHigh') : ''
  }
  return t(`scan.check.${check.key}`, { ...params, defaultValue: check.key })
}

/** "kWh/annum" → year · "kWh/1000h" → 1000 hours · "kWh/100 cycles" → 100 cycles. */
export function unitKey(unit) {
  const u = String(unit ?? '').toLowerCase()
  if (u.includes('1000')) return 'hours1000'
  if (u.includes('cycle')) return 'cycles100'
  return 'year'
}
