import { afterEach, describe, expect, it, vi } from 'vitest'
import { clearToken, getToken, setToken, signOutReason, subscribeToken } from './token'

afterEach(() => {
  clearToken()
  localStorage.clear()
  vi.restoreAllMocks()
})

describe('token store', () => {
  it('keeps the token across page loads (localStorage)', () => {
    setToken('1|abc')
    expect(getToken()).toBe('1|abc')
    expect(localStorage.getItem('tcb.token')).toBe('1|abc')
  })

  it('removes the token on clear', () => {
    setToken('1|abc')
    clearToken()
    expect(getToken()).toBeNull()
    expect(localStorage.getItem('tcb.token')).toBeNull()
  })

  it('remembers why the session ended until the next login', () => {
    setToken('1|abc')
    clearToken('expired')
    expect(signOutReason()).toBe('expired')
    setToken('2|def')
    expect(signOutReason()).toBeNull()
  })

  it('notifies subscribers on change', () => {
    const listener = vi.fn()
    const unsubscribe = subscribeToken(listener)
    setToken('1|abc')
    clearToken()
    unsubscribe()
    setToken('2|def')
    expect(listener).toHaveBeenCalledTimes(2)
  })

  it('follows logout in another tab', () => {
    setToken('1|abc')
    const listener = vi.fn()
    const unsubscribe = subscribeToken(listener)
    localStorage.removeItem('tcb.token')
    window.dispatchEvent(new StorageEvent('storage', { key: 'tcb.token', newValue: null }))
    unsubscribe()
    expect(listener).toHaveBeenCalled()
    expect(getToken()).toBeNull()
  })

  it('still works in memory when localStorage is blocked', () => {
    vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
      throw new DOMException('blocked', 'SecurityError')
    })
    vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
      throw new DOMException('blocked', 'SecurityError')
    })
    setToken('1|abc')
    expect(getToken()).toBe('1|abc')
  })
})
