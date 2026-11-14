import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it } from 'vitest'
import { json, task } from '../test/api'
import { contexts, detail, detailApi, draft } from '../test/detail'
import { count, fixHomeClock, sent } from '../test/home'
import { renderApp } from '../test/render'

// R1-19: Detail task (FR-08, FR-09, FR-11, FR-23). Layout from
// docs/mockup/tcb-v1/05-detail task.png. Clock: Friday 13 Nov 2026, 09:42:10
// Asia/Jakarta (fixHomeClock). Valid actions come from
// TaskDetail.allowed_transitions (decision 14 Nov 2026).

fixHomeClock()

async function open(path = '/tasks/TCB-31') {
  renderApp(path)
  return screen.findByRole('heading', { level: 1, name: 'Koreksi UTS kelas A' })
}

function actions() {
  return screen.getByRole('group', { name: 'Aksi' })
}

function dataPanel() {
  return screen.getByRole('region', { name: 'Data TCB' })
}

/** The <dd> of a row in the Data TCB panel. */
function row(label: string) {
  const term = within(dataPanel()).getByText(label, { selector: 'dt' })
  return term.nextElementSibling as HTMLElement
}

async function edit(label: string) {
  await userEvent.click(within(dataPanel()).getByRole('button', { name: `Ubah ${label}` }))
  return within(dataPanel()).getByRole('form', { name: label })
}

async function lastBody(fetchMock: ReturnType<typeof detailApi>, method: string, path: string) {
  await waitFor(() => expect(count(fetchMock, method, path)).toBeGreaterThan(0))
  return (await sent(fetchMock, method, path)).at(-1)
}

describe('header', () => {
  it('shows the task key, title, and state, loaded by task key', async () => {
    const fetchMock = detailApi()
    await open()

    expect(within(screen.getByRole('navigation', { name: 'Lokasi' })).getByRole('link', { name: 'Papan status' })).toHaveAttribute('href', '/board')
    expect(screen.getByRole('combobox', { name: 'Status' })).toHaveDisplayValue('Running')
    expect(count(fetchMock, 'GET', '/tasks/TCB-31')).toBe(1)
  })

  it('shows "Task tidak ditemukan." for an unknown task', async () => {
    detailApi({ 'GET /tasks/TCB-99': { status: 404, body: { code: 'not_found', message: 'Task tidak ditemukan.' } } })
    renderApp('/tasks/TCB-99')

    expect(await screen.findByRole('alert')).toHaveTextContent('Task tidak ditemukan.')
  })

  it('asks to pick a task when no task is given', async () => {
    detailApi()
    renderApp('/tasks')

    expect(await screen.findByText('Pilih task dari Beranda atau Papan status untuk melihat detailnya.')).toBeInTheDocument()
  })
})

describe('title in place', () => {
  it('saves a new title with Enter', async () => {
    const fetchMock = detailApi({ 'PATCH /tasks/31': { status: 200, body: { ...detail, title: 'Koreksi UTS kelas A dan B' } } })
    await open()

    await userEvent.click(screen.getByRole('button', { name: 'Ubah judul' }))
    const input = screen.getByRole('textbox', { name: 'Judul' })
    expect(input).toHaveValue('Koreksi UTS kelas A')
    await userEvent.clear(input)
    await userEvent.type(input, 'Koreksi UTS kelas A dan B{Enter}')

    expect(await lastBody(fetchMock, 'PATCH', '/tasks/31')).toEqual({ title: 'Koreksi UTS kelas A dan B' })
    await waitFor(() => expect(screen.queryByRole('textbox', { name: 'Judul' })).not.toBeInTheDocument())
  })

  it('closes with Esc without saving', async () => {
    const fetchMock = detailApi()
    await open()

    await userEvent.click(screen.getByRole('button', { name: 'Ubah judul' }))
    await userEvent.type(screen.getByRole('textbox', { name: 'Judul' }), ' lagi{Escape}')

    expect(screen.queryByRole('textbox', { name: 'Judul' })).not.toBeInTheDocument()
    expect(screen.getByRole('heading', { level: 1, name: 'Koreksi UTS kelas A' })).toBeInTheDocument()
    expect(count(fetchMock, 'PATCH', '/tasks/31')).toBe(0)
  })

  it('refuses an empty title without a request', async () => {
    const fetchMock = detailApi()
    await open()

    await userEvent.click(screen.getByRole('button', { name: 'Ubah judul' }))
    await userEvent.clear(screen.getByRole('textbox', { name: 'Judul' }))
    await userEvent.keyboard('{Enter}')

    expect(screen.getByText('Judul wajib diisi.')).toBeInTheDocument()
    expect(count(fetchMock, 'PATCH', '/tasks/31')).toBe(0)
  })

  it('keeps the editor and the text when the API refuses', async () => {
    detailApi({ 'PATCH /tasks/31': { status: 422, body: { message: 'Judul terlalu panjang.', errors: { title: ['Judul terlalu panjang.'] } } } })
    await open()

    await userEvent.click(screen.getByRole('button', { name: 'Ubah judul' }))
    await userEvent.type(screen.getByRole('textbox', { name: 'Judul' }), ' X{Enter}')

    expect(await screen.findByRole('alert')).toHaveTextContent('Judul terlalu panjang.')
    expect(screen.getByRole('textbox', { name: 'Judul' })).toHaveValue('Koreksi UTS kelas A X')
  })
})

describe('status and actions', () => {
  it('shows the actions of the current state from allowed_transitions', async () => {
    detailApi()
    await open()

    const names = within(actions()).getAllByRole('button').map((b) => b.textContent)
    expect(names).toEqual(['Selesai', 'Jeda', 'Terblokir', 'Batalkan'])
    expect(within(actions()).getByRole('link', { name: 'Pindah task' })).toHaveAttribute('href', '/')
  })

  it('goes to the recommendations of the Beranda with Pindah task', async () => {
    detailApi({ 'GET /recommendations': { status: 200, body: { batch_id: 'b', generated_at: '2026-11-13T02:42:10Z', running_full: true, items: [], stale_tasks: [] } } })
    await open()

    await userEvent.click(within(actions()).getByRole('link', { name: 'Pindah task' }))

    const heading = await screen.findByRole('heading', { level: 1, name: 'Beranda' })
    expect(heading).toBeInTheDocument()
    await waitFor(() => expect(document.activeElement).toHaveAttribute('id', 'rekomendasi-berikutnya'))
  })

  it('shows other actions for a task in another state', async () => {
    detailApi({}, { ...detail, state: 'new', allowed_transitions: ['ready', 'cancelled'] })
    await open()

    expect(within(actions()).getAllByRole('button').map((b) => b.textContent)).toEqual(['Siap dikerjakan', 'Batalkan'])
    expect(within(actions()).queryByRole('link', { name: 'Pindah task' })).not.toBeInTheDocument()
  })

  it('offers only the current state and the valid targets in the status menu', async () => {
    detailApi()
    await open()

    const options = within(screen.getByRole('combobox', { name: 'Status' })).getAllByRole('option')
    expect(options.map((o) => o.textContent)).toEqual(['Running', 'Ready', 'Waiting', 'Done', 'Cancelled'])
  })

  it('changes the state from the status menu and refreshes the detail', async () => {
    const fetchMock = detailApi({ 'POST /tasks/31/transitions': { status: 200, body: { ...detail, state: 'done', allowed_transitions: ['ready'] } } })
    await open()
    const before = count(fetchMock, 'GET', '/tasks/TCB-31')

    await userEvent.selectOptions(screen.getByRole('combobox', { name: 'Status' }), 'Done')

    expect(await lastBody(fetchMock, 'POST', '/tasks/31/transitions')).toEqual({ to: 'done' })
    expect(await screen.findByText('TCB-31 dipindah ke Done.')).toBeInTheDocument()
    await waitFor(() => expect(count(fetchMock, 'GET', '/tasks/TCB-31')).toBeGreaterThan(before))
  })

  it('finishes the task with Selesai', async () => {
    const fetchMock = detailApi({ 'POST /tasks/31/transitions': { status: 200, body: { ...detail, state: 'done' } } })
    await open()

    await userEvent.click(within(actions()).getByRole('button', { name: 'Selesai' }))

    expect(await lastBody(fetchMock, 'POST', '/tasks/31/transitions')).toEqual({ to: 'done' })
  })

  it('opens the context form for Jeda when the API asks for a context', async () => {
    const fetchMock = detailApi({
      'POST /tasks/31/transitions': async (request) => {
        const body = await request.clone().json()
        return body.context
          ? json(200, { ...detail, state: 'ready' })
          : json(422, { code: 'context_required', message: 'Isi konteks sebelum keluar dari Running.', errors: { context: ['Isi konteks.'] } })
      },
    })
    await open()

    await userEvent.click(within(actions()).getByRole('button', { name: 'Jeda' }))
    const dialog = await screen.findByRole('dialog', { name: 'Jeda TCB-31' })
    await userEvent.click(await within(dialog).findByRole('button', { name: 'Simpan & jeda' }))

    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
    expect(await lastBody(fetchMock, 'POST', '/tasks/31/transitions')).toMatchObject({ to: 'ready', context: { last_note: draft.last_note } })
  })

  it('asks for a reason before cancelling', async () => {
    detailApi({
      'POST /tasks/31/transitions': { status: 422, body: { code: 'reason_required', message: 'Isi alasan.', errors: { reason: ['Isi alasan.'] } } },
    })
    await open()

    await userEvent.click(within(actions()).getByRole('button', { name: 'Batalkan' }))

    expect(await screen.findByRole('dialog', { name: 'Batalkan TCB-31' })).toBeInTheDocument()
  })

  it('opens the switch form when Running is full', async () => {
    const ready = { ...detail, state: 'ready', allowed_transitions: ['running', 'suspended', 'cancelled'] }
    const other = task({ id: 35, task_key: 'TCB-35', title: 'Revisi bagian Metode paper', state: 'running' })
    detailApi({
      'POST /tasks/31/transitions': { status: 409, body: { code: 'wip_full', message: 'Running sudah penuh.', wip_limit: 1, running: [other] } },
      'GET /tasks/35/contexts/draft': { status: 200, body: draft },
      'GET /tasks/35': { status: 200, body: { ...other, latest_context: null, subtasks: [], open_dependencies: [], allowed_transitions: [] } },
    }, ready)
    await open()

    await userEvent.click(within(actions()).getByRole('button', { name: 'Mulai' }))

    const dialog = await screen.findByRole('dialog', { name: 'Simpan konteks sebelum pindah' })
    expect(dialog).toHaveTextContent('TCB-35 dipindah dengan konteks di bawah, lalu TCB-31 mulai berjalan.')
  })

  it('shows the message of a refused transition', async () => {
    detailApi({ 'POST /tasks/31/transitions': { status: 409, body: { code: 'invalid_transition', message: 'Task berstatus Running tidak bisa pindah ke New.' } } })
    await open()

    await userEvent.click(within(actions()).getByRole('button', { name: 'Selesai' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('Task berstatus Running tidak bisa pindah ke New.')
  })
})

describe('context history', () => {
  it('shows the contexts as a timeline, newest first, with the matching transition', async () => {
    detailApi()
    await open()

    const list = await screen.findByRole('list', { name: 'Riwayat konteks' })
    const items = within(list).getAllByRole('listitem')
    expect(items).toHaveLength(3)
    expect(items[0]).toHaveTextContent('11 Nov, 16.40 · Running → Ready')
    expect(items[0]).toHaveTextContent('22 dari 40 lembar selesai.')
    expect(items[0]).toHaveTextContent('Berikutnya: Tetapkan skor parsial nomor 3.')
    expect(within(items[0]).getByRole('link', { name: 'Rubrik UTS' })).toHaveAttribute('href', 'https://drive.example.com/rubrik')
    expect(items[1]).toHaveTextContent('11 Nov, 10.15 · Running → Waiting')
    expect(items[1]).toHaveTextContent('Blocker: Konfirmasi kunci jawaban dengan dosen pengampu')
    // No transition at that moment: a note saved without a state change.
    expect(items[2]).toHaveTextContent('10 Nov, 15.30 · Catatan')
    expect(items[2]).toHaveTextContent('Lembar 1–8 selesai.')
  })

  it('says so when there is no context yet', async () => {
    detailApi({ 'GET /tasks/31/contexts': { status: 200, body: { data: [] } } })
    await open()

    expect(await screen.findByText('Belum ada konteks tersimpan.')).toBeInTheDocument()
  })
})

describe('transition log', () => {
  it('lists time, from → to, reason, and source, newest first', async () => {
    detailApi()
    await open()

    const table = await screen.findByRole('table', { name: 'Log transisi' })
    expect(within(table).getAllByRole('columnheader').map((h) => h.textContent)).toEqual(['Waktu', 'Dari → Ke', 'Alasan', 'Sumber'])
    await waitFor(() => expect(within(table).getAllByRole('row')).toHaveLength(7))
    const rows = within(table).getAllByRole('row').slice(1)
    const cells = (r: HTMLElement) => within(r).getAllByRole('cell').map((c) => c.textContent)
    expect(cells(rows[0])).toEqual(['12 Nov, 08.30', 'Ready → Running', 'Blok fokus kalender dimulai', 'Kalender'])
    expect(cells(rows[1])).toEqual(['11 Nov, 16.40', 'Running → Ready', '—', 'Pengguna'])
    expect(cells(rows[2])).toEqual(['11 Nov, 13.05', 'Waiting → Ready', 'Blocker teratasi', 'Sistem'])
    expect(cells(rows[5])).toEqual(['9 Nov, 08.00', 'Dibuat → New', '—', 'Pengguna'])
  })
})

describe('data panel', () => {
  it('shows the TCB data of the task', async () => {
    detailApi()
    await open()

    expect(row('Kunci')).toHaveTextContent('TCB-31')
    expect(row('Key Result')).toHaveTextContent('Nilai ujian diunggah sebelum batas')
    expect(row('Prioritas')).toHaveTextContent('3 · Tinggi')
    expect(row('Deadline')).toHaveTextContent('Jumat, 13 Nov 2026, 17.00')
    expect(row('Estimasi / terpakai')).toHaveTextContent('240 / 150 mnt')
    expect(row('Progres')).toHaveTextContent('22 / 40 lembar')
    expect(row('Pindah konteks')).toHaveTextContent('2 kali')
    expect(row('Jenis kerja')).toHaveTextContent('Fokus')
    expect(row('Label')).toHaveTextContent('Koreksi ujian')
    expect(row('Subtask')).toHaveTextContent('1 dari 2 Done')
    expect(within(row('Subtask')).getByRole('link', { name: /TCB-33/ })).toHaveAttribute('href', '/tasks/TCB-33')
    expect(within(row('Dokumen')).getByRole('link', { name: 'Rekap nilai kelas A' })).toHaveAttribute('href', 'https://drive.example.com/rekap')
    expect(await within(row('Dependensi')).findByText('Nilai praktikum dari asisten')).toBeInTheDocument()
  })

  it('shows empty fields as a dash', async () => {
    detailApi({}, {
      ...detail,
      key_result_id: null,
      key_result_title: null,
      is_orphan: true,
      deadline_at: null,
      estimate_minutes: null,
      progress_unit: null,
      label: null,
      latest_context: null,
      subtasks: [],
    })
    await open()

    expect(row('Key Result')).toHaveTextContent('— (orphan)')
    expect(row('Deadline')).toHaveTextContent('—')
    expect(row('Estimasi / terpakai')).toHaveTextContent('— / 150 mnt')
    expect(row('Progres')).toHaveTextContent('—')
    expect(row('Label')).toHaveTextContent('—')
    expect(row('Subtask')).toHaveTextContent('Belum ada subtask')
    expect(row('Dokumen')).toHaveTextContent('Belum ada dokumen')
  })

  it('keeps the context switch count read-only', async () => {
    detailApi()
    await open()

    expect(within(dataPanel()).queryByRole('button', { name: 'Ubah Pindah konteks' })).not.toBeInTheDocument()
  })

  it('changes the priority', async () => {
    const fetchMock = detailApi({ 'PATCH /tasks/31': { status: 200, body: { ...detail, priority: 4 } } })
    await open()

    const form = await edit('Prioritas')
    await userEvent.selectOptions(within(form).getByRole('combobox', { name: 'Prioritas' }), '4 · Kritis')
    await userEvent.click(within(form).getByRole('button', { name: 'Simpan' }))

    expect(await lastBody(fetchMock, 'PATCH', '/tasks/31')).toEqual({ priority: 4 })
    await waitFor(() => expect(within(dataPanel()).queryByRole('form', { name: 'Prioritas' })).not.toBeInTheDocument())
  })

  it('links the task to another Key Result of an active Objective', async () => {
    const fetchMock = detailApi({ 'PATCH /tasks/31': { status: 200, body: detail } })
    await open()

    const form = await edit('Key Result')
    const select = within(form).getByRole('combobox', { name: 'Key Result' })
    await within(select).findByRole('option', { name: 'Rubrik semua mata kuliah tersedia' })
    expect(within(select).getByRole('group', { name: 'Semester ganjil 2026/2027' })).toBeInTheDocument()
    await userEvent.selectOptions(select, 'Rubrik semua mata kuliah tersedia')
    await userEvent.click(within(form).getByRole('button', { name: 'Simpan' }))

    expect(await lastBody(fetchMock, 'PATCH', '/tasks/31')).toEqual({ key_result_id: 8 })
  })

  it('sends the deadline in UTC from the time typed in the user time zone', async () => {
    const fetchMock = detailApi({ 'PATCH /tasks/31': { status: 200, body: detail } })
    await open()

    const form = await edit('Deadline')
    const input = within(form).getByLabelText('Deadline')
    expect(input).toHaveValue('2026-11-13T17:00')
    await userEvent.clear(input)
    await userEvent.type(input, '2026-11-20T08:30')
    await userEvent.click(within(form).getByRole('button', { name: 'Simpan' }))

    expect(await lastBody(fetchMock, 'PATCH', '/tasks/31')).toEqual({ deadline_at: '2026-11-20T01:30:00.000Z' })
  })

  it('changes the unit progress', async () => {
    const fetchMock = detailApi({ 'PATCH /tasks/31': { status: 200, body: detail } })
    await open()

    const form = await edit('Progres')
    const done = within(form).getByRole('spinbutton', { name: 'Selesai' })
    await userEvent.clear(done)
    await userEvent.type(done, '25')
    await userEvent.click(within(form).getByRole('button', { name: 'Simpan' }))

    expect(await lastBody(fetchMock, 'PATCH', '/tasks/31')).toEqual({ unit_done: 25, unit_total: 40, unit_name: 'lembar' })
  })

  it.each([
    ['Key Result', { key_result_id: null }],
    ['Deadline', { deadline_at: null }],
    ['Estimasi / terpakai', { estimate_minutes: null }],
    ['Progres', { unit_total: null }],
    ['Label', { label: null }],
    // Not nullable in the schema: emptying restores the default (decision 14 Nov 2026).
    ['Prioritas', { priority: 2 }],
    ['Jenis kerja', { energy: 'focus' }],
  ])('empties %s with Kosongkan', async (label, body) => {
    const fetchMock = detailApi({ 'PATCH /tasks/31': { status: 200, body: detail } })
    await open()

    const form = await edit(label)
    await userEvent.click(within(form).getByRole('button', { name: 'Kosongkan' }))

    expect(await lastBody(fetchMock, 'PATCH', '/tasks/31')).toEqual(body)
  })

  it('treats a cleared estimate as empty', async () => {
    const fetchMock = detailApi({ 'PATCH /tasks/31': { status: 200, body: detail } })
    await open()

    const form = await edit('Estimasi / terpakai')
    await userEvent.clear(within(form).getByRole('spinbutton', { name: 'Estimasi (menit)' }))
    await userEvent.click(within(form).getByRole('button', { name: 'Simpan' }))

    expect(await lastBody(fetchMock, 'PATCH', '/tasks/31')).toEqual({ estimate_minutes: null })
  })

  it('keeps the editor open with the error of a refused change', async () => {
    detailApi({
      'PATCH /tasks/31': { status: 422, body: { message: 'Progres tidak boleh melebihi total.', errors: { unit_done: ['Progres tidak boleh melebihi total.'] } } },
    })
    await open()

    const form = await edit('Progres')
    const done = within(form).getByRole('spinbutton', { name: 'Selesai' })
    await userEvent.clear(done)
    await userEvent.type(done, '41')
    await userEvent.click(within(form).getByRole('button', { name: 'Simpan' }))

    expect(await within(form).findByRole('alert')).toHaveTextContent('Progres tidak boleh melebihi total.')
    expect(done).toHaveValue(41)
  })

  it('closes the editor with Batal without saving', async () => {
    const fetchMock = detailApi()
    await open()

    const form = await edit('Label')
    await userEvent.click(within(form).getByRole('button', { name: 'Batal' }))

    expect(within(dataPanel()).queryByRole('form', { name: 'Label' })).not.toBeInTheDocument()
    expect(count(fetchMock, 'PATCH', '/tasks/31')).toBe(0)
  })
})

describe('subtasks', () => {
  it('adds a subtask with only a title', async () => {
    const fetchMock = detailApi({ 'POST /tasks': { status: 201, body: task({ id: 50, task_key: 'TCB-50', title: 'Rekap nilai', state: 'new', parent_task_id: 31 }) } })
    await open()

    await userEvent.type(within(row('Subtask')).getByRole('textbox', { name: 'Subtask baru' }), 'Rekap nilai{Enter}')

    expect(await lastBody(fetchMock, 'POST', '/tasks')).toEqual({ title: 'Rekap nilai', parent_task_id: 31 })
  })

  it('detaches a subtask', async () => {
    const fetchMock = detailApi({ 'PATCH /tasks/33': { status: 200, body: { ...detail.subtasks[1], parent_task_id: null } } })
    await open()

    await userEvent.click(within(row('Subtask')).getByRole('button', { name: 'Lepas TCB-33' }))

    expect(await lastBody(fetchMock, 'PATCH', '/tasks/33')).toEqual({ parent_task_id: null })
  })
})

describe('dependencies', () => {
  it('lists open and resolved dependencies', async () => {
    detailApi()
    await open()

    const deps = row('Dependensi')
    expect(await within(deps).findByText('Nilai praktikum dari asisten')).toBeInTheDocument()
    expect(within(deps).getByRole('link', { name: 'TCB-30' })).toHaveAttribute('href', '/tasks/TCB-30')
    expect(within(deps).getByText('teratasi')).toBeInTheDocument()
  })

  it.each([
    ['TCB-36', { depends_on_task_id: 'TCB-36' }],
    ['Tanda tangan kaprodi', { external_description: 'Tanda tangan kaprodi' }],
  ])('adds "%s" as a dependency', async (text, body) => {
    const fetchMock = detailApi({ 'POST /tasks/31/dependencies': { status: 201, body: { id: 12, task_id: 31, status: 'open' } } })
    await open()

    await userEvent.type(within(row('Dependensi')).getByRole('textbox', { name: 'Dependensi baru' }), `${text}{Enter}`)

    expect(await lastBody(fetchMock, 'POST', '/tasks/31/dependencies')).toEqual(body)
  })

  it('marks an external blocker resolved and removes a dependency', async () => {
    const fetchMock = detailApi({
      'PATCH /dependencies/9': { status: 200, body: { id: 9, task_id: 31, status: 'resolved' } },
      'DELETE /dependencies/9': { status: 204 },
    })
    await open()

    const deps = row('Dependensi')
    await userEvent.click(await within(deps).findByRole('button', { name: 'Tandai teratasi: Nilai praktikum dari asisten' }))
    expect(await lastBody(fetchMock, 'PATCH', '/dependencies/9')).toEqual({ status: 'resolved' })

    await userEvent.click(within(deps).getByRole('button', { name: 'Hapus dependensi: Nilai praktikum dari asisten' }))
    await waitFor(() => expect(count(fetchMock, 'DELETE', '/dependencies/9')).toBe(1))
  })

  it('shows the error of a refused dependency', async () => {
    detailApi({
      'POST /tasks/31/dependencies': { status: 422, body: { message: 'Dependensi ini membuat siklus.', errors: { depends_on_task_id: ['Dependensi ini membuat siklus.'] } } },
    })
    await open()

    await userEvent.type(within(row('Dependensi')).getByRole('textbox', { name: 'Dependensi baru' }), 'TCB-36{Enter}')

    expect(await within(row('Dependensi')).findByRole('alert')).toHaveTextContent('Dependensi ini membuat siklus.')
    expect(within(row('Dependensi')).getByRole('textbox', { name: 'Dependensi baru' })).toHaveValue('TCB-36')
  })
})

describe('documents', () => {
  it('adds a document by saving a context with the draft and the new link', async () => {
    const fetchMock = detailApi({ 'POST /tasks/31/contexts': { status: 201, body: { ...contexts[0], id: 13 } } })
    await open()

    const docs = row('Dokumen')
    await userEvent.type(within(docs).getByRole('textbox', { name: 'Judul dokumen' }), 'Kunci jawaban')
    await userEvent.type(within(docs).getByRole('textbox', { name: 'URL dokumen' }), 'https://drive.example.com/kunci{Enter}')

    expect(await lastBody(fetchMock, 'POST', '/tasks/31/contexts')).toEqual({
      ...draft,
      links: [...draft.links, { title: 'Kunci jawaban', url: 'https://drive.example.com/kunci' }],
    })
  })

  it('removes a document', async () => {
    const fetchMock = detailApi({ 'POST /tasks/31/contexts': { status: 201, body: { ...contexts[0], id: 13 } } })
    await open()

    await userEvent.click(within(row('Dokumen')).getByRole('button', { name: 'Hapus dokumen: Rubrik UTS' }))

    expect(await lastBody(fetchMock, 'POST', '/tasks/31/contexts')).toEqual({ ...draft, links: [draft.links[1]] })
  })
})
