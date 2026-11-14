import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it } from 'vitest'
import { requestsOf } from '../test/api'
import { goalsApi, krExams, objectiveA, objectiveB, orphanTasks } from '../test/goals'
import { count, fixHomeClock, sent } from '../test/home'
import { renderApp } from '../test/render'

// R1-20: Kelola Objective dan Key Result (FR-01, FR-02). Layout from
// docs/mockup/tcb-v1/04-progres goal.png, management only (full progress
// view FR-19 is Release 3). Clock: Friday 13 Nov 2026, 09:42:10 Asia/Jakarta.

fixHomeClock()

async function open() {
  renderApp('/goals')
  return screen.findByRole('article', { name: objectiveA.title })
}

function objective(title: string) {
  return screen.getByRole('article', { name: title })
}

async function keyResult(title: string) {
  return within(objective(objectiveA.title)).findByRole('listitem', { name: new RegExp(title) })
}

function orphanPanel() {
  return screen.getByRole('region', { name: /Orphan task/ })
}

async function lastBody(fetchMock: ReturnType<typeof goalsApi>, method: string, path: string) {
  await waitFor(() => expect(count(fetchMock, method, path)).toBeGreaterThan(0))
  return (await sent(fetchMock, method, path)).at(-1)
}

describe('active Objectives', () => {
  it('lists the active Objectives with their progress and period', async () => {
    const fetchMock = goalsApi()
    const card = await open()

    const list = requestsOf(fetchMock).find((r) => new URL(r.url).pathname === '/api/v1/objectives')
    expect(new URL(list!.url).searchParams.get('status')).toBe('active')
    expect(within(card).getByRole('heading', { level: 2, name: objectiveA.title })).toBeInTheDocument()
    expect(within(card).getByText('Objective · 1 Sep 2026 – 22 Jan 2027')).toBeInTheDocument()
    expect(within(card).getByRole('progressbar', { name: 'Progres Objective' })).toHaveAttribute('aria-valuenow', '44')
    expect(within(card).getByText('44%')).toBeInTheDocument()
    expect(await within(card).findByText('Rata-rata berbobot dari 3 Key Result. Sisa periode: 10 minggu.')).toBeInTheDocument()

    const other = objective(objectiveB.title)
    expect(within(other).getByText('0%')).toBeInTheDocument()
    expect(within(other).getByText('Proposal dan laporan kemajuan')).toBeInTheDocument()
    expect(await within(other).findByText('Belum ada Key Result. Sisa periode: 6 minggu.')).toBeInTheDocument()
  })

  it('invites to add the first Objective when there is none', async () => {
    goalsApi({ 'GET /objectives': { status: 200, body: { data: [] } } })
    renderApp('/goals')

    expect(await screen.findByText('Belum ada Objective aktif. Tambahkan Objective untuk mulai menautkan task.')).toBeInTheDocument()
  })

  it('shows a readable error when the list cannot be loaded', async () => {
    goalsApi({ 'GET /objectives': { status: 500, body: { message: 'Server Error' } } })
    renderApp('/goals')

    expect(await screen.findByRole('alert')).toHaveTextContent(/Terjadi galat di server/)
  })
})

describe('add Objective', () => {
  it('needs only a title: the period is prefilled from the latest Objective', async () => {
    const fetchMock = goalsApi({ 'POST /objectives': { status: 201, body: { ...objectiveA, id: 3, title: 'Semester genap' } } })
    await open()

    await userEvent.click(screen.getByRole('button', { name: 'Tambah Objective' }))
    const form = screen.getByRole('form', { name: 'Objective baru' })
    expect(within(form).getByLabelText('Periode mulai')).toHaveValue('2026-09-01')
    expect(within(form).getByLabelText('Periode akhir')).toHaveValue('2027-01-22')
    await userEvent.type(within(form).getByLabelText('Judul'), 'Semester genap{Enter}')

    expect(await lastBody(fetchMock, 'POST', '/objectives')).toEqual({ title: 'Semester genap', period_start: '2026-09-01', period_end: '2027-01-22' })
    await waitFor(() => expect(screen.queryByRole('form', { name: 'Objective baru' })).not.toBeInTheDocument())
    await waitFor(() => expect(count(fetchMock, 'GET', '/objectives')).toBe(2))
  })

  it('prefills six months from today when there is no Objective yet', async () => {
    goalsApi({ 'GET /objectives': { status: 200, body: { data: [] } } })
    renderApp('/goals')

    await userEvent.click(await screen.findByRole('button', { name: 'Tambah Objective' }))
    const form = screen.getByRole('form', { name: 'Objective baru' })
    expect(within(form).getByLabelText('Periode mulai')).toHaveValue('2026-11-13')
    expect(within(form).getByLabelText('Periode akhir')).toHaveValue('2027-05-12')
  })

  it('sends the description when filled', async () => {
    const fetchMock = goalsApi({ 'POST /objectives': { status: 201, body: objectiveA } })
    await open()

    await userEvent.click(screen.getByRole('button', { name: 'Tambah Objective' }))
    const form = screen.getByRole('form', { name: 'Objective baru' })
    await userEvent.type(within(form).getByLabelText('Judul'), 'Semester genap')
    await userEvent.type(within(form).getByLabelText(/^Deskripsi/), 'Dua mata kuliah')
    await userEvent.click(within(form).getByRole('button', { name: 'Simpan' }))

    expect(await lastBody(fetchMock, 'POST', '/objectives')).toEqual({
      title: 'Semester genap',
      description: 'Dua mata kuliah',
      period_start: '2026-09-01',
      period_end: '2027-01-22',
    })
  })

  it('refuses an empty title without a request', async () => {
    const fetchMock = goalsApi()
    await open()

    await userEvent.click(screen.getByRole('button', { name: 'Tambah Objective' }))
    await userEvent.click(within(screen.getByRole('form', { name: 'Objective baru' })).getByRole('button', { name: 'Simpan' }))

    expect(screen.getByText('Judul wajib diisi.')).toBeInTheDocument()
    expect(count(fetchMock, 'POST', '/objectives')).toBe(0)
  })

  it('keeps the form and its input when the API refuses', async () => {
    const message = 'Periode akhir tidak boleh sebelum periode mulai.'
    goalsApi({ 'POST /objectives': { status: 422, body: { message, errors: { period_end: [message] } } } })
    await open()

    await userEvent.click(screen.getByRole('button', { name: 'Tambah Objective' }))
    const form = screen.getByRole('form', { name: 'Objective baru' })
    await userEvent.type(within(form).getByLabelText('Judul'), 'Semester genap{Enter}')

    expect(await within(form).findByRole('alert')).toHaveTextContent(message)
    expect(within(form).getByLabelText('Judul')).toHaveValue('Semester genap')
  })

  it('closes with Batal without saving', async () => {
    const fetchMock = goalsApi()
    await open()

    await userEvent.click(screen.getByRole('button', { name: 'Tambah Objective' }))
    await userEvent.click(within(screen.getByRole('form', { name: 'Objective baru' })).getByRole('button', { name: 'Batal' }))

    expect(screen.queryByRole('form', { name: 'Objective baru' })).not.toBeInTheDocument()
    expect(count(fetchMock, 'POST', '/objectives')).toBe(0)
  })
})

describe('edit and archive Objective', () => {
  it('edits the title, description, and period', async () => {
    const fetchMock = goalsApi({ 'PATCH /objectives/1': { status: 200, body: { ...objectiveA, title: 'Kuliah lancar' } } })
    const card = await open()

    await userEvent.click(within(card).getByRole('button', { name: 'Ubah Objective' }))
    const form = within(card).getByRole('form', { name: 'Ubah Objective' })
    expect(within(form).getByLabelText('Judul')).toHaveValue(objectiveA.title)
    await userEvent.clear(within(form).getByLabelText('Judul'))
    await userEvent.type(within(form).getByLabelText('Judul'), 'Kuliah lancar')
    await userEvent.clear(within(form).getByLabelText('Periode akhir'))
    await userEvent.type(within(form).getByLabelText('Periode akhir'), '2027-01-31')
    await userEvent.click(within(form).getByRole('button', { name: 'Simpan' }))

    expect(await lastBody(fetchMock, 'PATCH', '/objectives/1')).toEqual({
      title: 'Kuliah lancar',
      description: null,
      period_start: '2026-09-01',
      period_end: '2027-01-31',
    })
    await waitFor(() => expect(within(card).queryByRole('form', { name: 'Ubah Objective' })).not.toBeInTheDocument())
  })

  it('closes the editor with Esc without saving', async () => {
    const fetchMock = goalsApi()
    const card = await open()

    await userEvent.click(within(card).getByRole('button', { name: 'Ubah Objective' }))
    await userEvent.type(within(card).getByLabelText('Judul'), ' X{Escape}')

    expect(within(card).queryByRole('form', { name: 'Ubah Objective' })).not.toBeInTheDocument()
    expect(count(fetchMock, 'PATCH', '/objectives/1')).toBe(0)
  })

  it('archives after a confirmation', async () => {
    const fetchMock = goalsApi({ 'PATCH /objectives/1': { status: 200, body: { ...objectiveA, status: 'archived' } } })
    const card = await open()

    await userEvent.click(within(card).getByRole('button', { name: 'Arsipkan' }))
    expect(within(card).getByText('Arsipkan Objective ini? Objective tidak tampil lagi di daftar aktif; Key Result dan task-nya tetap ada.')).toBeInTheDocument()
    await userEvent.click(within(card).getByRole('button', { name: 'Ya, arsipkan' }))

    expect(await lastBody(fetchMock, 'PATCH', '/objectives/1')).toEqual({ status: 'archived' })
    await waitFor(() => expect(count(fetchMock, 'GET', '/objectives')).toBe(2))
  })

  it('does not archive when the confirmation is cancelled', async () => {
    const fetchMock = goalsApi()
    const card = await open()

    await userEvent.click(within(card).getByRole('button', { name: 'Arsipkan' }))
    await userEvent.click(within(card).getByRole('button', { name: 'Batal' }))

    expect(within(card).queryByRole('button', { name: 'Ya, arsipkan' })).not.toBeInTheDocument()
    expect(count(fetchMock, 'PATCH', '/objectives/1')).toBe(0)
  })
})

describe('Key Results', () => {
  it('shows weight, progress mode, progress, and task counts or manual values', async () => {
    goalsApi()
    await open()

    const exams = await keyResult(krExams.title)
    expect(within(exams).getByRole('heading', { level: 3, name: `KR1 · ${krExams.title}` })).toBeInTheDocument()
    expect(within(exams).getByText('Bobot 0,4 · otomatis dari task')).toBeInTheDocument()
    expect(within(exams).getByRole('progressbar', { name: 'Progres Key Result' })).toHaveAttribute('aria-valuenow', '50')
    expect(within(exams).getByText('50%')).toBeInTheDocument()
    expect(within(exams).getByText('3 Done, 1 Running, 3 lainnya')).toBeInTheDocument()

    // Cancelled tasks do not count for progress, so they are left out.
    expect(within(await keyResult('Draf paper')).getByText('2 Done, 2 lainnya')).toBeInTheDocument()

    const reading = await keyResult('Artikel dibaca')
    expect(within(reading).getByRole('heading', { level: 3, name: /^KR3 · / })).toBeInTheDocument()
    expect(within(reading).getByText('Bobot 0,2 · manual')).toBeInTheDocument()
    expect(within(reading).getByText('24 dari 40 artikel')).toBeInTheDocument()
  })

  it('adds a Key Result with only a title: weight 1 and automatic progress', async () => {
    const fetchMock = goalsApi({ 'POST /objectives/1/key-results': { status: 201, body: { ...krExams, id: 10, title: 'Slide siap H-1' } } })
    const card = await open()

    await userEvent.click(within(card).getByRole('button', { name: 'Tambah Key Result' }))
    const form = within(card).getByRole('form', { name: 'Key Result baru' })
    expect(within(form).getByLabelText('Bobot')).toHaveValue('1')
    expect(within(form).getByRole('radio', { name: 'Otomatis dari task' })).toBeChecked()
    expect(within(form).queryByLabelText('Target')).not.toBeInTheDocument()
    await userEvent.type(within(form).getByLabelText('Judul'), 'Slide siap H-1{Enter}')

    expect(await lastBody(fetchMock, 'POST', '/objectives/1/key-results')).toEqual({ title: 'Slide siap H-1', weight: 1, progress_mode: 'auto' })
    await waitFor(() => expect(within(card).queryByRole('form', { name: 'Key Result baru' })).not.toBeInTheDocument())
    await waitFor(() => expect(count(fetchMock, 'GET', '/objectives/1')).toBe(2))
  })

  it('adds a manual Key Result: weight with a decimal comma, target required', async () => {
    const fetchMock = goalsApi({ 'POST /objectives/1/key-results': { status: 201, body: krExams } })
    const card = await open()

    await userEvent.click(within(card).getByRole('button', { name: 'Tambah Key Result' }))
    const form = within(card).getByRole('form', { name: 'Key Result baru' })
    await userEvent.type(within(form).getByLabelText('Judul'), 'Bab disertasi selesai')
    await userEvent.clear(within(form).getByLabelText('Bobot'))
    await userEvent.type(within(form).getByLabelText('Bobot'), '0,3')
    await userEvent.click(within(form).getByRole('radio', { name: 'Manual' }))
    await userEvent.click(within(form).getByRole('button', { name: 'Simpan' }))

    expect(within(form).getByText('Target wajib diisi bila mode progres manual.')).toBeInTheDocument()
    expect(count(fetchMock, 'POST', '/objectives/1/key-results')).toBe(0)

    await userEvent.type(within(form).getByLabelText('Target'), '5')
    await userEvent.type(within(form).getByLabelText('Nilai saat ini'), '1,5')
    await userEvent.type(within(form).getByLabelText('Satuan'), 'bab')
    await userEvent.click(within(form).getByRole('button', { name: 'Simpan' }))

    expect(await lastBody(fetchMock, 'POST', '/objectives/1/key-results')).toEqual({
      title: 'Bab disertasi selesai',
      weight: 0.3,
      progress_mode: 'manual',
      start_value: null,
      target_value: 5,
      current_value: 1.5,
      unit: 'bab',
    })
  })

  it('refuses a weight outside (0, 1] and a non-number without a request', async () => {
    const fetchMock = goalsApi()
    const card = await open()

    await userEvent.click(within(card).getByRole('button', { name: 'Tambah Key Result' }))
    const form = within(card).getByRole('form', { name: 'Key Result baru' })
    await userEvent.type(within(form).getByLabelText('Judul'), 'KR baru')
    await userEvent.clear(within(form).getByLabelText('Bobot'))
    await userEvent.type(within(form).getByLabelText('Bobot'), '1,5{Enter}')
    expect(within(form).getByText('Bobot harus angka lebih dari 0 dan paling besar 1.')).toBeInTheDocument()

    await userEvent.clear(within(form).getByLabelText('Bobot'))
    await userEvent.click(within(form).getByRole('radio', { name: 'Manual' }))
    await userEvent.type(within(form).getByLabelText('Bobot'), '0,5')
    await userEvent.type(within(form).getByLabelText('Target'), 'empat puluh{Enter}')
    expect(within(form).getByText('Target harus berupa angka.')).toBeInTheDocument()

    expect(count(fetchMock, 'POST', '/objectives/1/key-results')).toBe(0)
  })

  it('edits weight and progress mode of a Key Result', async () => {
    const fetchMock = goalsApi({ 'PATCH /key-results/7': { status: 200, body: { ...krExams, weight: 0.5 } } })
    await open()

    const exams = await keyResult(krExams.title)
    await userEvent.click(within(exams).getByRole('button', { name: 'Ubah Key Result' }))
    const form = within(exams).getByRole('form', { name: 'Ubah Key Result' })
    expect(within(form).getByLabelText('Judul')).toHaveValue(krExams.title)
    expect(within(form).getByLabelText('Bobot')).toHaveValue('0,4')
    await userEvent.clear(within(form).getByLabelText('Bobot'))
    await userEvent.type(within(form).getByLabelText('Bobot'), '0,5{Enter}')

    expect(await lastBody(fetchMock, 'PATCH', '/key-results/7')).toEqual({ title: krExams.title, weight: 0.5, progress_mode: 'auto' })
    await waitFor(() => expect(within(exams).queryByRole('form', { name: 'Ubah Key Result' })).not.toBeInTheDocument())
  })

  it('updates the current value of a manual Key Result', async () => {
    const fetchMock = goalsApi({ 'PATCH /key-results/9': { status: 200, body: {} } })
    await open()

    const reading = await keyResult('Artikel dibaca')
    await userEvent.click(within(reading).getByRole('button', { name: 'Ubah Key Result' }))
    const form = within(reading).getByRole('form', { name: 'Ubah Key Result' })
    expect(within(form).getByRole('radio', { name: 'Manual' })).toBeChecked()
    expect(within(form).getByLabelText('Target')).toHaveValue('40')
    await userEvent.clear(within(form).getByLabelText('Nilai saat ini'))
    await userEvent.type(within(form).getByLabelText('Nilai saat ini'), '30{Enter}')

    expect(await lastBody(fetchMock, 'PATCH', '/key-results/9')).toEqual({
      title: 'Artikel dibaca untuk tinjauan pustaka',
      weight: 0.2,
      progress_mode: 'manual',
      start_value: 0,
      target_value: 40,
      current_value: 30,
      unit: 'artikel',
    })
  })

  it('keeps the Key Result form and its input when the API refuses', async () => {
    const message = 'Bobot paling besar 1.'
    goalsApi({ 'PATCH /key-results/7': { status: 422, body: { message, errors: { weight: [message] } } } })
    await open()

    const exams = await keyResult(krExams.title)
    await userEvent.click(within(exams).getByRole('button', { name: 'Ubah Key Result' }))
    const form = within(exams).getByRole('form', { name: 'Ubah Key Result' })
    await userEvent.type(within(form).getByLabelText('Judul'), ' (semua kelas){Enter}')

    expect(await within(form).findByRole('alert')).toHaveTextContent(message)
    expect(within(form).getByLabelText('Judul')).toHaveValue(`${krExams.title} (semua kelas)`)
  })

  it('deletes a Key Result after a confirmation that names the tasks becoming orphans', async () => {
    const fetchMock = goalsApi({ 'DELETE /key-results/7': { status: 204 } })
    await open()

    const exams = await keyResult(krExams.title)
    await userEvent.click(within(exams).getByRole('button', { name: 'Hapus Key Result' }))
    expect(within(exams).getByText('Hapus Key Result ini? 7 task terkait menjadi orphan, tidak ikut terhapus.')).toBeInTheDocument()
    await userEvent.click(within(exams).getByRole('button', { name: 'Ya, hapus' }))

    await waitFor(() => expect(count(fetchMock, 'DELETE', '/key-results/7')).toBe(1))
    // The tasks became orphans: the orphan list and the Objective are read again.
    await waitFor(() => expect(count(fetchMock, 'GET', '/objectives/1')).toBe(2))
    await waitFor(() => expect(count(fetchMock, 'GET', '/tasks')).toBeGreaterThan(2))
  })

  it('does not delete when the confirmation is cancelled', async () => {
    const fetchMock = goalsApi()
    await open()

    const reading = await keyResult('Artikel dibaca')
    await userEvent.click(within(reading).getByRole('button', { name: 'Hapus Key Result' }))
    expect(within(reading).getByText('Hapus Key Result ini?')).toBeInTheDocument()
    await userEvent.click(within(reading).getByRole('button', { name: 'Batal' }))

    expect(count(fetchMock, 'DELETE', '/key-results/9')).toBe(0)
  })
})

describe('orphan tasks', () => {
  it('lists open orphan tasks with a link to their detail', async () => {
    const fetchMock = goalsApi()
    await open()

    expect(await within(orphanPanel()).findByRole('link', { name: 'Ide paper lanjutan: studi replikasi' })).toHaveAttribute('href', '/tasks/TCB-41')
    expect(within(orphanPanel()).getByRole('heading', { name: 'Orphan task (2)' })).toBeInTheDocument()
    expect(within(orphanPanel()).getByText('TCB-42')).toBeInTheDocument()

    const orphanRequest = requestsOf(fetchMock)
      .map((r) => new URL(r.url).searchParams)
      .find((q) => q.get('orphan') === 'true')
    expect(orphanRequest?.get('state')).toBe('new,ready,running,waiting,suspended')
  })

  it('links an orphan task to a Key Result picked from the active Objectives', async () => {
    const fetchMock = goalsApi({ 'PATCH /tasks/41': { status: 200, body: {} } })
    await open()

    const row = await within(orphanPanel()).findByRole('listitem', { name: /TCB-41/ })
    const select = within(row).getByRole('combobox', { name: 'Key Result untuk TCB-41' })
    await waitFor(() => expect(within(select).getByRole('option', { name: krExams.title })).toBeInTheDocument())
    expect(within(select).getByRole('group', { name: objectiveA.title })).toBeInTheDocument()
    await userEvent.selectOptions(select, 'Draf paper lengkap dikirim ke jurnal')
    await userEvent.click(within(row).getByRole('button', { name: 'Tautkan' }))

    expect(await lastBody(fetchMock, 'PATCH', '/tasks/41')).toEqual({ key_result_id: 8 })
    // Progress of the Key Result changes, and the task leaves the orphan list.
    await waitFor(() => expect(count(fetchMock, 'GET', '/objectives/1')).toBe(2))
  })

  it('asks to pick a Key Result first, without a request', async () => {
    const fetchMock = goalsApi()
    await open()

    const row = await within(orphanPanel()).findByRole('listitem', { name: /TCB-42/ })
    await userEvent.click(within(row).getByRole('button', { name: 'Tautkan' }))

    expect(within(row).getByText('Pilih Key Result dulu.')).toBeInTheDocument()
    expect(count(fetchMock, 'PATCH', '/tasks/42')).toBe(0)
  })

  it('shows the API error on the row', async () => {
    goalsApi({ 'PATCH /tasks/41': { status: 422, body: { message: 'Key Result tidak ditemukan.', errors: { key_result_id: ['Key Result tidak ditemukan.'] } } } })
    await open()

    const row = await within(orphanPanel()).findByRole('listitem', { name: /TCB-41/ })
    const select = within(row).getByRole('combobox', { name: 'Key Result untuk TCB-41' })
    await waitFor(() => expect(within(select).getByRole('option', { name: krExams.title })).toBeInTheDocument())
    await userEvent.selectOptions(select, krExams.title)
    await userEvent.click(within(row).getByRole('button', { name: 'Tautkan' }))

    expect(await within(row).findByRole('alert')).toHaveTextContent('Key Result tidak ditemukan.')
  })

  it('says so when every open task has a Key Result', async () => {
    goalsApi({ 'GET /tasks': orphanTasks([]) })
    await open()

    expect(await within(orphanPanel()).findByText('Semua task yang belum selesai sudah terhubung ke Key Result.')).toBeInTheDocument()
    expect(within(orphanPanel()).getByRole('heading', { name: 'Orphan task (0)' })).toBeInTheDocument()
  })

  it('asks to add a Key Result first when there is none to link to', async () => {
    goalsApi({ 'GET /objectives': { status: 200, body: { data: [] } } })
    renderApp('/goals')

    const row = await screen.findByRole('listitem', { name: /TCB-41/ })
    expect(within(row).queryByRole('button', { name: 'Tautkan' })).not.toBeInTheDocument()
    expect(within(orphanPanel()).getByText('Tambahkan Key Result dulu agar task bisa ditautkan.')).toBeInTheDocument()
  })
})
