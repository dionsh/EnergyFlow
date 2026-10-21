import { Suspense } from 'react'
import { Navigate, Outlet, useLocation } from 'react-router-dom'
import { hadSession, useAuth } from '../../providers/AuthProvider'
import { ErrorState, FullPageSpinner } from '../ui/States'

/**
 * Only for signed-in users; remembers where they were going. At "/" a visitor
 * gets `home` (the landing page) instead of the sign-in redirect — right away,
 * without waiting for the API (a sleeping Render instance takes about a minute),
 * unless this browser was signed in last time.
 */
export function RequireAuth({ home }) {
  const { status, error, retry } = useAuth()
  const location = useLocation()

  if (home && location.pathname === '/' && (status === 'anonymous' || (status !== 'authenticated' && !hadSession()))) {
    return <Suspense fallback={<FullPageSpinner />}>{home}</Suspense>
  }
  if (status === 'loading') return <FullPageSpinner />
  if (status === 'error') {
    return (
      <div className="flex min-h-svh items-center justify-center bg-bg">
        <ErrorState error={error} onRetry={retry} />
      </div>
    )
  }
  if (status === 'anonymous') return <Navigate to="/login" replace state={{ from: location.pathname }} />
  return <Outlet />
}

/** Login/register pages bounce signed-in users to the app. */
export function GuestOnly() {
  const { status } = useAuth()
  if (status === 'loading') return <FullPageSpinner />
  if (status === 'authenticated') return <Navigate to="/" replace />
  return <Outlet />
}
