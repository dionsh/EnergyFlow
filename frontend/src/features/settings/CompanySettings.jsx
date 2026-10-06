import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { CircleAlert, CircleCheck, Info } from 'lucide-react'
import { api } from '../../lib/api'
import { useAuth } from '../../providers/AuthProvider'
import { useApiErrors } from '../../hooks/useApiErrors'
import { Button } from '../../components/ui/Button'
import { Card, CardBody, CardHeader } from '../../components/ui/Card'
import { Field } from '../../components/ui/Field'
import { Callout } from '../../components/ui/States'

const FIELDS = ['name', 'legal_form', 'business_number', 'nace_code', 'employees', 'annual_turnover_eur', 'city']

const toForm = (company) =>
  Object.fromEntries(FIELDS.map((field) => [field, company?.[field] === null || company?.[field] === undefined ? '' : String(company[field])]))

export function CompanySettings() {
  const { t } = useTranslation()
  const { user, company, setSession } = useAuth()
  const { errorMessage, fieldErrors } = useApiErrors()
  const queryClient = useQueryClient()
  const [form, setForm] = useState(() => toForm(company))
  const canEdit = user.role === 'owner' || user.role === 'admin'

  const save = useMutation({
    mutationFn: async (values) => (await api.patch('/company', values)).data,
    onSuccess: (updated) => {
      setSession({ user, company: updated })
      setForm(toForm(updated))
      queryClient.invalidateQueries({ queryKey: ['onboarding'] })
    },
  })

  const update = (event) => {
    save.reset()
    setForm((current) => ({ ...current, [event.target.name]: event.target.value }))
  }

  function submit(event) {
    event.preventDefault()
    // Empty optional fields are sent as null so they can be cleared.
    save.mutate(Object.fromEntries(FIELDS.map((field) => [field, form[field].trim() === '' ? null : form[field].trim()])))
  }

  const fields = fieldErrors(save.error)

  return (
    <Card className="max-w-3xl">
      <CardHeader title={t('settings.company.title')} description={t('settings.company.hint')} />
      <CardBody>
        <form onSubmit={submit} className="flex flex-col gap-5" noValidate>
          {!canEdit && (
            <Callout tone="info" icon={Info}>
              {t('settings.company.readOnly')}
            </Callout>
          )}
          <fieldset disabled={!canEdit} className="grid gap-4 sm:grid-cols-2">
            <Field label={t('settings.company.name')} name="name" value={form.name} onChange={update} error={fields.name} className="sm:col-span-2" />
            <Field label={t('settings.company.legalForm')} name="legal_form" placeholder={t('settings.company.legalFormPlaceholder')} value={form.legal_form} onChange={update} error={fields.legal_form} optional />
            <Field label={t('settings.company.businessNumber')} name="business_number" value={form.business_number} onChange={update} error={fields.business_number} optional />
            <Field label={t('settings.company.nace')} name="nace_code" placeholder={t('settings.company.nacePlaceholder')} value={form.nace_code} onChange={update} error={fields.nace_code} />
            <Field label={t('settings.company.city')} name="city" value={form.city} onChange={update} error={fields.city} optional />
            <Field label={t('settings.company.employees')} name="employees" type="number" inputMode="numeric" min="0" value={form.employees} onChange={update} error={fields.employees} />
            <Field label={t('settings.company.turnover')} name="annual_turnover_eur" type="number" inputMode="decimal" min="0" step="1000" suffix="€" value={form.annual_turnover_eur} onChange={update} error={fields.annual_turnover_eur} />
          </fieldset>

          {save.isError && save.error.code !== 'validation_failed' && (
            <Callout tone="critical" icon={CircleAlert}>
              {errorMessage(save.error)}
            </Callout>
          )}

          {canEdit && (
            <div className="flex items-center gap-3 border-t border-line pt-4">
              <Button type="submit" loading={save.isPending}>
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
      </CardBody>
    </Card>
  )
}
