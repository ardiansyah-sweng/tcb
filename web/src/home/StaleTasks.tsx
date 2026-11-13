import { useState, type SubmitEvent } from 'react'
import { useRecommendations } from '../api/recommendations'
import { useTransitionTask, type Task } from '../api/tasks'
import { ErrorMessage } from '../components/ErrorMessage'
import { useStartTask } from './actions'
import { daysSince } from './format'
import { useNow } from './useNow'

const button = 'rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm text-slate-800 hover:bg-slate-100 disabled:opacity-60'

/**
 * RecommendationBatch.stale_tasks (decision R1-14, 13 Nov 2026): Ready tasks
 * waiting more than 14 days that did not make the top three, with the
 * "kerjakan, tunda, atau batalkan" reminder.
 */
export function StaleTasks() {
  const batch = useRecommendations()
  const tasks = batch.data?.stale_tasks ?? []
  if (tasks.length === 0) return null

  return (
    <section aria-labelledby="sudah-lama-menunggu" className="rounded-2xl border border-amber-200 bg-white p-5">
      <h2 id="sudah-lama-menunggu" className="text-lg font-semibold text-slate-900">
        Sudah lama menunggu
      </h2>
      <p className="mt-1 text-sm text-slate-600">Lebih dari 14 hari di Ready: kerjakan, tunda, atau batalkan.</p>
      <ul className="mt-3 divide-y divide-slate-200">
        {tasks.map((task) => (
          <StaleTask key={task.id} task={task} runningFull={batch.data?.running_full ?? false} />
        ))}
      </ul>
    </section>
  )
}

function StaleTask({ task, runningFull }: { task: Task; runningFull: boolean }) {
  const now = useNow(60_000)
  const start = useStartTask()
  const transition = useTransitionTask()
  const [cancelling, setCancelling] = useState(false)
  const [reason, setReason] = useState('')
  const [missingReason, setMissingReason] = useState(false)
  const busy = start.isPending || transition.isPending

  function cancel(event: SubmitEvent<HTMLFormElement>) {
    event.preventDefault()
    const value = reason.trim()
    if (value === '') {
      setMissingReason(true)
      return
    }
    setMissingReason(false)
    transition.mutate({ taskId: task.id, body: { to: 'cancelled', reason: value } })
  }

  return (
    <li className="py-3">
      <p>
        <span className="mr-2 font-mono text-sm text-slate-600">{task.task_key}</span>
        <span className="text-slate-900">{task.title}</span>
      </p>
      {task.ready_since && <p className="text-sm text-amber-800">{`${daysSince(task.ready_since, now)} hari di Ready`}</p>}

      <div className="mt-2 flex flex-wrap gap-2">
        <button type="button" disabled={busy} className={button} onClick={() => start.mutate({ task, recommendationId: null, runningFull })}>
          Kerjakan
        </button>
        <button
          type="button"
          disabled={busy}
          className={button}
          onClick={() => transition.mutate({ taskId: task.id, body: { to: 'suspended' } })}
        >
          Tunda
        </button>
        <button type="button" disabled={busy} className={button} onClick={() => setCancelling(true)} aria-expanded={cancelling}>
          Batalkan
        </button>
      </div>

      {cancelling && (
        <form onSubmit={cancel} className="mt-2 flex flex-wrap items-start gap-2" noValidate>
          <label className="sr-only" htmlFor={`cancel-reason-${task.id}`}>
            {`Alasan batal ${task.task_key}`}
          </label>
          <input
            id={`cancel-reason-${task.id}`}
            type="text"
            value={reason}
            onChange={(e) => setReason(e.target.value)}
            placeholder="Alasan batal"
            autoFocus
            className="min-w-0 flex-1 rounded-md border border-slate-300 px-3 py-1.5 text-sm"
          />
          <button type="submit" disabled={busy} className="rounded-lg bg-red-700 px-3 py-1.5 text-sm font-medium text-white hover:bg-red-800 disabled:opacity-60">
            Batalkan task
          </button>
          {missingReason && <p className="w-full text-sm text-red-700">Isi alasan batal.</p>}
        </form>
      )}

      {start.isError && <ErrorMessage error={start.error} className="mt-2" />}
      {transition.isError && <ErrorMessage error={transition.error} className="mt-2" />}
    </li>
  )
}
