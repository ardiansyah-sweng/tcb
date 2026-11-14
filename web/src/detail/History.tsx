import { useContexts, useTransitionLog } from '../api/taskDetail'
import { STATE_LABELS } from '../board/format'
import { ErrorMessage } from '../components/ErrorMessage'
import { formatDayTime } from '../home/format'
import { SOURCE_LABELS, number, transitionOf } from './format'

const card = 'rounded-2xl border border-slate-200 bg-white p-5 md:p-6'

function move(from: string | null | undefined, to: string): string {
  const fromLabel = from ? STATE_LABELS[from as keyof typeof STATE_LABELS] : 'Dibuat'
  return `${fromLabel} → ${STATE_LABELS[to as keyof typeof STATE_LABELS]}`
}

/**
 * Riwayat konteks (FR-08): a timeline, newest first. Each entry is labelled
 * with the transition saved with it, or "Catatan" for a note saved without
 * a state change.
 */
export function ContextTimeline({ taskId, timeZone }: { taskId: number; timeZone: string }) {
  const contexts = useContexts(taskId)
  const log = useTransitionLog(taskId)
  const entries = contexts.data?.data ?? []
  const transitions = log.data?.data ?? []

  return (
    <section aria-labelledby="riwayat-konteks" className={card}>
      <h2 id="riwayat-konteks" className="text-lg font-semibold text-slate-900">
        Riwayat konteks
      </h2>
      {contexts.isError && <ErrorMessage error={contexts.error} className="mt-3" />}
      {contexts.isPending && <p className="mt-3 text-slate-600">Memuat…</p>}
      {contexts.isSuccess && entries.length === 0 && <p className="mt-3 text-slate-600">Belum ada konteks tersimpan.</p>}
      {entries.length > 0 && (
        <ol aria-label="Riwayat konteks" className="mt-4 space-y-5">
          {entries.map((c, i) => {
            const t = transitionOf(c, transitions)
            return (
              <li key={c.id} className="relative border-l-2 border-slate-200 pl-5">
                <span aria-hidden className={`absolute -left-[7px] top-1 h-3 w-3 rounded-full ${i === 0 ? 'bg-blue-600' : 'bg-slate-400'}`} />
                <p className={`text-sm font-medium ${i === 0 ? 'text-blue-800' : 'text-slate-500'}`}>
                  {formatDayTime(c.created_at, timeZone)} · {t ? move(t.from_state, t.to_state) : 'Catatan'}
                </p>
                {c.last_note && <p className="mt-1 whitespace-pre-line text-slate-900">{c.last_note}</p>}
                {c.next_step && <p className="mt-1 text-sm text-slate-600">Berikutnya: {c.next_step}</p>}
                {c.blocker && <p className="mt-1 text-sm text-amber-800">Blocker: {c.blocker}</p>}
                {c.unit_done !== null && c.unit_done !== undefined && <p className="mt-1 text-sm text-slate-500">Progres: {number(c.unit_done)}</p>}
                {(c.links ?? []).length > 0 && (
                  <p className="mt-1 flex flex-wrap gap-x-3 text-sm">
                    {(c.links ?? []).map((l) => (
                      <a key={l.url} href={l.url} target="_blank" rel="noreferrer" className="text-blue-700 underline">
                        {l.title || l.url}
                      </a>
                    ))}
                  </p>
                )}
              </li>
            )
          })}
        </ol>
      )}
    </section>
  )
}

/** Log transisi (FR-09): the append-only log, newest first. */
export function TransitionLog({ taskId, timeZone }: { taskId: number; timeZone: string }) {
  const log = useTransitionLog(taskId)
  const rows = log.data?.data ?? []

  return (
    <section aria-labelledby="log-transisi" className={card}>
      <h2 id="log-transisi" className="text-lg font-semibold text-slate-900">
        Log transisi
      </h2>
      {log.isError && <ErrorMessage error={log.error} className="mt-3" />}
      <div className="mt-3 overflow-x-auto">
        <table aria-labelledby="log-transisi" className="w-full min-w-[32rem] text-left text-sm md:text-base">
          <thead className="border-b border-slate-200 text-slate-500">
            <tr>
              <th scope="col" className="py-2 pr-3 font-medium">Waktu</th>
              <th scope="col" className="py-2 pr-3 font-medium">Dari → Ke</th>
              <th scope="col" className="py-2 pr-3 font-medium">Alasan</th>
              <th scope="col" className="py-2 font-medium">Sumber</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((t) => (
              <tr key={t.id} className="border-b border-slate-100 align-top">
                <td className="py-2 pr-3 whitespace-nowrap">{formatDayTime(t.occurred_at, timeZone)}</td>
                <td className="py-2 pr-3">{move(t.from_state, t.to_state)}</td>
                <td className="py-2 pr-3">{t.reason || '—'}</td>
                <td className="py-2">{SOURCE_LABELS[t.source]}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </section>
  )
}
