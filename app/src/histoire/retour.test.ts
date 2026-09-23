import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { memoriserVue, reprendreVue, vueARetrouver } from './retour'

/**
 * Ce que ces tests protègent : le retour de la fiche ramène à la vue quittée,
 * une seule fois, et une mémoire vieille ou abîmée n'empêche pas l'arbre de
 * s'ouvrir normalement.
 */

class StockageMemoire {
  private d = new Map<string, string>()
  getItem(k: string): string | null {
    return this.d.get(k) ?? null
  }
  setItem(k: string, v: string): void {
    this.d.set(k, v)
  }
  removeItem(k: string): void {
    this.d.delete(k)
  }
}

const VUE = {
  x: 5230,
  y: 412,
  s: 0.62,
  selection: 'p17',
  filtres: { masquees: ['pm'], ligneDirecte: true, vivants: false },
}

describe('retour à la vue de l’arbre', () => {
  beforeEach(() => {
    ;(globalThis as { sessionStorage?: unknown }).sessionStorage = new StockageMemoire()
  })
  afterEach(() => {
    delete (globalThis as { sessionStorage?: unknown }).sessionStorage
  })

  it('rend la vue mémorisée, une seule fois', () => {
    memoriserVue(VUE, 1000)
    expect(vueARetrouver(2000)).toBe(true)
    expect(reprendreVue(2000)).toEqual({ ...VUE, t: 1000 })
    expect(vueARetrouver(2000)).toBe(false)
    expect(reprendreVue(2000)).toBeNull()
  })

  it('oublie une vue trop ancienne', () => {
    memoriserVue(VUE, 0)
    expect(vueARetrouver(3 * 60 * 60 * 1000)).toBe(false)
  })

  it('ignore une mémoire abîmée', () => {
    globalThis.sessionStorage.setItem('heritage.arbre.retour', '{pas du json')
    expect(reprendreVue()).toBeNull()
  })

  it('ne casse rien sans stockage', () => {
    delete (globalThis as { sessionStorage?: unknown }).sessionStorage
    expect(() => memoriserVue(VUE)).not.toThrow()
    expect(vueARetrouver()).toBe(false)
  })
})
