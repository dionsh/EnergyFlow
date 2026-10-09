import { useState } from 'react'
import { Link, useLocation, useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { CircleAlert, Factory } from 'lucide-react'
import { Button } from '../../components/ui/Button'
import { Field, PasswordField } from '../../components/ui/Field'
import { Callout } from '../../components/ui/States'
import { useAuth } from '../../providers/AuthProvider'
import { useApiErrors } from '../../hooks/useApiErrors'
import { AuthLayout } from './AuthLayout'

export function LoginPage() {
  const { t } = useTranslation()
  const { login, loginDemo } = useAuth()
  const { errorMessage, fieldErrors } = useApiErrors()
  const navigate = useNavigate()
  const location = useLocation()
  const [form, setForm] = useState({ email: '', password: '' })
  const [error, setError] = useState(null)
  const [submitting, setSubmitting] = useState(false)
  const [exploring, setExploring] = useState(false)

  async function exploreDemo() {
    setExploring(true)
    setError(null)
    try {
      await loginDemo()
      navigate('/', { replace: true })
    } catch (caught) {
      setError(caught)
    } finally {
      setExploring(false)
    }
  }

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
        <Link to="/forgot-password" className="-mt-2 self-end text-[13px] font-medium text-brand hover:underline">
          {t('auth.forgot.link')}
        </Link>
        <Button type="submit" size="lg" loading={submitting} className="mt-2 w-full">
          {t('auth.login.submit')}
        </Button>
      </form>
      <div className="mt-6 border-t border-line pt-6">
        <Button variant="secondary" size="lg" icon={Factory} loading={exploring} className="w-full" onClick={exploreDemo}>
          {t('demo.explore')}
        </Button>
        <p className="mt-2 text-center text-[12.5px] text-ink-3">{t('demo.exploreHint')}</p>
      </div>
    </AuthLayout>
  )
}
