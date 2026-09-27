import { describe, expect, it } from 'vitest'
import { bandeauDesFamilles, type BrancheDuBandeau } from './bandeau'

const branche = (cle: string, x0: number, x1: number, nom = 'Persil'): BrancheDuBandeau => ({
  cle,
  couleur: '#000',
  nom,
  sous: 'côté père',
  x0,
  x1,
})

describe('le bandeau des familles', () => {
  it('ne garde que la part visible de chaque branche', () => {
    const [s] = bandeauDesFamilles([branche('pp', -500, 300)], 64, 390, 12)
    expect(s!.gauche).toBe(64)
    expect(s!.largeur).toBe(236)
  })

  it('écarte une branche entièrement hors de l’écran', () => {
    expect(bandeauDesFamilles([branche('pp', 400, 900)], 64, 390, 12)).toEqual([])
    expect(bandeauDesFamilles([branche('pp', -900, 60)], 64, 390, 12)).toEqual([])
  })

  it('garde le nom collé au bord tant que la branche déborde', () => {
    // Branche très large, centrée loin à droite : le nom se range contre le bord gauche visible.
    const [s] = bandeauDesFamilles([branche('pp', -2000, 5000)], 64, 390, 12)
    expect(s!.etiquette!.x).toBeGreaterThan(64)
    expect(s!.etiquette!.x).toBeLessThan(390)
    // Branche entière à l'écran : le nom est à son milieu.
    const [t] = bandeauDesFamilles([branche('pp', 100, 300)], 64, 390, 12)
    expect(t!.etiquette!.x).toBe(200)
  })

  it('retire le nom mais garde le trait quand la place manque', () => {
    const [s] = bandeauDesFamilles([branche('pp', 100, 130, 'Sarriette-Dolomite')], 64, 390, 12)
    expect(s!.etiquette).toBeNull()
    expect(s!.largeur).toBe(30)
  })
})
