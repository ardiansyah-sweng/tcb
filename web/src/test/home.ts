import { afterEach, beforeEach, vi } from 'vitest'
import { setToken } from '../auth/token'
import { json, mockApi, requestsOf, task, user, type MockReply } from './api'

/**
 * Beranda fixtures (R1-16, R1-17). Clock: Friday 13 Nov 2026, 09:42:10
 * Asia/Jakarta; tests fake only Date (see fixHomeClock) so timers keep running.
 */

export const NOW = new Date('2026-11-13T02:42:10Z')

export const running = task({
  id: 31,
  task_key: 'TCB-31',
  title: 'Koreksi UTS kelas A',
  state: 'running',
  key_result_id: 7,
  key_result_title: 'Nilai ujian diunggah sebelum batas',
  estimate_minutes: 240,
  time_spent_minutes: 108,
  switch_count: 2,
})

export const runningDetail = {
  ...running,
  latest_context: {
    id: 5,
    task_id: 31,
    created_at: '2026-11-11T09:40:00Z',
    last_note: '22 dari 40 lembar selesai.',
    next_step: 'Tetapkan skor parsial soal nomor 3.',
    blocker: null,
    unit_done: 22,
    links: [
      { title: 'Rubrik UTS', url: 'https://drive.example.com/rubrik' },
      { url: 'https://drive.example.com/rekap' },
    ],
  },
  subtasks: [],
  open_dependencies: [],
  current_session_started_at: '2026-11-13T02:00:00Z',
}

export const draft = {
  last_note: '22 dari 40 lembar selesai.',
  next_step: 'Tetapkan skor parsial soal nomor 3.',
  blocker: null,
  unit_done: null,
  links: [{ title: 'Rubrik UTS', url: 'https://drive.example.com/rubrik' }],
}

export function rec(id: number, rank: number, score: number, taskId: number, title: string, extra: Record<string, unknown> = {}) {
  return {
    id,
    rank,
    score,
    breakdown: { goal: 0.5, urgency: 0.5, priority: 0.33, aging: 0.4 },
    reasons: ['Key Result bobot tinggi, progres masih 30%', 'Deadline Sabtu, 14 Nov', 'Prioritas tinggi'],
    stale: false,
    suggested_slot: null,
    task: task({ id: taskId, task_key: `TCB-${taskId}`, title, state: 'ready', ready_since: '2026-11-07T02:00:00Z' }),
    ...extra,
  }
}

export const staleTask = task({
  id: 20,
  task_key: 'TCB-20',
  title: 'Rapikan arsip RPS',
  state: 'ready',
  ready_since: '2026-10-20T02:00:00Z',
})

export function batch(overrides: Record<string, unknown> = {}) {
  return {
    batch_id: '6f1c0a52-8d1e-4c55-9b7a-2f0d3c1e9a10',
    generated_at: '2026-11-13T02:42:10Z',
    running_full: true,
    items: [
      rec(101, 1, 0.78, 35, 'Revisi bagian Metode paper'),
      rec(102, 2, 0.64, 38, 'Siapkan slide kuliah pertemuan 12', { reasons: ['Sudah 6 hari di Ready'] }),
      rec(103, 3, 0.58, 29, 'Buat kunci jawaban kuis 3', { stale: true }),
    ],
    stale_tasks: [],
    ...overrides,
  }
}

export const waitingExternal = task({ id: 34, task_key: 'TCB-34', title: 'Finalisasi nilai UTS kelas B', state: 'waiting', state_reason: 'Nilai praktikum dari asisten' })
export const waitingOnTask = task({ id: 33, task_key: 'TCB-33', title: 'Kirim draf paper ke rekan penulis', state: 'waiting', state_reason: 'Menunggu revisi Metode' })
export const waitingReasonOnly = task({ id: 36, task_key: 'TCB-36', title: 'Unggah nilai ke SIA', state: 'waiting', state_reason: 'SIA sedang perbaikan' })

export function detailOf(t: Record<string, unknown>, openDependencies: unknown[] = []) {
  return { ...t, latest_context: null, subtasks: [], open_dependencies: openDependencies, current_session_started_at: null }
}

export type Lists = { running?: unknown[]; waiting?: unknown[] }

/** GET /tasks answered per `state` filter. */
export function taskLists(lists: Lists): MockReply {
  return (request) => {
    const state = new URL(request.url).searchParams.get('state')
    const data = state === 'running' ? (lists.running ?? []) : state === 'waiting' ? (lists.waiting ?? []) : []
    return json(200, { data, next_cursor: null })
  }
}

export function home(routes: Record<string, MockReply> = {}, lists: Lists = { running: [running] }) {
  setToken('test-token')
  return mockApi({
    'GET /me': { status: 200, body: user },
    'GET /tasks': taskLists(lists),
    'GET /tasks/31': { status: 200, body: runningDetail },
    'GET /tasks/31/contexts/draft': { status: 200, body: draft },
    'GET /recommendations': { status: 200, body: batch() },
    ...routes,
  })
}

/** JSON bodies sent to "METHOD /path", in order. */
export async function sent(fetchMock: ReturnType<typeof mockApi>, method: string, path: string): Promise<unknown[]> {
  const matching = requestsOf(fetchMock).filter(
    (r) => r.method === method && new URL(r.url).pathname === `/api/v1${path}`,
  )
  return Promise.all(matching.map((r) => r.clone().json()))
}

export function count(fetchMock: ReturnType<typeof mockApi>, method: string, path: string): number {
  return requestsOf(fetchMock).filter((r) => r.method === method && new URL(r.url).pathname === `/api/v1${path}`).length
}

/** Fake only Date at NOW, so react-query and waitFor timers keep running. */
export function fixHomeClock() {
  beforeEach(() => {
    vi.useFakeTimers({ toFake: ['Date'] })
    vi.setSystemTime(NOW)
  })
  afterEach(() => {
    vi.useRealTimers()
    vi.unstubAllGlobals()
    localStorage.clear()
  })
}
