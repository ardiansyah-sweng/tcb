import { useMutation } from '@tanstack/react-query'
import { createContext, useContext } from 'react'
import { api, request } from '../api/client'
import { ApiError } from '../api/errors'
import { useRefreshAfterChange, type Task } from '../api/tasks'

/**
 * Actions shared by the Beranda panels: opening the context form (Jeda,
 * Terblokir, pindah) and starting a task.
 */

export type DialogRequest =
  | { mode: 'pause' | 'block'; task: Task }
  /** `leaving`: the running tasks to choose from; defaults to GET /tasks?state=running. */
  | { mode: 'switch'; target: Task; recommendationId: number | null; leaving?: Task[] }

type HomeActions = { openDialog: (request: DialogRequest) => void }

export const HomeActionsContext = createContext<HomeActions | null>(null)

export function useHomeActions(): HomeActions {
  const actions = useContext(HomeActionsContext)
  if (actions === null) throw new Error('useHomeActions must be used inside HomeActionsContext')
  return actions
}

function runningFromWipFull(body: unknown): Task[] | undefined {
  if (typeof body !== 'object' || body === null || !('running' in body)) return undefined
  const running = (body as { running: unknown }).running
  return Array.isArray(running) && running.length > 0 ? (running as Task[]) : undefined
}

type StartInput = { task: Task; recommendationId: number | null; runningFull: boolean }

/**
 * Starts a task. With Running full it opens the switch form instead (POST
 * /switch records the recommendation itself). A 409 wip_full (Running filled
 * up meanwhile) opens the switch form as well. A started recommendation is
 * recorded as picked.
 */
export function useStartTask() {
  const { openDialog } = useHomeActions()
  const refresh = useRefreshAfterChange()

  return useMutation({
    mutationFn: async ({ task, recommendationId, runningFull }: StartInput) => {
      if (runningFull) {
        openDialog({ mode: 'switch', target: task, recommendationId })
        return
      }
      try {
        await request(api.POST('/tasks/{taskId}/transitions', { params: { path: { taskId: String(task.id) } }, body: { to: 'running' } }))
      } catch (error) {
        if (error instanceof ApiError && error.code === 'wip_full') {
          openDialog({ mode: 'switch', target: task, recommendationId, leaving: runningFromWipFull(error.body) })
          return
        }
        throw error
      }
      try {
        if (recommendationId !== null) {
          await request(
            api.POST('/recommendations/{recommendationId}/decision', {
              params: { path: { recommendationId } },
              body: { decision: 'picked' },
            }),
          )
        }
      } finally {
        await refresh()
      }
    },
  })
}
