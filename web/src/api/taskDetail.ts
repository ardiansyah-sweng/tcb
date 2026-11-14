import { useMutation, useQueries, useQuery } from '@tanstack/react-query'
import { api, request, type Schemas } from './client'
import { activeObjectivesQueryKey, objectiveDetailQueryKey } from './objectives'
import { taskSubQueryKey } from './queryKeys'
import { useRefreshAfterChange } from './tasks'

/** Data and changes of the Detail task (R1-19). Sub-resources use the numeric ID. */

export type TaskUpdate = Schemas['TaskUpdate']
export type ContextSnapshot = Schemas['ContextSnapshot']
export type Transition = Schemas['Transition']
export type Dependency = Schemas['Dependency']
export type Link = Schemas['Link']

/** GET /tasks/{ref}; `ref` is what the URL holds (TCB-31 or 31). */
export function useTaskDetailByRef(ref: string) {
  return useQuery({
    queryKey: taskSubQueryKey('detail', ref),
    queryFn: () => request(api.GET('/tasks/{taskId}', { params: { path: { taskId: ref } } })),
  })
}

export function useContexts(taskId: number) {
  return useQuery({
    queryKey: taskSubQueryKey('contexts', taskId),
    queryFn: () => request(api.GET('/tasks/{taskId}/contexts', { params: { path: { taskId: String(taskId) } } })),
  })
}

export function useTransitionLog(taskId: number) {
  return useQuery({
    queryKey: taskSubQueryKey('transitions', taskId),
    queryFn: () => request(api.GET('/tasks/{taskId}/transitions', { params: { path: { taskId: String(taskId) } } })),
  })
}

export function useDependencies(taskId: number) {
  return useQuery({
    queryKey: taskSubQueryKey('dependencies', taskId),
    queryFn: () => request(api.GET('/tasks/{taskId}/dependencies', { params: { path: { taskId: String(taskId) } } })),
  })
}

export type KeyResultGroup = { objective: string; keyResults: { id: number; title: string }[] }

/**
 * Key Results of the active Objectives, grouped per Objective, for the Key
 * Result picker. GET /objectives has no Key Results, so each Objective is
 * read with GET /objectives/{id}; only while the picker is open.
 */
export function useKeyResultGroups(enabled: boolean) {
  const objectives = useQuery({
    queryKey: activeObjectivesQueryKey,
    queryFn: () => request(api.GET('/objectives', { params: { query: { status: 'active' } } })),
    enabled,
  })
  const list = objectives.data?.data ?? []
  const details = useQueries({
    queries: list.map((o) => ({
      queryKey: objectiveDetailQueryKey(o.id),
      queryFn: () => request(api.GET('/objectives/{objectiveId}', { params: { path: { objectiveId: o.id } } })),
      enabled,
    })),
  })
  const groups: KeyResultGroup[] = list.map((o, i) => ({
    objective: o.title,
    keyResults: (details[i]?.data?.key_results ?? []).map((kr) => ({ id: kr.id, title: kr.title })),
  }))
  const isPending = objectives.isPending || details.some((d) => d.isPending)
  const error = objectives.error ?? details.find((d) => d.error)?.error ?? null
  return { groups, isPending, error }
}

/** PATCH /tasks/{id} (not the state: that goes through the transition endpoint). */
export function useUpdateTask() {
  const refresh = useRefreshAfterChange()
  return useMutation({
    mutationFn: ({ taskId, body }: { taskId: number; body: TaskUpdate }) =>
      request(api.PATCH('/tasks/{taskId}', { params: { path: { taskId: String(taskId) } }, body })),
    onSuccess: refresh,
  })
}

/** POST /tasks with a parent: a subtask needs only its title. */
export function useAddSubtask() {
  const refresh = useRefreshAfterChange()
  return useMutation({
    mutationFn: ({ parentId, title }: { parentId: number; title: string }) =>
      request(api.POST('/tasks', { body: { title, parent_task_id: parentId } as Schemas['TaskCreate'] })),
    onSuccess: refresh,
  })
}

export function useAddDependency() {
  const refresh = useRefreshAfterChange()
  return useMutation({
    mutationFn: ({ taskId, body }: { taskId: number; body: Schemas['DependencyInput'] }) =>
      request(api.POST('/tasks/{taskId}/dependencies', { params: { path: { taskId: String(taskId) } }, body })),
    onSuccess: refresh,
  })
}

/** Resolve (external blocker only) or delete a dependency. */
export function useChangeDependency() {
  const refresh = useRefreshAfterChange()
  return useMutation({
    mutationFn: ({ id, action }: { id: number; action: 'resolve' | 'delete' }) =>
      action === 'resolve'
        ? request(api.PATCH('/dependencies/{dependencyId}', { params: { path: { dependencyId: id } }, body: { status: 'resolved' } }))
        : request(api.DELETE('/dependencies/{dependencyId}', { params: { path: { dependencyId: id } } })),
    onSuccess: refresh,
  })
}

/**
 * Linked documents live in the context snapshots (SRS: a context holds its
 * links). Changing them saves a new context without a state change: the
 * draft (latest context, current next step and units) with the new links,
 * so the latest context keeps its notes (decision 14 Nov 2026).
 */
export function useSaveLinks() {
  const refresh = useRefreshAfterChange()
  return useMutation({
    mutationFn: async ({ taskId, change }: { taskId: number; change: (links: Link[]) => Link[] }) => {
      const path = { taskId: String(taskId) }
      const draft = await request(api.GET('/tasks/{taskId}/contexts/draft', { params: { path } }))
      return request(api.POST('/tasks/{taskId}/contexts', { params: { path }, body: { ...draft, links: change(draft.links ?? []) } }))
    },
    onSuccess: refresh,
  })
}
