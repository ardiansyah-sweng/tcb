import { useId, useState } from 'react'
import { useCreateKeyResult, useObjectiveDetail, useUpdateObjective, type Objective } from '../api/objectives'
import { ErrorMessage } from '../components/ErrorMessage'
import { linkButton, smallButton } from '../detail/FieldEditor'
import { Confirm } from './Confirm'
import { formatPeriod, remainingPeriod } from './format'
import { KeyResultForm } from './KeyResultForm'
import { KeyResultItem, ProgressBar } from './KeyResultItem'
import { ObjectiveForm } from './ObjectiveForm'

/**
 * One active Objective (mockup 04): period, weighted progress, its Key
 * Results; Ubah, Arsipkan, and Tambah Key Result.
 */
export function ObjectiveCard({ objective, today }: { objective: Objective; today: string }) {
  const headingId = useId()
  const detail = useObjectiveDetail(objective.id)
  const [mode, setMode] = useState<'view' | 'edit' | 'archive'>('view')
  const [adding, setAdding] = useState(false)
  const update = useUpdateObjective()
  const addKeyResult = useCreateKeyResult()

  function close() {
    update.reset()
    setMode('view')
  }

  function closeAdd() {
    addKeyResult.reset()
    setAdding(false)
  }

  const keyResults = detail.data?.key_results ?? []
  const summary = detail.data
    ? `${keyResults.length > 0 ? `Rata-rata berbobot dari ${keyResults.length} Key Result.` : 'Belum ada Key Result.'} ${remainingPeriod(objective.period_end, today)}`
    : null

  return (
    <article aria-labelledby={headingId} className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm md:p-6">
      <p className="text-sm text-slate-600">Objective · {formatPeriod(objective.period_start, objective.period_end)}</p>
      <h2 id={headingId} className="mt-1 text-xl font-semibold text-slate-900 md:text-2xl">
        {objective.title}
      </h2>
      {objective.description && <p className="mt-1 text-slate-700">{objective.description}</p>}

      {mode === 'view' && (
        <div className="mt-2 flex gap-4">
          <button type="button" aria-label="Ubah Objective" onClick={() => setMode('edit')} className={linkButton}>
            Ubah
          </button>
          <button type="button" onClick={() => setMode('archive')} className={linkButton}>
            Arsipkan
          </button>
        </div>
      )}
      {mode === 'edit' && (
        <div className="mt-3">
          <ObjectiveForm
            label="Ubah Objective"
            initial={{
              title: objective.title,
              description: objective.description ?? '',
              period_start: objective.period_start,
              period_end: objective.period_end,
            }}
            pending={update.isPending}
            error={update.error}
            onSave={(v) =>
              update.mutate(
                { id: objective.id, body: { ...v, description: v.description === '' ? null : v.description } },
                { onSuccess: close },
              )
            }
            onCancel={close}
          />
        </div>
      )}
      {mode === 'archive' && (
        <div className="mt-3 space-y-2">
          <Confirm
            question="Arsipkan Objective ini? Objective tidak tampil lagi di daftar aktif; Key Result dan task-nya tetap ada."
            confirmLabel="Ya, arsipkan"
            pending={update.isPending}
            onConfirm={() => update.mutate({ id: objective.id, body: { status: 'archived' } })}
            onCancel={close}
          />
          {update.isError && <ErrorMessage error={update.error} />}
        </div>
      )}

      <div className="mt-4 rounded-lg bg-slate-50 p-4">
        <p className="text-xs font-semibold uppercase tracking-wide text-slate-600">Progres Objective</p>
        <div className="mt-2">
          <ProgressBar label="Progres Objective" progress={objective.progress} />
        </div>
        {summary && <p className="mt-2 text-sm text-slate-600">{summary}</p>}
      </div>

      <div className="mt-5 flex flex-wrap items-center justify-between gap-2">
        <h3 className="font-semibold text-slate-900">Key Result</h3>
        {!adding && (
          <button type="button" onClick={() => setAdding(true)} className={smallButton}>
            Tambah Key Result
          </button>
        )}
      </div>
      {adding && (
        <div className="mt-3">
          <KeyResultForm
            label="Key Result baru"
            pending={addKeyResult.isPending}
            error={addKeyResult.error}
            onSave={(body) => addKeyResult.mutate({ objectiveId: objective.id, body }, { onSuccess: closeAdd })}
            onCancel={closeAdd}
          />
        </div>
      )}
      {detail.isPending && <p className="mt-3 text-sm text-slate-600">Memuat Key Result…</p>}
      {detail.isError && <ErrorMessage error={detail.error} className="mt-3" />}
      {keyResults.length > 0 && (
        <ul className="mt-3 space-y-3">
          {keyResults.map((kr, i) => (
            <KeyResultItem key={kr.id} keyResult={kr} position={i + 1} />
          ))}
        </ul>
      )}
    </article>
  )
}
