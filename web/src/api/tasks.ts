import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, request, type Schemas } from './client'
import { recommendationsQueryKey, runningTasksQueryKey, taskDetailQueryKey, waitingTasksQueryKey } from './queryKeys'

/** Task queries and mutations shared by the screens. */

export type Task = Schemas['Task']
export type TaskDetail = Schemas['TaskDetail']
export type ContextInput = Schemas['ContextInput']
export type TransitionRequest = Schemas['TransitionRequest']

export function useRunningTasks() {
  return useQuery({
    queryKey: runningTasksQueryKey,
    queryFn: () => request(api.GET('/tasks', { params: { query: { state: 'running' } } })),
  })
}

export function useWaitingTasks() {
  return useQuery({
    queryKey: waitingTasksQueryKey,
    queryFn: () => request(api.GET('/tasks', { params: { query: { state: 'waiting' } } })),
  })
}

export function useTaskDetail(taskId: number) {
  return useQuery({
    queryKey: taskDetailQueryKey(taskId),
    queryFn: () => request(api.GET('/tasks/{taskId}', { params: { path: { taskId: String(taskId) } } })),
  })
}

/** Refreshes everything a state change can affect: task lists, details, and recommendations. */
export function useRefreshAfterChange() {
  const queryClient = useQueryClient()
  return () =>
    Promise.all([
      queryClient.invalidateQueries({ queryKey: ['tasks'] }),
      queryClient.invalidateQueries({ queryKey: recommendationsQueryKey }),
    ])
}

/** POST /tasks/{taskId}/transitions. Each caller gets its own error state. */
export function useTransitionTask() {
  const refresh = useRefreshAfterChange()
  return useMutation({
    mutationFn: ({ taskId, body }: { taskId: number; body: TransitionRequest }) =>
      request(api.POST('/tasks/{taskId}/transitions', { params: { path: { taskId: String(taskId) } }, body })),
    onSuccess: refresh,
  })
}
