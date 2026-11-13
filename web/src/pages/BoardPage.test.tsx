import { fireEvent, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it } from 'vitest'
import { json, task } from '../test/api'
import { board, boardTasks, defaultLists, readyTop, runningTask, taskQueries } from '../test/board'
import { count, fixHomeClock, sent } from '../test/home'
import { renderApp } from '../test/render'

// R1-18: Papan status (FR-18). Layout from docs/mockup/tcb-v1/02-papan status.png.
// Clock: Friday 13 Nov 2026, 09:42:10 Asia/Jakarta (fixHomeClock).

fixHomeClock()

const STATES = ['New', 'Ready', 'Running', 'Waiting', 'Suspended', 'Done', 'Cancelled']

function column(name: string) {
  return screen.getByRole('region', { name })
}

async function findCard(taskKey: string) {
  return screen.findByRole('article', { name: new RegExp(`^${taskKey} `) })
}

/** Drags the card of `taskKey` onto the column `to` (HTML5 drag and drop). */
async function drag(taskKey: string, to: string) {
  const card = await findCard(taskKey)
  fireEvent.dragStart(card)
  fireEvent.dragEnter(column(to))
  fireEvent.dragOver(column(to))
  fireEvent.drop(column(to))
  fireEvent.dragEnd(card)
}

describe('columns', () => {
  it('shows the seven state columns with their task counts', async () => {
    board()
    renderApp('/board')

    expect(await screen.findByRole('heading', { level: 1, name: 'Papan status' })).toBeInTheDocument()
    await findCard('TCB-35')
    for (const name of STATES) expect(column(name)).toBeInTheDocument()
    expect(within(column('New')).getByRole('heading', { name: 'New · 2' })).toBeInTheDocument()
    expect(within(column('Ready')).getByRole('heading', { name: 'Ready · 3' })).toBeInTheDocument()
    expect(within(column('Waiting')).getByRole('heading', { name: 'Waiting · 1' })).toBeInTheDocument()
    expect(within(column('Done')).getByRole('heading', { name: 'Done · 0' })).toBeInTheDocument()
  })

  it('asks for one state per column and Ready by recommendation score', async () => {
    const fetchMock = board()
    renderApp('/board')
    await findCard('TCB-35')

    const queries = taskQueries(fetchMock)
    const ready = queries.filter((q) => q.get('state') === 'ready')
    expect(ready).toHaveLength(1)
    expect(ready[0].get('sort')).toBe('score')
    for (const state of ['new', 'running', 'waiting', 'suspended', 'done', 'cancelled']) {
      expect(queries.some((q) => q.get('state') === state)).toBe(true)
    }
    expect(queries.filter((q) => q.get('state') !== 'ready').every((q) => q.get('sort') !== 'score')).toBe(true)
  })

  it('keeps the score order of Ready and shows each score', async () => {
    board()
    renderApp('/board')
    await findCard('TCB-35')

    const cards = within(column('Ready')).getAllByRole('article')
    expect(cards.map((c) => within(c).getByText(/^TCB-/).textContent)).toEqual(['TCB-35', 'TCB-38', 'TCB-27'])
    expect(within(cards[0]).getByText('0,78')).toBeInTheDocument()
    expect(within(cards[1]).getByText('0,64')).toBeInTheDocument()
    expect(within(cards[2]).queryByText(/^\d,\d\d$/)).not.toBeInTheDocument()
  })

  it('shows "n / WIP limit" on the Running column', async () => {
    board()
    renderApp('/board')
    await findCard('TCB-31')

    expect(within(column('Running')).getByRole('heading', { name: 'Running · 1 / 1' })).toBeInTheDocument()
  })

  it('loads more cards in a column when the API has another page', async () => {
    const done = Array.from({ length: 2 }, (_, i) => task({ id: 60 + i, task_key: `TCB-${60 + i}`, title: `Selesai ${i}`, state: 'done' }))
    board({
      'GET /tasks': (request) => {
        const params = new URL(request.url).searchParams
        if (params.get('state') === 'done') {
          return params.get('cursor') === 'c2'
            ? json(200, { data: [task({ id: 70, task_key: 'TCB-70', title: 'Selesai lama', state: 'done' })], next_cursor: null })
            : json(200, { data: done, next_cursor: 'c2' })
        }
        return boardTasks(defaultLists)(request)
      },
    })
    renderApp('/board')
    await findCard('TCB-60')

    expect(within(column('Done')).getByRole('heading', { name: 'Done · 2+' })).toBeInTheDocument()
    await userEvent.click(within(column('Done')).getByRole('button', { name: 'Muat lagi' }))

    expect(await findCard('TCB-70')).toBeInTheDocument()
    expect(within(column('Done')).getByRole('heading', { name: 'Done · 3' })).toBeInTheDocument()
    expect(within(column('Done')).queryByRole('button', { name: 'Muat lagi' })).not.toBeInTheDocument()
  })

  it('shows a readable error in a column that fails to load', async () => {
    board({
      'GET /tasks': (request) =>
        new URL(request.url).searchParams.get('state') === 'waiting'
          ? json(500, { message: 'SQLSTATE[08006] boom' })
          : boardTasks(defaultLists)(request),
    })
    renderApp('/board')

    expect(await within(column('Waiting')).findByText('Terjadi galat di server. Coba lagi sebentar lagi.')).toBeInTheDocument()
  })
})

describe('filters', () => {
  it('filters every column by Objective', async () => {
    const fetchMock = board()
    renderApp('/board')
    await findCard('TCB-35')
    const select = screen.getByRole('combobox', { name: 'Objective' })
    expect(within(select).getAllByRole('option').map((o) => o.textContent)).toEqual([
      'Semua',
      'Semester ganjil 2026/2027',
      'Publikasi 2026',
      'Tanpa KR (orphan)',
    ])

    await userEvent.selectOptions(select, 'Semester ganjil 2026/2027')

    await waitFor(() => expect(taskQueries(fetchMock).filter((q) => q.get('objective_id') === '1').length).toBeGreaterThanOrEqual(7))
    expect(taskQueries(fetchMock).every((q) => q.get('orphan') === null)).toBe(true)
  })

  it('filters orphan tasks with "Tanpa KR (orphan)"', async () => {
    const fetchMock = board()
    renderApp('/board')
    await findCard('TCB-35')

    await userEvent.selectOptions(screen.getByRole('combobox', { name: 'Objective' }), 'Tanpa KR (orphan)')

    await waitFor(() => expect(taskQueries(fetchMock).filter((q) => q.get('orphan') === 'true').length).toBeGreaterThanOrEqual(7))
    expect(taskQueries(fetchMock).every((q) => q.get('objective_id') === null)).toBe(true)
  })

  it('filters by kind of work (label), offering the usual labels and those in use', async () => {
    const fetchMock = board()
    renderApp('/board')
    await findCard('TCB-35')
    const select = screen.getByRole('combobox', { name: 'Jenis pekerjaan' })
    expect(within(select).getAllByRole('option').map((o) => o.textContent)).toEqual([
      'Semua',
      'Bimbingan',
      'Koreksi ujian',
      'Mengajar',
      'Menulis paper',
    ])

    await userEvent.selectOptions(select, 'Mengajar')

    await waitFor(() => expect(taskQueries(fetchMock).filter((q) => q.get('label') === 'Mengajar').length).toBeGreaterThanOrEqual(7))
    // The other labels stay available after filtering.
    expect(within(select).getAllByRole('option')).toHaveLength(5)
  })

  it('does not send a filter for "Semua"', async () => {
    const fetchMock = board()
    renderApp('/board')
    await findCard('TCB-35')

    for (const q of taskQueries(fetchMock)) {
      expect(q.get('objective_id')).toBeNull()
      expect(q.get('orphan')).toBeNull()
      expect(q.get('label')).toBeNull()
    }
  })
})

describe('cards', () => {
  it('shows task_key, title, and label', async () => {
    board()
    renderApp('/board')
    const card = await findCard('TCB-38')

    expect(within(card).getByText('TCB-38')).toBeInTheDocument()
    expect(within(card).getByText('Siapkan slide kuliah pertemuan 12')).toBeInTheDocument()
    expect(within(card).getByText('Mengajar')).toBeInTheDocument()
  })

  it('marks an orphan task', async () => {
    board()
    renderApp('/board')

    expect(within(await findCard('TCB-41')).getByText('Orphan · belum ada KR')).toBeInTheDocument()
    expect(within(await findCard('TCB-42')).queryByText('Orphan · belum ada KR')).not.toBeInTheDocument()
  })

  it('shows one info line: deadline and estimate', async () => {
    board()
    renderApp('/board')

    expect(within(await findCard('TCB-35')).getByText('Deadline 14 Nov · 90 mnt')).toBeInTheDocument()
  })

  it('shows one info line: unit progress', async () => {
    board()
    renderApp('/board')

    expect(within(await findCard('TCB-31')).getByText('22/40 lembar · 2 kali pindah')).toBeInTheDocument()
  })

  it('shows one info line: the waiting reason', async () => {
    board()
    renderApp('/board')

    expect(within(await findCard('TCB-34')).getByText('Menunggu nilai praktikum dari asisten')).toBeInTheDocument()
  })

  it('shows the review date of a suspended task', async () => {
    board()
    renderApp('/board')

    expect(within(await findCard('TCB-22')).getByText('Tinjau ulang 1 Des')).toBeInTheDocument()
  })

  it('links the card to the task detail', async () => {
    board()
    renderApp('/board')

    expect(within(await findCard('TCB-35')).getByRole('link', { name: 'Revisi bagian Metode paper' })).toHaveAttribute('href', '/tasks/TCB-35')
  })
})

describe('drag and drop', () => {
  it('moves a card with the transition endpoint and refreshes the board', async () => {
    const fetchMock = board({ 'POST /tasks/35/transitions': { status: 200, body: { ...readyTop, state: 'suspended' } } })
    renderApp('/board')
    await findCard('TCB-35')
    const before = taskQueries(fetchMock).length

    await drag('TCB-35', 'Suspended')

    expect(await screen.findByText('TCB-35 dipindah ke Suspended.')).toBeInTheDocument()
    expect(await sent(fetchMock, 'POST', '/tasks/35/transitions')).toEqual([{ to: 'suspended' }])
    await waitFor(() => expect(taskQueries(fetchMock).length).toBeGreaterThan(before))
  })

  it('does nothing when the card is dropped on its own column', async () => {
    const fetchMock = board()
    renderApp('/board')
    await findCard('TCB-35')

    await drag('TCB-35', 'Ready')

    expect(count(fetchMock, 'POST', '/tasks/35/transitions')).toBe(0)
  })

  it('shows the message of a refused transition', async () => {
    board({
      'POST /tasks/41/transitions': { status: 409, body: { code: 'invalid_transition', message: 'Transisi dari New ke Done tidak diizinkan.' } },
    })
    renderApp('/board')
    await findCard('TCB-41')

    await drag('TCB-41', 'Done')

    expect(await screen.findByRole('alert')).toHaveTextContent('Transisi dari New ke Done tidak diizinkan.')
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
  })

  it('opens the context form when a card leaves Running for Ready', async () => {
    const fetchMock = board({
      'POST /tasks/31/transitions': async (request) => {
        const body = await request.clone().json()
        return body.context
          ? json(200, { ...runningTask, state: 'ready' })
          : json(422, { code: 'context_required', message: 'Isi konteks sebelum keluar dari Running.', errors: { context: ['Isi konteks.'] } })
      },
    })
    renderApp('/board')
    await findCard('TCB-31')

    await drag('TCB-31', 'Ready')

    const dialog = await screen.findByRole('dialog', { name: 'Jeda TCB-31' })
    expect(screen.queryByRole('alert')).not.toBeInTheDocument()
    await userEvent.click(await within(dialog).findByRole('button', { name: 'Simpan & jeda' }))

    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
    const bodies = (await sent(fetchMock, 'POST', '/tasks/31/transitions')) as Array<Record<string, unknown>>
    expect(bodies.at(-1)).toMatchObject({ to: 'ready', context: { last_note: '22 dari 40 lembar selesai.', next_step: 'Soal nomor 3' } })
  })

  it('opens the context form with the reason when a card leaves Running for Waiting', async () => {
    board({
      'POST /tasks/31/transitions': { status: 422, body: { code: 'reason_required', message: 'Isi alasan.', errors: { reason: ['Isi alasan.'] } } },
    })
    renderApp('/board')
    await findCard('TCB-31')

    await drag('TCB-31', 'Waiting')

    const dialog = await screen.findByRole('dialog', { name: 'Terblokir TCB-31' })
    expect(await within(dialog).findByRole('textbox', { name: 'Menunggu apa?' })).toBeInTheDocument()
  })

  it('opens the context form when a card leaves Running for Suspended', async () => {
    const fetchMock = board({
      'POST /tasks/31/transitions': async (request) => {
        const body = await request.clone().json()
        return body.context
          ? json(200, { ...runningTask, state: 'suspended' })
          : json(422, { code: 'context_required', message: 'Isi konteks.', errors: { context: ['Isi konteks.'] } })
      },
    })
    renderApp('/board')
    await findCard('TCB-31')

    await drag('TCB-31', 'Suspended')

    const dialog = await screen.findByRole('dialog', { name: 'Tunda TCB-31' })
    await userEvent.click(await within(dialog).findByRole('button', { name: 'Simpan & tunda' }))
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
    const bodies = (await sent(fetchMock, 'POST', '/tasks/31/transitions')) as Array<Record<string, unknown>>
    expect(bodies.at(-1)).toMatchObject({ to: 'suspended', context: { next_step: 'Soal nomor 3' } })
  })

  it('finishes a running task without a form when dropped on Done', async () => {
    const fetchMock = board({ 'POST /tasks/31/transitions': { status: 200, body: { ...runningTask, state: 'done' } } })
    renderApp('/board')
    await findCard('TCB-31')

    await drag('TCB-31', 'Done')

    expect(await screen.findByText('TCB-31 dipindah ke Done.')).toBeInTheDocument()
    expect(await sent(fetchMock, 'POST', '/tasks/31/transitions')).toEqual([{ to: 'done' }])
  })

  it('asks for a reason when a card is dropped on Cancelled', async () => {
    const fetchMock = board({
      'POST /tasks/35/transitions': async (request) => {
        const body = await request.clone().json()
        return body.reason
          ? json(200, { ...readyTop, state: 'cancelled' })
          : json(422, { code: 'reason_required', message: 'Isi alasan.', errors: { reason: ['Isi alasan.'] } })
      },
    })
    renderApp('/board')
    await findCard('TCB-35')

    await drag('TCB-35', 'Cancelled')

    const dialog = await screen.findByRole('dialog', { name: 'Batalkan TCB-35' })
    const input = within(dialog).getByRole('textbox', { name: 'Alasan' })
    expect(input).toHaveFocus()
    await userEvent.type(input, '{Enter}')
    expect(within(dialog).getByText('Isi alasannya dulu.')).toBeInTheDocument()

    await userEvent.type(input, 'Paper ditarik{Enter}')

    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
    const bodies = (await sent(fetchMock, 'POST', '/tasks/35/transitions')) as unknown[]
    expect(bodies.at(-1)).toEqual({ to: 'cancelled', reason: 'Paper ditarik' })
    expect(await screen.findByText('TCB-35 dipindah ke Cancelled.')).toBeInTheDocument()
  })

  it('asks for a reason when a Ready card is dropped on Waiting', async () => {
    board({
      'POST /tasks/35/transitions': { status: 422, body: { code: 'reason_required', message: 'Isi alasan.', errors: { reason: ['Isi alasan.'] } } },
    })
    renderApp('/board')
    await findCard('TCB-35')

    await drag('TCB-35', 'Waiting')

    const dialog = await screen.findByRole('dialog', { name: 'Pindahkan TCB-35 ke Waiting' })
    expect(within(dialog).getByRole('textbox', { name: 'Menunggu apa?' })).toBeInTheDocument()
  })

  it('keeps the reason dialog open with the error when saving fails, and Esc closes it', async () => {
    board({
      'POST /tasks/35/transitions': async (request) => {
        const body = await request.clone().json()
        return body.reason
          ? json(409, { code: 'conflict', message: 'Task sudah berubah. Muat ulang papan.' })
          : json(422, { code: 'reason_required', message: 'Isi alasan.', errors: { reason: ['Isi alasan.'] } })
      },
    })
    renderApp('/board')
    await findCard('TCB-35')
    await drag('TCB-35', 'Cancelled')
    const dialog = await screen.findByRole('dialog', { name: 'Batalkan TCB-35' })

    await userEvent.type(within(dialog).getByRole('textbox', { name: 'Alasan' }), 'Tidak relevan{Enter}')

    expect(await within(dialog).findByText('Task sudah berubah. Muat ulang papan.')).toBeInTheDocument()
    expect(within(dialog).getByRole('textbox', { name: 'Alasan' })).toHaveValue('Tidak relevan')
    await userEvent.keyboard('{Escape}')
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
  })

  it('opens the switch form when Running is full', async () => {
    board({
      'POST /tasks/35/transitions': { status: 409, body: { code: 'wip_full', message: 'Running sudah penuh.', wip_limit: 1, running: [runningTask] } },
    })
    renderApp('/board')
    await findCard('TCB-35')

    await drag('TCB-35', 'Running')

    const dialog = await screen.findByRole('dialog', { name: 'Simpan konteks sebelum pindah' })
    expect(within(dialog).getByText(/TCB-31 dipindah dengan konteks di bawah, lalu TCB-35 mulai berjalan/)).toBeInTheDocument()
  })
})

describe('narrow screens', () => {
  it('scrolls the columns horizontally', async () => {
    board()
    renderApp('/board')
    await findCard('TCB-35')

    const strip = screen.getByRole('group', { name: 'Kolom status' })
    expect(strip).toHaveClass('overflow-x-auto')
    for (const name of STATES) expect(column(name)).toHaveClass('shrink-0')
  })
})
