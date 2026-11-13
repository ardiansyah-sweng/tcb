import { useEffect, useId, useRef, useState, type SubmitEvent } from 'react'
import { useTransitionTask, type Task } from '../api/tasks'
import { ErrorMessage } from '../components/ErrorMessage'

/**
 * Asks the reason a transition needs (to Waiting or Cancelled) after a card
 * is dropped on the Papan status (R1-18). The API decides that a reason is
 * required (422 reason_required); this form only collects it. Errors stay in
 * the dialog with the text kept; Esc and Batal close without saving.
 */
export function ReasonDialog({
  task,
  to,
  onClose,
  onDone,
}: {
  task: Task
  to: 'waiting' | 'cancelled'
  onClose: () => void
  onDone: () => void
}) {
  const titleId = useId()
  const inputId = useId()
  const [reason, setReason] = useState('')
  const [missing, setMissing] = useState(false)
  const transition = useTransitionTask()

  useEffect(() => {
    function onKey(event: KeyboardEvent) {
      if (event.key === 'Escape' && !transition.isPending) onClose()
    }
    document.addEventListener('keydown', onKey)
    return () => document.removeEventListener('keydown', onKey)
  }, [onClose, transition.isPending])

  // Give focus back to whatever had it before the dialog.
  const opener = useRef<Element | null>(null)
  useEffect(() => {
    opener.current = document.activeElement
    return () => {
      const element = opener.current
      if (element instanceof HTMLElement && element.isConnected) element.focus()
    }
  }, [])

  function submit(event: SubmitEvent<HTMLFormElement>) {
    event.preventDefault()
    const value = reason.trim()
    if (value === '') {
      setMissing(true)
      return
    }
    setMissing(false)
    transition.mutate({ taskId: task.id, body: { to, reason: value } }, { onSuccess: onDone })
  }

  const waiting = to === 'waiting'
  return (
    <div className="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/60 p-4 md:items-center">
      <div role="dialog" aria-modal="true" aria-labelledby={titleId} className="w-full max-w-lg rounded-2xl bg-white p-5 shadow-xl md:p-6">
        <h2 id={titleId} className="text-xl font-semibold text-slate-900">
          {waiting ? `Pindahkan ${task.task_key} ke Waiting` : `Batalkan ${task.task_key}`}
        </h2>
        <p className="mt-1 text-slate-600">{task.title}</p>
        <form onSubmit={submit} className="mt-4 space-y-3" noValidate>
          <div>
            <label htmlFor={inputId} className="block text-sm font-medium text-slate-800">
              {waiting ? 'Menunggu apa?' : 'Alasan'}
            </label>
            <input
              id={inputId}
              type="text"
              value={reason}
              onChange={(e) => setReason(e.target.value)}
              autoFocus
              className="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-slate-900 focus:border-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-200"
            />
          </div>
          {missing && <p className="text-sm text-red-700">Isi alasannya dulu.</p>}
          {transition.isError && <ErrorMessage error={transition.error} />}
          <div className="flex justify-end gap-2">
            <button type="button" onClick={onClose} disabled={transition.isPending} className="rounded-lg border border-slate-300 px-4 py-2 text-slate-800 hover:bg-slate-100">
              Batal
            </button>
            <button
              type="submit"
              disabled={transition.isPending}
              className={`rounded-lg px-4 py-2 font-medium text-white disabled:opacity-60 ${waiting ? 'bg-blue-700 hover:bg-blue-800' : 'bg-red-700 hover:bg-red-800'}`}
            >
              {waiting ? 'Simpan & tandai menunggu' : 'Batalkan task'}
            </button>
          </div>
        </form>
      </div>
    </div>
  )
}
