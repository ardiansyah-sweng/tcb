import { QueryClientProvider } from '@tanstack/react-query'
import { render } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import App from '../App'
import { createQueryClient } from '../queryClient'

/** Renders the whole app at `path`, with a fresh query cache and no retries. */
export function renderApp(path = '/') {
  const client = createQueryClient({ retry: false })
  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter initialEntries={[path]}>
        <App />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}
