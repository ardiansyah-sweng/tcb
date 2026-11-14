import { useId, useState, type SubmitEvent } from 'react'
import type { KeyResult, KeyResultInput } from '../api/objectives'
import { ErrorMessage } from '../components/ErrorMessage'
import { inputClass, smallButton } from '../detail/FieldEditor'
import { decimalInput, parseDecimal } from './format'
import { primaryButton } from './ObjectiveForm'

type Mode = 'auto' | 'manual'
type Values = { title: string; weight: string; mode: Mode; start: string; target: string; current: string; unit: string }

function valuesOf(kr?: KeyResult): Values {
  return {
    title: kr?.title ?? '',
    weight: kr ? decimalInput(kr.weight ?? 1) : '1',
    mode: kr?.progress_mode === 'manual' ? 'manual' : 'auto',
    start: decimalInput(kr?.start_value),
    target: decimalInput(kr?.target_value),
    current: decimalInput(kr?.current_value),
    unit: kr?.unit ?? '',
  }
}

/** The request body, or the first problem to show (no request then). */
function toBody(v: Values): KeyResultInput | string {
  const title = v.title.trim()
  if (title === '') return 'Judul wajib diisi.'
  const weight = parseDecimal(v.weight)
  if (weight === null || Number.isNaN(weight) || weight <= 0 || weight > 1) return 'Bobot harus angka lebih dari 0 dan paling besar 1.'
  if (v.mode === 'auto') return { title, weight, progress_mode: 'auto' }

  const start = parseDecimal(v.start)
  const target = parseDecimal(v.target)
  const current = parseDecimal(v.current)
  if (target === null) return 'Target wajib diisi bila mode progres manual.'
  if (Number.isNaN(start)) return 'Nilai awal harus berupa angka.'
  if (Number.isNaN(target)) return 'Target harus berupa angka.'
  if (Number.isNaN(current)) return 'Nilai saat ini harus berupa angka.'
  return {
    title,
    weight,
    progress_mode: 'manual',
    start_value: start,
    target_value: target,
    current_value: current,
    unit: v.unit.trim() === '' ? null : v.unit.trim(),
  }
}

/**
 * Add or edit a Key Result. Defaults: weight 1, progress automatic from the
 * tasks; only the title needs typing. Manual mode asks for a target and
 * accepts a decimal comma. Errors stay in the form with the input kept.
 */
export function KeyResultForm({
  label,
  keyResult,
  pending,
  error,
  onSave,
  onCancel,
}: {
  label: string
  keyResult?: KeyResult
  pending: boolean
  error: unknown
  onSave: (body: KeyResultInput) => void
  onCancel: () => void
}) {
  const id = useId()
  const [values, setValues] = useState(() => valuesOf(keyResult))
  const [problem, setProblem] = useState<string | null>(null)

  function set<K extends keyof Values>(field: K, value: Values[K]) {
    setValues((v) => ({ ...v, [field]: value }))
  }

  function submit(event: SubmitEvent<HTMLFormElement>) {
    event.preventDefault()
    const body = toBody(values)
    if (typeof body === 'string') return setProblem(body)
    setProblem(null)
    onSave(body)
  }

  const field = (key: 'start' | 'target' | 'current' | 'unit', text: string, decimal = true) => (
    <div>
      <label htmlFor={`${id}-${key}`} className="block text-sm font-medium text-slate-700">
        {text}
      </label>
      <input
        id={`${id}-${key}`}
        inputMode={decimal ? 'decimal' : undefined}
        value={values[key]}
        onChange={(e) => set(key, e.target.value)}
        className={`${inputClass} mt-1`}
      />
    </div>
  )

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
      className="space-y-3 rounded-lg border border-slate-200 bg-slate-50 p-4"
      noValidate
    >
      <div>
        <label htmlFor={`${id}-title`} className="block text-sm font-medium text-slate-700">
          Judul
        </label>
        <input id={`${id}-title`} autoFocus value={values.title} onChange={(e) => set('title', e.target.value)} className={`${inputClass} mt-1`} />
      </div>
      <div className="grid gap-3 sm:grid-cols-2">
        <div>
          <label htmlFor={`${id}-weight`} className="block text-sm font-medium text-slate-700">
            Bobot
          </label>
          <input
            id={`${id}-weight`}
            inputMode="decimal"
            aria-describedby={`${id}-weight-hint`}
            value={values.weight}
            onChange={(e) => set('weight', e.target.value)}
            className={`${inputClass} mt-1`}
          />
          <p id={`${id}-weight-hint`} className="mt-1 text-xs text-slate-500">
            Lebih dari 0, paling besar 1 (mis. 0,4).
          </p>
        </div>
        <fieldset>
          <legend className="block text-sm font-medium text-slate-700">Mode progres</legend>
          <div className="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-sm text-slate-800">
            <label className="flex items-center gap-1.5">
              <input type="radio" name={`${id}-mode`} checked={values.mode === 'auto'} onChange={() => set('mode', 'auto')} />
              Otomatis dari task
            </label>
            <label className="flex items-center gap-1.5">
              <input type="radio" name={`${id}-mode`} checked={values.mode === 'manual'} onChange={() => set('mode', 'manual')} />
              Manual
            </label>
          </div>
        </fieldset>
      </div>
      {values.mode === 'manual' && (
        <div className="grid gap-3 sm:grid-cols-4">
          {field('start', 'Nilai awal')}
          {field('target', 'Target')}
          {field('current', 'Nilai saat ini')}
          {field('unit', 'Satuan', false)}
        </div>
      )}
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
