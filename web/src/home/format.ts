/** Display helpers for the Beranda. Times are stored in UTC and shown in the user's time zone. */

export const DEFAULT_TIMEZONE = 'Asia/Jakarta'

/** "Jumat, 13 November 2026" */
export function formatLongDate(date: Date, timeZone: string): string {
  return new Intl.DateTimeFormat('id-ID', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric', timeZone }).format(date)
}

/** "11 Nov, 16.40" */
export function formatDayTime(iso: string, timeZone: string): string {
  const date = new Date(iso)
  const day = new Intl.DateTimeFormat('id-ID', { day: 'numeric', month: 'short', timeZone }).format(date)
  const time = new Intl.DateTimeFormat('id-ID', { hour: '2-digit', minute: '2-digit', hour12: false, timeZone }).format(date)
  return `${day}, ${time}`
}

/** Seconds as "00:42:10". */
export function formatDuration(totalSeconds: number): string {
  const s = Math.max(0, Math.floor(totalSeconds))
  const pad = (n: number) => String(n).padStart(2, '0')
  return `${pad(Math.floor(s / 3600))}:${pad(Math.floor((s % 3600) / 60))}:${pad(s % 60)}`
}

/** 0.78 → "0,78" */
export function formatScore(score: number): string {
  return score.toLocaleString('id-ID', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

/** Whole days between an ISO time and now. */
export function daysSince(iso: string, now: number): number {
  return Math.max(0, Math.floor((now - new Date(iso).getTime()) / 86_400_000))
}
