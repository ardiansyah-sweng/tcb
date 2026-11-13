import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { clearToken, getToken, setToken } from './auth/token'
import { json, mockApi, requestsOf, task, user } from './test/api'
import { renderApp } from './test/render'

const health = { 'GET /health': { status: 200, body: { status: 'ok', database: 'ok' } } }

function signedIn(routes: Parameters<typeof mockApi>[0] = {}) {
  setToken('1|secret')
  return mockApi({
    'GET /me': { status: 200, body: user },
    'GET /tasks': { status: 200, body: { data: [task()], next_cursor: null } },
    ...routes,
  })
}

afterEach(() => {
  clearToken()
  localStorage.clear()
  vi.unstubAllGlobals()
})

describe('login', () => {
  it('sends a visitor without a token to the login page', async () => {
    const fetchMock = mockApi(health)
    renderApp('/board')
    expect(await screen.findByRole('heading', { name: 'Masuk' })).toBeInTheDocument()
    expect(requestsOf(fetchMock).some((r) => r.url.endsWith('/me'))).toBe(false)
  })

  it('stores the token and returns to the page that was asked for', async () => {
    const fetchMock = mockApi({
      ...health,
      'POST /auth/login': { status: 200, body: { token: '7|fresh', token_type: 'Bearer', user } },
      'GET /me': { status: 200, body: user },
      'GET /tasks': { status: 200, body: { data: [], next_cursor: null } },
    })
    renderApp('/board')

    await userEvent.type(await screen.findByLabelText('Email'), 'dosen@example.com')
    await userEvent.type(screen.getByLabelText('Kata sandi'), 'rahasia123')
    await userEvent.click(screen.getByRole('button', { name: 'Masuk' }))

    expect(await screen.findByRole('heading', { name: 'Papan status' })).toBeInTheDocument()
    expect(getToken()).toBe('7|fresh')
    const login = requestsOf(fetchMock).find((r) => r.url.endsWith('/auth/login'))!
    expect(await login.clone().json()).toEqual({
      email: 'dosen@example.com',
      password: 'rahasia123',
      device_name: 'web',
    })
  })

  it('shows the message for a wrong password and stays on the form', async () => {
    mockApi({
      ...health,
      'POST /auth/login': {
        status: 422,
        body: { message: 'Email atau kata sandi salah.', errors: { email: ['Email atau kata sandi salah.'] } },
      },
    })
    renderApp('/login')

    await userEvent.type(await screen.findByLabelText('Email'), 'dosen@example.com')
    await userEvent.type(screen.getByLabelText('Kata sandi'), 'salah')
    await userEvent.click(screen.getByRole('button', { name: 'Masuk' }))

    expect(await screen.findByText('Email atau kata sandi salah.')).toBeInTheDocument()
    expect(screen.getByLabelText('Email')).toHaveValue('dosen@example.com')
    expect(getToken()).toBeNull()
  })

  it('shows the throttling message', async () => {
    mockApi({
      ...health,
      'POST /auth/login': {
        status: 429,
        body: { code: 'too_many_attempts', message: 'Terlalu banyak percobaan masuk. Coba lagi dalam 60 detik.' },
      },
    })
    renderApp('/login')
    await userEvent.type(await screen.findByLabelText('Email'), 'dosen@example.com')
    await userEvent.type(screen.getByLabelText('Kata sandi'), 'salah')
    await userEvent.click(screen.getByRole('button', { name: 'Masuk' }))
    expect(await screen.findByRole('alert')).toHaveTextContent(
      'Terlalu banyak percobaan masuk. Coba lagi dalam 60 detik.',
    )
  })

  it('shows a readable message when the API cannot be reached', async () => {
    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new TypeError('Failed to fetch')))
    renderApp('/login')
    await userEvent.type(await screen.findByLabelText('Email'), 'dosen@example.com')
    await userEvent.type(screen.getByLabelText('Kata sandi'), 'rahasia123')
    await userEvent.click(screen.getByRole('button', { name: 'Masuk' }))
    expect(await screen.findByRole('alert')).toHaveTextContent(
      'API tidak bisa dihubungi. Periksa koneksi, lalu coba lagi.',
    )
  })

  it('does not show the form again to someone already signed in', async () => {
    signedIn()
    renderApp('/login')
    expect(await screen.findByRole('heading', { name: 'Beranda' })).toBeInTheDocument()
  })
})

describe('session', () => {
  it('goes back to login when the API answers 401', async () => {
    signedIn({
      ...health,
      'GET /me': { status: 401, body: { code: 'unauthenticated', message: 'Silakan masuk terlebih dahulu.' } },
    })
    renderApp('/board')

    expect(await screen.findByRole('heading', { name: 'Masuk' })).toBeInTheDocument()
    expect(screen.getByText('Sesi Anda berakhir. Silakan masuk lagi.')).toBeInTheDocument()
    expect(getToken()).toBeNull()
  })

  it('logs out: revokes the token on the server and returns to login', async () => {
    const fetchMock = signedIn({ ...health, 'POST /auth/logout': { status: 204 } })
    renderApp('/')

    await userEvent.click(await screen.findByRole('button', { name: 'Keluar' }))

    expect(await screen.findByRole('heading', { name: 'Masuk' })).toBeInTheDocument()
    expect(getToken()).toBeNull()
    const logout = requestsOf(fetchMock).find((r) => r.url.endsWith('/auth/logout'))!
    expect(logout.headers.get('Authorization')).toBe('Bearer 1|secret')
    expect(screen.queryByText('Sesi Anda berakhir. Silakan masuk lagi.')).not.toBeInTheDocument()
  })

  it('still logs out locally when the server cannot be reached', async () => {
    signedIn({ ...health, 'POST /auth/logout': () => Promise.reject(new TypeError('Failed to fetch')) })
    renderApp('/')
    await userEvent.click(await screen.findByRole('button', { name: 'Keluar' }))
    expect(await screen.findByRole('heading', { name: 'Masuk' })).toBeInTheDocument()
    expect(getToken()).toBeNull()
  })
})

describe('layout', () => {
  it('has side navigation to the four screens', async () => {
    signedIn()
    renderApp('/')
    const nav = await screen.findByRole('navigation', { name: 'Navigasi utama' })
    const links = within(nav).getAllByRole('link')
    expect(links.map((a) => [a.textContent, a.getAttribute('href')])).toEqual([
      ['Beranda', '/'],
      ['Papan status', '/board'],
      ['Goal & Key Result', '/goals'],
      ['Detail task', '/tasks'],
    ])
  })

  it('navigates between screens and marks the current one', async () => {
    signedIn()
    renderApp('/')
    const nav = await screen.findByRole('navigation', { name: 'Navigasi utama' })
    expect(within(nav).getByRole('link', { name: 'Beranda' })).toHaveAttribute('aria-current', 'page')

    await userEvent.click(within(nav).getByRole('link', { name: 'Goal & Key Result' }))

    expect(await screen.findByRole('heading', { name: 'Goal & Key Result' })).toBeInTheDocument()
    expect(within(nav).getByRole('link', { name: 'Goal & Key Result' })).toHaveAttribute('aria-current', 'page')
    expect(within(nav).getByRole('link', { name: 'Beranda' })).not.toHaveAttribute('aria-current')
  })

  it('accepts a task key in the detail URL', async () => {
    signedIn()
    renderApp('/tasks/TCB-31')
    expect(await screen.findByRole('heading', { name: 'Detail task' })).toBeInTheDocument()
    expect(screen.getByText('TCB-31')).toBeInTheDocument()
    const nav = screen.getByRole('navigation', { name: 'Navigasi utama' })
    expect(within(nav).getByRole('link', { name: 'Detail task' })).toHaveAttribute('aria-current', 'page')
  })

  it('stacks the navigation above the content on phone width', async () => {
    signedIn()
    const { container } = renderApp('/')
    await screen.findByRole('navigation', { name: 'Navigasi utama' })
    const shell = container.firstElementChild as HTMLElement
    // Mobile first: a column (nav on top), side by side from the md breakpoint.
    expect(shell).toHaveClass('flex-col', 'md:flex-row')
    const [first, second] = Array.from(shell.children)
    expect(first.tagName).toBe('ASIDE')
    expect(second.tagName).toBe('MAIN')
  })

  it('shows an unknown address inside the layout', async () => {
    signedIn()
    renderApp('/tidak-ada')
    expect(await screen.findByRole('heading', { name: 'Halaman tidak ditemukan' })).toBeInTheDocument()
    expect(screen.getByRole('navigation', { name: 'Navigasi utama' })).toBeInTheDocument()
  })
})

describe('Running / WIP limit indicator', () => {
  it('shows running count against the WIP limit, and the switch hint when full', async () => {
    const fetchMock = signedIn()
    renderApp('/')
    const box = await screen.findByRole('status', { name: 'Running / WIP limit' })
    await waitFor(() => expect(box).toHaveTextContent('1 / 1'))
    expect(box).toHaveTextContent('Memulai task lain memicu pindah konteks')
    const tasksRequest = requestsOf(fetchMock).find((r) => new URL(r.url).pathname === '/api/v1/tasks')!
    expect(new URL(tasksRequest.url).searchParams.get('state')).toBe('running')
  })

  it('says a task can still be started when below the limit', async () => {
    signedIn({
      'GET /me': { status: 200, body: { ...user, wip_limit: 2 } },
    })
    renderApp('/')
    const box = await screen.findByRole('status', { name: 'Running / WIP limit' })
    await waitFor(() => expect(box).toHaveTextContent('1 / 2'))
    expect(box).toHaveTextContent('Masih bisa memulai task')
  })

  it('shows a readable error instead of a raw response', async () => {
    signedIn({ 'GET /tasks': () => json(500, { message: 'Server Error' }) })
    renderApp('/')
    const box = await screen.findByRole('status', { name: 'Running / WIP limit' })
    await waitFor(() => expect(box).toHaveTextContent('Terjadi galat di server. Coba lagi sebentar lagi.'))
    expect(box).not.toHaveTextContent('Server Error')
  })
})

describe('system status on the login page', () => {
  it('shows the database as connected when health is ok', async () => {
    mockApi(health)
    renderApp('/login')
    expect(await screen.findByText('Database tersambung')).toBeInTheDocument()
  })

  it('shows the database as disconnected on 503', async () => {
    mockApi({ 'GET /health': { status: 503, body: { status: 'ok', database: 'error' } } })
    renderApp('/login')
    expect(await screen.findByText('Database tidak tersambung')).toBeInTheDocument()
  })

  it('shows a readable message when the API cannot be reached', async () => {
    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new TypeError('Failed to fetch')))
    renderApp('/login')
    expect(await screen.findByText('API tidak bisa dihubungi')).toBeInTheDocument()
  })
})
