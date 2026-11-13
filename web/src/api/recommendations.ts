import { useQuery } from '@tanstack/react-query'
import { api, request } from './client'
import { recommendationsQueryKey } from './queryKeys'

/**
 * GET /recommendations stores a new batch on every call, so it is fetched
 * once per visit and again only after something changes (invalidation).
 */
export function useRecommendations() {
  return useQuery({
    queryKey: recommendationsQueryKey,
    queryFn: () => request(api.GET('/recommendations', { params: { query: { limit: 3 } } })),
    staleTime: Infinity,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
  })
}
