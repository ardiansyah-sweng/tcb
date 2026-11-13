import { describe, expect, it } from 'vitest'
import { ApiError, errorMessage } from './errors'

describe('ApiError.fromResponse', () => {
  it('uses the Indonesian message the API sends', () => {
    const err = ApiError.fromResponse(409, {
      code: 'invalid_transition',
      message: 'Task berstatus New tidak bisa langsung Running. Pindahkan ke Ready dulu.',
    })
    expect(err.message).toBe('Task berstatus New tidak bisa langsung Running. Pindahkan ke Ready dulu.')
    expect(err.code).toBe('invalid_transition')
    expect(err.status).toBe(409)
  })

  it('keeps wip_full details for the caller', () => {
    const body = { code: 'wip_full', message: 'Running sudah penuh.', wip_limit: 1, running: [{ task_key: 'TCB-31' }] }
    const err = ApiError.fromResponse(409, body)
    expect(err.code).toBe('wip_full')
    expect(err.body).toEqual(body)
  })

  it('shows the first field error for a validation failure, not the summary', () => {
    const err = ApiError.fromResponse(422, {
      message: 'Judul wajib diisi. (and 1 more error)',
      errors: { title: ['Judul wajib diisi.'], unit_done: ['Jumlah selesai melebihi total.'] },
    })
    expect(err.message).toBe('Judul wajib diisi.')
    expect(err.fieldErrors).toEqual({
      title: ['Judul wajib diisi.'],
      unit_done: ['Jumlah selesai melebihi total.'],
    })
  })

  it('never shows a raw code as the message', () => {
    expect(ApiError.fromResponse(409, { code: 'conflict', message: 'conflict' }).message).toBe(
      'Permintaan bertentangan dengan status data saat ini.',
    )
    expect(ApiError.fromResponse(409, { code: 'conflict' }).message).toBe(
      'Permintaan bertentangan dengan status data saat ini.',
    )
  })

  it('replaces server error bodies with a readable message', () => {
    expect(ApiError.fromResponse(500, { message: 'Server Error' }).message).toBe(
      'Terjadi galat di server. Coba lagi sebentar lagi.',
    )
    expect(ApiError.fromResponse(502, '<html>Bad Gateway</html>').message).toBe(
      'Terjadi galat di server. Coba lagi sebentar lagi.',
    )
  })

  it.each([
    [401, 'Sesi Anda berakhir. Silakan masuk lagi.'],
    [403, 'Anda tidak punya akses ke data ini.'],
    [404, 'Data tidak ditemukan.'],
    [422, 'Data yang dikirim belum sesuai.'],
    [429, 'Terlalu banyak permintaan. Tunggu sebentar, lalu coba lagi.'],
    [418, 'Permintaan tidak bisa diproses (galat 418).'],
  ])('falls back to a message for status %i without a body', (status, message) => {
    expect(ApiError.fromResponse(status, undefined).message).toBe(message)
  })
})

describe('errorMessage', () => {
  it('describes a network failure', () => {
    expect(errorMessage(ApiError.network(new TypeError('Failed to fetch')))).toBe(
      'API tidak bisa dihubungi. Periksa koneksi, lalu coba lagi.',
    )
  })

  it('does not leak messages of unexpected errors', () => {
    expect(errorMessage(new Error('Cannot read properties of undefined (reading "x")'))).toBe(
      'Terjadi kesalahan tak terduga. Coba lagi.',
    )
    expect(errorMessage('boom')).toBe('Terjadi kesalahan tak terduga. Coba lagi.')
  })
})
