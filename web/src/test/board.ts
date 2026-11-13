import { setToken } from '../auth/token'
import { json, mockApi, task, user, type MockReply } from './api'

/**
 * Papan status fixtures (R1-18). Mirrors docs/mockup/tcb-v1/02-papan status.png.
 * Clock: use fixHomeClock() from ./home (Friday 13 Nov 2026, 09:42 WIB).
 */

export const newOrphan = task({ id: 41, task_key: 'TCB-41', title: 'Ide paper lanjutan: studi replikasi', state: 'new', is_orphan: true, key_result_id: null })
export const newTask = task({ id: 42, task_key: 'TCB-42', title: 'Susun soal UAS', state: 'new', key_result_id: 3 })

export const readyTop = task({
  id: 35,
  task_key: 'TCB-35',
  title: 'Revisi bagian Metode paper',
  state: 'ready',
  label: 'Menulis paper',
  score: 0.78,
  deadline_at: '2026-11-14T09:00:00Z',
  estimate_minutes: 90,
})
export const readySecond = task({ id: 38, task_key: 'TCB-38', title: 'Siapkan slide kuliah pertemuan 12', state: 'ready', label: 'Mengajar', score: 0.64, estimate_minutes: 60 })
export const readyBlocked = task({ id: 27, task_key: 'TCB-27', title: 'Baca 3 paper untuk Related Work', state: 'ready', label: 'Bimbingan', score: null })

export const runningTask = task({
  id: 31,
  task_key: 'TCB-31',
  title: 'Koreksi UTS kelas A',
  state: 'running',
  label: 'Koreksi ujian',
  progress_unit: { done: 22, total: 40, name: 'lembar' },
  switch_count: 2,
  time_spent_minutes: 108,
})

export const waitingTask = task({ id: 34, task_key: 'TCB-34', title: 'Finalisasi nilai UTS kelas B', state: 'waiting', state_reason: 'Menunggu nilai praktikum dari asisten' })
export const suspendedTask = task({ id: 22, task_key: 'TCB-22', title: 'Usulan hibah penelitian', state: 'suspended', state_reason: 'Ditunda', review_on: '2026-12-01' })

export const objectives = [
  { id: 1, title: 'Semester ganjil 2026/2027', status: 'active', progress: 0.4 },
  { id: 2, title: 'Publikasi 2026', status: 'active', progress: 0.2 },
]

export type BoardLists = Partial<Record<string, unknown[]>>

export const defaultLists: BoardLists = {
  new: [newOrphan, newTask],
  ready: [readyTop, readySecond, readyBlocked],
  running: [runningTask],
  waiting: [waitingTask],
  suspended: [suspendedTask],
}

/** GET /tasks answered per `state` filter (one state per board column). */
export function boardTasks(lists: BoardLists): (request: Request) => Response {
  return (request) => {
    const state = new URL(request.url).searchParams.get('state') ?? ''
    return json(200, { data: lists[state] ?? [], next_cursor: null })
  }
}

export function board(routes: Record<string, MockReply> = {}, lists: BoardLists = defaultLists) {
  setToken('test-token')
  return mockApi({
    'GET /me': { status: 200, body: user },
    'GET /tasks': boardTasks(lists),
    'GET /objectives': { status: 200, body: { data: objectives } },
    'GET /tasks/31': { status: 200, body: { ...runningTask, latest_context: null, subtasks: [], open_dependencies: [], current_session_started_at: '2026-11-13T02:00:00Z' } },
    'GET /tasks/31/contexts/draft': { status: 200, body: { last_note: '22 dari 40 lembar selesai.', next_step: 'Soal nomor 3', blocker: null, unit_done: 22, links: [] } },
    ...routes,
  })
}

/** Query strings of GET /tasks requests, in order. */
export function taskQueries(fetchMock: ReturnType<typeof mockApi>): URLSearchParams[] {
  return fetchMock.mock.calls
    .map(([input]) => input as Request)
    .filter((r) => r.method === 'GET' && new URL(r.url).pathname === '/api/v1/tasks')
    .map((r) => new URL(r.url).searchParams)
}
