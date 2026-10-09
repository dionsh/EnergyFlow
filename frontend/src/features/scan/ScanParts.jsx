import { useTranslation } from 'react-i18next'
import { CircleCheck, CircleX, Info, ShieldAlert, ShieldCheck, TriangleAlert } from 'lucide-react'
import { cn } from '../../lib/cn'
import { formatCo2, formatEur, formatKw, formatKwh, formatNumber, formatPercent } from '../../lib/format'
import { Field, Select } from '../../components/ui/Field'
import { checkText, unitKey } from './scanText'

const STATUS = {
  pass: { icon: CircleCheck, className: 'text-good-text' },
  warn: { icon: TriangleAlert, className: 'text-warning-text' },
  fail: { icon: CircleX, className: 'text-critical-text' },
  info: { icon: Info, className: 'text-info-text' },
}

const VERDICT = {
  consistent: { icon: ShieldCheck, box: 'border-good/50 bg-good-subtle', text: 'text-good-text' },
  ok: { icon: ShieldCheck, box: 'border-good/50 bg-good-subtle', text: 'text-good-text' },
  check: { icon: TriangleAlert, box: 'border-warning/60 bg-warning-subtle', text: 'text-warning-text' },
  likely_fake: { icon: ShieldAlert, box: 'border-critical/50 bg-critical-subtle', text: 'text-critical-text' },
}

/** The editable values, grouped as on the document. */
export function ScanFieldsForm({ kindDef, form, onChange, disabled }) {
  const { t } = useTranslation()
  return (
    <div className="flex flex-col gap-5">
      {kindDef.groups.map(([group, fields]) => (
        <fieldset key={group} disabled={disabled}>
          <legend className="mb-2.5 text-[12px] font-semibold uppercase tracking-[0.04em] text-ink-3">{t(`scan.group.${group}`)}</legend>
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
            {fields.map((field) => (
              <Field
                key={field.key}
                label={t(`scan.field.${field.key}`)}
                suffix={field.unit}
                className={field.wide ? 'sm:col-span-2' : undefined}
                type={field.type === 'number' ? 'text' : field.type}
                inputMode={field.type === 'number' ? 'decimal' : undefined}
                value={form[field.key] ?? ''}
                onChange={(e) => onChange(field.key, e.target.value)}
              >
                {field.type === 'select'
                  ? ({ id }) => (
                    <Select id={id} value={form[field.key] ?? ''} onChange={(e) => onChange(field.key, e.target.value)}>
                      <option value="">—</option>
                      {[...field.options, ...(form[field.key] && !field.options.includes(form[field.key]) ? [form[field.key]] : [])].map((option) => (
                        <option key={option} value={option}>{t(`scan.option.${field.key}.${option}`, { defaultValue: option })}</option>
                      ))}
                    </Select>
                  )
                  : undefined}
              </Field>
            ))}
          </div>
        </fieldset>
      ))}
    </div>
  )
}

/** Verdict, every check with its reason, and the numbers that matter. */
export function ScanResult({ result, stale }) {
  const { t } = useTranslation()
  const verdict = VERDICT[result.verdict] ?? VERDICT.check
  const Icon = verdict.icon
  return (
    <div className={cn('flex flex-col gap-4 transition-opacity', stale && 'opacity-50')}>
      <div className={cn('flex items-start gap-3 rounded-md border px-4 py-3', verdict.box)}>
        <Icon className={cn('mt-0.5 size-5 shrink-0', verdict.text)} aria-hidden="true" />
        <div className="min-w-0">
          <p className={cn('text-[15px] font-semibold', verdict.text)}>{t(`scan.verdict.${result.kind}.${result.verdict}`)}</p>
          <p className="mt-0.5 text-[13px] text-ink-2">{t(`scan.verdictBody.${result.kind}.${result.verdict}`)}</p>
        </div>
      </div>
      <Derived result={result} />
      <ul className="flex flex-col gap-2">
        {result.checks.map((check, i) => {
          const status = STATUS[check.status] ?? STATUS.info
          const StatusIcon = status.icon
          return (
            <li key={`${check.key}-${i}`} className="flex items-start gap-2.5 text-[13px] leading-snug text-ink">
              <StatusIcon className={cn('mt-px size-4 shrink-0', status.className)} aria-label={t(`scan.status.${check.status}`)} />
              <span>{checkText(t, check)}</span>
            </li>
          )
        })}
      </ul>
      {result.kind === 'bill' && <p className="text-[12px] text-ink-3">{t('scan.billDisclaimer')}</p>}
    </div>
  )
}

function Derived({ result }) {
  const { t } = useTranslation()
  const d = result.derived ?? {}
  const rows = {
    bill: [
      ['pricePerKwh', d.price_eur_kwh !== null && d.price_eur_kwh !== undefined ? `${formatNumber(d.price_eur_kwh * 100, 2)} c/kWh` : null],
      ['expectedTotal', d.expected_total_eur !== null && d.expected_total_eur !== undefined ? formatEur(d.expected_total_eur, { compact: false }) : null],
      ['meteredKwh', d.metered_kwh !== null && d.metered_kwh !== undefined ? formatKwh(d.metered_kwh) : null],
    ],
    fuel: [
      ['co2', d.co2_kg !== undefined ? formatCo2(d.co2_kg) : null],
      ['energy', d.energy_kwh !== undefined && d.energy_kwh !== null ? formatKwh(d.energy_kwh) : null],
    ],
    nameplate: [
      ['ratedKw', d.rated_kw !== null && d.rated_kw !== undefined ? formatKw(d.rated_kw) : null],
      ['inputKw', d.input_kw !== null && d.input_kw !== undefined ? formatKw(d.input_kw) : null],
      ['load', d.load !== undefined ? formatPercent(d.load, 0) : null],
    ],
    label: [
      ['cost', d.eur !== undefined ? `${formatEur(d.eur, { compact: false })} / ${t(`scan.labelUnit.${unitKey(d.unit)}`)}` : null],
      ['co2', d.co2_kg !== undefined ? formatCo2(d.co2_kg) : null],
      ['price', d.price_eur_kwh !== undefined ? t(`scan.priceBasis.${d.price_basis}`, { price: formatEur(d.price_eur_kwh, { compact: false }) }) : null],
    ],
    meter: [
      ['meterDelta', d.delta_kwh !== undefined ? formatKwh(d.delta_kwh) : null],
      ['meteredKwh', d.metered_kwh !== undefined ? formatKwh(d.metered_kwh) : null],
    ],
  }[result.kind]?.filter(([, value]) => value !== null) ?? []
  if (rows.length === 0) return null
  return (
    <dl className="grid grid-cols-2 gap-x-4 gap-y-2 rounded-md border border-line px-4 py-3 sm:grid-cols-3">
      {rows.map(([key, value]) => (
        <div key={key} className="min-w-0">
          <dt className="text-[11.5px] text-ink-3">{t(`scan.derived.${key}`)}</dt>
          <dd className="mt-0.5 truncate text-[14px] font-semibold text-ink tabular">{value}</dd>
        </div>
      ))}
    </dl>
  )
}
