import { useCallback, useEffect, useRef, useState } from 'react'
import { Link, Navigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { ArrowLeft, LoaderCircle } from 'lucide-react'
import { useAuth } from '../../providers/AuthProvider'
import { buttonClasses } from '../../components/ui/buttonStyles'
import { ErrorState } from '../../components/ui/States'

/**
 * /demo, the address behind the Demo Day QR code: signs a visitor straight into the
 * read-only demo company. Someone already signed in keeps their own session and dashboard.
 */
export function DemoStart() {
  const { t } = useTranslation()
  const { status, error: authError, retry, loginDemo } = useAuth()
  const [error, setError] = useState(null)
  const started = useRef(false)

  const start = useCallback(() => {
    started.current = true
    setError(null)
    loginDemo().catch(setError) // signed in: status turns 'authenticated' and the redirect below runs
  }, [loginDemo])

  useEffect(() => {
    if (status === 'anonymous' && !started.current) start()
  }, [status, start])

  if (status === 'authenticated') return <Navigate to="/" replace />

  const failure = error ?? (status === 'error' ? authError : null)
  if (failure) {
    return (
      <div className="flex min-h-svh flex-col items-center justify-center bg-bg px-4">
        <ErrorState error={failure} onRetry={error ? start : retry} />
        <Link to="/" className={buttonClasses({ variant: 'ghost' })}>
          <ArrowLeft className="size-4" aria-hidden="true" />
          {t('demo.back')}
        </Link>
      </div>
    )
  }

  return (
    <div className="flex min-h-svh flex-col items-center justify-center gap-3 bg-bg px-4" role="status">
      <LoaderCircle className="size-6 animate-spin text-ink-3" aria-hidden="true" />
      <p className="text-sm text-ink-2">{t('demo.opening')}</p>
    </div>
  )
}
