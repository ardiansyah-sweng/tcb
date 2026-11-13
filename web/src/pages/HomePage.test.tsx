import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it } from 'vitest'
import { json, requestsOf, task, user, type MockReply } from '../test/api'
import {
  batch,
  count,
  detailOf,
  fixHomeClock,
  home,
  running,
  runningDetail,
  sent,
  staleTask,
  waitingExternal,
  waitingOnTask,
  waitingReasonOnly,
  type Lists,
} from '../test/home'
import { renderApp } from '../test/render'

// R1-16: Beranda (FR-03, FR-08, FR-12, FR-13, FR-18).
// Clock: Friday 13 Nov 2026, 09:42:10 Asia/Jakarta (fixHomeClock).

fixHomeClock()

describe('header', () => {
  it("shows today's date in the user's time zone", async () => {
    home()
    renderApp('/')
    expect(await screen.findByRole('heading', { level: 1, name: 'Beranda' })).toBeInTheDocument()
    expect(screen.getByText('Jumat, 13 November 2026')).toBeInTheDocument()
  })
})

describe('quick add', () => {
  it('adds a task as New from its title on Enter', async () => {
    const fetchMock = home({
      'POST /tasks': { status: 201, body: task({ id: 40, task_key: 'TCB-40', title: 'Susun soal UAS', state: 'new' }) },
    })
    renderApp('/')
    const input = await screen.findByRole('textbox', { name: 'Tambah task cepat' })

    await userEvent.type(input, 'Susun soal UAS{Enter}')

    expect(await screen.findByText('TCB-40 ditambahkan sebagai New.')).toBeInTheDocument()
    expect(await sent(fetchMock, 'POST', '/tasks')).toEqual([{ title: 'Susun soal UAS' }])
    expect(input).toHaveValue('')
  })

  it('also adds with the Tambah button', async () => {
    const fetchMock = home({
      'POST /tasks': { status: 201, body: task({ id: 41, task_key: 'TCB-41', title: 'Cek presensi', state: 'new' }) },
    })
    renderApp('/')
    await userEvent.type(await screen.findByRole('textbox', { name: 'Tambah task cepat' }), 'Cek presensi')
    await userEvent.click(screen.getByRole('button', { name: 'Tambah' }))

    expect(await screen.findByText('TCB-41 ditambahkan sebagai New.')).toBeInTheDocument()
    expect(await sent(fetchMock, 'POST', '/tasks')).toEqual([{ title: 'Cek presensi' }])
  })

  it('does not send an empty title', async () => {
    const fetchMock = home()
    renderApp('/')
    await userEvent.type(await screen.findByRole('textbox', { name: 'Tambah task cepat' }), '   {Enter}')

    expect(await screen.findByText('Tulis judul task dulu.')).toBeInTheDocument()
    expect(count(fetchMock, 'POST', '/tasks')).toBe(0)
  })

  it('keeps the title and shows a readable error when adding fails', async () => {
    home({
      'POST /tasks': {
        status: 422,
        body: { message: 'Judul terlalu panjang. (and 1 more error)', errors: { title: ['Judul paling banyak 255 karakter.'] } },
      },
    })
    renderApp('/')
    const input = await screen.findByRole('textbox', { name: 'Tambah task cepat' })
    await userEvent.type(input, 'Judul{Enter}')

    expect(await screen.findByText('Judul paling banyak 255 karakter.')).toBeInTheDocument()
    expect(input).toHaveValue('Judul')
  })
})

describe('running panel', () => {
  it('shows key, title, KR, session timer, time against estimate, and the last context', async () => {
    home()
    renderApp('/')
    const panel = await screen.findByRole('region', { name: 'Sedang berjalan' })

    expect(await within(panel).findByText('Koreksi UTS kelas A')).toBeInTheDocument()
    expect(within(panel).getByText('TCB-31')).toBeInTheDocument()
    expect(within(panel).getByRole('link', { name: 'KR · Nilai ujian diunggah sebelum batas' })).toHaveAttribute('href', '/goals')
    expect(await within(panel).findByText('00:42:10')).toBeInTheDocument()
    // 108 minutes from closed sessions + 42 in this one.
    expect(within(panel).getByText('sesi ini · total 150 dari 240 mnt')).toBeInTheDocument()

    expect(within(panel).getByText('Terakhir (konteks disimpan 11 Nov, 16.40)')).toBeInTheDocument()
    expect(within(panel).getByText('22 dari 40 lembar selesai.')).toBeInTheDocument()
    expect(within(panel).getByText('Langkah berikutnya')).toBeInTheDocument()
    expect(within(panel).getByText('Tetapkan skor parsial soal nomor 3.')).toBeInTheDocument()
    expect(within(panel).getByRole('link', { name: 'Rubrik UTS' })).toHaveAttribute('href', 'https://drive.example.com/rubrik')
    expect(within(panel).getByRole('link', { name: 'https://drive.example.com/rekap' })).toBeInTheDocument()

    for (const name of ['Selesai', 'Jeda', 'Terblokir…', 'Pindah task']) {
      expect(within(panel).getByRole('button', { name })).toBeInTheDocument()
    }
    expect(within(panel).getByRole('link', { name: 'Lihat detail task' })).toHaveAttribute('href', '/tasks/TCB-31')
  })

  it('shows total time without an estimate, and no context block when none was saved', async () => {
    home({
      'GET /tasks/31': {
        status: 200,
        body: { ...runningDetail, estimate_minutes: null, latest_context: null },
      },
    })
    renderApp('/')
    const panel = await screen.findByRole('region', { name: 'Sedang berjalan' })

    expect(await within(panel).findByText('sesi ini · total 150 mnt')).toBeInTheDocument()
    expect(within(panel).getByText('Belum ada konteks tersimpan.')).toBeInTheDocument()
  })

  it('shows an orphan task without a KR link', async () => {
    home({
      'GET /tasks/31': { status: 200, body: { ...runningDetail, key_result_id: null, key_result_title: null, is_orphan: true } },
    })
    renderApp('/')
    const panel = await screen.findByRole('region', { name: 'Sedang berjalan' })

    expect(await within(panel).findByText('Tanpa KR (orphan)')).toBeInTheDocument()
    expect(within(panel).queryByRole('link', { name: /^KR ·/ })).not.toBeInTheDocument()
  })

  it('finishes the task with Selesai and refreshes the screen', async () => {
    const fetchMock = home({ 'POST /tasks/31/transitions': { status: 200, body: { ...runningDetail, state: 'done' } } })
    renderApp('/')
    const panel = await screen.findByRole('region', { name: 'Sedang berjalan' })
    await within(panel).findByText('Koreksi UTS kelas A')
    const batches = count(fetchMock, 'GET', '/recommendations')

    await userEvent.click(within(panel).getByRole('button', { name: 'Selesai' }))

    await waitFor(() => expect(count(fetchMock, 'GET', '/recommendations')).toBeGreaterThan(batches))
    expect(await sent(fetchMock, 'POST', '/tasks/31/transitions')).toEqual([{ to: 'done' }])
  })

  it('shows why Selesai was refused', async () => {
    home({
      'POST /tasks/31/transitions': {
        status: 409,
        body: { code: 'invalid_transition', message: 'Task induk belum bisa selesai karena masih ada subtask terbuka.' },
      },
    })
    renderApp('/')
    const panel = await screen.findByRole('region', { name: 'Sedang berjalan' })
    await userEvent.click(await within(panel).findByRole('button', { name: 'Selesai' }))

    expect(await within(panel).findByText('Task induk belum bisa selesai karena masih ada subtask terbuka.')).toBeInTheDocument()
  })

  it('invites picking from the recommendations when nothing is running', async () => {
    home({ 'GET /recommendations': { status: 200, body: batch({ running_full: false }) } }, { running: [] })
    renderApp('/')
    const panel = await screen.findByRole('region', { name: 'Sedang berjalan' })

    expect(await within(panel).findByText('Tidak ada task yang sedang berjalan.')).toBeInTheDocument()
    expect(within(panel).getByText('Pilih satu dari rekomendasi, lalu tekan Mulai.')).toBeInTheDocument()
    await userEvent.click(within(panel).getByRole('button', { name: 'Lihat rekomendasi' }))
    expect(screen.getByRole('heading', { name: 'Rekomendasi berikutnya' })).toHaveFocus()
  })

  it('Pindah task moves focus to the recommendations', async () => {
    home()
    renderApp('/')
    const panel = await screen.findByRole('region', { name: 'Sedang berjalan' })
    await userEvent.click(await within(panel).findByRole('button', { name: 'Pindah task' }))

    expect(screen.getByRole('heading', { name: 'Rekomendasi berikutnya' })).toHaveFocus()
  })
})

describe('context form for Jeda and Terblokir', () => {
  it('Jeda opens the form filled from the draft and moves the task to Ready', async () => {
    const fetchMock = home({ 'POST /tasks/31/transitions': { status: 200, body: { ...runningDetail, state: 'ready' } } })
    renderApp('/')
    const panel = await screen.findByRole('region', { name: 'Sedang berjalan' })
    await userEvent.click(await within(panel).findByRole('button', { name: 'Jeda' }))

    const dialog = await screen.findByRole('dialog', { name: 'Jeda TCB-31' })
    const lastNote = within(dialog).getByRole('textbox', { name: 'Sampai mana?' })
    await waitFor(() => expect(lastNote).toHaveValue('22 dari 40 lembar selesai.'))
    expect(within(dialog).getByRole('textbox', { name: 'Langkah berikutnya' })).toHaveValue('Tetapkan skor parsial soal nomor 3.')

    await userEvent.clear(lastNote)
    await userEvent.type(lastNote, '25 dari 40 lembar selesai.')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Simpan & jeda' }))

    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
    expect(await sent(fetchMock, 'POST', '/tasks/31/transitions')).toEqual([
      {
        to: 'ready',
        context: {
          last_note: '25 dari 40 lembar selesai.',
          next_step: 'Tetapkan skor parsial soal nomor 3.',
          blocker: null,
          unit_done: null,
          links: [{ title: 'Rubrik UTS', url: 'https://drive.example.com/rubrik' }],
        },
      },
    ])
  })

  it('Terblokir asks for the reason and moves the task to Waiting', async () => {
    const fetchMock = home({ 'POST /tasks/31/transitions': { status: 200, body: { ...runningDetail, state: 'waiting' } } })
    renderApp('/')
    const panel = await screen.findByRole('region', { name: 'Sedang berjalan' })
    await userEvent.click(await within(panel).findByRole('button', { name: 'Terblokir…' }))

    const dialog = await screen.findByRole('dialog', { name: 'Terblokir TCB-31' })
    await waitFor(() => expect(within(dialog).getByRole('textbox', { name: 'Sampai mana?' })).toHaveValue('22 dari 40 lembar selesai.'))
    await userEvent.click(within(dialog).getByRole('button', { name: 'Simpan & tandai menunggu' }))

    expect(await within(dialog).findByText('Isi apa yang ditunggu.')).toBeInTheDocument()
    expect(count(fetchMock, 'POST', '/tasks/31/transitions')).toBe(0)

    await userEvent.type(within(dialog).getByRole('textbox', { name: 'Menunggu apa?' }), 'Nilai praktikum dari asisten')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Simpan & tandai menunggu' }))

    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
    expect(await sent(fetchMock, 'POST', '/tasks/31/transitions')).toEqual([
      {
        to: 'waiting',
        reason: 'Nilai praktikum dari asisten',
        context: {
          last_note: '22 dari 40 lembar selesai.',
          next_step: 'Tetapkan skor parsial soal nomor 3.',
          blocker: 'Nilai praktikum dari asisten',
          unit_done: null,
          links: [{ title: 'Rubrik UTS', url: 'https://drive.example.com/rubrik' }],
        },
      },
    ])
  })

  it('keeps the form open with its input when saving fails', async () => {
    home({
      'POST /tasks/31/transitions': {
        status: 422,
        body: { message: 'Catatan terlalu panjang.', errors: { 'context.last_note': ['Catatan terlalu panjang.'] } },
      },
    })
    renderApp('/')
    const panel = await screen.findByRole('region', { name: 'Sedang berjalan' })
    await userEvent.click(await within(panel).findByRole('button', { name: 'Jeda' }))
    const dialog = await screen.findByRole('dialog', { name: 'Jeda TCB-31' })
    const lastNote = within(dialog).getByRole('textbox', { name: 'Sampai mana?' })
    await waitFor(() => expect(lastNote).toHaveValue('22 dari 40 lembar selesai.'))
    await userEvent.type(lastNote, ' Tambahan.')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Simpan & jeda' }))

    expect(await within(dialog).findByText('Catatan terlalu panjang.')).toBeInTheDocument()
    expect(lastNote).toHaveValue('22 dari 40 lembar selesai. Tambahan.')
  })

  it('opens with empty fields when the draft cannot be loaded', async () => {
    home({ 'GET /tasks/31/contexts/draft': () => json(500, { message: 'Server Error' }) })
    renderApp('/')
    const panel = await screen.findByRole('region', { name: 'Sedang berjalan' })
    await userEvent.click(await within(panel).findByRole('button', { name: 'Jeda' }))
    const dialog = await screen.findByRole('dialog', { name: 'Jeda TCB-31' })

    expect(await within(dialog).findByText('Isian awal tidak bisa dimuat; form dikosongkan.')).toBeInTheDocument()
    expect(within(dialog).getByRole('textbox', { name: 'Sampai mana?' })).toHaveValue('')
  })

  it('Batal and Esc close the form without saving', async () => {
    const fetchMock = home()
    renderApp('/')
    const panel = await screen.findByRole('region', { name: 'Sedang berjalan' })
    await userEvent.click(await within(panel).findByRole('button', { name: 'Jeda' }))
    await userEvent.click(within(await screen.findByRole('dialog')).getByRole('button', { name: 'Batal' }))
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()

    await userEvent.click(within(panel).getByRole('button', { name: 'Jeda' }))
    await screen.findByRole('dialog')
    await userEvent.keyboard('{Escape}')
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    expect(count(fetchMock, 'POST', '/tasks/31/transitions')).toBe(0)
  })
})

describe('recommendations', () => {
  it('shows three cards with rank, score and bar, title, and at most two reasons, without Jadwalkan', async () => {
    home()
    renderApp('/')
    const list = await screen.findByRole('region', { name: 'Rekomendasi berikutnya' })
    const cards = await within(list).findAllByRole('article')
    expect(cards).toHaveLength(3)

    const first = cards[0]
    expect(within(first).getByText('#1 · TCB-35')).toBeInTheDocument()
    expect(within(first).getByText('Skor 0,78')).toBeInTheDocument()
    expect(within(first).getByRole('meter', { name: 'Skor' })).toHaveAttribute('aria-valuenow', '0.78')
    expect(within(first).getByText('Revisi bagian Metode paper')).toBeInTheDocument()
    expect(within(first).getAllByRole('listitem').map((li) => li.textContent)).toEqual([
      'Key Result bobot tinggi, progres masih 30%',
      'Deadline Sabtu, 14 Nov',
    ])
    expect(within(cards[1]).getAllByRole('listitem')).toHaveLength(1)

    expect(within(list).queryByRole('button', { name: /Jadwalkan/ })).not.toBeInTheDocument()
    expect(within(first).getByRole('button', { name: 'Lewati' })).toBeInTheDocument()
  })

  it('says Running is full and offers Pindah ke task ini', async () => {
    home()
    renderApp('/')
    const list = await screen.findByRole('region', { name: 'Rekomendasi berikutnya' })

    expect(await within(list).findByText('Running penuh, jadi memilih task akan menyimpan konteks TCB-31 dulu.')).toBeInTheDocument()
    expect(within(list).getAllByRole('button', { name: 'Pindah ke task ini' })).toHaveLength(3)
    expect(within(list).queryByRole('button', { name: 'Mulai' })).not.toBeInTheDocument()
  })

  it('starts a task with Mulai when Running is not full, and records it as picked', async () => {
    const fetchMock = home(
      {
        'GET /recommendations': { status: 200, body: batch({ running_full: false }) },
        'POST /tasks/35/transitions': { status: 200, body: detailOf(task({ id: 35, state: 'running' })) },
        'POST /recommendations/101/decision': { status: 204 },
      },
      { running: [] },
    )
    renderApp('/')
    const list = await screen.findByRole('region', { name: 'Rekomendasi berikutnya' })
    const [first] = await within(list).findAllByRole('article')

    await userEvent.click(within(first).getByRole('button', { name: 'Mulai' }))

    await waitFor(async () => expect(await sent(fetchMock, 'POST', '/recommendations/101/decision')).toEqual([{ decision: 'picked' }]))
    expect(await sent(fetchMock, 'POST', '/tasks/35/transitions')).toEqual([{ to: 'running' }])
  })

  it('switches with the context form when Running is full', async () => {
    const fetchMock = home({
      'POST /switch': {
        status: 200,
        body: { left: { ...running, state: 'ready' }, started: detailOf(task({ id: 35, state: 'running' })) },
      },
    })
    renderApp('/')
    const list = await screen.findByRole('region', { name: 'Rekomendasi berikutnya' })
    const [first] = await within(list).findAllByRole('article')
    await userEvent.click(within(first).getByRole('button', { name: 'Pindah ke task ini' }))

    const dialog = await screen.findByRole('dialog', { name: 'Simpan konteks sebelum pindah' })
    expect(within(dialog).getByText('Running sudah penuh (1/1). TCB-31 dipindah dengan konteks di bawah, lalu TCB-35 mulai berjalan.')).toBeInTheDocument()
    await waitFor(() => expect(within(dialog).getByRole('textbox', { name: 'Sampai mana?' })).toHaveValue('22 dari 40 lembar selesai.'))
    await userEvent.click(within(dialog).getByRole('button', { name: 'Simpan & pindah' }))

    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
    expect(await sent(fetchMock, 'POST', '/switch')).toEqual([
      {
        from_task_id: '31',
        from_to: 'ready',
        to_task_id: '35',
        recommendation_id: 101,
        context: {
          last_note: '22 dari 40 lembar selesai.',
          next_step: 'Tetapkan skor parsial soal nomor 3.',
          blocker: null,
          unit_done: null,
          links: [{ title: 'Rubrik UTS', url: 'https://drive.example.com/rubrik' }],
        },
      },
    ])
    // /switch records the recommendation as picked itself.
    expect(count(fetchMock, 'POST', '/recommendations/101/decision')).toBe(0)
  })

  it('opens the switch form when Mulai hits the WIP limit', async () => {
    home(
      {
        'GET /recommendations': { status: 200, body: batch({ running_full: false }) },
        'POST /tasks/35/transitions': {
          status: 409,
          body: { code: 'wip_full', message: 'WIP limit penuh.', wip_limit: 1, running: [running] },
        },
      },
      { running: [] },
    )
    renderApp('/')
    const list = await screen.findByRole('region', { name: 'Rekomendasi berikutnya' })
    const [first] = await within(list).findAllByRole('article')
    await userEvent.click(within(first).getByRole('button', { name: 'Mulai' }))

    expect(await screen.findByRole('dialog', { name: 'Simpan konteks sebelum pindah' })).toBeInTheDocument()
  })

  it('asks which task to leave when several are running', async () => {
    const other = task({ id: 32, task_key: 'TCB-32', title: 'Tulis abstrak', state: 'running' })
    const fetchMock = home(
      {
        'GET /me': { status: 200, body: { ...user, wip_limit: 2 } },
        'GET /tasks/32': { status: 200, body: { ...detailOf(other), current_session_started_at: '2026-11-13T02:30:00Z' } },
        'GET /tasks/32/contexts/draft': { status: 200, body: { last_note: 'Abstrak paragraf 1', next_step: null, blocker: null, unit_done: null, links: [] } },
        'POST /switch': { status: 200, body: { left: other, started: detailOf(task({ id: 35, state: 'running' })) } },
      },
      { running: [running, other] },
    )
    renderApp('/')
    const list = await screen.findByRole('region', { name: 'Rekomendasi berikutnya' })
    const [first] = await within(list).findAllByRole('article')
    await userEvent.click(within(first).getByRole('button', { name: 'Pindah ke task ini' }))

    const dialog = await screen.findByRole('dialog', { name: 'Simpan konteks sebelum pindah' })
    await userEvent.selectOptions(within(dialog).getByRole('combobox', { name: 'Task yang ditinggalkan' }), 'TCB-32 · Tulis abstrak')
    await waitFor(() => expect(within(dialog).getByRole('textbox', { name: 'Sampai mana?' })).toHaveValue('Abstrak paragraf 1'))
    await userEvent.click(within(dialog).getByRole('button', { name: 'Simpan & pindah' }))

    await waitFor(async () => expect(await sent(fetchMock, 'POST', '/switch')).toHaveLength(1))
    const [body] = (await sent(fetchMock, 'POST', '/switch')) as Array<Record<string, unknown>>
    expect(body.from_task_id).toBe('32')
  })

  it('Lewati records skipped and loads a new batch', async () => {
    const fetchMock = home({ 'POST /recommendations/102/decision': { status: 204 } })
    renderApp('/')
    const list = await screen.findByRole('region', { name: 'Rekomendasi berikutnya' })
    const cards = await within(list).findAllByRole('article')
    const batches = count(fetchMock, 'GET', '/recommendations')

    await userEvent.click(within(cards[1]).getByRole('button', { name: 'Lewati' }))

    await waitFor(() => expect(count(fetchMock, 'GET', '/recommendations')).toBe(batches + 1))
    expect(await sent(fetchMock, 'POST', '/recommendations/102/decision')).toEqual([{ decision: 'skipped' }])
  })

  it('reminds to act on a stale recommendation', async () => {
    home()
    renderApp('/')
    const list = await screen.findByRole('region', { name: 'Rekomendasi berikutnya' })
    const cards = await within(list).findAllByRole('article')

    expect(within(cards[2]).getByText('Sudah lebih dari 14 hari di Ready: kerjakan, tunda, atau batalkan.')).toBeInTheDocument()
    expect(within(cards[0]).queryByText(/lebih dari 14 hari/)).not.toBeInTheDocument()
  })

  it('says when there is nothing to recommend', async () => {
    home({ 'GET /recommendations': { status: 200, body: batch({ items: [], running_full: false }) } }, { running: [] })
    renderApp('/')
    const list = await screen.findByRole('region', { name: 'Rekomendasi berikutnya' })

    expect(await within(list).findByText('Belum ada task Ready yang bisa direkomendasikan.')).toBeInTheDocument()
  })

  it('does not fetch a new batch when the window regains focus', async () => {
    const fetchMock = home()
    renderApp('/')
    await within(await screen.findByRole('region', { name: 'Rekomendasi berikutnya' })).findAllByRole('article')
    const batches = count(fetchMock, 'GET', '/recommendations')

    window.dispatchEvent(new Event('focus'))
    document.dispatchEvent(new Event('visibilitychange'))
    await new Promise((resolve) => setTimeout(resolve, 20))

    expect(count(fetchMock, 'GET', '/recommendations')).toBe(batches)
  })
})

describe('long-waiting tasks (stale_tasks)', () => {
  function withStale(routes: Record<string, MockReply> = {}, lists: Lists = { running: [] }, runningFull = false) {
    return home(
      { 'GET /recommendations': { status: 200, body: batch({ running_full: runningFull, stale_tasks: [staleTask] }) }, ...routes },
      lists,
    )
  }

  it('lists them with how long they have waited', async () => {
    withStale()
    renderApp('/')
    const section = await screen.findByRole('region', { name: 'Sudah lama menunggu' })

    expect(await within(section).findByText('Rapikan arsip RPS')).toBeInTheDocument()
    expect(within(section).getByText('TCB-20')).toBeInTheDocument()
    expect(within(section).getByText('24 hari di Ready')).toBeInTheDocument()
    for (const name of ['Kerjakan', 'Tunda', 'Batalkan']) {
      expect(within(section).getByRole('button', { name })).toBeInTheDocument()
    }
  })

  it('is hidden when there are none', async () => {
    home()
    renderApp('/')
    await within(await screen.findByRole('region', { name: 'Rekomendasi berikutnya' })).findAllByRole('article')
    expect(screen.queryByRole('region', { name: 'Sudah lama menunggu' })).not.toBeInTheDocument()
  })

  it('Kerjakan starts the task without recording a recommendation', async () => {
    const fetchMock = withStale({ 'POST /tasks/20/transitions': { status: 200, body: detailOf({ ...staleTask, state: 'running' }) } })
    renderApp('/')
    const section = await screen.findByRole('region', { name: 'Sudah lama menunggu' })
    await userEvent.click(await within(section).findByRole('button', { name: 'Kerjakan' }))

    await waitFor(async () => expect(await sent(fetchMock, 'POST', '/tasks/20/transitions')).toEqual([{ to: 'running' }]))
    expect(requestsOf(fetchMock).some((r) => r.url.includes('/decision'))).toBe(false)
  })

  it('Kerjakan opens the switch form when Running is full', async () => {
    const fetchMock = withStale(
      { 'POST /switch': { status: 200, body: { left: running, started: detailOf({ ...staleTask, state: 'running' }) } } },
      { running: [running] },
      true,
    )
    renderApp('/')
    const section = await screen.findByRole('region', { name: 'Sudah lama menunggu' })
    await userEvent.click(await within(section).findByRole('button', { name: 'Kerjakan' }))
    const dialog = await screen.findByRole('dialog', { name: 'Simpan konteks sebelum pindah' })
    await waitFor(() => expect(within(dialog).getByRole('textbox', { name: 'Sampai mana?' })).toHaveValue('22 dari 40 lembar selesai.'))
    await userEvent.click(within(dialog).getByRole('button', { name: 'Simpan & pindah' }))

    await waitFor(async () => expect(await sent(fetchMock, 'POST', '/switch')).toHaveLength(1))
    const [body] = (await sent(fetchMock, 'POST', '/switch')) as Array<Record<string, unknown>>
    expect(body).toMatchObject({ from_task_id: '31', to_task_id: '20', recommendation_id: null })
  })

  it('Tunda moves the task to Suspended', async () => {
    const fetchMock = withStale({ 'POST /tasks/20/transitions': { status: 200, body: detailOf({ ...staleTask, state: 'suspended' }) } })
    renderApp('/')
    const section = await screen.findByRole('region', { name: 'Sudah lama menunggu' })
    await userEvent.click(await within(section).findByRole('button', { name: 'Tunda' }))

    await waitFor(async () => expect(await sent(fetchMock, 'POST', '/tasks/20/transitions')).toEqual([{ to: 'suspended' }]))
  })

  it('Batalkan asks for a reason before cancelling', async () => {
    const fetchMock = withStale({ 'POST /tasks/20/transitions': { status: 200, body: detailOf({ ...staleTask, state: 'cancelled' }) } })
    renderApp('/')
    const section = await screen.findByRole('region', { name: 'Sudah lama menunggu' })
    await userEvent.click(await within(section).findByRole('button', { name: 'Batalkan' }))

    const reason = within(section).getByRole('textbox', { name: 'Alasan batal TCB-20' })
    await userEvent.click(within(section).getByRole('button', { name: 'Batalkan task' }))
    expect(await within(section).findByText('Isi alasan batal.')).toBeInTheDocument()
    expect(count(fetchMock, 'POST', '/tasks/20/transitions')).toBe(0)

    await userEvent.type(reason, 'Sudah tidak relevan')
    await userEvent.click(within(section).getByRole('button', { name: 'Batalkan task' }))

    await waitFor(async () =>
      expect(await sent(fetchMock, 'POST', '/tasks/20/transitions')).toEqual([{ to: 'cancelled', reason: 'Sudah tidak relevan' }]),
    )
  })
})

describe('waiting list', () => {
  const externalDep = { id: 9, task_id: 34, depends_on_task_id: null, depends_on_task_key: null, external_description: 'Nilai praktikum dari asisten', status: 'open', resolved_at: null }
  const taskDep = { id: 10, task_id: 33, depends_on_task_id: 35, depends_on_task_key: 'TCB-35', external_description: null, status: 'open', resolved_at: null }

  function withWaiting(routes: Record<string, MockReply> = {}) {
    return home(
      {
        'GET /tasks/34': { status: 200, body: detailOf(waitingExternal, [externalDep]) },
        'GET /tasks/33': { status: 200, body: detailOf(waitingOnTask, [taskDep]) },
        'GET /tasks/36': { status: 200, body: detailOf(waitingReasonOnly) },
        ...routes,
      },
      { running: [running], waiting: [waitingExternal, waitingOnTask, waitingReasonOnly] },
    )
  }

  it('lists waiting tasks with what they wait for', async () => {
    withWaiting()
    renderApp('/')
    const section = await screen.findByRole('region', { name: 'Menunggu (3)' })

    const external = await within(section).findByRole('listitem', { name: 'TCB-34 Finalisasi nilai UTS kelas B' })
    expect(await within(external).findByRole('button', { name: 'Tandai teratasi' })).toBeInTheDocument()
    expect(within(external).getByText('Menunggu: Nilai praktikum dari asisten')).toBeInTheDocument()

    const onTask = within(section).getByRole('listitem', { name: 'TCB-33 Kirim draf paper ke rekan penulis' })
    expect(await within(onTask).findByText('Menunggu task TCB-35 · pindah ke Ready otomatis saat selesai')).toBeInTheDocument()
    expect(within(onTask).queryByRole('button', { name: 'Tandai teratasi' })).not.toBeInTheDocument()

    const reasonOnly = within(section).getByRole('listitem', { name: 'TCB-36 Unggah nilai ke SIA' })
    expect(await within(reasonOnly).findByRole('button', { name: 'Tandai teratasi' })).toBeInTheDocument()
    expect(within(reasonOnly).getByText('Menunggu: SIA sedang perbaikan')).toBeInTheDocument()
  })

  it('says when nothing is waiting', async () => {
    home()
    renderApp('/')
    const section = await screen.findByRole('region', { name: 'Menunggu (0)' })
    expect(await within(section).findByText('Tidak ada task yang menunggu.')).toBeInTheDocument()
  })

  it('marks an external blocker resolved', async () => {
    const fetchMock = withWaiting({ 'PATCH /dependencies/9': { status: 200, body: { ...externalDep, status: 'resolved' } } })
    renderApp('/')
    const section = await screen.findByRole('region', { name: 'Menunggu (3)' })
    const external = await within(section).findByRole('listitem', { name: 'TCB-34 Finalisasi nilai UTS kelas B' })
    await userEvent.click(await within(external).findByRole('button', { name: 'Tandai teratasi' }))

    await waitFor(async () => expect(await sent(fetchMock, 'PATCH', '/dependencies/9')).toEqual([{ status: 'resolved' }]))
  })

  it('moves a task that waits on a reason only back to Ready', async () => {
    const fetchMock = withWaiting({ 'POST /tasks/36/transitions': { status: 200, body: detailOf({ ...waitingReasonOnly, state: 'ready' }) } })
    renderApp('/')
    const section = await screen.findByRole('region', { name: 'Menunggu (3)' })
    const reasonOnly = await within(section).findByRole('listitem', { name: 'TCB-36 Unggah nilai ke SIA' })
    await userEvent.click(await within(reasonOnly).findByRole('button', { name: 'Tandai teratasi' }))

    await waitFor(async () => expect(await sent(fetchMock, 'POST', '/tasks/36/transitions')).toEqual([{ to: 'ready' }]))
  })

  it('shows a readable error when resolving fails', async () => {
    withWaiting({
      'PATCH /dependencies/9': {
        status: 422,
        body: { message: 'Hanya hambatan eksternal.', errors: { status: ['Hanya hambatan eksternal yang bisa ditandai teratasi.'] } },
      },
    })
    renderApp('/')
    const section = await screen.findByRole('region', { name: 'Menunggu (3)' })
    const external = await within(section).findByRole('listitem', { name: 'TCB-34 Finalisasi nilai UTS kelas B' })
    await userEvent.click(await within(external).findByRole('button', { name: 'Tandai teratasi' }))

    expect(await within(external).findByText('Hanya hambatan eksternal yang bisa ditandai teratasi.')).toBeInTheDocument()
  })
})
