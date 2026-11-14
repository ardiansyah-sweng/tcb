import { smallButton } from '../detail/FieldEditor'

/** An inline confirmation for an action that cannot simply be undone (archive, delete). */
export function Confirm({
  question,
  confirmLabel,
  pending,
  onConfirm,
  onCancel,
}: {
  question: string
  confirmLabel: string
  pending: boolean
  onConfirm: () => void
  onCancel: () => void
}) {
  return (
    <div role="group" aria-label="Konfirmasi" className="space-y-2 rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-950">
      <p>{question}</p>
      <div className="flex flex-wrap gap-2">
        <button
          type="button"
          autoFocus
          disabled={pending}
          onClick={onConfirm}
          className="rounded-md bg-red-700 px-2.5 py-1 text-sm font-medium text-white hover:bg-red-800 disabled:opacity-60"
        >
          {confirmLabel}
        </button>
        <button type="button" disabled={pending} onClick={onCancel} className={smallButton}>
          Batal
        </button>
      </div>
    </div>
  )
}
