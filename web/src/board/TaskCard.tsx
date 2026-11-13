import { Link } from 'react-router'
import type { Task } from '../api/tasks'
import { formatScore } from '../home/format'
import { infoLine } from './format'

/** One draggable card: task_key, score (Ready), title, label, and one info line. */
export function TaskCard({
  task,
  timeZone,
  onDragStart,
  onDragEnd,
}: {
  task: Task
  timeZone: string
  onDragStart: (task: Task) => void
  onDragEnd: () => void
}) {
  const info = infoLine(task, timeZone)

  return (
    <article
      aria-label={`${task.task_key} ${task.title}`}
      draggable
      onDragStart={(event) => {
        event.dataTransfer?.setData('text/plain', task.task_key)
        if (event.dataTransfer) event.dataTransfer.effectAllowed = 'move'
        onDragStart(task)
      }}
      onDragEnd={onDragEnd}
      className="cursor-grab rounded-xl border border-slate-200 bg-white p-3 shadow-sm active:cursor-grabbing"
    >
      <div className="flex items-baseline justify-between gap-2">
        <span className="font-mono text-sm text-slate-600">{task.task_key}</span>
        {task.score != null && <span className="text-sm font-semibold text-slate-900">{formatScore(task.score)}</span>}
      </div>
      <p className="mt-1">
        <Link to={`/tasks/${task.task_key}`} className="text-slate-900 hover:underline" draggable={false}>
          {task.title}
        </Link>
      </p>
      {(task.label || task.is_orphan) && (
        <div className="mt-2 flex flex-wrap gap-1">
          {task.label && <span className="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-700">{task.label}</span>}
          {task.is_orphan && (
            <span className="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-900">Orphan · belum ada KR</span>
          )}
        </div>
      )}
      {info && <p className={`mt-2 text-sm ${info.tone === 'warning' ? 'text-amber-800' : 'text-slate-600'}`}>{info.text}</p>}
    </article>
  )
}
