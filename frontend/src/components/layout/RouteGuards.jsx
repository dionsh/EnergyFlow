import { Navigate, Outlet, useLocation } from 'react-router-dom'
import { useAuth } from '../../providers/AuthProvider'
import { ErrorState, FullPageSpinner } from '../ui/States'

/** Only for signed-in users; remembers where they were going. */
export function RequireAuth() {
  const { status, error, retry } = useAuth()
  const location = useLocation()

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
