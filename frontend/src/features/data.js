import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { api } from '../lib/api'

// Polling intervals from docs/03-architecture.md §14. Queries pause while the tab is hidden.
const LIVE_MS = 5_000
const OVERVIEW_MS = 30_000

const get = async (path) => {
  const { data, meta } = await api.get(path)
  return { data, meta }
}

export function useLive() {
  return useQuery({ queryKey: ['live'], queryFn: () => get('/live'), refetchInterval: LIVE_MS, placeholderData: keepPreviousData })
}

export function useOverview() {
  return useQuery({ queryKey: ['overview'], queryFn: () => get('/overview'), refetchInterval: OVERVIEW_MS, placeholderData: keepPreviousData })
}

export function useScore() {
  return useQuery({ queryKey: ['score'], queryFn: () => get('/score'), staleTime: 5 * 60_000, placeholderData: keepPreviousData })
}

export function useEnergyFlow() {
  return useQuery({ queryKey: ['energy-flow'], queryFn: () => get('/energy-flow'), refetchInterval: 5 * 60_000, placeholderData: keepPreviousData })
}

export function useMachines() {
  return useQuery({ queryKey: ['machines'], queryFn: () => get('/machines'), refetchInterval: 15_000, placeholderData: keepPreviousData })
}

export function useMachine(id) {
  return useQuery({ queryKey: ['machine', id], queryFn: () => get(`/machines/${id}`), refetchInterval: 10_000, placeholderData: keepPreviousData })
}

export function useMachineSeries(id, range) {
  return useQuery({
    queryKey: ['machine', id, 'series', range],
    queryFn: () => get(`/machines/${id}/timeseries?range=${range}`),
    refetchInterval: range === '24h' ? 60_000 : false,
    placeholderData: keepPreviousData,
  })
}

export function useDevices() {
  return useQuery({ queryKey: ['devices'], queryFn: () => get('/devices'), refetchInterval: 10_000, placeholderData: keepPreviousData })
}

export function useDevice(id) {
  return useQuery({ queryKey: ['device', id], queryFn: () => get(`/devices/${id}`), refetchInterval: LIVE_MS, placeholderData: keepPreviousData })
}

const query = (params) => {
  const search = new URLSearchParams(Object.entries(params).filter(([, v]) => v !== undefined && v !== null && v !== ''))
  return search.size ? `?${search}` : ''
}

export function useWasteSummary(period) {
  return useQuery({ queryKey: ['waste', 'summary', period], queryFn: () => get(`/waste/summary${query({ period })}`), refetchInterval: 60_000, placeholderData: keepPreviousData })
}

export function useWasteEvents(params) {
  return useQuery({ queryKey: ['waste', 'events', params], queryFn: () => get(`/waste-events${query(params)}`), refetchInterval: 30_000, placeholderData: keepPreviousData })
}

/** One episode; while it is still going on the numbers keep ticking. */
export function useWasteEvent(id) {
  return useQuery({
    queryKey: ['waste', 'event', id],
    queryFn: () => get(`/waste-events/${id}`),
    enabled: Boolean(id),
    refetchInterval: (q) => (q.state.data?.data?.ongoing ? 10_000 : false),
  })
}

export function useAlert(id) {
  return useQuery({ queryKey: ['alerts', 'one', id], queryFn: () => get(`/alerts/${id}`), enabled: Boolean(id), refetchInterval: 30_000 })
}

export function useAlerts(status = 'active') {
  return useQuery({ queryKey: ['alerts', status], queryFn: () => get(`/alerts${query({ status })}`), refetchInterval: OVERVIEW_MS, placeholderData: keepPreviousData })
}

const TERMINAL = ['verified', 'failed', 'cancelled']

/** One command, polled every 1.5 s until the meter has confirmed (or the command failed). */
export function useCommand(id) {
  return useQuery({
    queryKey: ['command', id],
    queryFn: () => get(`/commands/${id}`),
    enabled: Boolean(id),
    refetchInterval: (q) => (TERMINAL.includes(q.state.data?.data?.status) ? false : 1_500),
  })
}

export function useCommands(params = {}) {
  return useQuery({ queryKey: ['commands', params], queryFn: () => get(`/commands${query(params)}`), refetchInterval: 15_000, placeholderData: keepPreviousData })
}

export function usePolicies() {
  return useQuery({ queryKey: ['policies'], queryFn: () => get('/policies'), refetchInterval: 60_000 })
}

export function useRecommendations(status = 'all') {
  return useQuery({ queryKey: ['recommendations', status], queryFn: () => get(`/recommendations${query({ status })}`), refetchInterval: 60_000, placeholderData: keepPreviousData })
}

export function useRecommendation(id) {
  return useQuery({ queryKey: ['recommendations', 'one', id], queryFn: () => get(`/recommendations/${id}`), enabled: Boolean(id) })
}

export function useWhatIfOptions() {
  return useQuery({ queryKey: ['what-if', 'options'], queryFn: () => get('/what-if/options'), staleTime: 5 * 60_000 })
}

/** A what-if replay. Cached per input, so moving a slider back is instant. */
export function useWhatIf(input) {
  return useQuery({
    queryKey: ['what-if', input],
    queryFn: async () => {
      const { data, meta } = await api.post('/what-if', input)
      return { data, meta }
    },
    enabled: Boolean(input?.machine_id && input?.action),
    placeholderData: keepPreviousData,
    staleTime: 60_000,
    retry: false,
  })
}

export function useImpact() {
  return useQuery({ queryKey: ['impact'], queryFn: () => get('/impact/summary'), refetchInterval: 60_000, placeholderData: keepPreviousData })
}

export function useIntervention(id) {
  return useQuery({ queryKey: ['impact', 'intervention', id], queryFn: () => get(`/impact/interventions/${id}`), enabled: Boolean(id) })
}

export function useCarbon(period) {
  return useQuery({ queryKey: ['carbon', 'summary', period], queryFn: () => get(`/carbon/summary${query({ period })}`), placeholderData: keepPreviousData })
}

export function useCarbonBreakdown(period, groupBy) {
  return useQuery({ queryKey: ['carbon', 'breakdown', period, groupBy], queryFn: () => get(`/carbon/breakdown${query({ period, group_by: groupBy })}`), placeholderData: keepPreviousData })
}

export function useVsme(year) {
  return useQuery({ queryKey: ['esg', 'vsme', year], queryFn: () => get(`/esg/vsme-b3${query({ year })}`) })
}

export function useReadiness() {
  return useQuery({ queryKey: ['esg', 'readiness'], queryFn: () => get('/esg/readiness') })
}

export function useReports() {
  return useQuery({ queryKey: ['reports'], queryFn: () => get('/reports') })
}

export function useReport(id) {
  return useQuery({ queryKey: ['reports', id], queryFn: () => get(`/reports/${id}`), enabled: Boolean(id) })
}

export function useNotifications() {
  return useQuery({ queryKey: ['notifications'], queryFn: () => get('/notifications'), refetchInterval: OVERVIEW_MS })
}

export function useBills() {
  return useQuery({ queryKey: ['bills'], queryFn: () => get('/bills') })
}

export function useFuelRecords() {
  return useQuery({ queryKey: ['fuel-records'], queryFn: () => get('/fuel-records') })
}

export function useMeterReadings() {
  return useQuery({ queryKey: ['meter-readings'], queryFn: () => get('/meter-readings') })
}

export function useConversation(id) {
  return useQuery({ queryKey: ['assistant', 'conversation', id], queryFn: () => get(`/assistant/conversations/${id}`), enabled: Boolean(id), retry: false })
}

export function useConversations(enabled) {
  return useQuery({ queryKey: ['assistant', 'conversations'], queryFn: () => get('/assistant/conversations'), enabled })
}

/** Starter questions for the page the user is on and what is happening right now. */
export function useAssistantSuggestions(context, language) {
  const params = { page: context.page, machine_id: context.machine_id, language }
  return useQuery({ queryKey: ['assistant', 'suggestions', params], queryFn: () => get(`/assistant/suggestions${query(params)}`), staleTime: 60_000 })
}

/**
 * Seconds since the last reading, measured on the company's clock (the demo runs
 * on virtual time): age at fetch time + time elapsed since the fetch.
 */
export function readingAge(query, now) {
  const payload = query.data?.data
  if (!payload?.last_reading_at || !payload?.now) return null
  const atFetch = (new Date(payload.now) - new Date(payload.last_reading_at)) / 1000
  return Math.max(0, Math.round(atFetch + (now - query.dataUpdatedAt) / 1000))
}
