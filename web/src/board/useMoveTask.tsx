import { useCallback, useState, type ReactNode } from 'react'
import { api, request } from '../api/client'
import { ApiError } from '../api/errors'
import { useRefreshAfterChange, type Task } from '../api/tasks'
import { runningFromWipFull, type DialogRequest } from '../home/actions'
import { ContextDialog } from '../home/ContextDialog'
import { STATE_LABELS, type State } from './format'
import { ReasonDialog } from './ReasonDialog'

type Dialog = { kind: 'context'; request: DialogRequest } | { kind: 'reason'; task: Task; to: 'waiting' | 'cancelled' }

/**
 * Moves a task to another state the way the Papan status does (R1-18), also
 * used by the Detail task (R1-19). The API is asked first: the rules live in
 * allowed_transitions (CLAUDE.md rule 2), so the client never decides itself
 * whether a move is allowed. The answer picks the next step: 409
 * invalid_transition is shown as a message, 422 context_required or a reason
 * needed when leaving Running opens the context form, 422 reason_required
 * otherwise opens the reason form, and 409 wip_full opens the switch form.
 */
export function useMoveTask() {
  const refresh = useRefreshAfterChange()
  const [error, setError] = useState<unknown>(null)
  const [notice, setNotice] = useState<string | null>(null)
  const [dialog, setDialog] = useState<Dialog | null>(null)

  const closeDialog = useCallback(() => setDialog(null), [])

  async function move(task: Task, to: State) {
    if (task.state === to) return
    setError(null)
    setNotice(null)
    try {
      await request(api.POST('/tasks/{taskId}/transitions', { params: { path: { taskId: String(task.id) } }, body: { to } }))
      setNotice(`${task.task_key} dipindah ke ${STATE_LABELS[to]}.`)
      await refresh()
    } catch (caught) {
      const fromRunning = task.state === 'running'
      const code = caught instanceof ApiError ? caught.code : null
      if (code === 'wip_full') {
        setDialog({
          kind: 'context',
          request: { mode: 'switch', target: task, recommendationId: null, leaving: runningFromWipFull((caught as ApiError).body) },
        })
      } else if (fromRunning && (code === 'context_required' || code === 'reason_required') && to !== 'cancelled') {
        const mode = to === 'waiting' ? 'block' : to === 'suspended' ? 'suspend' : 'pause'
        setDialog({ kind: 'context', request: { mode, task } })
      } else if (code === 'reason_required' && (to === 'waiting' || to === 'cancelled')) {
        setDialog({ kind: 'reason', task, to })
      } else {
        setError(caught)
      }
    }
  }

  let dialogs: ReactNode = null
  if (dialog?.kind === 'context') {
    dialogs = <ContextDialog request={dialog.request} onClose={closeDialog} />
  } else if (dialog?.kind === 'reason') {
    dialogs = (
      <ReasonDialog
        task={dialog.task}
        to={dialog.to}
        onClose={closeDialog}
        onDone={() => {
          setNotice(`${dialog.task.task_key} dipindah ke ${STATE_LABELS[dialog.to]}.`)
          setDialog(null)
        }}
      />
    )
  }

  return { move, error, notice, dialogs }
}
