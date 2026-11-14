import { useState, type SubmitEvent } from 'react'
import { useSaveLinks } from '../api/taskDetail'
import type { TaskDetail } from '../api/tasks'
import { ErrorMessage } from '../components/ErrorMessage'
import { inputClass, linkButton } from './FieldEditor'

/**
 * Linked documents: the links of the latest context. Adding or removing one
 * saves a new context with the draft and the changed links (useSaveLinks).
 */
export function DocumentList({ task }: { task: TaskDetail }) {
  const links = task.latest_context?.links ?? []
  const save = useSaveLinks()
  const [title, setTitle] = useState('')
  const [url, setUrl] = useState('')

  function submit(event: SubmitEvent<HTMLFormElement>) {
    event.preventDefault()
    const href = url.trim()
    if (href === '') return
    const label = title.trim()
    const link = label === '' ? { url: href } : { title: label, url: href }
    save.mutate(
      { taskId: task.id, change: (current) => [...current, link] },
      {
        onSuccess: () => {
          setTitle('')
          setUrl('')
        },
      },
    )
  }

  return (
    <div className="space-y-2">
      {links.length === 0 ? (
        <p>Belum ada dokumen</p>
      ) : (
        <ul className="space-y-1">
          {links.map((link) => {
            const text = link.title || link.url
            return (
              <li key={link.url} className="flex items-start justify-between gap-2">
                <a href={link.url} target="_blank" rel="noreferrer" className="min-w-0 break-words text-blue-700 underline">
                  {text}
                </a>
                <button
                  type="button"
                  aria-label={`Hapus dokumen: ${text}`}
                  disabled={save.isPending}
                  onClick={() => save.mutate({ taskId: task.id, change: (current) => current.filter((l) => l.url !== link.url) })}
                  className={`${linkButton} shrink-0`}
                >
                  Hapus
                </button>
              </li>
            )
          })}
        </ul>
      )}
      <form onSubmit={submit} className="grid gap-2 sm:grid-cols-2" noValidate>
        <input type="text" aria-label="Judul dokumen" placeholder="Judul" value={title} onChange={(e) => setTitle(e.target.value)} className={inputClass} />
        <input type="url" aria-label="URL dokumen" placeholder="https://…, Enter" value={url} onChange={(e) => setUrl(e.target.value)} className={inputClass} />
        {/* Enter in either input submits; a form with two inputs needs a submit button for that. */}
        <button type="submit" disabled={save.isPending} className="sr-only">
          Tambah dokumen
        </button>
      </form>
      {save.isError && <ErrorMessage error={save.error} />}
    </div>
  )
}
