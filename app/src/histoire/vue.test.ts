import { describe, expect, it } from 'vitest'
import {
  ECHELLE_MAX,
  echelleVerticale,
  niveau,
  syDe,
  trajectoire,
  versMonde,
  zoomAutour,
  type Canevas,
} from './vue'

const canevas: Canevas = { largeur: 1200, hauteur: 700, largeurMonde: 24000, hauteurMonde: 900 }

describe('niveaux de détail', () => {
  it('suit les seuils 0,16 / 0,42 / 0,75', () => {
    expect(niveau(0.05)).toBe(0)
    expect(niveau(0.159)).toBe(0)
    expect(niveau(0.16)).toBe(1)
    expect(niveau(0.419)).toBe(1)
    expect(niveau(0.42)).toBe(2)
    expect(niveau(0.749)).toBe(2)
    expect(niveau(0.75)).toBe(3)
    expect(niveau(1.8)).toBe(3)
  })
})

describe('échelle verticale', () => {
  it('garde les rangées lisibles au dézoom', () => {
    // fitY = (700 − 90) / 900 ≈ 0,68 ; à s = 0,01, 30·s = 0,3.
    expect(echelleVerticale(0.01, 700, 900)).toBeCloseTo(0.3)
    expect(echelleVerticale(0.05, 700, 900)).toBeCloseTo(610 / 900)
  })

  it('redevient uniforme dès que s atteint fitY', () => {
    expect(echelleVerticale(0.8, 700, 900)).toBe(0.8)
    expect(echelleVerticale(1.5, 700, 900)).toBe(1.5)
  })

  it('est continue', () => {
    for (let s = 0.005; s < 1.5; s += 0.001) {
      expect(Math.abs(echelleVerticale(s + 0.001, 700, 900) - echelleVerticale(s, 700, 900))).toBeLessThan(0.031)
    }
  })

  it('ne descend jamais sous s', () => {
    for (const s of [0.01, 0.1, 0.5, 1]) expect(echelleVerticale(s, 200, 5000)).toBeGreaterThanOrEqual(s)
  })
})

describe('zoom autour d’un point', () => {
  it('garde fixe le point du monde sous le curseur, sur les deux axes', () => {
    const depart = { s: 0.2, tx: 100, ty: 40 }
    const avant = versMonde(depart, canevas, 640, 300)
    for (const facteur of [1.6, 1 / 1.6, 3, 0.9]) {
      const apres = zoomAutour(depart, canevas, 640, 300, facteur)
      const point = versMonde(apres, canevas, 640, 300)
      expect(point.x).toBeCloseTo(avant.x, 6)
      expect(point.y).toBeCloseTo(avant.y, 6)
    }
  })

  it('reste dans les bornes', () => {
    expect(zoomAutour({ s: 1, tx: 0, ty: 0 }, canevas, 0, 0, 100).s).toBe(ECHELLE_MAX)
    expect(zoomAutour({ s: 0.1, tx: 0, ty: 0 }, canevas, 0, 0, 0.0001).s).toBeCloseTo((0.8 * 1200) / 24000)
  })
})

describe('vol vers une personne', () => {
  it('part du cadrage courant et arrive centré sur la cible', () => {
    const depart = { s: 0.05, tx: 150, ty: 20 }
    const vol = trajectoire(depart, canevas, 1, 5000, 400)
    expect(vol(0).s).toBeCloseTo(depart.s)
    expect(vol(0).tx).toBeCloseTo(depart.tx)
    const fin = vol(1)
    expect(fin.s).toBeCloseTo(1)
    expect(fin.tx + 5000 * fin.s).toBeCloseTo(600)
    expect(fin.ty + 400 * syDe(fin.s, canevas)).toBeCloseTo(350)
  })
})
