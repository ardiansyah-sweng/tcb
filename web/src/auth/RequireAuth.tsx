import { Navigate, Outlet, useLocation } from 'react-router'
import type { LoginLocationState } from '../pages/LoginPage'
import { useToken } from './session'
import { signOutReason } from './token'

/** Renders the child routes only with a token; otherwise goes to /login and back. */
export function RequireAuth() {
  const token = useToken()
  const location = useLocation()

  if (token === null) {
    const state: LoginLocationState = {
      from: location.pathname + location.search,
      expired: signOutReason() === 'expired',
    }
    return <Navigate to="/login" replace state={state} />
  }
  return <Outlet />
}
