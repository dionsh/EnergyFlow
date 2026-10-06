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
