import { api, type Schemas } from './client'

export type Health = Schemas['Health']

export async function fetchHealth(): Promise<Health> {
  const { data, error, response } = await api.GET('/health')
  if (data !== undefined) return data
  // 503 still carries a valid health body (database unreachable).
  if (response.status === 503 && error !== undefined) return error
  throw new Error(`Health check failed with status ${response.status}`)
}
