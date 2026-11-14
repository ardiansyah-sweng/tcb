import { useState, type SubmitEvent } from 'react'
import { Link } from 'react-router'
import { useAddSubtask, useUpdateTask } from '../api/taskDetail'
import type { TaskDetail } from '../api/tasks'
import { STATE_LABELS } from '../board/format'
import { ErrorMessage } from '../components/ErrorMessage'
import { inputClass, linkButton } from './FieldEditor'

/**
 * Subtasks (FR-11): a new one needs only its title (POST /tasks with
 * parent_task_id); "Lepas" detaches one (PATCH parent_task_id: null).
 */
export function SubtaskList({ task }: { task: TaskDetail }) {
  const subtasks = task.subtasks ?? []
  const [title, setTitle] = useState('')
  const add = useAddSubtask()
  const detach = useUpdateTask()
  const done = subtasks.filter((s) => s.state === 'done').length

  function submit(event: SubmitEvent<HTMLFormElement>) {
    event.preventDefault()
    const value = title.trim()
    if (value === '') return
    add.mutate({ parentId: task.id, title: value }, { onSuccess: () => setTitle('') })
  }

  return (
    <div className="space-y-2">
      <p>{subtasks.length === 0 ? 'Belum ada subtask' : `${done} dari ${subtasks.length} Done`}</p>
      {subtasks.length > 0 && (
        <ul className="space-y-1">
          {subtasks.map((s) => (
            <li key={s.id} className="flex items-start justify-between gap-2">
              <Link to={`/tasks/${s.task_key}`} className="min-w-0 text-blue-700 hover:underline">
                <span className="font-mono text-sm">{s.task_key}</span> {s.title}
                <span className="ml-1 text-sm text-slate-500">· {STATE_LABELS[s.state]}</span>
              </Link>
              <button
                type="button"
                aria-label={`Lepas ${s.task_key}`}
                disabled={detach.isPending}
                onClick={() => detach.mutate({ taskId: s.id, body: { parent_task_id: null } })}
                className={`${linkButton} shrink-0`}
              >
                Lepas
              </button>
            </li>
          ))}
        </ul>
      )}
      <form onSubmit={submit} className="flex gap-2" noValidate>
        <input
          type="text"
          aria-label="Subtask baru"
          placeholder="Subtask baru, Enter"
          value={title}
          onChange={(e) => setTitle(e.target.value)}
          disabled={add.isPending}
          className={inputClass}
        />
      </form>
      {add.isError && <ErrorMessage error={add.error} />}
      {detach.isError && <ErrorMessage error={detach.error} />}
    </div>
  )
}
