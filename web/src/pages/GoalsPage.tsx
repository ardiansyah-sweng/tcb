import { useState } from 'react'
import { useActiveObjectives, useCreateObjective } from '../api/objectives'
import { useMe } from '../auth/session'
import { ErrorMessage } from '../components/ErrorMessage'
import { smallButton } from '../detail/FieldEditor'
import { sixMonthsFrom, todayIn } from '../goals/format'
import { ObjectiveCard } from '../goals/ObjectiveCard'
import { ObjectiveForm, type ObjectiveValues } from '../goals/ObjectiveForm'
import { OrphanTasks } from '../goals/OrphanTasks'
import { DEFAULT_TIMEZONE } from '../home/format'

/**
 * R1-20 Kelola Objective dan Key Result (FR-01, FR-02); layout from
 * docs/mockup/tcb-v1/04-progres goal.png, management only. The full
 * progress view (time allocation, FR-19) waits for Release 3.
 */
export default function GoalsPage() {
  const me = useMe()
  const today = todayIn(me.data?.timezone || DEFAULT_TIMEZONE)
  const objectives = useActiveObjectives()
  const create = useCreateObjective()
  const [adding, setAdding] = useState(false)

  const list = objectives.data?.data ?? []
  // Input-minimal: the period of the latest Objective (the API lists newest first), else six months from today.
  const latest = list[0]
  const initial: ObjectiveValues = {
    title: '',
    description: '',
    period_start: latest?.period_start ?? today,
    period_end: latest?.period_end ?? sixMonthsFrom(today),
  }

  function closeAdd() {
    create.reset()
    setAdding(false)
  }

  function save(v: ObjectiveValues) {
    const body = { title: v.title, ...(v.description ? { description: v.description } : {}), period_start: v.period_start, period_end: v.period_end }
    create.mutate(body, { onSuccess: closeAdd })
  }

  return (
    <div className="mx-auto max-w-5xl space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-2xl font-semibold text-slate-900 md:text-3xl">Goal & Key Result</h1>
        {!adding && objectives.isSuccess && (
          <button type="button" onClick={() => setAdding(true)} className={smallButton}>
            Tambah Objective
          </button>
        )}
      </div>

      {adding && (
        <ObjectiveForm label="Objective baru" initial={initial} pending={create.isPending} error={create.error} onSave={save} onCancel={closeAdd} />
      )}

      {objectives.isPending && <p className="text-slate-600">Memuat…</p>}
      {objectives.isError && <ErrorMessage error={objectives.error} />}
      {objectives.isSuccess && list.length === 0 && (
        <p className="text-slate-700">Belum ada Objective aktif. Tambahkan Objective untuk mulai menautkan task.</p>
      )}
      {list.map((o) => (
        <ObjectiveCard key={o.id} objective={o} today={today} />
      ))}

      <OrphanTasks />
    </div>
  )
}
