import { QueryClient } from '@tanstack/react-query'
import { ApiError } from './api/errors'

/** Client errors (4xx) will not succeed on retry; network and 5xx may. */
function shouldRetry(failureCount: number, error: unknown): boolean {
  if (error instanceof ApiError && error.status >= 400 && error.status < 500) return false
  return failureCount < 2
}

export function createQueryClient(options: { retry?: false } = {}): QueryClient {
  return new QueryClient({
    defaultOptions: {
      queries: { retry: options.retry ?? shouldRetry, staleTime: 30_000 },
      mutations: { retry: false },
    },
  })
}
