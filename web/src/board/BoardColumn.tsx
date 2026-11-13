import { useInfiniteQuery } from '@tanstack/react-query'
import { useEffect, useState } from 'react'
import { api, request } from '../api/client'
import { boardColumnQueryKey } from '../api/queryKeys'
import { useRunningTasks, type Task } from '../api/tasks'
import { useMe } from '../auth/session'
import { ErrorMessage } from '../components/ErrorMessage'
import { STATE_LABELS, type State } from './format'
import { TaskCard } from './TaskCard'

export type BoardFilters = { objective_id?: string; orphan?: string; label?: string }

/**
 * One state column. Ready asks for sort=score (current recommendation score,
 * R1-18); the others keep the API's default order. "Muat lagi" follows
 * next_cursor; the count shows "+" while more pages exist.
 */
export function BoardColumn({
  state,
  filters,
  timeZone,
  dragged,
  onDragStart,
  onDragEnd,
  onDrop,
  onLabels,
}: {
  state: State
  filters: BoardFilters
  timeZone: string
  dragged: Task | null
  onDragStart: (task: Task) => void
  onDragEnd: () => void
  onDrop: (state: State) => void
  onLabels: (labels: string[]) => void
}) {
  const [over, setOver] = useState(false)
  const tasks = useInfiniteQuery({
    queryKey: boardColumnQueryKey(state, filters),
    initialPageParam: null as string | null,
    queryFn: ({ pageParam }) =>
      request(
        api.GET('/tasks', {
          params: {
            query: {
              state,
              ...(state === 'ready' ? { sort: 'score' as const } : {}),
              ...(filters.objective_id ? { objective_id: Number(filters.objective_id) } : {}),
              ...(filters.orphan ? { orphan: true } : {}),
              ...(filters.label ? { label: filters.label } : {}),
              ...(pageParam ? { cursor: pageParam } : {}),
            },
          },
        }),
      ),
    getNextPageParam: (last) => last.next_cursor ?? null,
  })

  const items: Task[] = tasks.data?.pages.flatMap((page) => page.data ?? []) ?? []
  const labelsKey = [...new Set(items.map((t) => t.label).filter((l): l is string => !!l))].sort().join('\n')
  useEffect(() => {
    if (labelsKey !== '') onLabels(labelsKey.split('\n'))
  }, [labelsKey, onLabels])

  const running = state === 'running'
  const me = useMe()
  const runningAll = useRunningTasks()
  let heading = `${STATE_LABELS[state]} · ${items.length}${tasks.hasNextPage ? '+' : ''}`
  if (running) {
    // WIP counts every running task, whatever the filters.
    const count = runningAll.data?.data?.length ?? items.length
    heading = `${STATE_LABELS[state]} · ${count} / ${me.data?.wip_limit ?? '–'}`
  }

  const droppable = dragged !== null && dragged.state !== state

  return (
    <section
      aria-label={STATE_LABELS[state]}
      onDragEnter={(e) => {
        if (droppable) {
          e.preventDefault()
          setOver(true)
        }
      }}
      onDragOver={(e) => {
        if (droppable) e.preventDefault()
      }}
      onDragLeave={(e) => {
        if (!e.currentTarget.contains(e.relatedTarget as Node | null)) setOver(false)
      }}
      onDrop={(e) => {
        e.preventDefault()
        setOver(false)
        onDrop(state)
      }}
      className={`flex w-72 shrink-0 flex-col rounded-2xl border-2 p-3 ${
        over ? 'border-blue-500 bg-blue-50' : running ? 'border-blue-700 bg-blue-50/60' : 'border-transparent bg-slate-100'
      }`}
    >
      <h2 className={`px-1 text-base font-semibold ${running ? 'text-blue-800' : 'text-slate-800'}`}>
        {heading}
      </h2>
      <div className="mt-3 flex min-h-24 flex-1 flex-col gap-3">
        {tasks.isPending && <p className="px-1 text-sm text-slate-500">Memuat…</p>}
        {tasks.isError && <ErrorMessage error={tasks.error} />}
        {items.map((task) => (
          <TaskCard key={task.id} task={task} timeZone={timeZone} onDragStart={onDragStart} onDragEnd={onDragEnd} />
        ))}
        {tasks.hasNextPage && (
          <button
            type="button"
            onClick={() => tasks.fetchNextPage()}
            disabled={tasks.isFetchingNextPage}
            className="rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm text-slate-800 hover:bg-slate-50 disabled:opacity-60"
          >
            Muat lagi
          </button>
        )}
      </div>
    </section>
  )
}
