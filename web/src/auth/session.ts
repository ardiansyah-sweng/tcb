import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useSyncExternalStore } from 'react'
import { api, request, type Schemas } from '../api/client'
import { meQueryKey } from '../api/queryKeys'
import { clearToken, getToken, setToken, subscribeToken } from './token'

/** Current token; re-renders when it is set or cleared (also from another tab). */
export function useToken(): string | null {
  return useSyncExternalStore(subscribeToken, getToken)
}

export function useMe() {
  const token = useToken()
  return useQuery({
    queryKey: meQueryKey,
    queryFn: () => request(api.GET('/me')),
    enabled: token !== null,
    staleTime: 5 * 60_000,
  })
}

export function useLogin() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (credentials: Schemas['LoginRequest']) =>
      request(api.POST('/auth/login', { body: { ...credentials, device_name: 'web' } })),
    onSuccess: (data) => {
      queryClient.clear()
      queryClient.setQueryData(meQueryKey, data.user)
      setToken(data.token)
    },
  })
}

export function useLogout() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async () => {
      try {
        await request(api.POST('/auth/logout'))
      } catch {
        // Unreachable or already revoked: still forget the token here.
      }
    },
    onSettled: () => {
      clearToken('logout')
      queryClient.clear()
    },
  })
}
