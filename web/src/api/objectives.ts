import { useQuery } from '@tanstack/react-query'
import { api, request, type Schemas } from './client'

export type Objective = Schemas['Objective']

export const activeObjectivesQueryKey = ['objectives', { status: 'active' }] as const

/** GET /objectives?status=active, for the Objective filter of the Papan status. */
export function useActiveObjectives() {
  return useQuery({
    queryKey: activeObjectivesQueryKey,
    queryFn: () => request(api.GET('/objectives', { params: { query: { status: 'active' } } })),
  })
}
