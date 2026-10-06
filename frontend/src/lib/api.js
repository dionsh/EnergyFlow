// Thin fetch wrapper for the EnergyFlow API.
// Same-origin by default (/api/v1 is proxied by Vite locally and by Vercel in
// production), so the session cookie is first-party and no CORS is involved.
const BASE_URL = import.meta.env.VITE_API_URL ?? '/api/v1'

export class ApiError extends Error {
  constructor(status, code, message, fields = {}) {
    super(message)
    this.name = 'ApiError'
    this.status = status
    this.code = code
    this.fields = fields
  }
}

async function request(path, { method = 'GET', body, signal } = {}) {
  const headers = { Accept: 'application/json', 'X-EF-Client': 'web' }
  if (body !== undefined) headers['Content-Type'] = 'application/json'

  let response
  try {
    response = await fetch(BASE_URL + path, {
      method,
      headers,
      credentials: 'include',
      body: body === undefined ? undefined : JSON.stringify(body),
      signal,
    })
  } catch (error) {
    if (error.name === 'AbortError') throw error
    throw new ApiError(0, 'network_error', 'Could not reach the server.')
  }

  if (response.status === 204) return { data: null, meta: {} }

  let payload = null
  try {
    payload = await response.json()
  } catch {
    // Non-JSON response (e.g. a proxy error page) — handled below.
  }

  if (!response.ok) {
    const error = payload?.error ?? {}
    throw new ApiError(
      response.status,
      error.code ?? (response.status >= 500 ? 'server_error' : 'request_failed'),
      error.message ?? response.statusText,
      error.fields ?? {},
    )
  }
  return payload ?? { data: null, meta: {} }
}

export const api = {
  get: (path, options) => request(path, { ...options, method: 'GET' }),
  post: (path, body, options) => request(path, { ...options, method: 'POST', body: body ?? {} }),
  patch: (path, body, options) => request(path, { ...options, method: 'PATCH', body }),
  put: (path, body, options) => request(path, { ...options, method: 'PUT', body }),
  delete: (path, options) => request(path, { ...options, method: 'DELETE' }),
}
