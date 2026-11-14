import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, request, type Schemas } from './client'
import { recommendationsQueryKey } from './queryKeys'

export type Objective = Schemas['Objective']
export type ObjectiveDetail = Schemas['ObjectiveDetail']
export type KeyResult = Schemas['KeyResult']
export type KeyResultInput = Schemas['KeyResultInput']

export const activeObjectivesQueryKey = ['objectives', { status: 'active' }] as const
export const objectiveDetailQueryKey = (id: number) => ['objectives', 'detail', id] as const
/** Under ['tasks'], so a transition elsewhere refreshes it too. */
export const orphanTasksQueryKey = ['tasks', 'orphans'] as const

/** Not Done or Cancelled: a finished orphan task is not worth linking. */
export const OPEN_STATES = 'new,ready,running,waiting,suspended'

/** GET /objectives?status=active (Papan status filter, Goal & Key Result). */
export function useActiveObjectives() {
  return useQuery({
    queryKey: activeObjectivesQueryKey,
    queryFn: () => request(api.GET('/objectives', { params: { query: { status: 'active' } } })),
  })
}

/** GET /objectives/{id}: the Objective with its Key Results and task counts. */
export function useObjectiveDetail(id: number) {
  return useQuery({
    queryKey: objectiveDetailQueryKey(id),
    queryFn: () => request(api.GET('/objectives/{objectiveId}', { params: { path: { objectiveId: id } } })),
  })
}

/** Open orphan tasks (first page of GET /tasks, 50 tasks). */
export function useOrphanTasks() {
  return useQuery({
    queryKey: orphanTasksQueryKey,
    queryFn: () => request(api.GET('/tasks', { params: { query: { orphan: true, state: OPEN_STATES } } })),
  })
}

/**
 * Goal changes move Objective progress, task KR titles and orphan flags, and
 * the goal component of the recommendation score: refresh all three.
 */
function useRefreshGoals() {
  const queryClient = useQueryClient()
  return () =>
    Promise.all([
      queryClient.invalidateQueries({ queryKey: ['objectives'] }),
      queryClient.invalidateQueries({ queryKey: ['tasks'] }),
      queryClient.invalidateQueries({ queryKey: recommendationsQueryKey }),
    ])
}

export function useCreateObjective() {
  const refresh = useRefreshGoals()
  return useMutation({
    mutationFn: (body: Schemas['ObjectiveInput']) => request(api.POST('/objectives', { body })),
    onSuccess: refresh,
  })
}

/** PATCH /objectives/{id}: fields, or { status: 'archived' }. */
export function useUpdateObjective() {
  const refresh = useRefreshGoals()
  return useMutation({
    mutationFn: ({ id, body }: { id: number; body: Schemas['ObjectiveUpdate'] }) =>
      request(api.PATCH('/objectives/{objectiveId}', { params: { path: { objectiveId: id } }, body })),
    onSuccess: refresh,
  })
}

export function useCreateKeyResult() {
  const refresh = useRefreshGoals()
  return useMutation({
    mutationFn: ({ objectiveId, body }: { objectiveId: number; body: KeyResultInput }) =>
      request(api.POST('/objectives/{objectiveId}/key-results', { params: { path: { objectiveId } }, body })),
    onSuccess: refresh,
  })
}

export function useUpdateKeyResult() {
  const refresh = useRefreshGoals()
  return useMutation({
    mutationFn: ({ id, body }: { id: number; body: KeyResultInput }) =>
      request(api.PATCH('/key-results/{keyResultId}', { params: { path: { keyResultId: id } }, body })),
    onSuccess: refresh,
  })
}

/** DELETE /key-results/{id}: its tasks become orphans (R1-05). */
export function useDeleteKeyResult() {
  const refresh = useRefreshGoals()
  return useMutation({
    mutationFn: (id: number) => request(api.DELETE('/key-results/{keyResultId}', { params: { path: { keyResultId: id } } })),
    onSuccess: refresh,
  })
}

/** PATCH /tasks/{id} { key_result_id }: link an orphan task to a Key Result. */
export function useLinkTask() {
  const refresh = useRefreshGoals()
  return useMutation({
    mutationFn: ({ taskId, keyResultId }: { taskId: number; keyResultId: number }) =>
      request(api.PATCH('/tasks/{taskId}', { params: { path: { taskId: String(taskId) } }, body: { key_result_id: keyResultId } })),
    onSuccess: refresh,
  })
}
