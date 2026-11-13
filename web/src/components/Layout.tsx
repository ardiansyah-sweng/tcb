import { NavLink, Outlet } from 'react-router'
import { useLogout, useMe } from '../auth/session'
import { WipIndicator } from './WipIndicator'

const navItems = [
  { to: '/', label: 'Beranda', end: true },
  { to: '/board', label: 'Papan status', end: false },
  { to: '/goals', label: 'Goal & Key Result', end: false },
  { to: '/tasks', label: 'Detail task', end: false },
]

function navClass({ isActive }: { isActive: boolean }) {
  const base = 'block whitespace-nowrap rounded-lg px-3 py-2 text-sm md:text-base'
  return isActive ? `${base} bg-blue-50 font-medium text-blue-800` : `${base} text-slate-700 hover:bg-slate-100`
}

/**
 * App shell. Mobile first: a column with the navigation on top of the content;
 * from the md breakpoint the navigation becomes a sidebar.
 */
export function Layout() {
  const me = useMe()
  const logout = useLogout()

  return (
    <div className="flex min-h-screen flex-col md:flex-row">
      <aside className="flex shrink-0 flex-col gap-3 border-b border-slate-200 bg-white p-4 md:w-64 md:gap-6 md:border-r md:border-b-0 md:p-5">
        <div className="flex items-center gap-3">
          <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-blue-700 text-xs font-bold text-white">
            TCB
          </span>
          <div className="min-w-0 flex-1">
            <p className="font-semibold leading-tight text-slate-900">Task Control Block</p>
            <p className="text-xs text-slate-500">Personal · v1</p>
          </div>
        </div>

        <nav aria-label="Navigasi utama" className="-mx-1 overflow-x-auto px-1">
          <ul className="flex gap-1 md:flex-col">
            {navItems.map((item) => (
              <li key={item.to}>
                <NavLink to={item.to} end={item.end} className={navClass}>
                  {item.label}
                </NavLink>
              </li>
            ))}
          </ul>
        </nav>

        <WipIndicator />

        <div className="flex items-center justify-between gap-2 text-sm md:mt-auto">
          <span className="truncate text-slate-600">{me.data?.name ?? ''}</span>
          <button
            type="button"
            onClick={() => logout.mutate()}
            disabled={logout.isPending}
            className="rounded-md border border-slate-300 px-3 py-1 text-slate-700 hover:bg-slate-100 disabled:opacity-60"
          >
            Keluar
          </button>
        </div>
      </aside>

      <main className="min-w-0 flex-1 px-4 py-6 md:px-10 md:py-10">
        <Outlet />
      </main>
    </div>
  )
}
