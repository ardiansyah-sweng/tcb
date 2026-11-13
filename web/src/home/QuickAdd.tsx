import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useState, type SubmitEvent } from 'react'
import { api, request, type Schemas } from '../api/client'
import { ErrorMessage } from '../components/ErrorMessage'

/** FR-03: type a title, press Enter; the task is created as New (title only). */
export function QuickAdd() {
  const queryClient = useQueryClient()
  const [title, setTitle] = useState('')
  const [notice, setNotice] = useState<string | null>(null)
  const [empty, setEmpty] = useState(false)

  const add = useMutation({
    // Only the title (input minimal). The generated type marks fields that
    // have a default (priority, energy, start_ready) as required; the API fills them.
    mutationFn: (value: string) => request(api.POST('/tasks', { body: { title: value } as Schemas['TaskCreate'] })),
    onSuccess: async (created) => {
      setTitle('')
      setNotice(`${created.task_key} ditambahkan sebagai New.`)
      await queryClient.invalidateQueries({ queryKey: ['tasks'] })
    },
  })

  function submit(event: SubmitEvent<HTMLFormElement>) {
    event.preventDefault()
    const value = title.trim()
    setNotice(null)
    if (value === '') {
      setEmpty(true)
      return
    }
    setEmpty(false)
    add.mutate(value)
  }

  return (
    <form onSubmit={submit} className="w-full md:max-w-md">
      <div className="flex gap-2">
        <label htmlFor="quick-add" className="sr-only">
          Tambah task cepat
        </label>
        <input
          id="quick-add"
          type="text"
          value={title}
          onChange={(e) => setTitle(e.target.value)}
          placeholder="Tambah task cepat… lalu Enter (masuk sebagai New)"
          className="min-w-0 flex-1 rounded-lg border border-slate-300 bg-white px-3 py-2 text-slate-900 focus:border-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-200"
        />
        <button
          type="submit"
          disabled={add.isPending}
          className="shrink-0 rounded-lg bg-blue-700 px-4 py-2 font-medium text-white hover:bg-blue-800 disabled:opacity-60"
        >
          Tambah
        </button>
      </div>
      <div aria-live="polite" className="mt-1 min-h-5 text-sm">
        {notice && <p role="status" className="text-green-800">{notice}</p>}
        {empty && <p className="text-red-700">Tulis judul task dulu.</p>}
      </div>
      {add.isError && <ErrorMessage error={add.error} className="mt-1" />}
    </form>
  )
}
