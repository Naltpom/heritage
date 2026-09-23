import { describe, expect, it } from 'vitest'
import { ZOOM_MAX, borner, cadrageInitial, coteDeSortie, echelle, zoneSource, zoomerAutour } from './cadrage'

/**
 * Ce que ces tests protègent : le carré envoyé au serveur est exactement ce que
 * le cercle montrait, et l'image couvre toujours la scène — jamais de bord vide
 * dans un portrait.
 */

const paysage = { largeur: 4000, hauteur: 3000 }
const COTE = 300

describe('cadrage d’un portrait', () => {
  it('part centré, l’image couvrant juste la scène', () => {
    const c = cadrageInitial(paysage, COTE)
    expect(c.zoom).toBe(1)
    expect(c.y).toBe(0)
    expect(c.x).toBeCloseTo((COTE - 4000 * (COTE / 3000)) / 2)
    const z = zoneSource(c, paysage, COTE)
    expect(z.cote).toBeCloseTo(3000)
    expect(z.x).toBeCloseTo(500)
    expect(z.y).toBeCloseTo(0)
  })

  it('ne laisse jamais de bord vide', () => {
    const c = borner({ zoom: 1, x: 50, y: -999 }, paysage, COTE)
    expect(c.x).toBe(0)
    const k = echelle(paysage, COTE, 1)
    expect(c.y).toBeCloseTo(COTE - 3000 * k)
    const z = zoneSource(c, paysage, COTE)
    expect(z.x).toBeGreaterThanOrEqual(0)
    expect(z.y + z.cote).toBeLessThanOrEqual(3000 + 1e-6)
  })

  it('borne le zoom entre 1 et le maximum', () => {
    expect(borner({ zoom: 0.2, x: 0, y: 0 }, paysage, COTE).zoom).toBe(1)
    expect(borner({ zoom: 99, x: 0, y: 0 }, paysage, COTE).zoom).toBe(ZOOM_MAX)
  })

  it('zoome autour d’un point sans le déplacer', () => {
    const c0 = cadrageInitial(paysage, COTE)
    const k0 = echelle(paysage, COTE, c0.zoom)
    const avant = { x: (150 - c0.x) / k0, y: (120 - c0.y) / k0 }
    const c1 = zoomerAutour(c0, paysage, COTE, 2, 150, 120)
    const k1 = echelle(paysage, COTE, c1.zoom)
    expect((150 - c1.x) / k1).toBeCloseTo(avant.x)
    expect((120 - c1.y) / k1).toBeCloseTo(avant.y)
    expect(zoneSource(c1, paysage, COTE).cote).toBeCloseTo(1500)
  })

  it('envoie la résolution réelle, plafonnée à 1024', () => {
    expect(coteDeSortie(3000)).toBe(1024)
    expect(coteDeSortie(640.4)).toBe(640)
  })
})
