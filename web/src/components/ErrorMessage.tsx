import { errorMessage } from '../api/errors'

/** An API error as a readable sentence (see api/errors.ts). */
export function ErrorMessage({ error, className = '' }: { error: unknown; className?: string }) {
  return (
    <p role="alert" className={`rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800 ${className}`}>
      {errorMessage(error)}
    </p>
  )
}
