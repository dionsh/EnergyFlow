import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useMutation } from '@tanstack/react-query'
import { CircleAlert, CircleCheck } from 'lucide-react'
import { api } from '../../lib/api'
import { LANGUAGES } from '../../i18n/languages'
import { useAuth } from '../../providers/AuthProvider'
import { useApiErrors } from '../../hooks/useApiErrors'
import { Button } from '../../components/ui/Button'
import { Card, CardBody, CardHeader } from '../../components/ui/Card'
import { Field, PasswordField, Select } from '../../components/ui/Field'
import { Callout } from '../../components/ui/States'

function SavedNote({ children }) {
  return (
    <span className="flex items-center gap-1.5 text-[13px] text-good-text" role="status">
      <CircleCheck className="size-4" aria-hidden="true" />
      {children}
    </span>
  )
}

function ProfileForm() {
  const { t, i18n } = useTranslation()
  const { user, setSession } = useAuth()
  const { errorMessage, fieldErrors } = useApiErrors()
  const [form, setForm] = useState({ full_name: user.full_name, locale: user.locale ?? i18n.language })

  const save = useMutation({
    mutationFn: async (values) => (await api.patch('/auth/me', values)).data,
    onSuccess: (data) => {
      setSession(data)
      i18n.changeLanguage(data.user.locale)
    },
  })
  const fields = fieldErrors(save.error)
  const update = (event) => {
    save.reset()
    setForm((current) => ({ ...current, [event.target.name]: event.target.value }))
  }

  return (
    <Card>
      <CardHeader title={t('settings.account.profile')} />
      <CardBody>
        <form
          noValidate
          className="flex flex-col gap-4"
          onSubmit={(event) => {
            event.preventDefault()
            save.mutate(form)
          }}
        >
          <Field label={t('settings.account.fullName')} name="full_name" autoComplete="name" value={form.full_name} onChange={update} error={fields.full_name} />
          <div className="grid gap-4 sm:grid-cols-2">
            <Field label={t('settings.account.email')} value={user.email} disabled readOnly />
            <Field label={t('settings.account.role')} value={t(`roles.${user.role}`)} disabled readOnly />
          </div>
          <Field label={t('settings.account.language')} error={fields.locale}>
            {({ id, invalid }) => (
              <Select id={id} invalid={invalid} name="locale" value={form.locale} onChange={update}>
                {LANGUAGES.map((language) => (
                  <option key={language.code} value={language.code}>
                    {language.label}
                  </option>
                ))}
              </Select>
            )}
          </Field>
          {save.isError && save.error.code !== 'validation_failed' && (
            <Callout tone="critical" icon={CircleAlert}>
              {errorMessage(save.error)}
            </Callout>
          )}
          <div className="flex items-center gap-3 border-t border-line pt-4">
            <Button type="submit" loading={save.isPending}>
              {save.isPending ? t('common.saving') : t('common.save')}
            </Button>
            {save.isSuccess && <SavedNote>{t('common.saved')}</SavedNote>}
          </div>
        </form>
      </CardBody>
    </Card>
  )
}

function PasswordForm() {
  const { t } = useTranslation()
  const { setSession } = useAuth()
  const { errorMessage, fieldErrors } = useApiErrors()
  const [form, setForm] = useState({ current_password: '', new_password: '' })

  const save = useMutation({
    mutationFn: async (values) => (await api.patch('/auth/me', values)).data,
    onSuccess: (data) => {
      setSession(data)
      setForm({ current_password: '', new_password: '' })
    },
  })
  const fields = fieldErrors(save.error)
  const update = (event) => {
    save.reset()
    setForm((current) => ({ ...current, [event.target.name]: event.target.value }))
  }

  return (
    <Card>
      <CardHeader title={t('settings.account.passwordTitle')} description={t('settings.account.passwordHint')} />
      <CardBody>
        <form
          noValidate
          className="flex flex-col gap-4"
          onSubmit={(event) => {
            event.preventDefault()
            save.mutate(form)
          }}
        >
          <PasswordField label={t('settings.account.currentPassword')} name="current_password" autoComplete="current-password" value={form.current_password} onChange={update} error={fields.current_password} />
          <PasswordField
            label={t('settings.account.newPassword')}
            name="new_password"
            autoComplete="new-password"
            hint={t('auth.register.passwordHint')}
            value={form.new_password}
            onChange={update}
            error={fields.new_password}
          />
          {save.isError && save.error.code !== 'validation_failed' && (
            <Callout tone="critical" icon={CircleAlert}>
              {errorMessage(save.error)}
            </Callout>
          )}
          <div className="flex flex-wrap items-center gap-3 border-t border-line pt-4">
            <Button type="submit" variant="secondary" loading={save.isPending}>
              {t('settings.account.changePassword')}
            </Button>
            {save.isSuccess && <SavedNote>{t('settings.account.passwordChanged')}</SavedNote>}
          </div>
        </form>
      </CardBody>
    </Card>
  )
}

export function AccountSettings() {
  return (
    <div className="grid max-w-5xl gap-6 lg:grid-cols-2">
      <ProfileForm />
      <PasswordForm />
    </div>
  )
}
