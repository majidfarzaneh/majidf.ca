// Thin fetch wrapper for the Laravel API (same origin, Sanctum cookie auth + CSRF).

export class ApiError extends Error {
  status: number
  errors: Record<string, string[]>

  constructor(status: number, message: string, errors: Record<string, string[]> = {}) {
    super(message)
    this.status = status
    this.errors = errors
  }

  /** First validation message for a field, if any. */
  field(name: string): string | undefined {
    return this.errors[name]?.[0]
  }
}

function xsrfToken(): string | null {
  const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/)
  return match ? decodeURIComponent(match[1]) : null
}

async function ensureCsrf(force = false) {
  if (force || !xsrfToken()) {
    await fetch('/sanctum/csrf-cookie', { credentials: 'same-origin' })
  }
}

type Method = 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE'

async function request<T>(method: Method, path: string, body?: unknown, retry = true): Promise<T> {
  const isForm = body instanceof FormData
  if (method !== 'GET') await ensureCsrf()

  const headers: Record<string, string> = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
  const token = xsrfToken()
  if (token) headers['X-XSRF-TOKEN'] = token
  // The browser sets the multipart boundary itself for FormData.
  if (body !== undefined && !isForm) headers['Content-Type'] = 'application/json'

  const res = await fetch(`/api${path}`, {
    method,
    headers,
    credentials: 'same-origin',
    body: body === undefined ? undefined : isForm ? body : JSON.stringify(body),
  })

  // Expired CSRF token: refresh once and retry.
  if (res.status === 419 && retry) {
    await ensureCsrf(true)
    return request<T>(method, path, body, false)
  }

  if (res.status === 204) return undefined as T
  const data = await res.json().catch(() => ({}))

  if (!res.ok) {
    const message = (data && typeof data.message === 'string' && data.message) || `Request failed (${res.status})`
    throw new ApiError(res.status, message, data?.errors ?? {})
  }
  return data as T
}

export const api = {
  get: <T>(path: string) => request<T>('GET', path),
  post: <T>(path: string, body: unknown = {}) => request<T>('POST', path, body),
  put: <T>(path: string, body: unknown = {}) => request<T>('PUT', path, body),
  patch: <T>(path: string, body: unknown = {}) => request<T>('PATCH', path, body),
  delete: <T>(path: string) => request<T>('DELETE', path),
  /** POST multipart form data (file uploads). */
  upload: <T>(path: string, form: FormData) => request<T>('POST', path, form),
}

/** Turn any thrown error into a sentence a person can read. */
export function errorMessage(err: unknown): string {
  if (err instanceof ApiError) {
    const first = Object.values(err.errors)[0]?.[0]
    return first ?? err.message
  }
  return err instanceof Error ? err.message : 'Something went wrong.'
}

/** Build a query string, skipping empty values. */
export function qs(params: Record<string, string | number | boolean | undefined | null>): string {
  const search = new URLSearchParams()
  for (const [k, v] of Object.entries(params)) {
    if (v !== undefined && v !== null && v !== '') search.set(k, String(v))
  }
  const s = search.toString()
  return s ? `?${s}` : ''
}
