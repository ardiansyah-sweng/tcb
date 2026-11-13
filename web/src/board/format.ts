import type { Task } from '../api/tasks'

/** Card info line of the Papan status (R1-18). */

export const STATE_LABELS = {
  new: 'New',
  ready: 'Ready',
  running: 'Running',
  waiting: 'Waiting',
  suspended: 'Suspended',
  done: 'Done',
  cancelled: 'Cancelled',
} as const

export type State = keyof typeof STATE_LABELS

export const BOARD_STATES = Object.keys(STATE_LABELS) as State[]

/** Usual kinds of work (SRS, unit_name_suggestion); labels in use are added to these. */
export const USUAL_LABELS = ['Mengajar', 'Menulis paper', 'Koreksi ujian']

function shortDate(date: Date, timeZone: string): string {
  return new Intl.DateTimeFormat('id-ID', { day: 'numeric', month: 'short', timeZone }).format(date)
}

function number(value: number): string {
  return value.toLocaleString('id-ID', { maximumFractionDigits: 2 })
}

export type InfoLine = { text: string; tone: 'muted' | 'warning' }

/**
 * One line per card: the reason of a waiting or cancelled task, the review
 * date of a suspended one, otherwise unit progress, deadline, and estimate.
 */
export function infoLine(task: Task, timeZone: string): InfoLine | null {
  if ((task.state === 'waiting' || task.state === 'cancelled') && task.state_reason) {
    return { text: task.state_reason, tone: 'warning' }
  }
  if (task.state === 'suspended' && task.review_on) {
    // A date without time: format it in UTC so it does not shift a day.
    return { text: `Tinjau ulang ${shortDate(new Date(`${task.review_on}T00:00:00Z`), 'UTC')}`, tone: 'muted' }
  }

  const parts: string[] = []
  const unit = task.progress_unit
  if (unit) parts.push(`${number(unit.done)}/${number(unit.total)} ${unit.name}`)
  if (task.deadline_at) parts.push(`Deadline ${shortDate(new Date(task.deadline_at), timeZone)}`)
  if (task.estimate_minutes) parts.push(`${task.estimate_minutes} mnt`)
  if (task.state === 'running' && task.switch_count > 0) parts.push(`${task.switch_count} kali pindah`)
  return parts.length === 0 ? null : { text: parts.join(' · '), tone: 'muted' }
}
