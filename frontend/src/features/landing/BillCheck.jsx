import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { CircleAlert, Factory, Gauge, Info, ReceiptText } from 'lucide-react'
import { api } from '../../lib/api'
import { formatEur, formatKw, formatNumber } from '../../lib/format'
import { useApiErrors } from '../../hooks/useApiErrors'
import { Button } from '../../components/ui/Button'
import { Field, Select } from '../../components/ui/Field'
import { Callout } from '../../components/ui/States'
import { Container, DemoError, SectionHeading } from './LandingParts'

/** Tariff groups with a published template plan in the API (tariff_plans with company_id NULL). */
const GROUPS = ['commercial_cat_1', 'commercial_cat_2']
/** Category II is billed for energy and a fixed fee only, so there is nothing to price. */
const PRICED = 'commercial_cat_1'

const NBSP = ' '
const kvarh = (value) => `${formatNumber(value)}${NBSP}kVArh`

/**
 * "Kosovo bill check": one month of a visitor's own bill, priced by the API with ERO's
 * published tariff (the same TariffBook rules as the dashboard). This page only formats the answer.
 */
export function BillCheck({ demo }) {
  const { t } = useTranslation()
  const { errorMessage, fieldErrors } = useApiErrors()
  const [form, setForm] = useState({ category: PRICED, kwh: '', kvarh: '', kw: '' })
  const [check, setCheck] = useState({ busy: false, result: null, error: null })
  const fields = fieldErrors(check.error)
  const priced = form.category === PRICED

  const update = (event) => setForm((current) => ({ ...current, [event.target.name]: event.target.value }))

  async function submit(event) {
    event.preventDefault()
    setCheck((current) => ({ ...current, busy: true, error: null }))
    try {
      const { data } = await api.get(`/public/bill-check?${new URLSearchParams(form)}`)
      setCheck({ busy: false, result: data, error: null })
    } catch (error) {
      setCheck({ busy: false, result: null, error })
    }
  }

  return (
    <section id="bill" aria-labelledby="bill-title" className="border-b border-line bg-surface">
      <Container className="grid gap-10 py-14 sm:py-20 lg:grid-cols-[minmax(0,5fr)_minmax(0,7fr)] lg:gap-14">
        <div className="min-w-0">
          <SectionHeading id="bill-title" eyebrow={t('landing.billCheck.eyebrow')} title={t('landing.billCheck.title')} body={t('landing.billCheck.body')} />
          <form onSubmit={submit} className="mt-8 flex flex-col gap-4" noValidate>
            <Field label={t('landing.billCheck.group')} hint={t('landing.billCheck.groupHint')}>
              {({ id, describedBy }) => (
                <Select id={id} name="category" value={form.category} onChange={update} aria-describedby={describedBy}>
                  {GROUPS.map((group) => <option key={group} value={group}>{t(`landing.billCheck.groups.${group}`)}</option>)}
                </Select>
              )}
            </Field>
            {priced && (
              <>
                <Field
                  label={t('landing.billCheck.kwh')} hint={t('landing.billCheck.kwhHint')} name="kwh" suffix="kWh"
                  type="number" inputMode="decimal" min="0" step="any" value={form.kwh} onChange={update} error={fields.kwh}
                />
                <div className="grid gap-4 sm:grid-cols-2">
                  <Field
                    label={t('landing.billCheck.kvarh')} name="kvarh" suffix="kVArh" className="[&_input]:pr-14"
                    type="number" inputMode="decimal" min="0" step="any" value={form.kvarh} onChange={update} error={fields.kvarh}
                  />
                  <Field
                    label={t('landing.billCheck.kw')} hint={t('landing.billCheck.kwHint')} name="kw" suffix="kW"
                    type="number" inputMode="decimal" min="0" step="any" value={form.kw} onChange={update} error={fields.kw}
                  />
                </div>
                <Button type="submit" size="lg" icon={ReceiptText} loading={check.busy} className="h-11 self-start px-5">
                  {t('landing.billCheck.submit')}
                </Button>
                {check.error && Object.keys(fields).length === 0 && (
                  <Callout tone="critical" icon={CircleAlert}>{errorMessage(check.error)}</Callout>
                )}
              </>
            )}
          </form>
        </div>
        <div className="min-w-0" aria-live="polite">
          {!priced ? (
            <Callout icon={Info}>{t('landing.billCheck.notCharged')}</Callout>
          ) : check.result ? (
            <BillResult result={check.result} demo={demo} />
          ) : (
            <BillRules />
          )}
          <p className="mt-4 text-xs leading-relaxed text-ink-3">{t('landing.billCheck.source')}</p>
        </div>
      </Container>
    </section>
  )
}

/** Before the first check: the two ERO rules the check applies. */
function BillRules() {
  const { t } = useTranslation()
  const rules = t('landing.billCheck.rules', { returnObjects: true })
  return (
    <div className="rounded-lg border border-dashed border-line-strong p-5 sm:p-6">
      <h3 className="text-sm font-semibold text-ink">{t('landing.billCheck.rulesTitle')}</h3>
      <ul className="mt-4 flex flex-col gap-4">
        {Array.isArray(rules) && rules.map((rule) => (
          <li key={rule.title}>
            <p className="text-[15px] font-semibold text-ink">{rule.title}</p>
            <p className="mt-1 text-sm leading-relaxed text-ink-2">{rule.body}</p>
          </li>
        ))}
      </ul>
    </div>
  )
}

function BillResult({ result, demo }) {
  const { t } = useTranslation()
  const { reactive, engaged_power: engaged, power_factor: pf } = result
  const charged = reactive && reactive.month_eur > 0
  return (
    <div className="rounded-lg border border-line bg-bg">
      {pf !== null && reactive && (
        <div className="flex items-start gap-3 border-b border-line px-5 py-4">
          <Gauge className="mt-0.5 size-[18px] shrink-0 text-ink-3" aria-hidden="true" />
          <p className="text-sm text-ink-2">
            <span className="font-semibold text-ink">{t('landing.billCheck.powerFactor', { pf: formatNumber(pf, 3, 2) })}</span>
            {' · '}
            {t(charged ? 'landing.billCheck.pfBelow' : 'landing.billCheck.pfOk', { threshold: formatNumber(reactive.threshold, 2, 2) })}
          </p>
        </div>
      )}
      <div className="grid divide-y divide-line sm:grid-cols-2 sm:divide-x sm:divide-y-0">
        {reactive && (
          <Charge
            title={t('landing.billCheck.reactive')}
            month={reactive.month_eur}
            year={reactive.year_eur}
            detail={t('landing.billCheck.reactiveDetail', {
              billed: kvarh(reactive.billed_kvarh), allowed: kvarh(reactive.allowed_kvarh), rate: formatNumber(reactive.rate_eur_kvarh * 100, 2, 2),
            })}
            advice={charged ? t('landing.billCheck.reactiveAdvice', { threshold: formatNumber(reactive.threshold, 2, 2) }) : null}
          />
        )}
        {engaged && (
          <Charge
            title={t('landing.billCheck.engaged')}
            month={engaged.month_eur}
            year={engaged.year_eur}
            detail={t('landing.billCheck.engagedDetail', { kw: formatKw(engaged.kw), rate: formatEur(engaged.rate_eur_kw_month) })}
            advice={engaged.kw > 0 ? t('landing.billCheck.engagedAdvice', { eur: formatEur(engaged.per_kw_year_eur) }) : null}
          />
        )}
      </div>
      <div className="flex flex-col gap-3 border-t border-line px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
        <p className="text-sm text-ink-2">{t('landing.billCheck.cta')}</p>
        <Button variant="secondary" icon={Factory} loading={demo.busy} onClick={demo.start} className="shrink-0 self-start sm:self-auto">
          {t('demo.explore')}
        </Button>
      </div>
      {demo.error && <div className="px-5 pb-4"><DemoError error={demo.error} /></div>}
    </div>
  )
}

function Charge({ title, month, year, detail, advice }) {
  const { t } = useTranslation()
  return (
    <div className="flex min-w-0 flex-col p-5">
      <h3 className="text-sm font-semibold text-ink">{title}</h3>
      <p className="mt-2 flex flex-wrap items-baseline gap-x-1.5">
        <span className="tabular text-[28px] font-semibold leading-tight tracking-[-0.01em] text-ink">{formatEur(month, { compact: false })}</span>
        <span className="text-sm text-ink-3">{t('landing.billCheck.perMonth')}</span>
      </p>
      <p className="tabular text-sm font-medium text-ink-2">{t('landing.billCheck.perYear', { eur: formatEur(year) })}</p>
      <p className="mt-3 text-[13px] leading-relaxed text-ink-3">{detail}</p>
      {advice && <p className="mt-3 text-sm leading-relaxed text-ink-2">{advice}</p>}
    </div>
  )
}
