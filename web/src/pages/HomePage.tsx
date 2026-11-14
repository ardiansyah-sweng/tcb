import { useCallback, useEffect, useMemo, useState } from 'react'
import { useLocation } from 'react-router'
import { useMe } from '../auth/session'
import { HomeActionsContext, type DialogRequest } from '../home/actions'
import { ContextDialog } from '../home/ContextDialog'
import { focusRecommendations } from '../home/focus'
import { DEFAULT_TIMEZONE, formatLongDate } from '../home/format'
import { QuickAdd } from '../home/QuickAdd'
import { Recommendations } from '../home/Recommendations'
import { RunningPanel } from '../home/RunningPanel'
import { StaleTasks } from '../home/StaleTasks'
import { useNow } from '../home/useNow'
import { WaitingList } from '../home/WaitingList'

/** R1-16 Beranda (FR-03, FR-08, FR-12, FR-13, FR-18); layout from docs/mockup/tcb-v1/01-beranda.png. */
export default function HomePage() {
  const me = useMe()
  const timeZone = me.data?.timezone || DEFAULT_TIMEZONE
  const now = useNow(60_000)
  const [dialog, setDialog] = useState<DialogRequest | null>(null)
  const closeDialog = useCallback(() => setDialog(null), [])
  const actions = useMemo(() => ({ openDialog: setDialog }), [])

  // "Pindah task" on the Detail task (R1-19) comes here to pick the next task.
  const location = useLocation()
  const focus = (location.state as { focus?: string } | null)?.focus
  useEffect(() => {
    if (focus === 'recommendations') focusRecommendations()
  }, [focus, location.key])

  return (
    <HomeActionsContext.Provider value={actions}>
      <div className="mx-auto max-w-7xl">
        <header className="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
          <div>
            <p className="text-sm text-slate-600">{formatLongDate(new Date(now), timeZone)}</p>
            <h1 className="text-3xl font-semibold text-slate-900 md:text-4xl">Beranda</h1>
          </div>
          <QuickAdd />
        </header>

        <div className="mt-6 grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,26rem)]">
          <div className="min-w-0 space-y-6">
            <RunningPanel timeZone={timeZone} />
            <WaitingList />
          </div>
          <div className="min-w-0 space-y-6">
            <Recommendations />
            <StaleTasks />
          </div>
        </div>
      </div>

      {dialog && <ContextDialog request={dialog} onClose={closeDialog} />}
    </HomeActionsContext.Provider>
  )
}
