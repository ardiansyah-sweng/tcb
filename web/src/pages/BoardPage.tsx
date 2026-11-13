import { useCallback, useId, useMemo, useState } from 'react'
import { api, request } from '../api/client'
import { ApiError } from '../api/errors'
import { useActiveObjectives } from '../api/objectives'
import { useRefreshAfterChange, type Task } from '../api/tasks'
import { useMe } from '../auth/session'
import { BoardColumn, type BoardFilters } from '../board/BoardColumn'
import { BOARD_STATES, STATE_LABELS, USUAL_LABELS, type State } from '../board/format'
import { ReasonDialog } from '../board/ReasonDialog'
import { ErrorMessage } from '../components/ErrorMessage'
import { runningFromWipFull, type DialogRequest } from '../home/actions'
import { ContextDialog } from '../home/ContextDialog'
import { DEFAULT_TIMEZONE } from '../home/format'

const ORPHAN = 'orphan'

const selectClass =
  'mt-1 w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-slate-900 focus:border-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-200'

type Dialog = { kind: 'context'; request: DialogRequest } | { kind: 'reason'; task: Task; to: 'waiting' | 'cancelled' }

/**
 * R1-18 Papan status (FR-18); layout from docs/mockup/tcb-v1/02-papan status.png.
 *
 * Dropping a card asks the API for the transition first: the rules live in
 * allowed_transitions (CLAUDE.md rule 2), so the board never decides itself
 * whether a move is allowed. The answer picks the next step: 409
 * invalid_transition is shown as a message, 422 context_required or a reason
 * needed when leaving Running opens the context form, 422 reason_required
 * otherwise opens the reason form, and 409 wip_full opens the switch form.
 */
export default function BoardPage() {
  const me = useMe()
  const timeZone = me.data?.timezone || DEFAULT_TIMEZONE
  const objectives = useActiveObjectives()
  const refresh = useRefreshAfterChange()
  const objectiveId = useId()
  const labelId = useId()

  const [objective, setObjective] = useState('')
  const [label, setLabel] = useState('')
  const [seenLabels, setSeenLabels] = useState<string[]>([])
  const [dragged, setDragged] = useState<Task | null>(null)
  const [moveError, setMoveError] = useState<unknown>(null)
  const [notice, setNotice] = useState<string | null>(null)
  const [dialog, setDialog] = useState<Dialog | null>(null)

  const filters = useMemo<BoardFilters>(() => {
    const result: BoardFilters = {}
    if (objective === ORPHAN) result.orphan = 'true'
    else if (objective !== '') result.objective_id = objective
    if (label !== '') result.label = label
    return result
  }, [objective, label])

  const onLabels = useCallback((labels: string[]) => {
    setSeenLabels((current) => {
      const missing = labels.filter((l) => !current.includes(l))
      return missing.length === 0 ? current : [...current, ...missing]
    })
  }, [])
  const labelOptions = [...new Set([...USUAL_LABELS, ...seenLabels, ...(label ? [label] : [])])].sort((a, b) => a.localeCompare(b, 'id'))

  const closeDialog = useCallback(() => setDialog(null), [])
  const onDragEnd = useCallback(() => setDragged(null), [])

  async function move(task: Task, to: State) {
    if (task.state === to) return
    setMoveError(null)
    setNotice(null)
    const done = `${task.task_key} dipindah ke ${STATE_LABELS[to]}.`
    try {
      await request(api.POST('/tasks/{taskId}/transitions', { params: { path: { taskId: String(task.id) } }, body: { to } }))
      setNotice(done)
      await refresh()
    } catch (error) {
      const fromRunning = task.state === 'running'
      const code = error instanceof ApiError ? error.code : null
      if (code === 'wip_full') {
        setDialog({
          kind: 'context',
          request: { mode: 'switch', target: task, recommendationId: null, leaving: runningFromWipFull((error as ApiError).body) },
        })
      } else if (fromRunning && (code === 'context_required' || code === 'reason_required') && to !== 'cancelled') {
        const mode = to === 'waiting' ? 'block' : to === 'suspended' ? 'suspend' : 'pause'
        setDialog({ kind: 'context', request: { mode, task } })
      } else if (code === 'reason_required' && (to === 'waiting' || to === 'cancelled')) {
        setDialog({ kind: 'reason', task, to })
      } else {
        setMoveError(error)
      }
    }
  }

  function drop(to: State) {
    const task = dragged
    setDragged(null)
    if (task) void move(task, to)
  }

  return (
    <div className="mx-auto max-w-full">
      <header className="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
        <div>
          <h1 className="text-3xl font-semibold text-slate-900 md:text-4xl">Papan status</h1>
          <p className="mt-1 text-slate-600">Ready diurutkan dari skor rekomendasi tertinggi. Kolom Running dibatasi WIP limit.</p>
        </div>
        <div className="grid gap-3 sm:grid-cols-2 lg:w-[32rem]">
          <div>
            <label htmlFor={objectiveId} className="block text-sm text-slate-600">
              Objective
            </label>
            <select id={objectiveId} className={selectClass} value={objective} onChange={(e) => setObjective(e.target.value)}>
              <option value="">Semua</option>
              {(objectives.data?.data ?? []).map((o) => (
                <option key={o.id} value={String(o.id)}>
                  {o.title}
                </option>
              ))}
              <option value={ORPHAN}>Tanpa KR (orphan)</option>
            </select>
          </div>
          <div>
            <label htmlFor={labelId} className="block text-sm text-slate-600">
              Jenis pekerjaan
            </label>
            <select id={labelId} className={selectClass} value={label} onChange={(e) => setLabel(e.target.value)}>
              <option value="">Semua</option>
              {labelOptions.map((l) => (
                <option key={l} value={l}>
                  {l}
                </option>
              ))}
            </select>
          </div>
        </div>
      </header>

      {objectives.isError && <ErrorMessage error={objectives.error} className="mt-4" />}
      {moveError !== null && <ErrorMessage error={moveError} className="mt-4" />}
      {notice && (
        <p aria-live="polite" className="mt-4 rounded-md bg-emerald-50 px-3 py-2 text-sm text-emerald-900">
          {notice}
        </p>
      )}

      <div role="group" aria-label="Kolom status" className="mt-6 flex gap-4 overflow-x-auto pb-4">
        {BOARD_STATES.map((state) => (
          <BoardColumn
            key={state}
            state={state}
            filters={filters}
            timeZone={timeZone}
            dragged={dragged}
            onDragStart={setDragged}
            onDragEnd={onDragEnd}
            onDrop={drop}
            onLabels={onLabels}
          />
        ))}
      </div>

      <p className="mt-2 text-sm text-slate-600">
        Menyeret kartu antarkolom menjalankan aturan transisi yang sama: transisi tidak sah ditolak, dan keluar dari Running meminta konteks.
      </p>

      {dialog?.kind === 'context' && <ContextDialog request={dialog.request} onClose={closeDialog} />}
      {dialog?.kind === 'reason' && (
        <ReasonDialog
          task={dialog.task}
          to={dialog.to}
          onClose={closeDialog}
          onDone={() => {
            setNotice(`${dialog.task.task_key} dipindah ke ${STATE_LABELS[dialog.to]}.`)
            setDialog(null)
          }}
        />
      )}
    </div>
  )
}
