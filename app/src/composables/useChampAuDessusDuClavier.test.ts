import { describe, expect, it } from 'vitest'
import { hauteurDuClavier } from './useChampAuDessusDuClavier'

describe('le clavier du téléphone', () => {
  it('mesure ce qu’il cache : l’écran moins la partie visible', () => {
    expect(hauteurDuClavier(800, 480, 0)).toBe(320)
    expect(hauteurDuClavier(800, 480, 20)).toBe(300)
  })

  it('ne réserve rien quand il est rangé', () => {
    expect(hauteurDuClavier(800, 800, 0)).toBe(0)
    expect(hauteurDuClavier(800, 810, 0)).toBe(0)
  })
})
