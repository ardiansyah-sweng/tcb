import { vi } from 'vitest'

export type MockReply = { status: number; body?: unknown } | ((request: Request) => Response | Promise<Response>)

export function json(status: number, body?: unknown): Response {
  if (body === undefined) return new Response(null, { status })
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' },
  })
}

/**
 * Stubs global fetch with a route table keyed by "METHOD /path" (path relative
 * to /api/v1, without query string). Unmatched requests get a 404 not_found.
 * Returns the mock so tests can inspect the Request objects it received.
 */
export function mockApi(routes: Record<string, MockReply>) {
  const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
    const request = input instanceof Request ? input : new Request(new URL(String(input), window.location.origin), init)
    const path = new URL(request.url).pathname.replace(/^\/api\/v1/, '')
    const reply = routes[`${request.method} ${path}`]
    if (reply === undefined) return json(404, { code: 'not_found', message: 'Alamat tidak ditemukan.' })
    if (typeof reply === 'function') return reply(request)
    return json(reply.status, reply.body)
  })
  vi.stubGlobal('fetch', fetchMock)
  return fetchMock
}

/** Requests received by a mockApi() fetch mock, in order. */
export function requestsOf(fetchMock: ReturnType<typeof mockApi>): Request[] {
  return fetchMock.mock.calls.map(([input]) => input as Request)
}

export const user = {
  id: 1,
  name: 'Ardi',
  email: 'dosen@example.com',
  timezone: 'Asia/Jakarta',
  wip_limit: 1,
  score_weights: { goal: 0.35, urgency: 0.25, priority: 0.15, aging: 0.1, fit: 0.15 },
}

export function task(overrides: Record<string, unknown> = {}) {
  return {
    id: 31,
    task_key: 'TCB-31',
    title: 'Koreksi UTS kelas A',
    is_orphan: false,
    state: 'running',
    priority: 2,
    ...overrides,
  }
}
