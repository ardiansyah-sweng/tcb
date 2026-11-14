import type { KeyResult } from '../api/objectives'
import { number } from '../detail/format'

/** Goal & Key Result (R1-20) display helpers. Period dates are plain dates (YYYY-MM-DD). */

const DAY = 86_400_000

/** Today in the user's time zone, as YYYY-MM-DD. */
export function todayIn(timeZone: string, now = Date.now()): string {
  return new Intl.DateTimeFormat('en-CA', { timeZone, year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date(now))
}

function toUtc(date: string): number {
  const [y, m, d] = date.split('-').map(Number)
  return Date.UTC(y, m - 1, d)
}

function fromUtc(ms: number): string {
  return new Date(ms).toISOString().slice(0, 10)
}

/** Six months from `start`, the last day included: 2026-11-13 → 2027-05-12. */
export function sixMonthsFrom(start: string): string {
  const [y, m, d] = start.split('-').map(Number)
  return fromUtc(Date.UTC(y, m - 1 + 6, d) - DAY)
}

export function formatDate(date: string): string {
  return new Date(toUtc(date)).toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric', timeZone: 'UTC' })
}

export function formatPeriod(start: string, end: string): string {
  return `${formatDate(start)} – ${formatDate(end)}`
}

/** "Sisa periode: 10 minggu." from today to the last day of the period. */
export function remainingPeriod(end: string, today: string): string {
  const days = Math.round((toUtc(end) - toUtc(today)) / DAY)
  if (days < 0) return 'Periode sudah berakhir.'
  if (days === 0) return 'Periode berakhir hari ini.'
  if (days < 14) return `Sisa periode: ${days} hari.`
  return `Sisa periode: ${Math.floor(days / 7)} minggu.`
}

export function percent(progress: number): number {
  return Math.round(Math.min(Math.max(progress, 0), 1) * 100)
}

export function weightLine(kr: KeyResult): string {
  return `Bobot ${number(kr.weight ?? 1)} · ${kr.progress_mode === 'manual' ? 'manual' : 'otomatis dari task'}`
}

/**
 * Automatic: "3 Done, 1 Running, 3 lainnya" (Cancelled left out, as in the
 * progress). Manual: "24 dari 40 artikel".
 */
export function progressLine(kr: KeyResult): string {
  if (kr.progress_mode === 'manual') {
    const current = kr.current_value ?? kr.start_value ?? 0
    return `${number(current)} dari ${number(kr.target_value ?? 0)} ${kr.unit ?? ''}`.trim()
  }
  const c = kr.task_counts ?? {}
  const done = c.done ?? 0
  const running = c.running ?? 0
  const other = (c.new ?? 0) + (c.ready ?? 0) + (c.waiting ?? 0) + (c.suspended ?? 0)
  const parts = [done && `${done} Done`, running && `${running} Running`, other && `${other} lainnya`].filter(Boolean)
  return parts.length > 0 ? parts.join(', ') : 'Belum ada task.'
}

/** Every task linked to the Key Result, Cancelled included: all become orphans on delete. */
export function linkedTaskCount(kr: KeyResult): number {
  return Object.values(kr.task_counts ?? {}).reduce((sum, n) => sum + n, 0)
}

/** "0,4" → 0.4; empty → null; anything else → NaN. Accepts a decimal comma. */
export function parseDecimal(value: string): number | null {
  const text = value.trim().replace(',', '.')
  if (text === '') return null
  return /^-?\d+(\.\d+)?$/.test(text) ? Number(text) : Number.NaN
}

/** 0.4 → "0,4" for an input. */
export function decimalInput(value: number | null | undefined): string {
  return value === null || value === undefined ? '' : String(value).replace('.', ',')
}
