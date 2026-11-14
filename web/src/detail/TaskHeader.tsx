import { useState, type SubmitEvent } from 'react'
import { Link } from 'react-router'
import { useUpdateTask } from '../api/taskDetail'
import type { TaskDetail } from '../api/tasks'
import { STATE_LABELS, type State } from '../board/format'
import { ErrorMessage } from '../components/ErrorMessage'
import { actionLabel, sortActions } from './format'

const primaryButton = 'rounded-lg bg-blue-700 px-4 py-2 font-medium text-white hover:bg-blue-800 disabled:opacity-60'
const secondaryButton = 'rounded-lg border border-slate-300 bg-white px-4 py-2 font-medium text-slate-800 hover:bg-slate-100 disabled:opacity-60'

/** The title, edited in place: Enter saves, Esc closes, empty is refused. */
function Title({ task }: { task: TaskDetail }) {
  const [editing, setEditing] = useState(false)
  const [value, setValue] = useState(task.title)
  const [missing, setMissing] = useState(false)
  const update = useUpdateTask()

  function close() {
    update.reset()
    setMissing(false)
    setEditing(false)
  }

  function submit(event: SubmitEvent<HTMLFormElement>) {
    event.preventDefault()
    const title = value.trim()
    if (title === '') {
      setMissing(true)
      return
    }
    setMissing(false)
    if (title === task.title) {
      close()
      return
    }
    update.mutate({ taskId: task.id, body: { title } }, { onSuccess: close })
  }

  if (!editing) {
    return (
      <div className="flex min-w-0 items-baseline gap-2">
        <h1 className="min-w-0 break-words text-2xl font-semibold text-slate-900 md:text-4xl">{task.title}</h1>
        <button
          type="button"
          aria-label="Ubah judul"
          onClick={() => {
            setValue(task.title)
            setEditing(true)
          }}
          className="shrink-0 text-sm text-blue-700 hover:underline"
        >
          Ubah
        </button>
      </div>
    )
  }

  return (
    <form onSubmit={submit} className="w-full space-y-2" noValidate>
      <input
        type="text"
        aria-label="Judul"
        value={value}
        onChange={(e) => setValue(e.target.value)}
        onKeyDown={(e) => {
          if (e.key === 'Escape' && !update.isPending) close()
        }}
        autoFocus
        disabled={update.isPending}
        className="w-full rounded-md border border-slate-300 px-3 py-2 text-2xl font-semibold text-slate-900 focus:border-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-200"
      />
      {missing && <p className="text-sm text-red-700">Judul wajib diisi.</p>}
      {update.isError && <ErrorMessage error={update.error} />}
      <p className="text-sm text-slate-500">Enter menyimpan, Esc membatalkan.</p>
    </form>
  )
}

/**
 * Header of the Detail task (R1-19): breadcrumb, title in place, the state
 * menu, and the action buttons. Both offer only TaskDetail.allowed_transitions
 * (read from the allowed_transitions table, CLAUDE.md rule 2); what a move
 * still needs (context, reason, a free Running slot) is answered by the API
 * and handled by `onMove` (useMoveTask).
 */
export function TaskHeader({ task, onMove }: { task: TaskDetail; onMove: (to: State) => void }) {
  const targets = (task.allowed_transitions ?? []) as State[]
  const actions = sortActions(targets)

  return (
    <header>
      <nav aria-label="Lokasi" className="text-sm text-slate-600">
        <Link to="/board" className="text-blue-700 underline">
          Papan status
        </Link>{' '}
        / <span className="font-mono">{task.task_key}</span>
      </nav>
      <div className="mt-2 flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
        <div className="flex min-w-0 flex-1 flex-col gap-2">
          <Title key={task.title} task={task} />
          <label className="flex items-center gap-2 text-sm text-slate-600">
            <span className="sr-only">Status</span>
            <select
              aria-label="Status"
              value={task.state}
              onChange={(e) => onMove(e.target.value as State)}
              className="rounded-full border border-blue-200 bg-blue-50 px-3 py-1 text-sm font-medium text-blue-800 focus:outline-none focus:ring-2 focus:ring-blue-200"
            >
              <option value={task.state}>{STATE_LABELS[task.state]}</option>
              {targets.map((to) => (
                <option key={to} value={to}>
                  {STATE_LABELS[to]}
                </option>
              ))}
            </select>
          </label>
        </div>
        <div role="group" aria-label="Aksi" className="flex flex-wrap gap-2">
          {actions.map((to, i) => (
            <button key={to} type="button" onClick={() => onMove(to)} className={i === 0 ? primaryButton : secondaryButton}>
              {actionLabel(task.state, to)}
            </button>
          ))}
          {task.state === 'running' && (
            <Link to="/" state={{ focus: 'recommendations' }} className={secondaryButton}>
              Pindah task
            </Link>
          )}
        </div>
      </div>
    </header>
  )
}
