import { BadgeCheck, Fuel, Gauge, ReceiptText, Tag } from 'lucide-react'

/**
 * What can be scanned, and the fields of each (the same keys the API reads and
 * checks: backend/src/Services/Scan/ScanReader.php). `unit` is shown as a suffix;
 * `wide` fields take a full row.
 */
export const SCAN_KINDS = [
  {
    kind: 'bill',
    icon: ReceiptText,
    save: 'saveBill',
    groups: [
      ['bill', [
        { key: 'supplier', type: 'text', wide: true },
        { key: 'customer_number', type: 'text' },
        { key: 'issue_date', type: 'date' },
        { key: 'period_start', type: 'date' },
        { key: 'period_end', type: 'date' },
        { key: 'due_date', type: 'date' },
      ]],
      ['consumption', [
        { key: 'kwh_high', type: 'number', unit: 'kWh' },
        { key: 'kwh_low', type: 'number', unit: 'kWh' },
        { key: 'kwh_total', type: 'number', unit: 'kWh' },
        { key: 'meter_start', type: 'number' },
        { key: 'meter_end', type: 'number' },
      ]],
      ['amounts', [
        { key: 'net_eur', type: 'number', unit: '€' },
        { key: 'vat_eur', type: 'number', unit: '€' },
        { key: 'total_eur', type: 'number', unit: '€' },
        { key: 'previous_debt_eur', type: 'number', unit: '€' },
      ]],
    ],
  },
  {
    kind: 'meter',
    icon: Gauge,
    save: 'saveReading',
    groups: [
      ['meter', [
        { key: 'meter_serial', type: 'text' },
        { key: 'register', type: 'text' },
        { key: 'reading_kwh', type: 'number', unit: 'kWh' },
        { key: 'reading_t1', type: 'number', unit: 'kWh' },
        { key: 'reading_t2', type: 'number', unit: 'kWh' },
      ]],
    ],
  },
  {
    kind: 'nameplate',
    icon: Tag,
    save: 'applyToMachine',
    needsMachine: true,
    groups: [
      ['identity', [
        { key: 'manufacturer', type: 'text' },
        { key: 'model', type: 'text' },
        { key: 'serial', type: 'text' },
        { key: 'year', type: 'number' },
      ]],
      ['rating', [
        { key: 'rated_power_kw', type: 'number', unit: 'kW' },
        { key: 'rated_power_hp', type: 'number', unit: 'HP' },
        { key: 'voltage_v', type: 'number', unit: 'V' },
        { key: 'current_a', type: 'number', unit: 'A' },
        { key: 'frequency_hz', type: 'number', unit: 'Hz' },
        { key: 'phases', type: 'select', options: ['1', '3'] },
        { key: 'power_factor', type: 'number', unit: 'cos φ' },
        { key: 'efficiency_class', type: 'text' },
        { key: 'efficiency_pct', type: 'number', unit: '%' },
        { key: 'speed_rpm', type: 'number', unit: 'rpm' },
      ]],
    ],
  },
  {
    kind: 'fuel',
    icon: Fuel,
    save: 'addScope1',
    groups: [
      ['receipt', [
        { key: 'vendor', type: 'text', wide: true },
        { key: 'date', type: 'date' },
        { key: 'fuel', type: 'select', options: ['diesel', 'petrol', 'gas_oil', 'lpg', 'heating_oil'] },
        { key: 'litres', type: 'number', unit: 'l' },
        { key: 'unit_price_eur', type: 'number', unit: '€/l' },
        { key: 'total_eur', type: 'number', unit: '€' },
        { key: 'vat_eur', type: 'number', unit: '€' },
      ]],
    ],
  },
  {
    kind: 'label',
    icon: BadgeCheck,
    save: null,
    groups: [
      ['product', [
        { key: 'product_type', type: 'text' },
        { key: 'brand', type: 'text' },
        { key: 'model', type: 'text' },
        { key: 'energy_class', type: 'text' },
        { key: 'kwh_per_year', type: 'number', unit: 'kWh' },
        { key: 'consumption_unit', type: 'text' },
      ]],
    ],
  },
]

export const kindFor = (kind) => SCAN_KINDS.find((k) => k.kind === kind) ?? null

export const emptyFields = (kind) =>
  Object.fromEntries((kindFor(kind)?.groups ?? []).flatMap(([, fields]) => fields.map((f) => [f.key, ''])))

/** API fields (numbers, nulls) → form strings, and back. */
export const toForm = (kind, fields) =>
  Object.fromEntries(Object.entries(emptyFields(kind)).map(([key]) => [key, fields?.[key] === null || fields?.[key] === undefined ? '' : String(fields[key])]))

export const toApi = (kind, form) => {
  const types = Object.fromEntries((kindFor(kind)?.groups ?? []).flatMap(([, fields]) => fields.map((f) => [f.key, f.type])))
  return Object.fromEntries(Object.entries(form).map(([key, value]) => {
    const text = String(value ?? '').trim()
    if (text === '') return [key, null]
    return [key, types[key] === 'number' ? Number(text.replace(',', '.')) : text]
  }))
}
