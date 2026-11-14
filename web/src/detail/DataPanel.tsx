import { useId, useRef, useState, type ReactNode, type RefObject } from 'react'
import { Link as RouterLink } from 'react-router'
import { useKeyResultGroups } from '../api/taskDetail'
import type { TaskDetail } from '../api/tasks'
import { USUAL_LABELS } from '../board/format'
import { ErrorMessage } from '../components/ErrorMessage'
import { DependencyList } from './DependencyList'
import { DocumentList } from './DocumentList'
import { EditableField, inputClass } from './FieldEditor'
import {
  DEFAULT_ENERGY,
  DEFAULT_PRIORITY,
  ENERGY_LABELS,
  PRIORITY_LABELS,
  formatDeadline,
  fromLocalInput,
  number,
  toLocalInput,
} from './format'
import { SubtaskList } from './SubtaskList'

/** "" → null, otherwise the number typed. */
function numberOrNull(value: string | undefined): number | null {
  const trimmed = (value ?? '').trim()
  return trimmed === '' ? null : Number(trimmed)
}

function textOrNull(value: string | undefined): string | null {
  const trimmed = (value ?? '').trim()
  return trimmed === '' ? null : trimmed
}

function Row({ label, children }: { label: string; children: ReactNode }) {
  return (
    <>
      <dt className="text-slate-500">{label}</dt>
      <dd className="min-w-0 text-slate-900">{children}</dd>
    </>
  )
}

function KeyResultEditor({ task, select }: { task: TaskDetail; select: RefObject<HTMLSelectElement | null> }) {
  const { groups, isPending, error } = useKeyResultGroups(true)
  // Controlled, so the current KR stays selected when the options arrive.
  const [value, setValue] = useState(String(task.key_result_id ?? ''))
  const known = groups.some((g) => g.keyResults.some((kr) => kr.id === task.key_result_id))
  return (
    <>
      <select ref={select} aria-label="Key Result" value={value} onChange={(e) => setValue(e.target.value)} className={inputClass}>
        <option value="">Tanpa KR (orphan)</option>
        {/* A KR of a closed Objective stays selectable while it is linked. */}
        {task.key_result_id !== null && task.key_result_id !== undefined && !known && (
          <option value={task.key_result_id}>{task.key_result_title ?? `KR ${task.key_result_id}`}</option>
        )}
        {groups.map((g) => (
          <optgroup key={g.objective} label={g.objective}>
            {g.keyResults.map((kr) => (
              <option key={kr.id} value={kr.id}>
                {kr.title}
              </option>
            ))}
          </optgroup>
        ))}
      </select>
      {isPending && <p className="text-sm text-slate-500">Memuat Key Result…</p>}
      {error !== null && <ErrorMessage error={error} />}
    </>
  )
}

/**
 * Data TCB panel (R1-19): every field can be edited in place and emptied,
 * except the title (in the header) and the accounting the API keeps
 * (time spent, context switches). Priority and energy cannot be null, so
 * emptying them restores the default (decision 14 Nov 2026).
 */
export function DataPanel({ task, timeZone }: { task: TaskDetail; timeZone: string }) {
  const keyResult = useRef<HTMLSelectElement>(null)
  const priority = useRef<HTMLSelectElement>(null)
  const deadline = useRef<HTMLInputElement>(null)
  const estimate = useRef<HTMLInputElement>(null)
  const unitDone = useRef<HTMLInputElement>(null)
  const unitTotal = useRef<HTMLInputElement>(null)
  const unitName = useRef<HTMLInputElement>(null)
  const energy = useRef<HTMLSelectElement>(null)
  const label = useRef<HTMLInputElement>(null)
  const labelListId = useId()

  const unit = task.progress_unit
  const id = task.id

  return (
    <section aria-labelledby="data-tcb" className="rounded-2xl border border-slate-200 bg-white p-5 md:p-6">
      <h2 id="data-tcb" className="text-lg font-semibold text-slate-900">
        Data TCB
      </h2>
      <dl className="mt-3 grid grid-cols-[minmax(7rem,auto)_1fr] gap-x-4 gap-y-3 text-sm md:text-base">
        <Row label="Kunci">
          <span className="font-mono">{task.task_key}</span>
        </Row>

        <Row label="Key Result">
          <EditableField
            taskId={id}
            label="Key Result"
            display={task.key_result_title ? <RouterLink to="/goals" className="text-blue-700 underline">{task.key_result_title}</RouterLink> : '— (orphan)'}
            editor={<KeyResultEditor task={task} select={keyResult} />}
            toBody={() => ({ key_result_id: numberOrNull(keyResult.current?.value) })}
            emptyBody={{ key_result_id: null }}
          />
        </Row>

        <Row label="Prioritas">
          <EditableField
            taskId={id}
            label="Prioritas"
            display={`${task.priority} · ${PRIORITY_LABELS[task.priority] ?? ''}`}
            editor={
              <select ref={priority} aria-label="Prioritas" defaultValue={task.priority} className={inputClass}>
                {[1, 2, 3, 4].map((p) => (
                  <option key={p} value={p}>
                    {p} · {PRIORITY_LABELS[p]}
                  </option>
                ))}
              </select>
            }
            toBody={() => ({ priority: Number(priority.current?.value ?? DEFAULT_PRIORITY) })}
            emptyBody={{ priority: DEFAULT_PRIORITY }}
          />
        </Row>

        <Row label="Deadline">
          <EditableField
            taskId={id}
            label="Deadline"
            display={task.deadline_at ? formatDeadline(task.deadline_at, timeZone) : '—'}
            editor={
              <input
                ref={deadline}
                type="datetime-local"
                aria-label="Deadline"
                defaultValue={task.deadline_at ? toLocalInput(task.deadline_at, timeZone) : ''}
                className={inputClass}
              />
            }
            toBody={() => {
              const value = deadline.current?.value ?? ''
              return { deadline_at: value === '' ? null : fromLocalInput(value, timeZone) }
            }}
            emptyBody={{ deadline_at: null }}
          />
        </Row>

        <Row label="Estimasi / terpakai">
          <EditableField
            taskId={id}
            label="Estimasi / terpakai"
            display={`${task.estimate_minutes ?? '—'} / ${task.time_spent_minutes ?? 0} mnt`}
            editor={
              <>
                <input
                  ref={estimate}
                  type="number"
                  min={1}
                  aria-label="Estimasi (menit)"
                  defaultValue={task.estimate_minutes ?? ''}
                  className={inputClass}
                />
                <p className="text-sm text-slate-500">Terpakai {task.time_spent_minutes ?? 0} mnt dihitung dari sesi kerja.</p>
              </>
            }
            toBody={() => ({ estimate_minutes: numberOrNull(estimate.current?.value) })}
            emptyBody={{ estimate_minutes: null }}
          />
        </Row>

        <Row label="Progres">
          <EditableField
            taskId={id}
            label="Progres"
            display={unit ? `${number(unit.done)} / ${number(unit.total)} ${unit.name}` : '—'}
            editor={
              <div className="grid grid-cols-3 gap-2">
                <input ref={unitDone} type="number" min={0} aria-label="Selesai" defaultValue={unit?.done ?? ''} className={inputClass} />
                <input ref={unitTotal} type="number" min={0} aria-label="Total" defaultValue={unit?.total ?? ''} className={inputClass} />
                <input
                  ref={unitName}
                  type="text"
                  aria-label="Satuan"
                  defaultValue={unit?.name ?? ''}
                  placeholder={task.unit_name_suggestion}
                  className={inputClass}
                />
              </div>
            }
            toBody={() => {
              const total = numberOrNull(unitTotal.current?.value)
              // Without a total there is no unit progress (unit_done and unit_name are cleared too).
              if (total === null) return { unit_total: null }
              return { unit_done: numberOrNull(unitDone.current?.value), unit_total: total, unit_name: textOrNull(unitName.current?.value) }
            }}
            emptyBody={{ unit_total: null }}
          />
        </Row>

        <Row label="Pindah konteks">{task.switch_count ?? 0} kali</Row>

        <Row label="Jenis kerja">
          <EditableField
            taskId={id}
            label="Jenis kerja"
            display={ENERGY_LABELS[task.energy ?? DEFAULT_ENERGY]}
            editor={
              <select ref={energy} aria-label="Jenis kerja" defaultValue={task.energy ?? DEFAULT_ENERGY} className={inputClass}>
                <option value="focus">{ENERGY_LABELS.focus}</option>
                <option value="light">{ENERGY_LABELS.light}</option>
              </select>
            }
            toBody={() => ({ energy: (energy.current?.value as 'focus' | 'light' | undefined) ?? DEFAULT_ENERGY })}
            emptyBody={{ energy: DEFAULT_ENERGY }}
          />
        </Row>

        <Row label="Label">
          <EditableField
            taskId={id}
            label="Label"
            display={task.label ?? '—'}
            editor={
              <>
                <input ref={label} type="text" aria-label="Label" list={labelListId} defaultValue={task.label ?? ''} className={inputClass} />
                <datalist id={labelListId}>
                  {USUAL_LABELS.map((l) => (
                    <option key={l} value={l} />
                  ))}
                </datalist>
              </>
            }
            toBody={() => ({ label: textOrNull(label.current?.value) })}
            emptyBody={{ label: null }}
          />
        </Row>

        <Row label="Subtask">
          <SubtaskList task={task} />
        </Row>

        <Row label="Dependensi">
          <DependencyList taskId={id} />
        </Row>

        <Row label="Dokumen">
          <DocumentList task={task} />
        </Row>
      </dl>
    </section>
  )
}
