import { describe, expect, it } from 'vitest'
import { doitFermer } from './useGlisserPourFermer'

describe('glisser pour fermer', () => {
  it('ferme quand on tire assez bas', () => {
    expect(doitFermer(90, 1000)).toBe(true)
    expect(doitFermer(200, 2000)).toBe(true)
  })

  it('ferme d’un coup sec, même court', () => {
    expect(doitFermer(40, 50)).toBe(true)
  })

  it('revient en place pour un geste hésitant ou minuscule', () => {
    expect(doitFermer(60, 800)).toBe(false)
    expect(doitFermer(20, 10)).toBe(false)
    expect(doitFermer(0, 0)).toBe(false)
  })
})
