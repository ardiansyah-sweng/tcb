/** TanStack Query keys shared across screens. */

export const meQueryKey = ['me'] as const

/** Under ['tasks']: invalidating ['tasks'] after a transition refreshes the Running count. */
export const runningTasksQueryKey = ['tasks', { state: 'running' }] as const
