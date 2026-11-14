import { useId, useState } from 'react'
import { useDeleteKeyResult, useUpdateKeyResult, type KeyResult } from '../api/objectives'
import { ErrorMessage } from '../components/ErrorMessage'
import { linkButton } from '../detail/FieldEditor'
import { Confirm } from './Confirm'
import { linkedTaskCount, percent, progressLine, weightLine } from './format'
import { KeyResultForm } from './KeyResultForm'

export function ProgressBar({ label, progress }: { label: string; progress: number }) {
  const value = percent(progress)
  return (
    <div className="flex items-center gap-3">
      <div
        role="progressbar"
        aria-label={label}
        aria-valuemin={0}
        aria-valuemax={100}
        aria-valuenow={value}
        className="h-2 min-w-0 flex-1 overflow-hidden rounded-full bg-slate-200"
      >
        <div className="h-full rounded-full bg-blue-700" style={{ width: `${value}%` }} />
      </div>
      <span className="w-12 shrink-0 text-right font-semibold text-slate-900">{value}%</span>
    </div>
  )
}

/** One Key Result: weight, mode, progress, task counts; Ubah and Hapus. */
export function KeyResultItem({ keyResult, position }: { keyResult: KeyResult; position: number }) {
  const headingId = useId()
  const [mode, setMode] = useState<'view' | 'edit' | 'delete'>('view')
  const update = useUpdateKeyResult()
  const remove = useDeleteKeyResult()
  const linked = linkedTaskCount(keyResult)

  function close() {
    update.reset()
    remove.reset()
    setMode('view')
  }

  return (
    <li aria-labelledby={headingId} className="rounded-lg border border-slate-200 bg-white p-4">
      <div className="flex flex-wrap items-start justify-between gap-x-4 gap-y-1">
        <h3 id={headingId} className="min-w-0 font-semibold text-slate-900">
          KR{position} · {keyResult.title}
        </h3>
        <p className="text-sm text-slate-600">{weightLine(keyResult)}</p>
      </div>
      <div className="mt-3">
        <ProgressBar label="Progres Key Result" progress={keyResult.progress} />
      </div>
      <p className="mt-2 text-sm text-slate-700">{progressLine(keyResult)}</p>

      {mode === 'view' && (
        <div className="mt-2 flex gap-4">
          <button type="button" aria-label="Ubah Key Result" onClick={() => setMode('edit')} className={linkButton}>
            Ubah
          </button>
          <button type="button" aria-label="Hapus Key Result" onClick={() => setMode('delete')} className="text-sm text-red-700 underline-offset-2 hover:underline">
            Hapus
          </button>
        </div>
      )}
      {mode === 'edit' && (
        <div className="mt-3">
          <KeyResultForm
            label="Ubah Key Result"
            keyResult={keyResult}
            pending={update.isPending}
            error={update.error}
            onSave={(body) => update.mutate({ id: keyResult.id, body }, { onSuccess: close })}
            onCancel={close}
          />
        </div>
      )}
      {mode === 'delete' && (
        <div className="mt-3 space-y-2">
          <Confirm
            question={linked > 0 ? `Hapus Key Result ini? ${linked} task terkait menjadi orphan, tidak ikut terhapus.` : 'Hapus Key Result ini?'}
            confirmLabel="Ya, hapus"
            pending={remove.isPending}
            onConfirm={() => remove.mutate(keyResult.id)}
            onCancel={close}
          />
          {remove.isError && <ErrorMessage error={remove.error} />}
        </div>
      )}
    </li>
  )
}
