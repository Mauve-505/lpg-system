// ---------------------------------------------------------------------------
// API client
// - Same-origin cookie session
// - CSRF token is fetched once and attached to every state-changing request
// - Owner branch scope travels in the X-Branch-Id header
// - A 419 (expired CSRF) is retried once after refreshing the token
// ---------------------------------------------------------------------------

let csrfToken = null
let csrfPromise = null
let branchId = null

export const hooks = {
  onUnauth() {},
  onPasswordRequired() {},
}

export class ApiError extends Error {
  constructor(message, { status = 0, code = 'request_failed', field = null, extra = null } = {}) {
    super(message)
    this.name = 'ApiError'
    this.status = status
    this.code = code
    this.field = field
    this.extra = extra
  }
}

export function setBranch(id) {
  branchId = id === null || id === undefined || id === '' ? null : String(id)
}

export function getBranch() {
  return branchId
}

/**
 * Run a request against one concrete branch without changing the owner's
 * global branch view. Used by write forms when the owner is viewing
 * "all branches".
 */
export async function withBranch(id, fn) {
  const previous = branchId
  branchId = id === null || id === undefined || id === '' ? null : String(id)
  try {
    return await fn()
  } finally {
    branchId = previous
  }
}

export function setCsrfToken(token) {
  if (token) csrfToken = String(token)
}

export function clearCsrfToken() {
  csrfToken = null
}

async function fetchCsrf(force = false) {
  if (csrfToken && !force) return csrfToken
  if (!csrfPromise) {
    csrfPromise = fetch('/api/csrf', {
      method: 'GET',
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    })
      .then(async res => {
        const data = await res.json().catch(() => ({}))
        if (!res.ok || !data.csrf_token) {
          throw new ApiError(data.error || 'Could not start a secure session.', {
            status: res.status,
            code: data.code || 'csrf_unavailable',
          })
        }
        csrfToken = data.csrf_token
        return csrfToken
      })
      .finally(() => { csrfPromise = null })
  }
  return csrfPromise
}

export function ensureCsrf() {
  return fetchCsrf()
}

export function query(params = {}) {
  const q = new URLSearchParams()
  Object.entries(params).forEach(([k, v]) => {
    if (v === null || v === undefined || v === '') return
    q.set(k, String(v))
  })
  const s = q.toString()
  return s ? '?' + s : ''
}

function errorFrom(data, res) {
  const body = data && typeof data === 'object' ? data : {}
  return new ApiError(body.error || `Request failed (${res.status}).`, {
    status: res.status,
    code: body.code || 'request_failed',
    field: body.field || null,
    extra: body,
  })
}

async function request(path, { method = 'GET', body, retry = true, raw = false } = {}) {
  const verb = method.toUpperCase()
  const headers = { Accept: 'application/json' }
  const hasBody = body !== undefined
  if (hasBody) headers['Content-Type'] = 'application/json'
  if (!['GET', 'HEAD', 'OPTIONS'].includes(verb)) {
    headers['X-CSRF-Token'] = await fetchCsrf()
  }
  if (branchId) headers['X-Branch-Id'] = branchId

  let res
  try {
    res = await fetch('/api/' + String(path).replace(/^\/+/, ''), {
      method: verb,
      credentials: 'same-origin',
      headers,
      body: hasBody ? JSON.stringify(body) : undefined,
    })
  } catch {
    throw new ApiError('Cannot reach the server. Check your connection and try again.', { code: 'network_error' })
  }

  if (res.status === 419 && retry) {
    await fetchCsrf(true)
    return request(path, { method, body, retry: false, raw })
  }

  if (raw) {
    if (!res.ok) {
      const data = await res.json().catch(() => ({}))
      handleAuthStatus(res.status, data, path)
      throw errorFrom(data, res)
    }
    return res
  }

  const contentType = res.headers.get('content-type') || ''
  const data = contentType.includes('application/json')
    ? await res.json().catch(() => ({}))
    : await res.text().catch(() => '')

  if (!res.ok) {
    handleAuthStatus(res.status, data, path)
    throw errorFrom(data, res)
  }
  if (data && typeof data === 'object' && data.csrf_token) csrfToken = data.csrf_token
  return data
}

function handleAuthStatus(status, data, path = '') {
  const body = data && typeof data === 'object' ? data : {}
  const authAttempt = /^(login|register|logout)$/.test(String(path).replace(/^\/+/, ''))
  if (status === 401 && !authAttempt) {
    csrfToken = null
    hooks.onUnauth()
  }
  if (status === 403 && body.code === 'password_change_required') {
    hooks.onPasswordRequired()
  }
}

export const get = path => request(path)

/** GET a paginated endpoint and return the rows plus pagination headers. */
export async function getPage(path) {
  const res = await request(path, { raw: true })
  const data = await res.json().catch(() => [])
  const total = Number(res.headers.get('x-total-count') || (Array.isArray(data) ? data.length : 0))
  const per = Number(res.headers.get('x-per-page') || 50)
  const page = Number(res.headers.get('x-page') || 1)
  return { data, total, per, page, pages: Math.max(1, Math.ceil(total / Math.max(1, per))) }
}

export const post = (path, body = {}) => request(path, { method: 'POST', body })
export const put = (path, body = {}) => request(path, { method: 'PUT', body })
export const patch = (path, body = {}) => request(path, { method: 'PATCH', body })
export const del = (path, body) => request(path, { method: 'DELETE', body })

/** Download a CSV report. Adds format=csv to the query string. */
export async function downloadCsv(path, fallbackName = 'export.csv') {
  const url = path + (path.includes('?') ? '&' : '?') + 'format=csv'
  const res = await request(url, { raw: true })
  const blob = await res.blob()
  const disposition = res.headers.get('content-disposition') || ''
  const match = /filename="?([^";]+)"?/i.exec(disposition)
  const name = match ? match[1] : fallbackName
  const href = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = href
  a.download = name
  document.body.appendChild(a)
  a.click()
  a.remove()
  setTimeout(() => URL.revokeObjectURL(href), 1000)
}

/** Public endpoint helper (no session required by the server). */
export const publicGet = path => fetch('/api/' + String(path).replace(/^\/+/, ''), {
  credentials: 'same-origin',
  headers: { Accept: 'application/json' },
}).then(async res => {
  const data = await res.json().catch(() => ({}))
  if (!res.ok) throw errorFrom(data, res)
  return data
})
