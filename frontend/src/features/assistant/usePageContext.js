import { useMemo } from 'react'
import { useLocation } from 'react-router-dom'

/**
 * What the user is looking at, so a question like "why?" is understood: the page,
 * and the machine, waste event or opportunity open on it (from the URL the
 * pages already use).
 */
export function usePageContext() {
  const { pathname, search } = useLocation()
  return useMemo(() => {
    const params = new URLSearchParams(search)
    const [first, second] = pathname.split('/').filter(Boolean)
    const context = { page: first ?? 'overview' }
    const id = (value) => (/^\d+$/.test(value ?? '') ? Number(value) : undefined)
    if (first === 'machines' && id(second)) {
      context.page = 'machine'
      context.machine_id = id(second)
    }
    if (first === 'waste' && id(params.get('event'))) context.waste_event_id = id(params.get('event'))
    if (first === 'opportunities' && id(params.get('opportunity'))) context.recommendation_id = id(params.get('opportunity'))
    return context
  }, [pathname, search])
}
