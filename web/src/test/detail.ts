import { setToken } from '../auth/token'
import { mockApi, task, user, type MockReply } from './api'

/**
 * Detail task fixtures (R1-19). Mirrors docs/mockup/tcb-v1/05-detail task.png.
 * Clock: use fixHomeClock() from ./home (Friday 13 Nov 2026, 09:42 WIB).
 */

export const subtaskDone = task({ id: 32, task_key: 'TCB-32', title: 'Susun rubrik', state: 'done', parent_task_id: 31 })
export const subtaskReady = task({ id: 33, task_key: 'TCB-33', title: 'Koreksi lembar jawaban', state: 'ready', parent_task_id: 31 })

export const detail = {
  ...task({
    id: 31,
    task_key: 'TCB-31',
    title: 'Koreksi UTS kelas A',
    state: 'running',
    key_result_id: 7,
    key_result_title: 'Nilai ujian diunggah sebelum batas',
    is_orphan: false,
    label: 'Koreksi ujian',
    priority: 3,
    energy: 'focus',
    // Friday 13 Nov 2026, 17.00 WIB.
    deadline_at: '2026-11-13T10:00:00Z',
    estimate_minutes: 240,
    time_spent_minutes: 150,
    switch_count: 2,
    progress_unit: { done: 22, total: 40, name: 'lembar' },
    unit_name_suggestion: 'lembar',
  }),
  latest_context: {
    id: 12,
    task_id: 31,
    created_at: '2026-11-11T09:40:00Z',
    last_note: '22 dari 40 lembar selesai.',
    next_step: 'Tetapkan skor parsial nomor 3.',
    blocker: null,
    unit_done: 22,
    links: [
      { title: 'Rubrik UTS', url: 'https://drive.example.com/rubrik' },
      { title: 'Rekap nilai kelas A', url: 'https://drive.example.com/rekap' },
    ],
  },
  subtasks: [subtaskDone, subtaskReady],
  open_dependencies: [],
  current_session_started_at: '2026-11-13T01:30:00Z',
  allowed_transitions: ['ready', 'waiting', 'done', 'cancelled'],
}

/** Newest first, as GET /tasks/{id}/contexts returns them. */
export const contexts = [
  { ...detail.latest_context },
  {
    id: 11,
    task_id: 31,
    created_at: '2026-11-11T03:15:00Z',
    last_note: 'Lembar 1–15 selesai.',
    next_step: null,
    blocker: 'Konfirmasi kunci jawaban dengan dosen pengampu',
    unit_done: 15,
    links: [],
  },
  {
    id: 10,
    task_id: 31,
    created_at: '2026-11-10T08:30:00Z',
    last_note: 'Lembar 1–8 selesai.',
    next_step: null,
    blocker: null,
    unit_done: 8,
    links: [],
  },
]

/** Newest first, as GET /tasks/{id}/transitions returns them. */
export const transitions = [
  { id: 25, from_state: 'ready', to_state: 'running', reason: 'Blok fokus kalender dimulai', source: 'calendar', occurred_at: '2026-11-12T01:30:00Z' },
  // Same moment as context 12 (saved in the same transaction).
  { id: 24, from_state: 'running', to_state: 'ready', reason: null, source: 'user', occurred_at: '2026-11-11T09:40:00.412Z' },
  { id: 23, from_state: 'waiting', to_state: 'ready', reason: 'Blocker teratasi', source: 'system', occurred_at: '2026-11-11T06:05:00Z' },
  { id: 22, from_state: 'running', to_state: 'waiting', reason: 'Kunci jawaban nomor 5 perlu dikonfirmasi', source: 'user', occurred_at: '2026-11-11T03:15:00Z' },
  { id: 21, from_state: 'ready', to_state: 'running', reason: null, source: 'user', occurred_at: '2026-11-10T02:00:00Z' },
  { id: 20, from_state: null, to_state: 'new', reason: null, source: 'user', occurred_at: '2026-11-09T01:00:00Z' },
]

export const dependencies = [
  { id: 9, task_id: 31, depends_on_task_id: null, depends_on_task_key: null, external_description: 'Nilai praktikum dari asisten', status: 'open', resolved_at: null },
  { id: 8, task_id: 31, depends_on_task_id: 30, depends_on_task_key: 'TCB-30', external_description: null, status: 'resolved', resolved_at: '2026-11-10T01:00:00Z' },
]

export const objectives = [{ id: 1, title: 'Semester ganjil 2026/2027', status: 'active', progress: 0.4, period_start: '2026-08-01', period_end: '2027-01-31' }]

export const objectiveDetail = {
  ...objectives[0],
  key_results: [
    { id: 7, objective_id: 1, title: 'Nilai ujian diunggah sebelum batas', progress: 0.3 },
    { id: 8, objective_id: 1, title: 'Rubrik semua mata kuliah tersedia', progress: 0.5 },
  ],
}

export const draft = {
  last_note: '22 dari 40 lembar selesai.',
  next_step: 'Tetapkan skor parsial nomor 3.',
  blocker: null,
  unit_done: 22,
  links: detail.latest_context.links,
}

/** API of the Detail task for TCB-31; `routes` override or add replies. */
export function detailApi(routes: Record<string, MockReply> = {}, body: Record<string, unknown> = detail) {
  setToken('test-token')
  return mockApi({
    'GET /me': { status: 200, body: user },
    'GET /tasks': { status: 200, body: { data: [], next_cursor: null } },
    'GET /tasks/TCB-31': { status: 200, body },
    'GET /tasks/31': { status: 200, body },
    'GET /tasks/31/contexts': { status: 200, body: { data: contexts } },
    'GET /tasks/31/contexts/draft': { status: 200, body: draft },
    'GET /tasks/31/transitions': { status: 200, body: { data: transitions } },
    'GET /tasks/31/dependencies': { status: 200, body: { data: dependencies } },
    'GET /objectives': { status: 200, body: { data: objectives } },
    'GET /objectives/1': { status: 200, body: objectiveDetail },
    ...routes,
  })
}
