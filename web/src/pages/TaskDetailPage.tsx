import { useParams } from 'react-router'
import { useTaskDetailByRef } from '../api/taskDetail'
import { useMe } from '../auth/session'
import { useMoveTask } from '../board/useMoveTask'
import { ErrorMessage } from '../components/ErrorMessage'
import { DataPanel } from '../detail/DataPanel'
import { ContextTimeline, TransitionLog } from '../detail/History'
import { TaskHeader } from '../detail/TaskHeader'
import { DEFAULT_TIMEZONE } from '../home/format'

/**
 * R1-19 Detail task (FR-08, FR-09, FR-11, FR-23); layout from
 * docs/mockup/tcb-v1/05-detail task.png. The URL holds a task key (TCB-31)
 * or a numeric ID. The calendar card of the mockup waits for Release 2.
 */
export default function TaskDetailPage() {
  const { taskId } = useParams()
  if (!taskId) {
    return (
      <section>
        <h1 className="text-2xl font-semibold text-slate-900 md:text-3xl">Detail task</h1>
        <p className="mt-4 text-slate-600">Pilih task dari Beranda atau Papan status untuk melihat detailnya.</p>
      </section>
    )
  }
  // A new key gives a new page state (editors, messages).
  return <TaskDetail key={taskId} taskRef={taskId} />
}

function TaskDetail({ taskRef }: { taskRef: string }) {
  const me = useMe()
  const timeZone = me.data?.timezone || DEFAULT_TIMEZONE
  const detail = useTaskDetailByRef(taskRef)
  const { move, error, notice, dialogs } = useMoveTask()

  if (detail.isPending) return <p className="text-slate-600">Memuat…</p>
  if (detail.isError) {
    return (
      <section>
        <h1 className="text-2xl font-semibold text-slate-900 md:text-3xl">Detail task</h1>
        <ErrorMessage error={detail.error} className="mt-4" />
      </section>
    )
  }

  const task = detail.data
  return (
    <div className="mx-auto max-w-7xl">
      <TaskHeader task={task} onMove={(to) => void move(task, to)} />

      {error !== null && <ErrorMessage error={error} className="mt-4" />}
      {notice && (
        <p aria-live="polite" className="mt-4 rounded-md bg-emerald-50 px-3 py-2 text-sm text-emerald-900">
          {notice}
        </p>
      )}

      <div className="mt-6 grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,26rem)]">
        <div className="min-w-0 space-y-6">
          <ContextTimeline taskId={task.id} timeZone={timeZone} />
          <TransitionLog taskId={task.id} timeZone={timeZone} />
        </div>
        <div className="min-w-0">
          <DataPanel task={task} timeZone={timeZone} />
        </div>
      </div>

      {dialogs}
    </div>
  )
}
