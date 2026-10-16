/**
 * L'encodeur QR — ce qu'un lecteur de codes vérifie avant de lire une donnée.
 *
 * Le décodage complet a été éprouvé hors dépôt (jsQR, versions 1 à 19, texte
 * accentué et sinogrammes compris) ; ce qui reste ici, ce sont les invariants
 * de la norme, que n'importe quelle régression casserait : la taille, les trois
 * motifs de repérage, la ligne de synchronisation, le module sombre, et les deux
 * copies du format, identiques et protégées par leur BCH.
 */
import { describe, expect, it } from 'vitest'
import { cheminSvg, encoderQr, VERSION_MAX } from './qr'

function formatLu(m: boolean[][]): { premier: number; second: number } {
  const t = m.length
  const b = (v: boolean): number => (v ? 1 : 0)
  let premier = 0
  const cases1: [number, number][] = [
    [8, 0], [8, 1], [8, 2], [8, 3], [8, 4], [8, 5], [8, 7], [8, 8], [7, 8], [5, 8], [4, 8], [3, 8], [2, 8], [1, 8], [0, 8],
  ]
  cases1.forEach(([x, y], i) => (premier |= b(m[y]![x]!) << i))
  let second = 0
  for (let i = 0; i < 8; i++) second |= b(m[8]![t - 1 - i]!) << i
  for (let i = 8; i < 15; i++) second |= b(m[t - 15 + i]![8]!) << i
  return { premier, second }
}

describe('encoderQr', () => {
  it('choisit le plus petit format qui tient le texte', () => {
    expect(encoderQr('a').version).toBe(1)
    expect(encoderQr('https://heritage.exemple.fr/administration/suivi').version).toBe(4)
    expect(encoderQr('x'.repeat(600)).version).toBe(19)
  })

  it('a la taille de sa version : 4 × v + 17 modules de côté', () => {
    const c = encoderQr('https://heritage.exemple.fr/administration/suivi')
    expect(c.taille).toBe(4 * c.version + 17)
    expect(c.modules).toHaveLength(c.taille)
    for (const l of c.modules) expect(l).toHaveLength(c.taille)
  })

  it('pose les trois motifs de repérage, la synchronisation et le module sombre', () => {
    const { modules: m, taille: t } = encoderQr('Généalogie — Heritage')
    for (const [x0, y0] of [[0, 0], [t - 7, 0], [0, t - 7]] as const) {
      // Le bord du motif est sombre, l'anneau intérieur clair, le cœur sombre.
      expect(m[y0]![x0]).toBe(true)
      expect(m[y0 + 1]![x0 + 1]).toBe(false)
      expect(m[y0 + 3]![x0 + 3]).toBe(true)
    }
    for (let i = 8; i < t - 8; i++) {
      expect(m[6]![i]).toBe(i % 2 === 0)
      expect(m[i]![6]).toBe(i % 2 === 0)
    }
    expect(m[t - 8]![8]).toBe(true)
  })

  it('écrit deux copies identiques du format, niveau M, au BCH valide', () => {
    for (const texte of ['a', 'https://heritage.exemple.fr/administration/suivi', 'y'.repeat(200)]) {
      const { premier, second } = formatLu(encoderQr(texte).modules)
      expect(premier).toBe(second)
      const brut = premier ^ 0x5412
      expect(brut >>> 13).toBe(0) // niveau M : bits « 00 »
      let r = brut >>> 10
      for (let i = 0; i < 10; i++) r = (r << 1) ^ ((r >>> 9) * 0x537)
      expect(((brut >>> 10) << 10) | r).toBe(brut)
    }
  })

  it('est déterministe', () => {
    expect(encoderQr('même texte').modules).toEqual(encoderQr('même texte').modules)
  })

  it('refuse un texte trop long plutôt que d’improviser un code illisible', () => {
    expect(() => encoderQr('z'.repeat(2000))).toThrow(new RegExp(`version ${VERSION_MAX}`))
  })
})

describe('cheminSvg', () => {
  it('trace une suite horizontale par sous-chemin, décalée de la marge', () => {
    const chemin = cheminSvg(encoderQr('a'), 4)
    // Le haut du premier motif de repérage : sept modules sombres d'affilée.
    expect(chemin.startsWith('M4 4h7v1h-7z')).toBe(true)
  })
})
