/**
 * The only module that reads or writes the Sanctum token.
 *
 * Stored in localStorage (decision R1-15) so a session survives closing the
 * tab. Mitigations: nothing else touches the token, it never goes into URLs or
 * logs, a 401 removes it at once (api/client.ts), and logout revokes it on the
 * server. If storage is blocked the token lives in memory for this page only.
 */

const KEY = 'tcb.token'

export type SignOutReason = 'logout' | 'expired'

let memoryToken: string | null = null
let reason: SignOutReason | null = null
const listeners = new Set<() => void>()

function emit() {
  for (const listener of listeners) listener()
}

export function getToken(): string | null {
  try {
    return localStorage.getItem(KEY) ?? null
  } catch {
    return memoryToken
  }
}

export function setToken(token: string): void {
  memoryToken = token
  reason = null
  try {
    localStorage.setItem(KEY, token)
  } catch {
    // Storage blocked (private mode, policy): keep the in-memory copy.
  }
  emit()
}

export function clearToken(why: SignOutReason = 'logout'): void {
  memoryToken = null
  reason = why
  try {
    localStorage.removeItem(KEY)
  } catch {
    // Nothing stored.
  }
  emit()
}

/** Why the last session ended; null after a login or when never signed in. */
export function signOutReason(): SignOutReason | null {
  return reason
}

export function subscribeToken(listener: () => void): () => void {
  listeners.add(listener)
  // Logout or login in another tab.
  const onStorage = (event: StorageEvent) => {
    if (event.key === KEY || event.key === null) listener()
  }
  window.addEventListener('storage', onStorage)
  return () => {
    listeners.delete(listener)
    window.removeEventListener('storage', onStorage)
  }
}
