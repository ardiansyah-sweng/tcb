import { useId } from 'react'
import { errorMessage } from '../api/errors'
import { useRunningTasks } from '../api/tasks'
import { useMe } from '../auth/session'

export function WipIndicator() {
  const labelId = useId()
  const me = useMe()
  const running = useRunningTasks()

  const error = me.error ?? running.error
  const count = running.data?.data?.length
  const limit = me.data?.wip_limit
  const full = count !== undefined && limit !== undefined && count >= limit

  let hint: string
  if (error) hint = errorMessage(error)
  else if (count === undefined || limit === undefined) hint = 'Memuat…'
  else hint = full ? 'Memulai task lain memicu pindah konteks' : 'Masih bisa memulai task'

  return (
    <div role="status" aria-labelledby={labelId} className="rounded-lg border border-slate-200 bg-white px-3 py-2 md:p-3">
      <p id={labelId} className="text-xs text-slate-500">
        Running / WIP limit
      </p>
      <p className={`text-lg font-semibold ${full ? 'text-blue-800' : 'text-slate-900'}`}>
        {count ?? '–'} / {limit ?? '–'}
      </p>
      <p className={`text-xs ${error ? 'text-red-700' : 'text-slate-500'}`}>{hint}</p>
    </div>
  )
}
