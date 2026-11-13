import { useMutation, useQuery } from '@tanstack/react-query'
import { useEffect, useId, useState, type SubmitEvent } from 'react'
import { api, request } from '../api/client'
import { contextDraftQueryKey } from '../api/queryKeys'
import { useRefreshAfterChange, useRunningTasks, type ContextInput, type Task } from '../api/tasks'
import { ErrorMessage } from '../components/ErrorMessage'
import type { DialogRequest } from './actions'

/**
 * Context form used by Jeda (running → ready), Terblokir (running → waiting)
 * and pindah task (POST /switch). Filled from GET /contexts/draft so the user
 * only corrects it. R1-17 extends the switch variant (both task cards, unit
 * progress row, Waiting as destination).
 */

const inputClass =
  'mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-slate-900 focus:border-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-200'

function orNull(value: string): string | null {
  const trimmed = value.trim()
  return trimmed === '' ? null : trimmed
}

export function ContextDialog({ request: req, onClose }: { request: DialogRequest; onClose: () => void }) {
  const titleId = useId()
  const running = useRunningTasks()

  const candidates: Task[] = req.mode === 'switch' ? (req.leaving ?? running.data?.data ?? []) : [req.task]
  const [fromId, setFromId] = useState<number | null>(null)
  const from = candidates.find((t) => t.id === fromId) ?? candidates[0]

  const [pending, setPending] = useState(false)
  useEffect(() => {
    function onKey(event: KeyboardEvent) {
      if (event.key === 'Escape' && !pending) onClose()
    }
    document.addEventListener('keydown', onKey)
    return () => document.removeEventListener('keydown', onKey)
  }, [onClose, pending])

  let title: string
  let description: string | null = null
  if (req.mode === 'switch') {
    title = 'Simpan konteks sebelum pindah'
    if (from) description = `${from.task_key} kembali ke Ready dengan konteks di bawah, lalu ${req.target.task_key} mulai berjalan.`
  } else if (req.mode === 'block') {
    title = `Terblokir ${req.task.task_key}`
    description = `${req.task.task_key} pindah ke Waiting sampai hambatannya teratasi.`
  } else {
    title = `Jeda ${req.task.task_key}`
    description = `${req.task.task_key} kembali ke Ready dengan konteks di bawah.`
  }

  return (
    <div className="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/60 p-4 md:items-center">
      <div
        role="dialog"
        aria-modal="true"
        aria-labelledby={titleId}
        className="w-full max-w-xl rounded-2xl bg-white p-5 shadow-xl md:p-8"
      >
        <h2 id={titleId} className="text-xl font-semibold text-slate-900 md:text-2xl">
          {title}
        </h2>
        {description && <p className="mt-2 text-slate-600">{description}</p>}

        {req.mode === 'switch' && candidates.length > 1 && (
          <label className="mt-4 block text-sm font-medium text-slate-800">
            Task yang ditinggalkan
            <select
              className={inputClass}
              value={from?.id}
              onChange={(e) => setFromId(Number(e.target.value))}
              disabled={pending}
            >
              {candidates.map((t) => (
                <option key={t.id} value={t.id}>
                  {`${t.task_key} · ${t.title}`}
                </option>
              ))}
            </select>
          </label>
        )}

        {from ? (
          <ContextForm key={from.id} req={req} from={from} onClose={onClose} onPendingChange={setPending} />
        ) : running.isPending && req.mode === 'switch' && req.leaving === undefined ? (
          <p className="mt-4 text-slate-600">Memuat…</p>
        ) : (
          <>
            <p className="mt-4 text-slate-600">Tidak ada task yang sedang berjalan.</p>
            <div className="mt-6 flex justify-end">
              <button type="button" onClick={onClose} className="rounded-lg border border-slate-300 px-4 py-2 text-slate-800 hover:bg-slate-100">
                Batal
              </button>
            </div>
          </>
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
  const [lastNote, setLastNote] = useState(draft?.last_note ?? '')
  const [nextStep, setNextStep] = useState(draft?.next_step ?? '')
  const [blocker, setBlocker] = useState(draft?.blocker ?? '')
  const [missingReason, setMissingReason] = useState(false)
  const refresh = useRefreshAfterChange()
  const block = req.mode === 'block'

  const save = useMutation({
    mutationFn: async () => {
      const context: ContextInput = {
        last_note: orNull(lastNote),
        next_step: orNull(nextStep),
        blocker: orNull(blocker),
        unit_done: draft?.unit_done ?? null,
        links: draft?.links ?? [],
      }
      const taskId = String(from.id)
      if (req.mode === 'switch') {
        await request(
          api.POST('/switch', {
            body: {
              from_task_id: taskId,
              from_to: 'ready',
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
            body: { to: 'waiting', reason: orNull(blocker), context },
          }),
        )
      } else {
        await request(api.POST('/tasks/{taskId}/transitions', { params: { path: { taskId } }, body: { to: 'ready', context } }))
      }
    },
    onMutate: () => onPendingChange(true),
    onSettled: () => onPendingChange(false),
    onSuccess: async () => {
      onClose()
      await refresh()
    },
  })

  function submit(event: SubmitEvent<HTMLFormElement>) {
    event.preventDefault()
    if (block && orNull(blocker) === null) {
      setMissingReason(true)
      return
    }
    setMissingReason(false)
    save.mutate()
  }

  const submitLabel = req.mode === 'switch' ? 'Simpan & pindah' : block ? 'Simpan & tandai menunggu' : 'Simpan & jeda'

  return (
    <form onSubmit={submit} className="mt-5 space-y-4" noValidate>
      {draftFailed && (
        <p className="rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-900">Isian awal tidak bisa dimuat; form dikosongkan.</p>
      )}

      <div>
        <label className="block text-sm font-medium text-slate-800">
          Sampai mana?
          <textarea
            className={`${inputClass} min-h-24`}
            value={lastNote}
            onChange={(e) => setLastNote(e.target.value)}
            autoFocus
          />
        </label>
        <p className="mt-1 text-xs text-slate-500">Diisi awal dari konteks terakhir; cukup koreksi bagian yang berubah.</p>
      </div>

      <label className="block text-sm font-medium text-slate-800">
        Langkah berikutnya
        <input type="text" className={inputClass} value={nextStep} onChange={(e) => setNextStep(e.target.value)} />
      </label>

      <div>
        <label className="block text-sm font-medium text-slate-800">
          {block ? 'Menunggu apa?' : (
            <>
              Blocker <span className="font-normal text-slate-500">(opsional)</span>
            </>
          )}
          <input
            type="text"
            className={inputClass}
            value={blocker}
            onChange={(e) => setBlocker(e.target.value)}
            placeholder="Misalnya: menunggu nilai dari asisten"
            aria-invalid={missingReason || undefined}
            required={block}
          />
        </label>
        {missingReason && (
          <p role="alert" className="mt-1 text-sm text-red-700">
            Isi apa yang ditunggu.
          </p>
        )}
      </div>

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
