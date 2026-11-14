import { useState, type ReactNode, type SubmitEvent } from 'react'
import { useUpdateTask, type TaskUpdate } from '../api/taskDetail'
import { ErrorMessage } from '../components/ErrorMessage'

export const inputClass =
  'w-full rounded-md border border-slate-300 bg-white px-2 py-1.5 text-slate-900 focus:border-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-200'
export const smallButton = 'rounded-md border border-slate-300 px-2.5 py-1 text-sm text-slate-800 hover:bg-slate-100 disabled:opacity-60'
export const linkButton = 'text-sm text-blue-700 underline-offset-2 hover:underline'

/**
 * One editable row of the Data TCB panel: the value with an "Ubah" button,
 * or a small form with Simpan, Kosongkan, and Batal. Every field may be
 * emptied (input-minimal principle); `emptyBody` says what empty means for
 * it. Errors stay in the form with the input kept; Esc closes it.
 */
export function EditableField({
  taskId,
  label,
  display,
  editor,
  toBody,
  emptyBody,
}: {
  taskId: number
  label: string
  display: ReactNode
  /** Inputs of the form; rendered only while editing, so they start from the current value. */
  editor: ReactNode
  /** The PATCH body from the inputs, read on Simpan. */
  toBody: () => TaskUpdate
  emptyBody: TaskUpdate
}) {
  const [editing, setEditing] = useState(false)
  const update = useUpdateTask()

  function save(body: TaskUpdate) {
    update.mutate({ taskId, body }, { onSuccess: () => setEditing(false) })
  }

  function submit(event: SubmitEvent<HTMLFormElement>) {
    event.preventDefault()
    save(toBody())
  }

  function cancel() {
    update.reset()
    setEditing(false)
  }

  if (!editing) {
    return (
      <div className="flex items-start justify-between gap-2">
        <div className="min-w-0">{display}</div>
        <button type="button" aria-label={`Ubah ${label}`} onClick={() => setEditing(true)} className={`${linkButton} shrink-0`}>
          Ubah
        </button>
      </div>
    )
  }

  return (
    <form
      aria-label={label}
      onSubmit={submit}
      onKeyDown={(e) => {
        if (e.key === 'Escape' && !update.isPending) {
          e.stopPropagation()
          cancel()
        }
      }}
      className="space-y-2"
      noValidate
    >
      {editor}
      {update.isError && <ErrorMessage error={update.error} />}
      <div className="flex flex-wrap gap-2">
        <button type="submit" disabled={update.isPending} className="rounded-md bg-blue-700 px-2.5 py-1 text-sm font-medium text-white hover:bg-blue-800 disabled:opacity-60">
          Simpan
        </button>
        <button type="button" disabled={update.isPending} onClick={() => save(emptyBody)} className={smallButton}>
          Kosongkan
        </button>
        <button type="button" disabled={update.isPending} onClick={cancel} className={smallButton}>
          Batal
        </button>
      </div>
    </form>
  )
}
