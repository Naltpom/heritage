import { describe, expect, it } from 'vitest'
import { echelleDeRendu } from './pdf'

describe('l’échelle de rendu d’une page de PDF', () => {
  it('remplit la largeur demandée sur un écran ordinaire', () => {
    // Une page A4 fait 595 points de large.
    expect(echelleDeRendu(595, 595, 1)).toBe(1)
    expect(echelleDeRendu(595, 1190, 1)).toBe(2)
  })

  it('suit la densité de l’écran, pour rester net sur un téléphone', () => {
    expect(echelleDeRendu(500, 400, 2)).toBeCloseTo(1.6)
  })

  it('borne la densité à 3 : au-delà on décode des mégapixels pour rien', () => {
    expect(echelleDeRendu(500, 500, 4)).toBe(3)
  })

  it('ne descend jamais sous la densité 1, même quand le navigateur dézoome', () => {
    expect(echelleDeRendu(500, 500, 0.5)).toBe(1)
  })

  it('rend 1 plutôt qu’une échelle absurde sur une mesure nulle ou invalide', () => {
    // Un cadre pas encore dans le DOM mesure 0 : une échelle nulle ferait une
    // toile de 0 × 0, et pdf.js lèverait.
    expect(echelleDeRendu(595, 0, 2)).toBe(1)
    expect(echelleDeRendu(0, 400, 2)).toBe(1)
    expect(echelleDeRendu(595, 400, Number.NaN)).toBeCloseTo(400 / 595)
  })
})
