/**
 * Turns API failures into messages a person can read (Indonesian), never raw
 * codes, HTML error pages, or English framework messages.
 */

const NETWORK_MESSAGE = 'API tidak bisa dihubungi. Periksa koneksi, lalu coba lagi.'
const SERVER_MESSAGE = 'Terjadi galat di server. Coba lagi sebentar lagi.'
const UNEXPECTED_MESSAGE = 'Terjadi kesalahan tak terduga. Coba lagi.'

const STATUS_MESSAGES: Record<number, string> = {
  401: 'Sesi Anda berakhir. Silakan masuk lagi.',
  403: 'Anda tidak punya akses ke data ini.',
  404: 'Data tidak ditemukan.',
  409: 'Permintaan bertentangan dengan status data saat ini.',
  422: 'Data yang dikirim belum sesuai.',
  429: 'Terlalu banyak permintaan. Tunggu sebentar, lalu coba lagi.',
}

type ErrorBody = {
  code?: unknown
  message?: unknown
  errors?: unknown
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

function readFieldErrors(errors: unknown): Record<string, string[]> {
  if (!isRecord(errors)) return {}
  const result: Record<string, string[]> = {}
  for (const [field, messages] of Object.entries(errors)) {
    const list = (Array.isArray(messages) ? messages : [messages]).filter(
      (m): m is string => typeof m === 'string' && m.trim() !== '',
    )
    if (list.length > 0) result[field] = list
  }
  return result
}

/** A message from the API is shown only if it reads as a sentence, not a code. */
function isReadable(message: unknown): message is string {
  return typeof message === 'string' && message.trim() !== '' && !/^[a-z0-9_.]+$/.test(message.trim())
}

function fallbackFor(status: number): string {
  if (status >= 500) return SERVER_MESSAGE
  return STATUS_MESSAGES[status] ?? `Permintaan tidak bisa diproses (galat ${status}).`
}

export class ApiError extends Error {
  /** HTTP status; 0 when the API could not be reached. */
  readonly status: number
  /** `code` from the Error schema (e.g. invalid_transition, wip_full), if any. */
  readonly code: string | undefined
  /** Per-field messages from ValidationErrorBody (422). */
  readonly fieldErrors: Record<string, string[]>
  /** Parsed response body, for callers that need details (wip_full.running, …). */
  readonly body: unknown

  constructor(status: number, message: string, options: { code?: string; fieldErrors?: Record<string, string[]>; body?: unknown; cause?: unknown } = {}) {
    super(message, { cause: options.cause })
    this.name = 'ApiError'
    this.status = status
    this.code = options.code
    this.fieldErrors = options.fieldErrors ?? {}
    this.body = options.body
  }

  static fromResponse(status: number, body: unknown): ApiError {
    const parsed: ErrorBody = isRecord(body) ? body : {}
    const code = typeof parsed.code === 'string' ? parsed.code : undefined
    const fieldErrors = readFieldErrors(parsed.errors)
    const firstFieldError = Object.values(fieldErrors)[0]?.[0]

    let message: string
    if (status >= 500) {
      message = SERVER_MESSAGE
    } else if (firstFieldError !== undefined) {
      // Laravel's summary appends "(and N more errors)" in English.
      message = firstFieldError
    } else if (isReadable(parsed.message)) {
      message = parsed.message
    } else {
      message = fallbackFor(status)
    }

    return new ApiError(status, message, { code, fieldErrors, body })
  }

  static network(cause: unknown): ApiError {
    return new ApiError(0, NETWORK_MESSAGE, { cause })
  }
}

/** The message to show for any error thrown while talking to the API. */
export function errorMessage(error: unknown): string {
  return error instanceof ApiError ? error.message : UNEXPECTED_MESSAGE
}
