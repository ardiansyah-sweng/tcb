import { useMutation } from '@tanstack/react-query'
import { api, request, type Schemas } from '../api/client'
import { useRecommendations } from '../api/recommendations'
import { useRefreshAfterChange, useRunningTasks } from '../api/tasks'
import { ErrorMessage } from '../components/ErrorMessage'
import { useStartTask } from './actions'
import { formatScore } from './format'
import { RECOMMENDATIONS_HEADING_ID } from './focus'

type Recommendation = Schemas['Recommendation']

/** FR-12, FR-13: three recommendations with their reasons; start, switch, or skip. */
export function Recommendations() {
  const batch = useRecommendations()
  const running = useRunningTasks()
  const runningFull = batch.data?.running_full ?? false
  const runningTasks = running.data?.data ?? []

  let hint: string | null = null
  if (runningFull) {
    hint =
      runningTasks.length === 1
        ? `Running penuh, jadi memilih task akan menyimpan konteks ${runningTasks[0].task_key} dulu.`
        : 'Running penuh, jadi memilih task akan menyimpan konteks task yang berjalan dulu.'
  }

  return (
    <section aria-labelledby={RECOMMENDATIONS_HEADING_ID}>
      <h2 id={RECOMMENDATIONS_HEADING_ID} tabIndex={-1} className="text-xl font-semibold text-slate-900 focus:outline-none">
        Rekomendasi berikutnya
      </h2>
      {hint && <p className="mt-1 text-sm text-slate-600">{hint}</p>}

      <div className="mt-3 space-y-3">
        {batch.isPending && <p className="text-slate-500">Memuat…</p>}
        {batch.isError && <ErrorMessage error={batch.error} />}
        {batch.isSuccess && batch.data.items.length === 0 && (
          <p className="rounded-2xl border border-dashed border-slate-300 bg-white p-5 text-slate-600">
            Belum ada task Ready yang bisa direkomendasikan.
          </p>
        )}
        {batch.data?.items.map((item) => (
          <RecommendationCard key={item.id} item={item} runningFull={runningFull} />
        ))}
      </div>
    </section>
  )
}

function RecommendationCard({ item, runningFull }: { item: Recommendation; runningFull: boolean }) {
  const start = useStartTask()
  const refresh = useRefreshAfterChange()
  const skip = useMutation({
    mutationFn: () =>
      request(
        api.POST('/recommendations/{recommendationId}/decision', {
          params: { path: { recommendationId: item.id } },
          body: { decision: 'skipped' },
        }),
      ),
    onSuccess: refresh,
  })
  const busy = start.isPending || skip.isPending
  const percent = Math.round(Math.min(1, Math.max(0, item.score)) * 100)

  return (
    <article className="rounded-2xl border border-slate-200 bg-white p-5">
      <div className="flex items-baseline justify-between gap-2 text-sm">
        <span className="font-mono text-slate-600">{`#${item.rank} · ${item.task.task_key}`}</span>
        <span className="font-semibold text-slate-900">{`Skor ${formatScore(item.score)}`}</span>
      </div>
      <div
        role="meter"
        aria-label="Skor"
        aria-valuemin={0}
        aria-valuemax={1}
        aria-valuenow={item.score}
        className="mt-2 h-2 overflow-hidden rounded-full bg-slate-200"
      >
        <div className="h-full rounded-full bg-blue-700" style={{ width: `${percent}%` }} />
      </div>

      <h3 className="mt-3 text-lg font-semibold text-slate-900">{item.task.title}</h3>
      {item.reasons.length > 0 && (
        <ul className="mt-2 list-disc space-y-1 pl-5 text-slate-700">
          {item.reasons.slice(0, 2).map((reason) => (
            <li key={reason}>{reason}</li>
          ))}
        </ul>
      )}
      {item.stale && (
        <p className="mt-2 rounded-md bg-amber-50 px-3 py-1 text-sm text-amber-900">
          Sudah lebih dari 14 hari di Ready: kerjakan, tunda, atau batalkan.
        </p>
      )}

      {start.isError && <ErrorMessage error={start.error} className="mt-3" />}
      {skip.isError && <ErrorMessage error={skip.error} className="mt-3" />}

      {/* "Jadwalkan" comes with Release 2 (calendar). */}
      <div className="mt-4 flex flex-wrap gap-2">
        <button
          type="button"
          disabled={busy}
          onClick={() => start.mutate({ task: item.task, recommendationId: item.id, runningFull })}
          className={
            item.rank === 1
              ? 'rounded-lg bg-blue-700 px-4 py-2 font-medium text-white hover:bg-blue-800 disabled:opacity-60'
              : 'rounded-lg border border-slate-300 px-4 py-2 text-slate-800 hover:bg-slate-100 disabled:opacity-60'
          }
        >
          {runningFull ? 'Pindah ke task ini' : 'Mulai'}
        </button>
        <button
          type="button"
          disabled={busy}
          onClick={() => skip.mutate()}
          className="rounded-lg border border-slate-300 px-4 py-2 text-slate-800 hover:bg-slate-100 disabled:opacity-60"
        >
          Lewati
        </button>
      </div>
    </article>
  )
}
