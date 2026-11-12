import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import App from './App'

function renderApp() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={client}>
      <App />
    </QueryClientProvider>,
  )
}

function mockFetch(status: number, body: unknown) {
  vi.stubGlobal(
    'fetch',
    vi.fn().mockResolvedValue(
      new Response(JSON.stringify(body), {
        status,
        headers: { 'Content-Type': 'application/json' },
      }),
    ),
  )
}

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('App', () => {
  it('calls GET /api/v1/health', async () => {
    mockFetch(200, { status: 'ok', database: 'ok' })
    renderApp()
    await screen.findByText('Database tersambung')
    expect(fetch).toHaveBeenCalledWith('/api/v1/health', expect.anything())
  })

  it('shows the database as connected when health is ok', async () => {
    mockFetch(200, { status: 'ok', database: 'ok' })
    renderApp()
    expect(await screen.findByText('Database tersambung')).toBeInTheDocument()
  })

  it('shows the database as disconnected on 503', async () => {
    mockFetch(503, { status: 'ok', database: 'error' })
    renderApp()
    expect(await screen.findByText('Database tidak tersambung')).toBeInTheDocument()
  })

  it('shows a readable message when the API cannot be reached', async () => {
    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new TypeError('Failed to fetch')))
    renderApp()
    expect(await screen.findByText('API tidak bisa dihubungi')).toBeInTheDocument()
  })
})
