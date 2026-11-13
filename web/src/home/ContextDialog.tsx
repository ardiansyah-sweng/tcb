import { useMutation, useQuery } from '@tanstack/react-query'
import { useEffect, useId, useRef, useState, type KeyboardEvent, type ReactNode, type SubmitEvent } from 'react'
import { api, request } from '../api/client'
import { contextDraftQueryKey } from '../api/queryKeys'
import { useRefreshAfterChange, useRunningTasks, useTaskDetail, type ContextInput, type Task } from '../api/tasks'
import { useMe } from '../auth/session'
import { ErrorMessage } from '../components/ErrorMessage'
import type { DialogRequest } from './actions'
import { formatScore } from './format'
import { useNow } from './useNow'

/**
 * Context form (R1-16, R1-17) used by Jeda (running → ready), Terblokir
 * (running → waiting), Tunda from the Papan status (running → suspended,
 * R1-18) and pindah task (POST /switch). Filled from GET
 * /contexts/draft so the user only corrects it. The switch variant shows the
 * task left and the task started and lets the task left go to Ready (default)
 * or Waiting (the blocker is the reason). Keyboard: focus starts in
 * "Sampai mana?", Tab stays inside, Enter saves, Esc closes.
 */

const inputClass =
  'mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-slate-900 focus:border-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-200'

const FOCUSABLE = 'a[href], button, input, select, textarea, [tabindex]'

function orNull(value: string): string | null {
  const trimmed = value.trim()
  return trimmed === '' ? null : trimmed
}

/** Keeps Tab and Shift+Tab inside the dialog (modal). */
function trapTab(event: KeyboardEvent<HTMLDivElement>) {
  if (event.key !== 'Tab') return
  const focusable = Array.from(event.currentTarget.querySelectorAll<HTMLElement>(FOCUSABLE)).filter(
    (el) => el.tabIndex >= 0 && !el.hasAttribute('disabled'),
  )
  if (focusable.length === 0) return
  const first = focusable[0]
  const last = focusable[focusable.length - 1]
  if (event.shiftKey && document.activeElement === first) {
    event.preventDefault()
    last.focus()
  } else if (!event.shiftKey && document.activeElement === last) {
    event.preventDefault()
    first.focus()
  }
}

export function ContextDialog({ request: req, onClose }: { request: DialogRequest; onClose: () => void }) {
  const titleId = useId()
  const running = useRunningTasks()
  const me = useMe()

  const candidates: Task[] = req.mode === 'switch' ? (req.leaving ?? running.data?.data ?? []) : [req.task]
  const [fromId, setFromId] = useState<number | null>(null)
  const from = candidates.find((t) => t.id === fromId) ?? candidates[0]

  const [pending, setPending] = useState(false)
  useEffect(() => {
    function onKey(event: globalThis.KeyboardEvent) {
      if (event.key === 'Escape' && !pending) onClose()
    }
    document.addEventListener('keydown', onKey)
    return () => document.removeEventListener('keydown', onKey)
  }, [onClose, pending])

  // Give focus back to whatever opened the dialog.
  const opener = useRef<Element | null>(null)
  useEffect(() => {
    opener.current = document.activeElement
    return () => {
      const element = opener.current
      if (element instanceof HTMLElement && element.isConnected) element.focus()
    }
  }, [])

  let title: string
  let description: string | null = null
  if (req.mode === 'switch') {
    title = 'Simpan konteks sebelum pindah'
    if (from) {
      const count = running.data?.data?.length
      const limit = me.data?.wip_limit
      const full = count !== undefined && limit !== undefined ? `Running sudah penuh (${count}/${limit}). ` : ''
      description = `${full}${from.task_key} dipindah dengan konteks di bawah, lalu ${req.target.task_key} mulai berjalan.`
    }
  } else if (req.mode === 'block') {
    title = `Terblokir ${req.task.task_key}`
    description = `${req.task.task_key} pindah ke Waiting sampai hambatannya teratasi.`
  } else if (req.mode === 'suspend') {
    title = `Tunda ${req.task.task_key}`
    description = `${req.task.task_key} pindah ke Suspended dengan konteks di bawah.`
  } else {
    title = `Jeda ${req.task.task_key}`
    description = `${req.task.task_key} kembali ke Ready dengan konteks di bawah.`
  }

  let body: ReactNode
  if (from) {
    body = <ContextForm key={from.id} req={req} from={from} onClose={onClose} onPendingChange={setPending} />
  } else if (running.isPending && req.mode === 'switch' && req.leaving === undefined) {
    body = <p className="mt-4 text-slate-600">Memuat…</p>
  } else {
    body = (
      <>
        <p className="mt-4 text-slate-600">Tidak ada task yang sedang berjalan.</p>
        <div className="mt-6 flex justify-end">
          <button type="button" onClick={onClose} className="rounded-lg border border-slate-300 px-4 py-2 text-slate-800 hover:bg-slate-100">
            Batal
          </button>
        </div>
      </>
    )
  }

  return (
    <div className="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/60 p-4 md:items-center">
      <div
        role="dialog"
        aria-modal="true"
        aria-labelledby={titleId}
        onKeyDown={trapTab}
        className="w-full max-w-2xl rounded-2xl bg-white p-5 shadow-xl md:p-8"
      >
        <h2 id={titleId} className="text-xl font-semibold text-slate-900 md:text-2xl">
          {title}
        </h2>
        {description && <p className="mt-2 text-slate-600">{description}</p>}

        {req.mode === 'switch' && candidates.length > 1 && (
          <label className="mt-4 block text-sm font-medium text-slate-800">
            Task yang ditinggalkan
            <select className={inputClass} value={from?.id} onChange={(e) => setFromId(Number(e.target.value))} disabled={pending}>
              {candidates.map((t) => (
                <option key={t.id} value={t.id}>
                  {`${t.task_key} · ${t.title}`}
                </option>
              ))}
            </select>
          </label>
        )}

        {req.mode === 'switch' && from && <SwitchCards from={from} req={req} />}

        {body}
      </div>
    </div>
  )
}

function SwitchCards({ from, req }: { from: Task; req: Extract<DialogRequest, { mode: 'switch' }> }) {
  const leftId = useId()
  const startedId = useId()
  const detail = useTaskDetail(from.id)
  const now = useNow()
  const started = detail.data?.current_session_started_at ?? null
  const switches = (detail.data?.switch_count ?? from.switch_count) + 1
  const session = started ? `${Math.max(0, Math.floor((now - new Date(started).getTime()) / 60_000))} mnt sesi ini · ` : ''

  return (
    <div className="mt-5 grid gap-3 sm:grid-cols-2">
      <div role="group" aria-labelledby={leftId} className="rounded-xl border border-slate-200 p-4">
        <p id={leftId} className="text-xs font-semibold uppercase tracking-wide text-slate-500">
          Ditinggalkan
        </p>
        <p className="mt-1 font-mono text-sm text-slate-600">{from.task_key}</p>
        <p className="mt-1 text-slate-900">{from.title}</p>
        <p className="mt-1 text-sm text-slate-600">{`${session}pindah ke-${switches}`}</p>
      </div>
      <div role="group" aria-labelledby={startedId} className="rounded-xl border-2 border-blue-700 bg-blue-50 p-4">
        <p id={startedId} className="text-xs font-semibold uppercase tracking-wide text-blue-800">
          Dimulai
        </p>
        <p className="mt-1 font-mono text-sm text-blue-800">{req.target.task_key}</p>
        <p className="mt-1 text-slate-900">{req.target.title}</p>
        {req.origin && (
          <p className="mt-1 text-sm text-blue-800">{`Rekomendasi #${req.origin.rank} · skor ${formatScore(req.origin.score)}`}</p>
        )}
      </div>
    </div>
  )
}

function ContextForm({
  req,
  from,
  onClose,
  onPendingChange,
}: {
  req: DialogRequest
  from: Task
  onClose: () => void
  onPendingChange: (pending: boolean) => void
}) {
  const draft = useQuery({
    queryKey: contextDraftQueryKey(from.id),
    queryFn: () => request(api.GET('/tasks/{taskId}/contexts/draft', { params: { path: { taskId: String(from.id) } } })),
    staleTime: 0,
    gcTime: 0,
    retry: false,
  })

  if (draft.isPending) return <p className="mt-4 text-slate-600">Memuat isian…</p>
  return (
    <ContextFields
      req={req}
      from={from}
      draft={draft.data ?? null}
      draftFailed={draft.isError}
      onClose={onClose}
      onPendingChange={onPendingChange}
    />
  )
}

function ContextFields({
  req,
  from,
  draft,
  draftFailed,
  onClose,
  onPendingChange,
}: {
  req: DialogRequest
  from: Task
  draft: ContextInput | null
  draftFailed: boolean
  onClose: () => void
  onPendingChange: (pending: boolean) => void
}) {
  const unit = from.progress_unit ?? null
  const currentDone = draft?.unit_done ?? unit?.done ?? null

  const [lastNote, setLastNote] = useState(draft?.last_note ?? '')
  const [nextStep, setNextStep] = useState(draft?.next_step ?? '')
  const [blocker, setBlocker] = useState(draft?.blocker ?? '')
  const [unitText, setUnitText] = useState(currentDone === null ? '' : String(currentDone))
  const [fromTo, setFromTo] = useState<'ready' | 'waiting'>('ready')
  const [problem, setProblem] = useState<string | null>(null)
  const refresh = useRefreshAfterChange()
  const destinationId = useId()

  const block = req.mode === 'block'
  const needsBlocker = block || (req.mode === 'switch' && fromTo === 'waiting')
  const unitValue = unitText.trim() === '' ? null : Number(unitText)

  const save = useMutation({
    mutationFn: async (context: ContextInput) => {
      const taskId = String(from.id)
      if (req.mode === 'switch') {
        await request(
          api.POST('/switch', {
            body: {
              from_task_id: taskId,
              from_to: fromTo,
              ...(fromTo === 'waiting' ? { reason: context.blocker } : {}),
              to_task_id: String(req.target.id),
              recommendation_id: req.recommendationId,
              context,
            },
          }),
        )
      } else if (req.mode === 'block') {
        await request(
          api.POST('/tasks/{taskId}/transitions', {
            params: { path: { taskId } },
            body: { to: 'waiting', reason: context.blocker, context },
          }),
        )
      } else {
        const to = req.mode === 'suspend' ? 'suspended' : 'ready'
        await request(api.POST('/tasks/{taskId}/transitions', { params: { path: { taskId } }, body: { to, context } }))
      }
    },
    onMutate: () => onPendingChange(true),
    onSettled: () => onPendingChange(false),
    onSuccess: async () => {
      onClose()
      await refresh()
    },
  })

  function step(delta: number) {
    if (unit === null) return
    const base = unitValue === null || Number.isNaN(unitValue) ? (currentDone ?? 0) : unitValue
    setUnitText(String(Math.min(unit.total, Math.max(0, base + delta))))
  }

  function submit(event: SubmitEvent<HTMLFormElement>) {
    event.preventDefault()
    if (needsBlocker && orNull(blocker) === null) {
      setProblem(block ? 'Isi apa yang ditunggu.' : 'Isi blocker sebagai alasan menunggu.')
      return
    }
    let unitDone = currentDone
    if (unit !== null && unitValue !== null) {
      if (Number.isNaN(unitValue) || unitValue < 0 || unitValue > unit.total) {
        setProblem(`Jumlah selesai harus antara 0 dan ${unit.total}.`)
        return
      }
      unitDone = unitValue
    }
    setProblem(null)
    save.mutate({
      last_note: orNull(lastNote),
      next_step: orNull(nextStep),
      blocker: orNull(blocker),
      unit_done: unit === null ? (draft?.unit_done ?? null) : unitDone,
      links: draft?.links ?? [],
    })
  }

  let blockerLabel: ReactNode
  if (block) blockerLabel = 'Menunggu apa?'
  else if (needsBlocker) blockerLabel = 'Blocker (wajib untuk Waiting)'
  else
    blockerLabel = (
      <>
        Blocker <span className="font-normal text-slate-500">(opsional)</span>
      </>
    )

  let submitLabel = 'Simpan & jeda'
  if (req.mode === 'switch') submitLabel = 'Simpan & pindah'
  else if (block) submitLabel = 'Simpan & tandai menunggu'
  else if (req.mode === 'suspend') submitLabel = 'Simpan & tunda'
  const stepButton = 'h-10 w-10 shrink-0 rounded-md border border-slate-300 text-lg text-slate-800 hover:bg-slate-100 disabled:opacity-40'

  return (
    <form onSubmit={submit} className="mt-5 space-y-4" noValidate>
      {draftFailed && (
        <p className="rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-900">Isian awal tidak bisa dimuat; form dikosongkan.</p>
      )}

      <div>
        <label className="block text-sm font-medium text-slate-800">
          Sampai mana?
          <textarea className={`${inputClass} min-h-24`} value={lastNote} onChange={(e) => setLastNote(e.target.value)} autoFocus />
        </label>
        <p className="mt-1 text-xs text-slate-500">Diisi awal dari konteks terakhir; cukup koreksi bagian yang berubah.</p>
      </div>

      {unit !== null && (
        <div className="flex flex-wrap items-center gap-2">
          <span className="text-sm font-medium text-slate-800">
            Progres <span className="font-normal text-slate-500">(opsional)</span>
          </span>
          <button
            type="button"
            aria-label="Kurangi satu"
            className={stepButton}
            onClick={() => step(-1)}
            disabled={unitValue !== null && unitValue <= 0}
          >
            −
          </button>
          <input
            type="number"
            aria-label="Jumlah selesai"
            inputMode="decimal"
            min={0}
            max={unit.total}
            value={unitText}
            onChange={(e) => setUnitText(e.target.value)}
            className="h-10 w-20 rounded-md border border-slate-300 text-center text-slate-900"
          />
          <button
            type="button"
            aria-label="Tambah satu"
            className={stepButton}
            onClick={() => step(1)}
            disabled={unitValue !== null && unitValue >= unit.total}
          >
            +
          </button>
          <span className="text-slate-700">{`dari ${unit.total} ${unit.name}`}</span>
        </div>
      )}

      <label className="block text-sm font-medium text-slate-800">
        Langkah berikutnya
        <input type="text" className={inputClass} value={nextStep} onChange={(e) => setNextStep(e.target.value)} />
      </label>

      <label className="block text-sm font-medium text-slate-800">
        {blockerLabel}
        <input
          type="text"
          className={inputClass}
          value={blocker}
          onChange={(e) => setBlocker(e.target.value)}
          placeholder="Misalnya: menunggu nilai dari asisten"
          required={needsBlocker}
        />
      </label>

      {req.mode === 'switch' && (
        <div>
          <p id={destinationId} className="text-sm font-medium text-slate-800">{`Pindahkan ${from.task_key} ke`}</p>
          <div role="radiogroup" aria-labelledby={destinationId} className="mt-2 flex flex-wrap gap-2">
            {(
              [
                ['ready', 'Ready'],
                ['waiting', 'Waiting (isi blocker)'],
              ] as const
            ).map(([value, label]) => (
              <label
                key={value}
                className={`flex items-center gap-2 rounded-lg border px-4 py-2 ${
                  fromTo === value ? 'border-blue-700 ring-1 ring-blue-700' : 'border-slate-300'
                }`}
              >
                <input type="radio" name="from_to" value={value} checked={fromTo === value} onChange={() => setFromTo(value)} />
                {label}
              </label>
            ))}
          </div>
        </div>
      )}

      {problem && (
        <p role="alert" className="text-sm text-red-700">
          {problem}
        </p>
      )}
      {save.isError && <ErrorMessage error={save.error} />}

      <div className="flex flex-wrap justify-end gap-2 border-t border-slate-200 pt-4">
        <button
          type="button"
          onClick={onClose}
          disabled={save.isPending}
          className="rounded-lg border border-slate-300 px-4 py-2 text-slate-800 hover:bg-slate-100 disabled:opacity-60"
        >
          Batal
        </button>
        <button
          type="submit"
          disabled={save.isPending}
          className="rounded-lg bg-blue-700 px-4 py-2 font-medium text-white hover:bg-blue-800 disabled:opacity-60"
        >
          {submitLabel}
        </button>
      </div>
    </form>
  )
}
