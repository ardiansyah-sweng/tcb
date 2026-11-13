import { afterEach, describe, expect, it, vi } from 'vitest'
import { clearToken, getToken, setToken, signOutReason } from '../auth/token'
import { mockApi, requestsOf, task, user } from '../test/api'
import { api, request } from './client'
import { ApiError } from './errors'

afterEach(() => {
  clearToken()
  localStorage.clear()
  vi.unstubAllGlobals()
})

describe('API client (generated from docs/tcb_openapi.yaml)', () => {
  it('calls /api/v1 with the stored token as Bearer', async () => {
    setToken('1|secret')
    const fetchMock = mockApi({ 'GET /me': { status: 200, body: user } })

    const me = await request(api.GET('/me'))

    expect(me.wip_limit).toBe(1)
    const [req] = requestsOf(fetchMock)
    expect(new URL(req.url).pathname).toBe('/api/v1/me')
    expect(req.headers.get('Authorization')).toBe('Bearer 1|secret')
    expect(req.headers.get('Accept')).toBe('application/json')
  })

  it('sends no Authorization header without a token', async () => {
    const fetchMock = mockApi({ 'GET /health': { status: 200, body: { status: 'ok', database: 'ok' } } })
    await request(api.GET('/health'))
    expect(requestsOf(fetchMock)[0].headers.has('Authorization')).toBe(false)
  })

  it('serializes query parameters from the contract', async () => {
    setToken('1|secret')
    const fetchMock = mockApi({ 'GET /tasks': { status: 200, body: { data: [task()], next_cursor: null } } })
    const page = await request(api.GET('/tasks', { params: { query: { state: 'running' } } }))
    expect(page.data).toHaveLength(1)
    expect(new URL(requestsOf(fetchMock)[0].url).searchParams.get('state')).toBe('running')
  })

  it('throws ApiError with the API message on an error response', async () => {
    setToken('1|secret')
    mockApi({
      'POST /tasks/TCB-31/transitions': {
        status: 409,
        body: { code: 'invalid_transition', message: 'Task berstatus New tidak bisa langsung Running.' },
      },
    })
    const call = request(
      api.POST('/tasks/{taskId}/transitions', { params: { path: { taskId: 'TCB-31' } }, body: { to: 'running' } }),
    )
    await expect(call).rejects.toBeInstanceOf(ApiError)
    await expect(call).rejects.toMatchObject({ status: 409, code: 'invalid_transition' })
  })

  it('turns a network failure into a readable ApiError', async () => {
    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new TypeError('Failed to fetch')))
    await expect(request(api.GET('/health'))).rejects.toMatchObject({
      status: 0,
      message: 'API tidak bisa dihubungi. Periksa koneksi, lalu coba lagi.',
    })
  })

  it('clears the token when the API answers 401', async () => {
    setToken('1|expired')
    mockApi({ 'GET /me': { status: 401, body: { code: 'unauthenticated', message: 'Silakan masuk terlebih dahulu.' } } })
    await expect(request(api.GET('/me'))).rejects.toMatchObject({ status: 401 })
    expect(getToken()).toBeNull()
    expect(signOutReason()).toBe('expired')
  })

  it('keeps the token on other errors', async () => {
    setToken('1|secret')
    mockApi({ 'GET /me': { status: 500, body: { message: 'Server Error' } } })
    await expect(request(api.GET('/me'))).rejects.toMatchObject({ status: 500 })
    expect(getToken()).toBe('1|secret')
  })
})
