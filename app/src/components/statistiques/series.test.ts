import { describe, expect, it } from 'vitest'
import {
  COULEUR_FEMMES,
  COULEUR_HOMMES,
  demandesParMois,
  douzeMois,
  estVide,
  hommesFemmesParSiecle,
  mortaliteParSiecle,
} from './series'

/**
 * De la charge aux séries. Ce que ces tests tiennent : **une case vide n'est
 * pas un zéro** (elle se dessine comme une absence), les couleurs sont des
 * jetons existants, et l'échantillon affiché est la somme des effectifs.
 */
describe('hommes et femmes par siècle', () => {
  const lignes = [
    { siecle: 18, hommes: { moyenne: 52.3, n: 10 }, femmes: { moyenne: null, n: 0 }, ensemble: { moyenne: 52.3, n: 10 } },
    { siecle: 19, hommes: { moyenne: 61, n: 4 }, femmes: { moyenne: 64.5, n: 6 }, ensemble: { moyenne: 63, n: 10 } },
  ]

  it('une case sans personne vaut null, jamais zéro', () => {
    const g = hommesFemmesParSiecle(lignes)

    expect(g.categories).toEqual(['XVIIIᵉ s.', 'XIXᵉ s.'])
    expect(g.series[0]!.valeurs).toEqual([52.3, 61])
    expect(g.series[1]!.valeurs).toEqual([null, 64.5])
  })

  it('les couleurs sont celles des fiches de l’arbre', () => {
    const g = hommesFemmesParSiecle(lignes)

    expect(g.series.map((s) => s.couleur)).toEqual([COULEUR_HOMMES, COULEUR_FEMMES])
    expect(COULEUR_HOMMES).toMatch(/^var\(--h-/)
  })

  it('l’échantillon est la somme des effectifs', () => {
    expect(hommesFemmesParSiecle(lignes).n).toBe(20)
  })
})

describe('la mortalité des enfants', () => {
  it('se dit en pour cent de l’effectif du siècle', () => {
    const g = mortaliteParSiecle([
      { siecle: 18, avantCinqAns: 30, n: 120 },
      { siecle: 19, avantCinqAns: 0, n: 0 },
    ])

    expect(g.series[0]!.valeurs).toEqual([25, null])
    expect(g.n).toBe(120)
  })
})

describe('les mois', () => {
  it('douze colonnes, de janvier à décembre', () => {
    const g = douzeMois('Naissances', [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12])

    expect(g.categories).toHaveLength(12)
    expect(g.n).toBe(78)
  })

  it('un graphique sans aucune valeur est vide ; un zéro est une valeur', () => {
    expect(estVide(douzeMois('Décès', []))).toBe(true)
    expect(estVide(mortaliteParSiecle([{ siecle: 19, avantCinqAns: 0, n: 0 }]))).toBe(true)
    expect(estVide(mortaliteParSiecle([{ siecle: 20, avantCinqAns: 0, n: 3 }]))).toBe(false)
  })
})

describe('les demandes du chantier', () => {
  it('une initiale de mois par colonne, deux séries', () => {
    const g = demandesParMois([
      { mois: '2026-09', proposees: 4, validees: 3 },
      { mois: '2026-10', proposees: 1, validees: 0 },
    ])

    expect(g.categories).toEqual(['S', 'O'])
    expect(g.series.map((s) => s.nom)).toEqual(['Proposées', 'Validées'])
    expect(g.n).toBe(5)
  })
})
