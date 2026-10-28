import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { CircleAlert, CircleCheck, Info } from 'lucide-react'
import { api } from '../../lib/api'
import { formatEur, formatNumber } from '../../lib/format'
import { hasRole } from '../../lib/roles'
import { useAuth } from '../../providers/AuthProvider'
import { useApiErrors } from '../../hooks/useApiErrors'
import { Button } from '../../components/ui/Button'
import { Card, CardBody, CardHeader } from '../../components/ui/Card'
import { Field, Select } from '../../components/ui/Field'
import { Callout, ErrorState, Skeleton } from '../../components/ui/States'

const TARIFF = ['company', 'tariff']
const NBSP = ' '

/** The published group (template) the company's own plan was copied from, matched by category. */
const templateFor = (state) => state.templates.find((plan) => plan.category === state.current?.category) ?? null

/**
 * The company's KESCO tariff group. Picking one gives the company its own copy of ERO's
 * published plan; every € in the app is priced with it. Rates shown come from the API.
 */
export function TariffSettings() {
  const { t } = useTranslation()
  const tariff = useQuery({ queryKey: TARIFF, queryFn: async () => (await api.get('/company/tariff')).data })
  return (
    <Card className="mt-6 max-w-3xl">
      <CardHeader title={t('settings.tariff.title')} description={t('settings.tariff.hint')} />
      <CardBody>
        {tariff.isPending ? (
          <Skeleton className="h-28" />
        ) : tariff.isError ? (
          <ErrorState error={tariff.error} onRetry={tariff.refetch} />
        ) : (
          <TariffForm state={tariff.data} />
        )}
      </CardBody>
    </Card>
  )
}

function TariffForm({ state }) {
  const { t } = useTranslation()
  const { user, company } = useAuth()
  const { errorMessage } = useApiErrors()
  const queryClient = useQueryClient()
  const current = templateFor(state)
  const [choice, setChoice] = useState(current ? String(current.id) : '')
  const demo = Boolean(company?.is_demo)
  const canEdit = hasRole(user, 'admin') && !demo

  const save = useMutation({
    mutationFn: async (templateId) => (await api.patch('/company/tariff', { template_id: templateId })).data,
    onSuccess: (data) => {
      queryClient.setQueryData(TARIFF, data)
      // Costs everywhere (overview, machines, waste, reports) depend on the tariff.
      queryClient.invalidateQueries({ predicate: (query) => query.queryKey[0] !== 'auth' })
    },
  })

  const selected = state.templates.find((plan) => String(plan.id) === choice) ?? null

  return (
    <form
      noValidate
      className="flex flex-col gap-4"
      onSubmit={(event) => {
        event.preventDefault()
        if (selected) save.mutate(selected.id)
      }}
    >
      {demo ? (
        <Callout icon={Info}>{t('errors.demo_account')}</Callout>
      ) : (
        !canEdit && <Callout icon={Info}>{t('settings.tariff.readOnly')}</Callout>
      )}
      {state.current && !current && <Callout icon={Info}>{t('settings.tariff.custom', { name: state.current.name })}</Callout>}
      <Field label={t('settings.tariff.group')} hint={t('settings.tariff.groupHint')}>
        {({ id, describedBy }) => (
          <Select
            id={id}
            aria-describedby={describedBy}
            value={choice}
            disabled={!canEdit || save.isPending}
            onChange={(event) => {
              save.reset()
              setChoice(event.target.value)
            }}
          >
            {!current && <option value="" disabled>{t('settings.tariff.choose')}</option>}
            {state.templates.map((plan) => (
              <option key={plan.id} value={String(plan.id)}>{t(`settings.tariff.groups.${plan.category}`, { defaultValue: plan.name })}</option>
            ))}
          </Select>
        )}
      </Field>
      {selected && <Rates plan={selected} />}

      {save.isError && (
        <Callout tone="critical" icon={CircleAlert}>
          {errorMessage(save.error)}
        </Callout>
      )}
      {canEdit && (
        <div className="flex items-center gap-3 border-t border-line pt-4">
          <Button type="submit" loading={save.isPending} disabled={!selected || selected.id === current?.id}>
            {save.isPending ? t('common.saving') : t('common.save')}
          </Button>
          {save.isSuccess && (
            <span className="flex items-center gap-1.5 text-[13px] text-good-text" role="status">
              <CircleCheck className="size-4" aria-hidden="true" />
              {t('common.saved')}
            </span>
          )}
        </div>
      )}
    </form>
  )
}

function Rates({ plan }) {
  const { t } = useTranslation()
  const perKwh = (rate) => `${formatNumber(rate, 4, 4)}${NBSP}€/kWh`
  const rows = [
    ['energy', t('settings.tariff.energyValue', { high: perKwh(plan.rate_high), low: plan.rate_low === null ? '—' : perKwh(plan.rate_low) })],
    ['fixed', t('settings.tariff.fixedValue', { eur: formatEur(plan.fixed_monthly_eur) })],
    ['engaged', plan.demand_charge_eur_kw_month > 0 ? t('settings.tariff.engagedValue', { eur: formatEur(plan.demand_charge_eur_kw_month) }) : t('settings.tariff.notCharged')],
    [
      'reactive',
      plan.reactive_charge_eur_kvarh > 0 && plan.reactive_pf_threshold !== null
        ? t('settings.tariff.reactiveValue', { rate: formatNumber(plan.reactive_charge_eur_kvarh * 100, 2, 2), threshold: formatNumber(plan.reactive_pf_threshold, 2, 2) })
        : t('settings.tariff.notCharged'),
    ],
  ]
  return (
    <div className="rounded-md border border-line bg-surface-2/40 px-4 py-3">
      <dl className="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-[auto_minmax(0,1fr)]">
        {rows.map(([key, value]) => (
          <div key={key} className="contents">
            <dt className="text-ink-2">{t(`settings.tariff.${key}`)}</dt>
            <dd className="tabular text-ink">{value}</dd>
          </div>
        ))}
      </dl>
      <p className="mt-3 text-xs text-ink-3">{t('settings.tariff.source')}</p>
    </div>
  )
}
