import createClient, { type Middleware } from 'openapi-fetch'
import { clearToken, getToken } from '../auth/token'
import { ApiError } from './errors'
import type { components, paths } from './schema'

/**
 * Typed client for docs/tcb_openapi.yaml. `schema.d.ts` is generated
 * (`npm run gen:api`); do not edit it by hand.
 */

export type Schemas = components['schemas']

const auth: Middleware = {
  onRequest({ request }) {
    request.headers.set('Accept', 'application/json')
    const token = getToken()
    if (token !== null) request.headers.set('Authorization', `Bearer ${token}`)
    return request
  },
  onResponse({ response }) {
    // Missing, expired, or revoked token: drop it so the app shows the login page.
    if (response.status === 401 && getToken() !== null) clearToken('expired')
    return undefined
  },
}

export const api = createClient<paths>({
  // Absolute URL: Request() needs one outside the browser (tests).
  baseUrl: `${window.location.origin}/api/v1`,
  // Look fetch up on every call so tests can stub it after import.
  fetch: (request) => globalThis.fetch(request),
})
api.use(auth)

type Result<D> = { data?: D; error?: unknown; response: Response }

/** Awaits an `api.*` call: returns the data or throws an ApiError. */
export async function request<D>(call: Promise<Result<D>>): Promise<D> {
  let result: Result<D>
  try {
    result = await call
  } catch (cause) {
    throw ApiError.network(cause)
  }
  if (!result.response.ok) throw ApiError.fromResponse(result.response.status, result.error)
  return result.data as D
}
