import { Link } from 'react-router'
import { useRunningTasks, useTaskDetail, useTransitionTask, type Task } from '../api/tasks'
import { ErrorMessage } from '../components/ErrorMessage'
import { useHomeActions } from './actions'
import { focusRecommendations } from './focus'
import { formatDayTime, formatDuration } from './format'
import { useNow } from './useNow'

const secondaryButton =
  'rounded-lg border border-slate-300 bg-white px-4 py-2 text-slate-800 hover:bg-slate-100 disabled:opacity-60'

/** FR-08, FR-18: the running task(s) with their timer and last context. */
export function RunningPanel({ timeZone }: { timeZone: string }) {
  const running = useRunningTasks()
  const tasks = running.data?.data ?? []

  return (
    <section aria-label="Sedang berjalan" className="space-y-4">
      {running.isError && <ErrorMessage error={running.error} />}
      {running.isPending && <p className="rounded-2xl border border-slate-200 bg-white p-6 text-slate-500">Memuat…</p>}
      {running.isSuccess && tasks.length === 0 && (
        <div className="rounded-2xl border border-dashed border-slate-300 bg-white p-6">
          <p className="font-medium text-slate-900">Tidak ada task yang sedang berjalan.</p>
          <p className="mt-1 text-slate-600">Pilih satu dari rekomendasi, lalu tekan Mulai.</p>
          <button type="button" onClick={focusRecommendations} className={`${secondaryButton} mt-4`}>
            Lihat rekomendasi
          </button>
        </div>
      )}
      {tasks.map((task) => (
        <RunningTask key={task.id} task={task} timeZone={timeZone} />
      ))}
    </section>
  )
}

function RunningTask({ task: listed, timeZone }: { task: Task; timeZone: string }) {
  const detail = useTaskDetail(listed.id)
  const task = detail.data ?? listed
  const sessionStart = detail.data?.current_session_started_at ?? null
  const context = detail.data?.latest_context ?? null
  const now = useNow()
  const { openDialog } = useHomeActions()
  const finish = useTransitionTask()

  const sessionSeconds = sessionStart ? (now - new Date(sessionStart).getTime()) / 1000 : 0
  const totalMinutes = task.time_spent_minutes + Math.floor(Math.max(0, sessionSeconds) / 60)
  const total = task.estimate_minutes
    ? `total ${totalMinutes} dari ${task.estimate_minutes} mnt`
    : `total ${totalMinutes} mnt`

  return (
    <article className="rounded-2xl border-2 border-blue-700 bg-white p-5 md:p-8">
      <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">
        <span className="rounded-full bg-blue-50 px-3 py-1 font-medium text-blue-800">Sedang berjalan</span>
        <span className="font-mono text-slate-600">{task.task_key}</span>
        {task.key_result_title ? (
          <Link to="/goals" className="text-blue-700 underline hover:text-blue-900">
            {`KR · ${task.key_result_title}`}
          </Link>
        ) : (
          <span className="text-slate-500">Tanpa KR (orphan)</span>
        )}
      </div>

      <div className="mt-4">
        <p className="font-mono text-3xl font-semibold text-slate-900" aria-label="Timer sesi">
          {sessionStart ? formatDuration(sessionSeconds) : '–'}
        </p>
        <p className="text-sm text-slate-600">{`sesi ini · ${total}`}</p>
      </div>

      <h2 className="mt-4 text-2xl font-semibold text-slate-900 md:text-3xl">{task.title}</h2>

      <div className="mt-4 rounded-xl bg-slate-50 p-4 text-slate-800">
        {detail.isPending ? (
          <p className="text-slate-500">Memuat konteks…</p>
        ) : context === null ? (
          <p className="text-slate-500">Belum ada konteks tersimpan.</p>
        ) : (
          <>
            <p className="text-xs font-semibold uppercase tracking-wide text-slate-600">
              {`Terakhir (konteks disimpan ${formatDayTime(context.created_at, timeZone)})`}
            </p>
            {context.last_note && <p className="mt-1 whitespace-pre-line">{context.last_note}</p>}
            {context.next_step && (
              <>
                <p className="mt-3 text-xs font-semibold uppercase tracking-wide text-slate-600">Langkah berikutnya</p>
                <p className="mt-1">{context.next_step}</p>
              </>
            )}
            {context.links && context.links.length > 0 && (
              <div className="mt-3 flex flex-wrap gap-2">
                {context.links.map((link) => (
                  <a
                    key={link.url}
                    href={link.url}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="max-w-full truncate rounded-md border border-slate-200 bg-white px-3 py-1 text-sm text-blue-700 hover:bg-blue-50"
                  >
                    {link.title || link.url}
                  </a>
                ))}
              </div>
            )}
          </>
        )}
      </div>

      {detail.isError && <ErrorMessage error={detail.error} className="mt-3" />}
      {finish.isError && <ErrorMessage error={finish.error} className="mt-3" />}

      <div className="mt-5 flex flex-wrap items-center gap-2">
        <button
          type="button"
          disabled={finish.isPending}
          onClick={() => finish.mutate({ taskId: task.id, body: { to: 'done' } })}
          className="rounded-lg bg-blue-700 px-4 py-2 font-medium text-white hover:bg-blue-800 disabled:opacity-60"
        >
          Selesai
        </button>
        <button type="button" className={secondaryButton} onClick={() => openDialog({ mode: 'pause', task })}>
          Jeda
        </button>
        <button type="button" className={secondaryButton} onClick={() => openDialog({ mode: 'block', task })}>
          Terblokir…
        </button>
        <button type="button" className={secondaryButton} onClick={focusRecommendations}>
          Pindah task
        </button>
        <Link to={`/tasks/${task.task_key}`} className="ml-auto text-blue-700 underline hover:text-blue-900">
          Lihat detail task
        </Link>
      </div>
    </article>
  )
}
