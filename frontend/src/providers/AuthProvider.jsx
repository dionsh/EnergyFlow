import { createContext, use, useCallback, useEffect, useMemo } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { api, ApiError } from '../lib/api'
import i18n from '../i18n'
import { isSupported } from '../i18n/languages'

const AuthContext = createContext(null)
const ME = ['auth', 'me']

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
      queryClient.clear()
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
