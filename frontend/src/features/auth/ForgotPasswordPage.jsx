import { useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { ArrowLeft, CircleAlert, Mail } from 'lucide-react'
import { api } from '../../lib/api'
import { useApiErrors } from '../../hooks/useApiErrors'
import { Button } from '../../components/ui/Button'
import { Callout } from '../../components/ui/States'
import { Field, PasswordField } from '../../components/ui/Field'
import { AuthLayout } from './AuthLayout'

export function ForgotPasswordPage() {
  const { t } = useTranslation()
  const [params] = useSearchParams()
  const token = params.get('token') ?? ''
  const { errorMessage, fieldErrors } = useApiErrors()
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [confirmPassword, setConfirmPassword] = useState('')
  const [error, setError] = useState(null)
  const [submitting, setSubmitting] = useState(false)
  const [complete, setComplete] = useState(false)
  const fields = fieldErrors(error)
  const resetting = Boolean(token)

  async function submit(event) {
    event.preventDefault()
    setError(null)
    if (resetting && password !== confirmPassword) {
      setError({ code: 'passwords_do_not_match' })
      return
    }
    setSubmitting(true)
    try {
      if (resetting) {
        await api.post('/auth/password/reset', { token, password })
      } else {
        await api.post('/auth/password/forgot', { email })
      }
      setComplete(true)
    } catch (caught) {
      setError(caught)
    } finally {
      setSubmitting(false)
    }
  }

  const footer = (
    <Link to="/login" className="inline-flex items-center gap-1.5 font-medium text-brand hover:underline">
      <ArrowLeft className="size-4" aria-hidden="true" />
      {t('auth.forgot.backToLogin')}
    </Link>
  )

  return (
    <AuthLayout
      title={t(complete ? (resetting ? 'auth.forgot.resetDoneTitle' : 'auth.forgot.sentTitle') : (resetting ? 'auth.forgot.resetTitle' : 'auth.forgot.title'))}
      subtitle={t(complete ? (resetting ? 'auth.forgot.resetDoneHint' : 'auth.forgot.sentHint') : (resetting ? 'auth.forgot.resetHint' : 'auth.forgot.hint'))}
      footer={footer}
    >
      {complete ? (
        <Callout tone="good" icon={Mail}>{t(resetting ? 'auth.forgot.resetDoneMessage' : 'auth.forgot.sentMessage')}</Callout>
      ) : (
        <form onSubmit={submit} className="flex flex-col gap-4" noValidate>
          {error && error.code !== 'validation_failed' && (
            <Callout tone="critical" icon={CircleAlert}>{errorMessage(error)}</Callout>
          )}
          {resetting ? (
            <>
              <PasswordField label={t('auth.register.password')} name="password" autoComplete="new-password" value={password} onChange={(e) => setPassword(e.target.value)} error={fields.password} hint={t('auth.register.passwordHint')} required />
              <PasswordField label={t('auth.forgot.confirmPassword')} name="confirm-password" autoComplete="new-password" value={confirmPassword} onChange={(e) => setConfirmPassword(e.target.value)} required />
            </>
          ) : (
            <Field label={t('auth.login.email')} name="email" type="email" autoComplete="email" value={email} onChange={(e) => setEmail(e.target.value)} error={fields.email} required />
          )}
          <Button type="submit" size="lg" icon={resetting ? undefined : Mail} loading={submitting} className="mt-1 w-full">
            {t(submitting ? 'auth.forgot.submitting' : (resetting ? 'auth.forgot.resetSubmit' : 'auth.forgot.submit'))}
          </Button>
        </form>
      )}
      {!resetting && !complete && !import.meta.env.PROD && (
        <p className="mt-4 text-xs leading-relaxed text-ink-3">{t('auth.forgot.localHint')}</p>
      )}
    </AuthLayout>
  )
}
