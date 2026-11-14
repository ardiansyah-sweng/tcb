import { describe, expect, it } from 'vitest'
import { decimalInput, parseDecimal, remainingPeriod, sixMonthsFrom, todayIn } from './format'

describe('remainingPeriod', () => {
  it('counts whole weeks from two weeks on, days below that', () => {
    expect(remainingPeriod('2027-01-22', '2026-11-13')).toBe('Sisa periode: 10 minggu.')
    expect(remainingPeriod('2026-11-27', '2026-11-13')).toBe('Sisa periode: 2 minggu.')
    expect(remainingPeriod('2026-11-26', '2026-11-13')).toBe('Sisa periode: 13 hari.')
    expect(remainingPeriod('2026-11-13', '2026-11-13')).toBe('Periode berakhir hari ini.')
    expect(remainingPeriod('2026-11-12', '2026-11-13')).toBe('Periode sudah berakhir.')
  })
})

describe('dates', () => {
  it('takes today in the user time zone', () => {
    // 13 Nov 2026, 23.30 UTC is already 14 Nov in Jakarta.
    expect(todayIn('Asia/Jakarta', Date.UTC(2026, 10, 13, 23, 30))).toBe('2026-11-14')
    expect(todayIn('UTC', Date.UTC(2026, 10, 13, 23, 30))).toBe('2026-11-13')
  })

  it('ends six months later, the last day included', () => {
    expect(sixMonthsFrom('2026-11-13')).toBe('2027-05-12')
    expect(sixMonthsFrom('2026-09-01')).toBe('2027-02-28')
  })
})

describe('decimals', () => {
  it('accepts a decimal comma or point, empty as null, anything else as NaN', () => {
    expect(parseDecimal('0,4')).toBe(0.4)
    expect(parseDecimal(' 0.25 ')).toBe(0.25)
    expect(parseDecimal('')).toBeNull()
    expect(parseDecimal('1,2,3')).toBeNaN()
    expect(parseDecimal('abc')).toBeNaN()
    expect(decimalInput(0.4)).toBe('0,4')
    expect(decimalInput(null)).toBe('')
  })
})
