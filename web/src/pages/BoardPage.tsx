import { useCallback, useId, useMemo, useState } from 'react'
import { useActiveObjectives } from '../api/objectives'
import type { Task } from '../api/tasks'
import { useMe } from '../auth/session'
import { BoardColumn, type BoardFilters } from '../board/BoardColumn'
import { BOARD_STATES, USUAL_LABELS, type State } from '../board/format'
import { useMoveTask } from '../board/useMoveTask'
import { ErrorMessage } from '../components/ErrorMessage'
import { DEFAULT_TIMEZONE } from '../home/format'

const ORPHAN = 'orphan'

const selectClass =
  'mt-1 w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-slate-900 focus:border-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-200'

/**
 * R1-18 Papan status (FR-18); layout from docs/mockup/tcb-v1/02-papan status.png.
 *
 * Dropping a card asks the API for the transition first and reacts to the
 * answer (useMoveTask): the board never decides itself whether a move is allowed.
 */
export default function BoardPage() {
  const me = useMe()
  const timeZone = me.data?.timezone || DEFAULT_TIMEZONE
  const objectives = useActiveObjectives()
  const objectiveId = useId()
  const labelId = useId()

  const [objective, setObjective] = useState('')
  const [label, setLabel] = useState('')
  const [seenLabels, setSeenLabels] = useState<string[]>([])
  const [dragged, setDragged] = useState<Task | null>(null)

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

  const onDragEnd = useCallback(() => setDragged(null), [])
  const { move, error: moveError, notice, dialogs } = useMoveTask()

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

      {dialogs}
    </div>
  )
}
