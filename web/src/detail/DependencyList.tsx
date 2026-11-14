import { useState, type SubmitEvent } from 'react'
import { Link } from 'react-router'
import { useAddDependency, useChangeDependency, useDependencies, type Dependency } from '../api/taskDetail'
import { ErrorMessage } from '../components/ErrorMessage'
import { inputClass, linkButton } from './FieldEditor'

/** "TCB-36" or "36" is another task; anything else is an external blocker. */
const TASK_REF = /^(tcb-)?\d+$/i

function name(dep: Dependency): string {
  return dep.depends_on_task_key ?? dep.external_description ?? `#${dep.id}`
}

/**
 * Dependencies of the task (FR-11): what it waits for, open and resolved.
 * One input adds either kind. An external blocker can be marked resolved;
 * a task dependency is released when that task is Done, Cancelled, or
 * deleted. Every dependency can be removed.
 */
export function DependencyList({ taskId }: { taskId: number }) {
  const deps = useDependencies(taskId)
  const add = useAddDependency()
  const change = useChangeDependency()
  const [text, setText] = useState('')

  function submit(event: SubmitEvent<HTMLFormElement>) {
    event.preventDefault()
    const value = text.trim()
    if (value === '') return
    const body = TASK_REF.test(value) ? { depends_on_task_id: value.toUpperCase() } : { external_description: value }
    add.mutate({ taskId, body }, { onSuccess: () => setText('') })
  }

  const list = deps.data?.data ?? []
  return (
    <div className="space-y-2">
      {deps.isError && <ErrorMessage error={deps.error} />}
      {deps.isSuccess && list.length === 0 && <p>Tidak ada</p>}
      {list.length > 0 && (
        <ul className="space-y-1">
          {list.map((dep) => {
            const resolved = dep.status === 'resolved'
            return (
              <li key={dep.id} className="flex items-start justify-between gap-2">
                <span className={`min-w-0 ${resolved ? 'text-slate-500' : ''}`}>
                  {dep.depends_on_task_key ? (
                    <Link to={`/tasks/${dep.depends_on_task_key}`} className="font-mono text-sm text-blue-700 hover:underline">
                      {dep.depends_on_task_key}
                    </Link>
                  ) : (
                    <span>{dep.external_description}</span>
                  )}
                  {resolved && (
                    <>
                      {' · '}
                      <span className="text-sm">teratasi</span>
                    </>
                  )}
                </span>
                <span className="flex shrink-0 gap-2">
                  {!resolved && dep.depends_on_task_id === null && (
                    <button
                      type="button"
                      aria-label={`Tandai teratasi: ${name(dep)}`}
                      disabled={change.isPending}
                      onClick={() => change.mutate({ id: dep.id, action: 'resolve' })}
                      className={linkButton}
                    >
                      Teratasi
                    </button>
                  )}
                  <button
                    type="button"
                    aria-label={`Hapus dependensi: ${name(dep)}`}
                    disabled={change.isPending}
                    onClick={() => change.mutate({ id: dep.id, action: 'delete' })}
                    className={linkButton}
                  >
                    Hapus
                  </button>
                </span>
              </li>
            )
          })}
        </ul>
      )}
      <form onSubmit={submit} noValidate>
        <input
          type="text"
          aria-label="Dependensi baru"
          placeholder="TCB-… atau hambatan, Enter"
          value={text}
          onChange={(e) => setText(e.target.value)}
          disabled={add.isPending}
          className={inputClass}
        />
      </form>
      {add.isError && <ErrorMessage error={add.error} />}
      {change.isError && <ErrorMessage error={change.error} />}
    </div>
  )
}
