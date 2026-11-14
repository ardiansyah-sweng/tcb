import type { State } from '../board/format'
import type { ContextSnapshot, Transition } from '../api/taskDetail'

/** Display helpers of the Detail task (R1-19). */

/** SRS 6: 1 = rendah, 4 = kritis. */
export const PRIORITY_LABELS: Record<number, string> = { 1: 'Rendah', 2: 'Sedang', 3: 'Tinggi', 4: 'Kritis' }
export const DEFAULT_PRIORITY = 2

export const ENERGY_LABELS = { focus: 'Fokus', light: 'Ringan' } as const
export const DEFAULT_ENERGY = 'focus'

export const SOURCE_LABELS = { user: 'Pengguna', calendar: 'Kalender', system: 'Sistem' } as const

/**
 * Button name of a move. Only names: whether the move is allowed comes from
 * TaskDetail.allowed_transitions (CLAUDE.md rule 2).
 */
export function actionLabel(from: State, to: State): string {
  switch (to) {
    case 'running':
      return 'Mulai'
    case 'done':
      return 'Selesai'
    case 'waiting':
      return 'Terblokir'
    case 'suspended':
      return 'Tunda'
    case 'cancelled':
      return 'Batalkan'
    case 'new':
      return 'Kembali ke New'
    case 'ready':
      if (from === 'running') return 'Jeda'
      if (from === 'new') return 'Siap dikerjakan'
      if (from === 'waiting') return 'Lanjutkan'
      if (from === 'suspended') return 'Aktifkan lagi'
      return 'Buka lagi'
  }
}

/** Order of the action buttons: the usual next step first, cancelling last. */
const ACTION_ORDER: State[] = ['running', 'done', 'ready', 'waiting', 'suspended', 'new', 'cancelled']

export function sortActions(targets: State[]): State[] {
  return [...targets].sort((a, b) => ACTION_ORDER.indexOf(a) - ACTION_ORDER.indexOf(b))
}

/** "Jumat, 13 Nov 2026, 17.00" in the user's time zone. */
export function formatDeadline(iso: string, timeZone: string): string {
  const date = new Date(iso)
  const day = new Intl.DateTimeFormat('id-ID', { weekday: 'long', day: 'numeric', month: 'short', year: 'numeric', timeZone }).format(date)
  const time = new Intl.DateTimeFormat('id-ID', { hour: '2-digit', minute: '2-digit', hour12: false, timeZone }).format(date)
  return `${day}, ${time}`
}

/** Wall-clock parts of `date` in `timeZone`. */
function wallClock(date: Date, timeZone: string) {
  const parts = new Intl.DateTimeFormat('en-US', {
    timeZone,
    hourCycle: 'h23',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
  }).formatToParts(date)
  const get = (type: string) => parts.find((p) => p.type === type)?.value ?? '00'
  return { year: get('year'), month: get('month'), day: get('day'), hour: get('hour'), minute: get('minute') }
}

/** UTC ISO time → value of an <input type="datetime-local"> in `timeZone`. */
export function toLocalInput(iso: string, timeZone: string): string {
  const w = wallClock(new Date(iso), timeZone)
  return `${w.year}-${w.month}-${w.day}T${w.hour}:${w.minute}`
}

/** Value of an <input type="datetime-local"> read in `timeZone` → UTC ISO time. */
export function fromLocalInput(value: string, timeZone: string): string {
  const match = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})/.exec(value)
  if (!match) return value
  const [, y, mo, d, h, mi] = match.map(Number)
  const asUtc = Date.UTC(y, mo - 1, d, h, mi)
  // Offset of the zone at that moment; twice, in case the guess crossed a DST change.
  const offsetAt = (time: number) => {
    const w = wallClock(new Date(time), timeZone)
    return Date.UTC(+w.year, +w.month - 1, +w.day, +w.hour, +w.minute) - time
  }
  let utc = asUtc - offsetAt(asUtc)
  utc = asUtc - offsetAt(utc)
  return new Date(utc).toISOString()
}

/**
 * The transition saved in the same transaction as a context (leaving
 * Running, switching). Contexts carry no transition, so they are matched by
 * time; a note saved without a state change has none.
 */
export function transitionOf(context: ContextSnapshot, log: Transition[]): Transition | undefined {
  const at = new Date(context.created_at).getTime()
  const near = log.filter((t) => Math.abs(new Date(t.occurred_at).getTime() - at) <= 5_000)
  return near.find((t) => t.from_state === 'running') ?? near[0]
}

export function number(value: number): string {
  return value.toLocaleString('id-ID', { maximumFractionDigits: 2 })
}
