import { useId, useState, type SubmitEvent } from 'react'
import { ErrorMessage } from '../components/ErrorMessage'
import { inputClass, smallButton } from '../detail/FieldEditor'

export const primaryButton =
  'rounded-md bg-blue-700 px-2.5 py-1 text-sm font-medium text-white hover:bg-blue-800 disabled:opacity-60'

export type ObjectiveValues = { title: string; description: string; period_start: string; period_end: string }

/**
 * Add or edit an Objective. Only the title needs typing: the period arrives
 * prefilled. API errors stay in the form with the input kept; Esc and Batal
 * close it without saving.
 */
export function ObjectiveForm({
  label,
  initial,
  pending,
  error,
  onSave,
  onCancel,
}: {
  label: string
  initial: ObjectiveValues
  pending: boolean
  error: unknown
  onSave: (values: ObjectiveValues) => void
  onCancel: () => void
}) {
  const id = useId()
  const [values, setValues] = useState(initial)
  const [problem, setProblem] = useState<string | null>(null)

  function set(field: keyof ObjectiveValues, value: string) {
    setValues((v) => ({ ...v, [field]: value }))
  }

  function submit(event: SubmitEvent<HTMLFormElement>) {
    event.preventDefault()
    if (values.title.trim() === '') return setProblem('Judul wajib diisi.')
    setProblem(null)
    onSave({ ...values, title: values.title.trim(), description: values.description.trim() })
  }

  return (
    <form
      aria-label={label}
      onSubmit={submit}
      onKeyDown={(e) => {
        if (e.key === 'Escape' && !pending) {
          e.stopPropagation()
          onCancel()
        }
      }}
      className="space-y-3 rounded-lg border border-slate-200 bg-white p-4"
      noValidate
    >
      <div>
        <label htmlFor={`${id}-title`} className="block text-sm font-medium text-slate-700">
          Judul
        </label>
        <input id={`${id}-title`} autoFocus value={values.title} onChange={(e) => set('title', e.target.value)} className={`${inputClass} mt-1`} />
      </div>
      <div>
        <label htmlFor={`${id}-description`} className="block text-sm font-medium text-slate-700">
          Deskripsi <span className="font-normal text-slate-500">(opsional)</span>
        </label>
        <input id={`${id}-description`} value={values.description} onChange={(e) => set('description', e.target.value)} className={`${inputClass} mt-1`} />
      </div>
      <div className="grid gap-3 sm:grid-cols-2">
        <div>
          <label htmlFor={`${id}-start`} className="block text-sm font-medium text-slate-700">
            Periode mulai
          </label>
          <input id={`${id}-start`} type="date" value={values.period_start} onChange={(e) => set('period_start', e.target.value)} className={`${inputClass} mt-1`} />
        </div>
        <div>
          <label htmlFor={`${id}-end`} className="block text-sm font-medium text-slate-700">
            Periode akhir
          </label>
          <input id={`${id}-end`} type="date" value={values.period_end} onChange={(e) => set('period_end', e.target.value)} className={`${inputClass} mt-1`} />
        </div>
      </div>
      {problem !== null ? (
        <p role="alert" className="text-sm text-red-700">
          {problem}
        </p>
      ) : (
        error != null && <ErrorMessage error={error} />
      )}
      <div className="flex flex-wrap gap-2">
        <button type="submit" disabled={pending} className={primaryButton}>
          Simpan
        </button>
        <button type="button" disabled={pending} onClick={onCancel} className={smallButton}>
          Batal
        </button>
      </div>
    </form>
  )
}
