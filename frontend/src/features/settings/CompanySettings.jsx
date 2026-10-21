import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Building2, CircleAlert, CircleCheck, ImagePlus, Info, Trash2 } from 'lucide-react'
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

async function prepareLogo(file) {
  if (!['image/png', 'image/jpeg', 'image/webp'].includes(file.type)) throw new Error('format')
  if (file.size > 8 * 1024 * 1024) throw new Error('size')

  const image = await createImageBitmap(file)
  const scale = Math.min(1, 640 / image.width, 640 / image.height)
  const canvas = document.createElement('canvas')
  canvas.width = Math.max(1, Math.round(image.width * scale))
  canvas.height = Math.max(1, Math.round(image.height * scale))
  canvas.getContext('2d').drawImage(image, 0, 0, canvas.width, canvas.height)
  image.close()

  let data = canvas.toDataURL('image/webp', 0.84)
  if (data.length > 680_000) {
    const smaller = document.createElement('canvas')
    smaller.width = Math.round(canvas.width * 0.7)
    smaller.height = Math.round(canvas.height * 0.7)
    smaller.getContext('2d').drawImage(canvas, 0, 0, smaller.width, smaller.height)
    data = smaller.toDataURL('image/webp', 0.72)
  }
  if (data.length > 680_000) throw new Error('size')
  return data
}

export function CompanySettings() {
  const { t } = useTranslation()
  const { user, company, setSession } = useAuth()
  const { errorMessage, fieldErrors } = useApiErrors()
  const queryClient = useQueryClient()
  const [form, setForm] = useState(() => toForm(company))
  const [logo, setLogo] = useState(company?.logo_data_url ?? '')
  const [logoIssue, setLogoIssue] = useState('')
  const canEdit = user.role === 'owner' || user.role === 'admin'

  const save = useMutation({
    mutationFn: async (values) => (await api.patch('/company', { ...values, logo_data_url: logo || null })).data,
    onSuccess: (updated) => {
      setSession({ user, company: updated })
      setForm(toForm(updated))
      setLogo(updated.logo_data_url ?? '')
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
          <div className="flex flex-col gap-4 rounded-md border border-line bg-surface-2/40 p-4 sm:flex-row sm:items-center">
            <div className="flex size-20 shrink-0 items-center justify-center overflow-hidden rounded-md border border-line bg-surface">
              {logo ? (
                <img src={logo} alt={t('settings.company.logoAlt', { name: company?.name })} className="size-full object-contain p-2" />
              ) : (
                <Building2 className="size-8 text-ink-3" aria-hidden="true" />
              )}
            </div>
            <div className="min-w-0 flex-1">
              <p className="text-sm font-medium text-ink">{t('settings.company.logo')}</p>
              <p className="mt-1 text-[13px] text-ink-2">{t('settings.company.logoHint')}</p>
              {canEdit && (
                <div className="mt-3 flex flex-wrap items-center gap-2">
                  <label className="relative inline-flex h-8 cursor-pointer items-center gap-1.5 rounded-sm border border-line-strong bg-surface px-3 text-[13px] font-medium text-ink hover:bg-surface-2">
                    <ImagePlus className="size-4" aria-hidden="true" />
                    {t(logo ? 'settings.company.changeLogo' : 'settings.company.addLogo')}
                    <input
                      type="file"
                      accept="image/png,image/jpeg,image/webp"
                      className="sr-only"
                      onChange={async (event) => {
                        const file = event.target.files?.[0]
                        event.target.value = ''
                        if (!file) return
                        try {
                          setLogoIssue('')
                          setLogo(await prepareLogo(file))
                          save.reset()
                        } catch (error) {
                          setLogoIssue(error.message === 'format' ? 'format' : 'size')
                        }
                      }}
                      disabled={!canEdit || save.isPending}
                    />
                  </label>
                  {logo && (
                    <Button type="button" variant="ghost" size="sm" icon={Trash2} disabled={save.isPending} onClick={() => { setLogo(''); setLogoIssue(''); save.reset() }}>
                      {t('settings.company.removeLogo')}
                    </Button>
                  )}
                </div>
              )}
              {logoIssue && <p className="mt-2 text-xs text-critical-text" role="alert">{t(`settings.company.logoError.${logoIssue}`)}</p>}
            </div>
          </div>
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
