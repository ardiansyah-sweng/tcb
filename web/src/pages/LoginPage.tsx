import { useId, useState, type SubmitEvent } from 'react'
import { Navigate, useLocation } from 'react-router'
import { ApiError } from '../api/errors'
import { useLogin, useToken } from '../auth/session'
import { ErrorMessage } from '../components/ErrorMessage'
import { HealthStatus } from '../components/HealthStatus'

export type LoginLocationState = { from?: string; expired?: boolean }

const inputClass =
  'mt-1 block w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-slate-900 shadow-sm focus:border-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-600/30'

export default function LoginPage() {
  const token = useToken()
  const location = useLocation()
  const state = (location.state ?? {}) as LoginLocationState
  const from = state.from && state.from !== '/login' ? state.from : '/'

  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const login = useLogin()
  const ids = { email: useId(), password: useId() }

  // Also the redirect after a successful login (useLogin stores the token).
  if (token !== null) return <Navigate to={from} replace />

  const fieldErrors = login.error instanceof ApiError ? login.error.fieldErrors : {}
  const emailError = fieldErrors.email?.[0]
  const passwordError = fieldErrors.password?.[0]
  // Field messages are shown under their input; anything else goes in the alert.
  const showAlert = login.isError && emailError === undefined && passwordError === undefined

  function submit(event: SubmitEvent<HTMLFormElement>) {
    event.preventDefault()
    login.mutate({ email, password })
  }

  return (
    <div className="flex min-h-screen items-center justify-center bg-slate-50 px-4 py-10">
      <div className="w-full max-w-sm">
        <div className="mb-6 flex items-center gap-3">
          <span className="flex h-10 w-10 items-center justify-center rounded-lg bg-blue-700 text-sm font-bold text-white">
            TCB
          </span>
          <div>
            <p className="font-semibold leading-tight text-slate-900">Task Control Block</p>
            <p className="text-sm text-slate-500">Personal · v1</p>
          </div>
        </div>

        <form onSubmit={submit} noValidate className="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
          <h1 className="text-xl font-semibold text-slate-900">Masuk</h1>

          {state.expired && !login.isError && (
            <p className="mt-3 rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-900">
              Sesi Anda berakhir. Silakan masuk lagi.
            </p>
          )}
          {showAlert && <ErrorMessage error={login.error} className="mt-3" />}

          <label htmlFor={ids.email} className="mt-4 block text-sm font-medium text-slate-700">
            Email
          </label>
          <input
            id={ids.email}
            type="email"
            autoComplete="username"
            required
            value={email}
            onChange={(e) => setEmail(e.target.value)}
            aria-invalid={emailError !== undefined}
            aria-describedby={emailError ? `${ids.email}-error` : undefined}
            className={inputClass}
          />
          {emailError && (
            <p id={`${ids.email}-error`} className="mt-1 text-sm text-red-700">
              {emailError}
            </p>
          )}

          <label htmlFor={ids.password} className="mt-4 block text-sm font-medium text-slate-700">
            Kata sandi
          </label>
          <input
            id={ids.password}
            type="password"
            autoComplete="current-password"
            required
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            aria-invalid={passwordError !== undefined}
            aria-describedby={passwordError ? `${ids.password}-error` : undefined}
            className={inputClass}
          />
          {passwordError && (
            <p id={`${ids.password}-error`} className="mt-1 text-sm text-red-700">
              {passwordError}
            </p>
          )}

          <button
            type="submit"
            disabled={login.isPending}
            className="mt-6 w-full rounded-md bg-blue-700 px-4 py-2 font-medium text-white hover:bg-blue-800 focus:outline-none focus:ring-2 focus:ring-blue-600/40 disabled:opacity-60"
          >
            {login.isPending ? 'Memproses…' : 'Masuk'}
          </button>
        </form>

        <div className="mt-4 text-center text-sm">
          <HealthStatus />
        </div>
      </div>
    </div>
  )
}
