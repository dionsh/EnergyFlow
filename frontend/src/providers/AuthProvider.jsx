import { createContext, use, useCallback, useEffect, useMemo } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { api, ApiError } from '../lib/api'
import i18n from '../i18n'
import { isSupported } from '../i18n/languages'

const AuthContext = createContext(null)
const ME = ['auth', 'me']
const SESSION_HINT = 'ef.signedIn'

/** Whether this browser was signed in when last seen: decides what "/" shows before /auth/me answers. Not a credential. */
export function hadSession() {
  try {
    return localStorage.getItem(SESSION_HINT) === '1'
  } catch {
    return false
  }
}

async function fetchMe() {
  try {
    return (await api.get('/auth/me')).data
  } catch (error) {
    if (error instanceof ApiError && error.status === 401) return null
    throw error
  }
}

export function AuthProvider({ children }) {
  const queryClient = useQueryClient()
  const me = useQuery({ queryKey: ME, queryFn: fetchMe, staleTime: 5 * 60_000, retry: 1 })

  // Follow the signed-in user's language preference.
  const userLocale = me.data?.user?.locale
  useEffect(() => {
    if (userLocale && isSupported(userLocale) && userLocale !== i18n.language) {
      i18n.changeLanguage(userLocale)
    }
  }, [userLocale])

  // Remember signed in / out for the next visit (see hadSession); unknown while loading or on error.
  const signedIn = me.data === undefined ? null : Boolean(me.data)
  useEffect(() => {
    if (signedIn === null) return
    try {
      if (signedIn) localStorage.setItem(SESSION_HINT, '1')
      else localStorage.removeItem(SESSION_HINT)
    } catch {
      // Storage unavailable (private mode): "/" then waits for the API, as before.
    }
  }, [signedIn])

  const setSession = useCallback((data) => queryClient.setQueryData(ME, data), [queryClient])

  const login = useCallback(
    async (credentials) => setSession((await api.post('/auth/login', credentials)).data),
    [setSession],
  )

  const loginDemo = useCallback(async () => setSession((await api.post('/auth/demo')).data), [setSession])

  const register = useCallback(
    async (details) => setSession((await api.post('/auth/register', { ...details, locale: i18n.language })).data),
    [setSession],
  )

  const logout = useCallback(async () => {
    try {
      await api.post('/auth/logout')
    } finally {
      // Preserve the active auth query and mark it anonymous. Clearing the
      // entire cache can make useQuery refetch /auth/me during logout, racing
      // the server response and leaving protected pages in an unauthenticated
      // error state instead of letting the route guard redirect to login.
      await queryClient.cancelQueries()
      queryClient.removeQueries({
        predicate: (query) => query.queryKey[0] !== ME[0] || query.queryKey[1] !== ME[1],
      })
      setSession(null)
    }
  }, [queryClient, setSession])

  const value = useMemo(
    () => ({
      status: me.isPending ? 'loading' : me.isError ? 'error' : me.data ? 'authenticated' : 'anonymous',
      error: me.error,
      retry: me.refetch,
      user: me.data?.user ?? null,
      company: me.data?.company ?? null,
      setSession,
      login,
      loginDemo,
      register,
      logout,
    }),
    [me.isPending, me.isError, me.error, me.refetch, me.data, setSession, login, loginDemo, register, logout],
  )

  return <AuthContext value={value}>{children}</AuthContext>
}

export function useAuth() {
  const context = use(AuthContext)
  if (!context) throw new Error('useAuth must be used inside <AuthProvider>')
  return context
}
