import { setToken } from '../auth/token'
import { json, mockApi, task, user, type MockReply } from './api'

/**
 * Goal & Key Result fixtures (R1-20). Mirrors docs/mockup/tcb-v1/04-progres goal.png.
 * Clock: use fixHomeClock() from ./home (Friday 13 Nov 2026, 09:42 WIB).
 */

export const objectiveA = {
  id: 1,
  title: 'Kuliah berjalan lancar dan satu paper tersubmit',
  description: null,
  // 70 days after 13 Nov 2026: 10 weeks left.
  period_start: '2026-09-01',
  period_end: '2027-01-22',
  status: 'active',
  progress: 0.44,
}

export const objectiveB = {
  id: 2,
  title: 'Riset hibah internal',
  description: 'Proposal dan laporan kemajuan',
  period_start: '2026-07-01',
  period_end: '2026-12-31',
  status: 'active',
  progress: 0,
}

const counts = (c: Record<string, number>) => ({ new: 0, ready: 0, running: 0, waiting: 0, suspended: 0, done: 0, cancelled: 0, ...c })

export const krExams = {
  id: 7,
  objective_id: 1,
  title: 'Semua nilai ujian diunggah sebelum batas',
  weight: 0.4,
  progress_mode: 'auto',
  start_value: null,
  target_value: null,
  current_value: null,
  unit: null,
  progress: 0.5,
  task_counts: counts({ new: 1, ready: 1, running: 1, suspended: 1, done: 3 }),
}

export const krPaper = {
  id: 8,
  objective_id: 1,
  title: 'Draf paper lengkap dikirim ke jurnal',
  weight: 0.4,
  progress_mode: 'auto',
  start_value: null,
  target_value: null,
  current_value: null,
  unit: null,
  progress: 0.3,
  task_counts: counts({ ready: 1, waiting: 1, done: 2, cancelled: 1 }),
}

export const krReading = {
  id: 9,
  objective_id: 1,
  title: 'Artikel dibaca untuk tinjauan pustaka',
  weight: 0.2,
  progress_mode: 'manual',
  start_value: 0,
  target_value: 40,
  current_value: 24,
  unit: 'artikel',
  progress: 0.6,
  task_counts: counts({}),
}

export const detailA = { ...objectiveA, key_results: [krExams, krPaper, krReading] }
export const detailB = { ...objectiveB, key_results: [] }

export const orphanA = task({ id: 41, task_key: 'TCB-41', title: 'Ide paper lanjutan: studi replikasi', state: 'ready', is_orphan: true, key_result_id: null, key_result_title: null })
export const orphanB = task({ id: 42, task_key: 'TCB-42', title: 'Susun soal UAS', state: 'new', is_orphan: true, key_result_id: null, key_result_title: null })

/** GET /tasks: the orphan list for ?orphan=true, nothing otherwise (Running indicator). */
export function orphanTasks(orphans: unknown[]): MockReply {
  return (request) => {
    const query = new URL(request.url).searchParams
    return json(200, { data: query.get('orphan') === 'true' ? orphans : [], next_cursor: null })
  }
}

/** API of the Goal & Key Result page; `routes` override or add replies. */
export function goalsApi(routes: Record<string, MockReply> = {}) {
  setToken('test-token')
  return mockApi({
    'GET /me': { status: 200, body: user },
    'GET /tasks': orphanTasks([orphanA, orphanB]),
    // Newest period first, as the API orders them.
    'GET /objectives': { status: 200, body: { data: [objectiveA, objectiveB] } },
    'GET /objectives/1': { status: 200, body: detailA },
    'GET /objectives/2': { status: 200, body: detailB },
    ...routes,
  })
}
