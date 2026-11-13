import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it } from 'vitest'
import { task, user, type MockReply } from '../test/api'
import { batch, count, detailOf, draft, fixHomeClock, home, running, runningDetail, sent } from '../test/home'
import { renderApp } from '../test/render'

// R1-17: dialog pindah konteks (FR-06, FR-07, FR-23).
// Opened from the first recommendation (TCB-35, #1, skor 0,78) while TCB-31
// runs with WIP limit 1. Clock: 13 Nov 2026 09:42:10 WIB, session since 09:00.

fixHomeClock()

const withUnits = { ...running, progress_unit: { done: 22, total: 40, name: 'lembar' } }
const withUnitsDetail = { ...runningDetail, progress_unit: { done: 22, total: 40, name: 'lembar' } }

const switched = {
  status: 200,
  body: { left: { ...running, state: 'ready' }, started: detailOf(task({ id: 35, task_key: 'TCB-35', state: 'running' })) },
}

/** Home with TCB-31 running (optionally with 22/40 lembar), opens the switch dialog for recommendation #1. */
async function openSwitch(routes: Record<string, MockReply> = {}, units = false) {
  const fetchMock = home(
    {
      ...(units
        ? {
            'GET /tasks/31': { status: 200, body: withUnitsDetail },
            'GET /tasks/31/contexts/draft': { status: 200, body: { ...draft, unit_done: 22 } },
          }
        : {}),
      'POST /switch': switched,
      ...routes,
    },
    { running: [units ? withUnits : running] },
  )
  renderApp('/')
  const list = await screen.findByRole('region', { name: 'Rekomendasi berikutnya' })
  const [first] = await within(list).findAllByRole('article')
  const trigger = within(first).getByRole('button', { name: 'Pindah ke task ini' })
  await userEvent.click(trigger)
  const dialog = await screen.findByRole('dialog', { name: 'Simpan konteks sebelum pindah' })
  const lastNote = await within(dialog).findByRole('textbox', { name: 'Sampai mana?' })
  await waitFor(() => expect(lastNote).toHaveValue('22 dari 40 lembar selesai.'))
  return { fetchMock, dialog, trigger }
}

async function switchBody(fetchMock: ReturnType<typeof home>): Promise<Record<string, unknown>> {
  await waitFor(() => expect(count(fetchMock, 'POST', '/switch')).toBe(1))
  const [body] = (await sent(fetchMock, 'POST', '/switch')) as Array<Record<string, unknown>>
  return body
}

describe('tasks left and started', () => {
  it('shows the task that is left and the task that starts', async () => {
    const { dialog } = await openSwitch()

    expect(within(dialog).getByText('Running sudah penuh (1/1). TCB-31 dipindah dengan konteks di bawah, lalu TCB-35 mulai berjalan.')).toBeInTheDocument()

    const left = within(dialog).getByRole('group', { name: 'Ditinggalkan' })
    expect(within(left).getByText('TCB-31')).toBeInTheDocument()
    expect(within(left).getByText('Koreksi UTS kelas A')).toBeInTheDocument()
    expect(await within(left).findByText('42 mnt sesi ini · pindah ke-3')).toBeInTheDocument()

    const started = within(dialog).getByRole('group', { name: 'Dimulai' })
    expect(within(started).getByText('TCB-35')).toBeInTheDocument()
    expect(within(started).getByText('Revisi bagian Metode paper')).toBeInTheDocument()
    expect(within(started).getByText('Rekomendasi #1 · skor 0,78')).toBeInTheDocument()
  })

  it('does not mention a recommendation when starting a long-waiting task', async () => {
    const stale = task({ id: 20, task_key: 'TCB-20', title: 'Rapikan arsip RPS', state: 'ready', ready_since: '2026-10-20T02:00:00Z' })
    home({ 'GET /recommendations': { status: 200, body: batch({ stale_tasks: [stale] }) } })
    renderApp('/')
    const section = await screen.findByRole('region', { name: 'Sudah lama menunggu' })
    await userEvent.click(await within(section).findByRole('button', { name: 'Kerjakan' }))

    const started = within(await screen.findByRole('dialog')).getByRole('group', { name: 'Dimulai' })
    expect(within(started).getByText('Rapikan arsip RPS')).toBeInTheDocument()
    expect(within(started).queryByText(/Rekomendasi #/)).not.toBeInTheDocument()
  })
})

describe('prefilled from the draft', () => {
  it('fills every field from /contexts/draft so only the changed part needs editing', async () => {
    const { fetchMock, dialog } = await openSwitch({
      'GET /tasks/31/contexts/draft': { status: 200, body: { ...draft, blocker: 'Kunci jawaban perlu dicek' } },
    })
    expect(within(dialog).getByRole('textbox', { name: 'Langkah berikutnya' })).toHaveValue('Tetapkan skor parsial soal nomor 3.')
    expect(within(dialog).getByRole('textbox', { name: 'Blocker (opsional)' })).toHaveValue('Kunci jawaban perlu dicek')
    expect(within(dialog).getByText('Diisi awal dari konteks terakhir; cukup koreksi bagian yang berubah.')).toBeInTheDocument()

    const nextStep = within(dialog).getByRole('textbox', { name: 'Langkah berikutnya' })
    await userEvent.clear(nextStep)
    await userEvent.type(nextStep, 'Lanjut dari lembar ke-23')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Simpan & pindah' }))

    expect((await switchBody(fetchMock)).context).toEqual({
      last_note: '22 dari 40 lembar selesai.',
      next_step: 'Lanjut dari lembar ke-23',
      blocker: 'Kunci jawaban perlu dicek',
      unit_done: null,
      links: [{ title: 'Rubrik UTS', url: 'https://drive.example.com/rubrik' }],
    })
  })
})

describe('unit progress row', () => {
  it('is hidden when the task has no unit total', async () => {
    const { dialog } = await openSwitch()
    expect(within(dialog).queryByRole('spinbutton', { name: 'Jumlah selesai' })).not.toBeInTheDocument()
    expect(within(dialog).queryByText(/^dari \d+/)).not.toBeInTheDocument()
  })

  it('shows − number + with "dari N satuan" and sends the corrected count', async () => {
    const { fetchMock, dialog } = await openSwitch({}, true)
    const amount = await within(dialog).findByRole('spinbutton', { name: 'Jumlah selesai' })
    expect(amount).toHaveValue(22)
    expect(within(dialog).getByText('dari 40 lembar')).toBeInTheDocument()

    await userEvent.click(within(dialog).getByRole('button', { name: 'Tambah satu' }))
    await userEvent.click(within(dialog).getByRole('button', { name: 'Tambah satu' }))
    await userEvent.click(within(dialog).getByRole('button', { name: 'Kurangi satu' }))
    expect(amount).toHaveValue(23)

    await userEvent.click(within(dialog).getByRole('button', { name: 'Simpan & pindah' }))
    expect((await switchBody(fetchMock)).context).toMatchObject({ unit_done: 23 })
  })

  it('stays between 0 and the total', async () => {
    const { dialog } = await openSwitch({ 'GET /tasks/31/contexts/draft': { status: 200, body: { ...draft, unit_done: 40 } } }, true)
    const amount = await within(dialog).findByRole('spinbutton', { name: 'Jumlah selesai' })
    expect(amount).toHaveValue(40)
    expect(within(dialog).getByRole('button', { name: 'Tambah satu' })).toBeDisabled()

    await userEvent.clear(amount)
    await userEvent.type(amount, '0')
    expect(within(dialog).getByRole('button', { name: 'Kurangi satu' })).toBeDisabled()
  })

  it('refuses a typed count above the total without sending', async () => {
    const { fetchMock, dialog } = await openSwitch({}, true)
    const amount = await within(dialog).findByRole('spinbutton', { name: 'Jumlah selesai' })
    await userEvent.clear(amount)
    await userEvent.type(amount, '45')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Simpan & pindah' }))

    expect(await within(dialog).findByText('Jumlah selesai harus antara 0 dan 40.')).toBeInTheDocument()
    expect(count(fetchMock, 'POST', '/switch')).toBe(0)
  })

  it('keeps the current count when the field is left empty', async () => {
    const { fetchMock, dialog } = await openSwitch({}, true)
    await userEvent.clear(await within(dialog).findByRole('spinbutton', { name: 'Jumlah selesai' }))
    await userEvent.click(within(dialog).getByRole('button', { name: 'Simpan & pindah' }))

    expect((await switchBody(fetchMock)).context).toMatchObject({ unit_done: 22 })
  })

  it('is part of the same form for Jeda', async () => {
    const fetchMock = home(
      {
        'GET /tasks/31': { status: 200, body: withUnitsDetail },
        'GET /tasks/31/contexts/draft': { status: 200, body: { ...draft, unit_done: 22 } },
        'POST /tasks/31/transitions': { status: 200, body: { ...withUnitsDetail, state: 'ready' } },
      },
      { running: [withUnits] },
    )
    renderApp('/')
    const panel = await screen.findByRole('region', { name: 'Sedang berjalan' })
    await userEvent.click(await within(panel).findByRole('button', { name: 'Jeda' }))
    const dialog = await screen.findByRole('dialog', { name: 'Jeda TCB-31' })
    await userEvent.click(await within(dialog).findByRole('button', { name: 'Tambah satu' }))
    await userEvent.click(within(dialog).getByRole('button', { name: 'Simpan & jeda' }))

    await waitFor(() => expect(count(fetchMock, 'POST', '/tasks/31/transitions')).toBe(1))
    const [body] = (await sent(fetchMock, 'POST', '/tasks/31/transitions')) as Array<{ context: Record<string, unknown> }>
    expect(body.context.unit_done).toBe(23)
  })
})

describe('destination of the task left', () => {
  it('defaults to Ready', async () => {
    const { fetchMock, dialog } = await openSwitch()
    const destination = within(dialog).getByRole('radiogroup', { name: 'Pindahkan TCB-31 ke' })
    expect(within(destination).getByRole('radio', { name: 'Ready' })).toBeChecked()
    expect(within(destination).getByRole('radio', { name: 'Waiting (isi blocker)' })).not.toBeChecked()
    expect(within(destination).queryByRole('radio', { name: /Suspended/ })).not.toBeInTheDocument()

    await userEvent.click(within(dialog).getByRole('button', { name: 'Simpan & pindah' }))
    const body = await switchBody(fetchMock)
    expect(body.from_to).toBe('ready')
    expect(body).not.toHaveProperty('reason')
  })

  it('requires the blocker as reason for Waiting', async () => {
    const { fetchMock, dialog } = await openSwitch()
    await userEvent.click(within(dialog).getByRole('radio', { name: 'Waiting (isi blocker)' }))
    await userEvent.click(within(dialog).getByRole('button', { name: 'Simpan & pindah' }))

    expect(await within(dialog).findByText('Isi blocker sebagai alasan menunggu.')).toBeInTheDocument()
    expect(count(fetchMock, 'POST', '/switch')).toBe(0)

    await userEvent.type(within(dialog).getByRole('textbox', { name: 'Blocker (wajib untuk Waiting)' }), 'Menunggu nilai dari asisten')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Simpan & pindah' }))

    const body = await switchBody(fetchMock)
    expect(body).toMatchObject({ from_to: 'waiting', reason: 'Menunggu nilai dari asisten' })
    expect(body.context).toMatchObject({ blocker: 'Menunggu nilai dari asisten' })
  })
})

describe('Simpan & pindah', () => {
  it('calls POST /switch and closes', async () => {
    const { fetchMock, dialog } = await openSwitch()
    await userEvent.click(within(dialog).getByRole('button', { name: 'Simpan & pindah' }))

    expect(await switchBody(fetchMock)).toMatchObject({ from_task_id: '31', to_task_id: '35', recommendation_id: 101 })
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
  })

  it('shows the error without closing or losing any input', async () => {
    const { dialog } = await openSwitch(
      { 'POST /switch': { status: 409, body: { code: 'conflict', message: 'TCB-31 sudah tidak berjalan. Muat ulang Beranda.' } } },
      true,
    )
    await userEvent.type(within(dialog).getByRole('textbox', { name: 'Sampai mana?' }), ' Sisa 18.')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Tambah satu' }))
    await userEvent.click(within(dialog).getByRole('radio', { name: 'Waiting (isi blocker)' }))
    await userEvent.type(within(dialog).getByRole('textbox', { name: 'Blocker (wajib untuk Waiting)' }), 'Asisten')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Simpan & pindah' }))

    expect(await within(dialog).findByText('TCB-31 sudah tidak berjalan. Muat ulang Beranda.')).toBeInTheDocument()
    expect(screen.getByRole('dialog')).toBe(dialog)
    expect(within(dialog).getByRole('textbox', { name: 'Sampai mana?' })).toHaveValue('22 dari 40 lembar selesai. Sisa 18.')
    expect(within(dialog).getByRole('spinbutton', { name: 'Jumlah selesai' })).toHaveValue(23)
    expect(within(dialog).getByRole('radio', { name: 'Waiting (isi blocker)' })).toBeChecked()
    expect(within(dialog).getByRole('textbox', { name: 'Blocker (wajib untuk Waiting)' })).toHaveValue('Asisten')
  })
})

describe('keyboard only', () => {
  it('starts in "Sampai mana?", and Tab then Enter on Simpan & pindah saves', async () => {
    const { fetchMock, dialog } = await openSwitch()
    expect(within(dialog).getByRole('textbox', { name: 'Sampai mana?' })).toHaveFocus()

    const save = within(dialog).getByRole('button', { name: 'Simpan & pindah' })
    for (let i = 0; i < 10 && document.activeElement !== save; i++) await userEvent.tab()
    expect(save).toHaveFocus()
    await userEvent.keyboard('{Enter}')

    await switchBody(fetchMock)
  })

  it('submits with Enter from a single-line field', async () => {
    const { fetchMock, dialog } = await openSwitch()
    await userEvent.click(within(dialog).getByRole('textbox', { name: 'Langkah berikutnya' }))
    await userEvent.keyboard('{Enter}')
    await switchBody(fetchMock)
  })

  it('chooses Waiting with the arrow keys', async () => {
    const { dialog } = await openSwitch()
    const ready = within(dialog).getByRole('radio', { name: 'Ready' })
    for (let i = 0; i < 10 && document.activeElement !== ready; i++) await userEvent.tab()
    expect(ready).toHaveFocus()
    await userEvent.keyboard('{ArrowRight}')
    expect(within(dialog).getByRole('radio', { name: 'Waiting (isi blocker)' })).toBeChecked()
  })

  it('keeps Tab inside the dialog', async () => {
    const { dialog } = await openSwitch()
    const first = within(dialog).getByRole('textbox', { name: 'Sampai mana?' })
    const save = within(dialog).getByRole('button', { name: 'Simpan & pindah' })

    await userEvent.tab({ shift: true })
    expect(save).toHaveFocus()
    await userEvent.tab()
    expect(first).toHaveFocus()
  })

  it('Esc closes without saving and returns focus to the button that opened it', async () => {
    const { fetchMock, trigger } = await openSwitch()
    await userEvent.keyboard('{Escape}')

    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    expect(trigger).toHaveFocus()
    expect(count(fetchMock, 'POST', '/switch')).toBe(0)
  })
})

describe('WIP limit above one', () => {
  it('names the running count against the limit', async () => {
    const other = task({ id: 32, task_key: 'TCB-32', title: 'Tulis abstrak', state: 'running' })
    home(
      {
        'GET /me': { status: 200, body: { ...user, wip_limit: 2 } },
        'GET /tasks/32': { status: 200, body: detailOf(other) },
      },
      { running: [running, other] },
    )
    renderApp('/')
    const list = await screen.findByRole('region', { name: 'Rekomendasi berikutnya' })
    const [first] = await within(list).findAllByRole('article')
    await userEvent.click(within(first).getByRole('button', { name: 'Pindah ke task ini' }))

    const dialog = await screen.findByRole('dialog')
    expect(await within(dialog).findByText(/^Running sudah penuh \(2\/2\)\. TCB-31 dipindah/)).toBeInTheDocument()
  })
})
