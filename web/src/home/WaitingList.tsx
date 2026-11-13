import { useMutation } from '@tanstack/react-query'
import { api, request, type Schemas } from '../api/client'
import { useRefreshAfterChange, useTaskDetail, useTransitionTask, useWaitingTasks, type Task } from '../api/tasks'
import { ErrorMessage } from '../components/ErrorMessage'

type Dependency = Schemas['Dependency']

const button = 'shrink-0 rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm text-slate-800 hover:bg-slate-100 disabled:opacity-60'

/**
 * Waiting tasks with what they wait for (decision 13 Nov 2026):
 * - an external blocker (dependency row) → "Tandai teratasi" resolves it;
 * - another task → released automatically when that task ends, no button;
 * - only a reason (from Terblokir) → "Tandai teratasi" moves it to Ready.
 */
export function WaitingList() {
  const waiting = useWaitingTasks()
  const tasks = waiting.data?.data ?? []
  const title = waiting.isSuccess ? `Menunggu (${tasks.length})` : 'Menunggu'

  return (
    <section aria-labelledby="menunggu" className="rounded-2xl border border-slate-200 bg-white p-5 md:p-8">
      <h2 id="menunggu" className="text-xl font-semibold text-slate-900">
        {title}
      </h2>
      {waiting.isPending && <p className="mt-3 text-slate-500">Memuat…</p>}
      {waiting.isError && <ErrorMessage error={waiting.error} className="mt-3" />}
      {waiting.isSuccess && tasks.length === 0 && <p className="mt-3 text-slate-600">Tidak ada task yang menunggu.</p>}
      {tasks.length > 0 && (
        <ul className="mt-3 divide-y divide-slate-200 border-t border-slate-200">
          {tasks.map((task) => (
            <WaitingTask key={task.id} task={task} />
          ))}
        </ul>
      )}
    </section>
  )
}

function WaitingTask({ task }: { task: Task }) {
  const detail = useTaskDetail(task.id)
  const dependencies = detail.data?.open_dependencies ?? []
  const toReady = useTransitionTask()

  return (
    <li aria-label={`${task.task_key} ${task.title}`} className="py-4">
      <p>
        <span className="mr-2 font-mono text-sm text-slate-600">{task.task_key}</span>
        <span className="text-slate-900">{task.title}</span>
      </p>

      {detail.isSuccess && dependencies.length > 0 ? (
        <div className="mt-1 space-y-2">
          {dependencies.map((dependency) => (
            <DependencyLine key={dependency.id} dependency={dependency} />
          ))}
        </div>
      ) : (
        <div className="mt-1 flex flex-wrap items-center justify-between gap-2">
          <p className="text-sm text-amber-800">{`Menunggu: ${task.state_reason ?? 'tanpa keterangan'}`}</p>
          {detail.isSuccess && (
            <button
              type="button"
              className={button}
              disabled={toReady.isPending}
              onClick={() => toReady.mutate({ taskId: task.id, body: { to: 'ready' } })}
            >
              Tandai teratasi
            </button>
          )}
        </div>
      )}

      {detail.isError && <ErrorMessage error={detail.error} className="mt-2" />}
      {toReady.isError && <ErrorMessage error={toReady.error} className="mt-2" />}
    </li>
  )
}

function DependencyLine({ dependency }: { dependency: Dependency }) {
  const refresh = useRefreshAfterChange()
  const resolve = useMutation({
    mutationFn: () =>
      request(
        api.PATCH('/dependencies/{dependencyId}', {
          params: { path: { dependencyId: dependency.id } },
          body: { status: 'resolved' },
        }),
      ),
    onSuccess: refresh,
  })

  if (dependency.external_description == null) {
    return (
      <p className="text-sm text-amber-800">
        {`Menunggu task ${dependency.depends_on_task_key ?? '(dihapus)'} · pindah ke Ready otomatis saat selesai`}
      </p>
    )
  }

  return (
    <div>
      <div className="flex flex-wrap items-center justify-between gap-2">
        <p className="text-sm text-amber-800">{`Menunggu: ${dependency.external_description}`}</p>
        <button type="button" className={button} disabled={resolve.isPending} onClick={() => resolve.mutate()}>
          Tandai teratasi
        </button>
      </div>
      {resolve.isError && <ErrorMessage error={resolve.error} className="mt-2" />}
    </div>
  )
}
