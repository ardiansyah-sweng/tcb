import { useState } from 'react'
import { Link } from 'react-router'
import { useLinkTask, useOrphanTasks } from '../api/objectives'
import type { Schemas } from '../api/client'
import { useKeyResultGroups, type KeyResultGroup } from '../api/taskDetail'
import { ErrorMessage } from '../components/ErrorMessage'
import { inputClass, smallButton } from '../detail/FieldEditor'

type Task = Schemas['Task']

function OrphanRow({ task, groups }: { task: Task; groups: KeyResultGroup[] | null }) {
  const [choice, setChoice] = useState('')
  const [problem, setProblem] = useState<string | null>(null)
  const link = useLinkTask()

  function submit() {
    if (choice === '') return setProblem('Pilih Key Result dulu.')
    setProblem(null)
    link.mutate({ taskId: task.id, keyResultId: Number(choice) })
  }

  return (
    <li aria-label={`${task.task_key} · ${task.title}`} className="border-t border-slate-200 py-3 first:border-t-0">
      <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <div className="min-w-0">
          <p className="font-mono text-xs text-slate-600">{task.task_key}</p>
          <Link to={`/tasks/${task.task_key}`} className="text-slate-900 underline-offset-2 hover:underline">
            {task.title}
          </Link>
        </div>
        {groups !== null && (
          <div className="flex shrink-0 gap-2">
            <select
              aria-label={`Key Result untuk ${task.task_key}`}
              value={choice}
              onChange={(e) => setChoice(e.target.value)}
              className={`${inputClass} max-w-56`}
            >
              <option value="">Pilih KR…</option>
              {groups
                .filter((g) => g.keyResults.length > 0)
                .map((g) => (
                  <optgroup key={g.objective} label={g.objective}>
                    {g.keyResults.map((kr) => (
                      <option key={kr.id} value={kr.id}>
                        {kr.title}
                      </option>
                    ))}
                  </optgroup>
                ))}
            </select>
            <button type="button" disabled={link.isPending} onClick={submit} className={smallButton}>
              Tautkan
            </button>
          </div>
        )}
      </div>
      {problem !== null ? (
        <p role="alert" className="mt-2 text-sm text-red-700">
          {problem}
        </p>
      ) : (
        link.isError && <ErrorMessage error={link.error} className="mt-2" />
      )}
    </li>
  )
}

/**
 * Open tasks without a Key Result (their goal score is zero), each linkable
 * to a Key Result of an active Objective.
 */
export function OrphanTasks() {
  const tasks = useOrphanTasks()
  const keyResults = useKeyResultGroups(true)
  const list = tasks.data?.data ?? []
  const more = tasks.data?.next_cursor ? '+' : ''
  // A failed Objective list is reported by the page itself; rows then have no picker.
  const ready = !keyResults.isPending && keyResults.error === null
  const noKeyResult = ready && keyResults.groups.every((g) => g.keyResults.length === 0)
  const groups = ready && !noKeyResult ? keyResults.groups : null

  return (
    <section aria-labelledby="orphan-heading" className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm md:p-6">
      <h2 id="orphan-heading" className="text-lg font-semibold text-slate-900">
        Orphan task ({tasks.data ? `${list.length}${more}` : '…'})
      </h2>
      <p className="mt-1 text-sm text-slate-600">Tidak terhubung ke KR, sehingga skor goal-nya nol.</p>
      {tasks.isPending && <p className="mt-3 text-sm text-slate-600">Memuat…</p>}
      {tasks.isError && <ErrorMessage error={tasks.error} className="mt-3" />}
      {tasks.data && list.length === 0 && <p className="mt-3 text-sm text-slate-700">Semua task yang belum selesai sudah terhubung ke Key Result.</p>}
      {list.length > 0 && noKeyResult && (
        <p className="mt-3 text-sm text-amber-800">Tambahkan Key Result dulu agar task bisa ditautkan.</p>
      )}
      {list.length > 0 && (
        <ul className="mt-3">
          {list.map((t) => (
            <OrphanRow key={t.id} task={t} groups={groups} />
          ))}
        </ul>
      )}
    </section>
  )
}
