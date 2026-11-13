/** TanStack Query keys shared across screens. */

export const meQueryKey = ['me'] as const

/**
 * Everything about tasks lives under ['tasks']: invalidating ['tasks'] after
 * a transition refreshes the Running count, the lists, and task details.
 */
export const runningTasksQueryKey = ['tasks', { state: 'running' }] as const
export const waitingTasksQueryKey = ['tasks', { state: 'waiting' }] as const
export const taskDetailQueryKey = (taskId: number) => ['tasks', 'detail', taskId] as const
export const contextDraftQueryKey = (taskId: number) => ['tasks', 'draft', taskId] as const

/** Every GET /recommendations stores a new batch, so it is fetched only on purpose. */
export const recommendationsQueryKey = ['recommendations'] as const
