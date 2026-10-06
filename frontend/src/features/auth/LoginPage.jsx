import { useState } from 'react'
import { Link, useLocation, useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { CircleAlert } from 'lucide-react'
import { Button } from '../../components/ui/Button'
import { Field, PasswordField } from '../../components/ui/Field'
import { Callout } from '../../components/ui/States'
import { useAuth } from '../../providers/AuthProvider'
import { useApiErrors } from '../../hooks/useApiErrors'
import { AuthLayout } from './AuthLayout'

export function LoginPage() {
  const { t } = useTranslation()
  const { login } = useAuth()
  const { errorMessage, fieldErrors } = useApiErrors()
  const navigate = useNavigate()
  const location = useLocation()
  const [form, setForm] = useState({ email: '', password: '' })
  const [error, setError] = useState(null)
  const [submitting, setSubmitting] = useState(false)

  const update = (event) => setForm((current) => ({ ...current, [event.target.name]: event.target.value }))
  const fields = fieldErrors(error)

  async function submit(event) {
    event.preventDefault()
    setSubmitting(true)
    setError(null)
    try {
      await login(form)
      navigate(location.state?.from ?? '/', { replace: true })
    } catch (caught) {
      setError(caught)
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <AuthLayout
      title={t('auth.login.title')}
      subtitle={t('auth.login.subtitle')}
      footer={
        <>
          {t('auth.login.noAccount')}{' '}
          <Link to="/register" className="font-medium text-brand hover:underline">
            {t('auth.login.register')}
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
          label={t('auth.login.email')}
          name="email"
          type="email"
          autoComplete="email"
          value={form.email}
          onChange={update}
          error={fields.email}
          required
        />
        <PasswordField
          label={t('auth.login.password')}
          name="password"
          autoComplete="current-password"
          value={form.password}
          onChange={update}
          error={fields.password}
          required
        />
        <Button type="submit" size="lg" loading={submitting} className="mt-2 w-full">
          {t('auth.login.submit')}
        </Button>
      </form>
    </AuthLayout>
  )
}
