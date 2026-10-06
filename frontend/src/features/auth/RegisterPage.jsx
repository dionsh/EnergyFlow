import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { CircleAlert } from 'lucide-react'
import { Button } from '../../components/ui/Button'
import { Field, PasswordField } from '../../components/ui/Field'
import { Callout } from '../../components/ui/States'
import { useAuth } from '../../providers/AuthProvider'
import { useApiErrors } from '../../hooks/useApiErrors'
import { AuthLayout } from './AuthLayout'

const EMPTY = { full_name: '', company_name: '', city: '', email: '', password: '' }

export function RegisterPage() {
  const { t } = useTranslation()
  const { register } = useAuth()
  const { errorMessage, fieldErrors } = useApiErrors()
  const navigate = useNavigate()
  const [form, setForm] = useState(EMPTY)
  const [error, setError] = useState(null)
  const [submitting, setSubmitting] = useState(false)

  const update = (event) => setForm((current) => ({ ...current, [event.target.name]: event.target.value }))
  const fields = fieldErrors(error)

  async function submit(event) {
    event.preventDefault()
    setSubmitting(true)
    setError(null)
    try {
      await register(form)
      navigate('/', { replace: true })
    } catch (caught) {
      setError(caught)
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <AuthLayout
      title={t('auth.register.title')}
      subtitle={t('auth.register.subtitle')}
      footer={
        <>
          {t('auth.register.haveAccount')}{' '}
          <Link to="/login" className="font-medium text-brand hover:underline">
            {t('auth.register.login')}
          </Link>
        </>
      }
    >
      <form onSubmit={submit} className="flex flex-col gap-4" noValidate>
        {error && error.code !== 'validation_failed' && (
          <Callout tone="critical" icon={CircleAlert}>
            {errorMessage(error)}
          </Callout>
        )}
        <Field
          label={t('auth.register.fullName')}
          name="full_name"
          autoComplete="name"
          value={form.full_name}
          onChange={update}
          error={fields.full_name}
          required
        />
        <div className="grid gap-4 sm:grid-cols-[3fr_2fr]">
          <Field
            label={t('auth.register.companyName')}
            name="company_name"
            autoComplete="organization"
            value={form.company_name}
            onChange={update}
            error={fields.company_name}
            required
          />
          <Field
            label={t('auth.register.city')}
            name="city"
            autoComplete="address-level2"
            placeholder={t('auth.register.cityPlaceholder')}
            value={form.city}
            onChange={update}
            error={fields.city}
            optional
          />
        </div>
        <Field
          label={t('auth.register.email')}
          name="email"
          type="email"
          autoComplete="email"
          value={form.email}
          onChange={update}
          error={fields.email}
          required
        />
        <PasswordField
          label={t('auth.register.password')}
          name="password"
          autoComplete="new-password"
          hint={t('auth.register.passwordHint')}
          value={form.password}
          onChange={update}
          error={fields.password}
          required
        />
        <Button type="submit" size="lg" loading={submitting} className="mt-2 w-full">
          {t('auth.register.submit')}
        </Button>
      </form>
    </AuthLayout>
  )
}
