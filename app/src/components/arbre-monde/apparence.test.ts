/** La couleur des feuilles : la teinte de la lignée, l'automne pour un défunt, le parchemin pour un anonyme. */
import { describe, expect, it } from 'vitest'
import { TEINTES, couleurDeFeuille, cssDeTeinte, melanger } from './apparence'

describe('couleurDeFeuille', () => {
  it('garde la teinte franche d’un vivant, tire celle d’un défunt vers l’ocre', () => {
    expect(couleurDeFeuille({ teinte: 'pp', vivant: true, restreinte: false })).toBe(TEINTES.pp)
    const defunt = couleurDeFeuille({ teinte: 'pm', vivant: false, restreinte: false })
    expect(defunt).not.toBe(TEINTES.pm)
    expect(defunt).toBe(melanger(TEINTES.pm, 0xc9955a, 0.38))
  })

  it('rend un parchemin pour un vivant hors cercle, quelle que soit sa lignée', () => {
    expect(couleurDeFeuille({ teinte: 'mm', vivant: true, restreinte: true })).toBe(0xe6d3b0)
  })

  it('reprend les quatre teintes de la charte', () => {
    expect([cssDeTeinte('pp'), cssDeTeinte('pm'), cssDeTeinte('mp'), cssDeTeinte('mm')]).toEqual(['#d9a066', '#4f9d8c', '#8a7bc8', '#7fa855'])
  })
})

describe('melanger', () => {
  it('va d’une couleur à l’autre', () => {
    expect(melanger(0x000000, 0xffffff, 0)).toBe(0x000000)
    expect(melanger(0x000000, 0xffffff, 1)).toBe(0xffffff)
    expect(melanger(0x000000, 0xfefefe, 0.5)).toBe(0x7f7f7f)
  })
})
