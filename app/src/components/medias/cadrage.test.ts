import { describe, expect, it } from 'vitest'
import { ZOOM_MAX, borner, cadrageDepuisCadre, cadrageInitial, cadreDeZone, coteDeSortie, echelle, zoneDepuisCadre, zoneSource, zoomerAutour, type CadreVisage } from './cadrage'

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

describe('cadrage posé sur un cadre de visage', () => {
  // Une photo en portrait, 1200 × 1600 : un cadre carré en millièmes n'y est pas carré en pixels.
  const portrait = { largeur: 1200, hauteur: 1600 }
  const couvre = (z: { x: number; y: number; cote: number }, img: typeof portrait, c: CadreVisage) => {
    const eps = 1e-6
    expect(z.x).toBeLessThanOrEqual((c.gauche / 1000) * img.largeur + eps)
    expect(z.y).toBeLessThanOrEqual((c.haut / 1000) * img.hauteur + eps)
    expect(z.x + z.cote).toBeGreaterThanOrEqual((c.droite / 1000) * img.largeur - eps)
    expect(z.y + z.cote).toBeGreaterThanOrEqual((c.bas / 1000) * img.hauteur - eps)
  }

  it('centre un carré sur le cadre, du côté de son plus grand côté', () => {
    // 300 × 320 px, centré en (600, 480).
    const cadre = { haut: 200, gauche: 375, bas: 400, droite: 625 }
    const z = zoneDepuisCadre(cadre, portrait)
    expect(z.cote).toBeCloseTo(320)
    expect(z.x + z.cote / 2).toBeCloseTo(600)
    expect(z.y + z.cote / 2).toBeCloseTo(480)
    couvre(z, portrait, cadre)
  })

  it('reste dans l’image sans découvrir un cadre collé au bord', () => {
    const cadre = { haut: 0, gauche: 850, bas: 150, droite: 1000 }
    const c = cadrageDepuisCadre(cadre, portrait, COTE)
    expect(c).toEqual(borner(c, portrait, COTE))
    const z = zoneSource(c, portrait, COTE)
    expect(z.x + z.cote).toBeLessThanOrEqual(1200 + 1e-6)
    expect(z.y).toBeGreaterThanOrEqual(-1e-6)
    couvre(z, portrait, cadre)
  })

  it('plafonne le zoom sur un visage minuscule, qui reste couvert', () => {
    const cadre = { haut: 500, gauche: 500, bas: 510, droite: 510 }
    const c = cadrageDepuisCadre(cadre, portrait, COTE)
    expect(c.zoom).toBe(ZOOM_MAX)
    couvre(zoneSource(c, portrait, COTE), portrait, cadre)
  })

  it('prend le zoom 1 pour un cadre plus grand que la photo n’est large', () => {
    const c = cadrageDepuisCadre({ haut: 0, gauche: 0, bas: 1000, droite: 1000 }, portrait, COTE)
    expect(c.zoom).toBe(1)
    expect(zoneSource(c, portrait, COTE).cote).toBeCloseTo(1200)
  })

  it('ajoute la marge demandée', () => {
    const cadre = { haut: 200, gauche: 375, bas: 400, droite: 625 }
    const z = zoneSource(cadrageDepuisCadre(cadre, portrait, COTE, 0.25), portrait, COTE)
    expect(z.cote).toBeCloseTo(400)
  })

  it('retombe sur l’image centrée pour un cadre vide ou inversé', () => {
    expect(cadrageDepuisCadre({ haut: 400, gauche: 100, bas: 400, droite: 300 }, paysage, COTE)).toEqual(
      cadrageInitial(paysage, COTE),
    )
    expect(cadrageDepuisCadre({ haut: 100, gauche: 600, bas: 400, droite: 300 }, paysage, COTE)).toEqual(
      cadrageInitial(paysage, COTE),
    )
  })

  it('retrouve le même carré depuis le cadre d’un ajustement', () => {
    const zone = { x: 210, y: 330, cote: 500 }
    const z = zoneDepuisCadre(cadreDeZone(zone, portrait), portrait)
    expect(z.x).toBeCloseTo(210)
    expect(z.y).toBeCloseTo(330)
    expect(z.cote).toBeCloseTo(500)
  })
})
