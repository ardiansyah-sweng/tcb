export type Health = {
  status: 'ok'
  database: 'ok' | 'error'
}

export async function fetchHealth(): Promise<Health> {
  const res = await fetch('/api/v1/health', { headers: { Accept: 'application/json' } })
  // 503 still carries a valid health body (database unreachable).
  if (res.ok || res.status === 503) {
    return (await res.json()) as Health
  }
  throw new Error(`Health check failed with status ${res.status}`)
}
