import { describe, expect, it } from 'vitest'
import { ELLIPSE, largeurDuTexte, poserEtiquettes, tronquer, type DemandeDEtiquette, type EtiquettePosee } from './etiquettes'

/**
 * Ce que ces tests protègent : deux étiquettes posées sur la même ligne ne se
 * recouvrent JAMAIS — « Provos Provost », « ProvoBORNERIE » (captures du 01/10) —, et
 * une étiquette ne sort jamais de l'intervalle qui lui est permis.
 */

const demande = (cle: string, x: number, nom: string, priorite: number, demi = 80): DemandeDEtiquette => ({
  cle,
  x,
  y: 100,
  nom,
  taille: 10.5,
  min: x - demi,
  max: x + demi,
  priorite,
})

function disjointes(posees: EtiquettePosee[], marge = 0): boolean {
  for (let i = 0; i < posees.length; i++)
    for (let j = i + 1; j < posees.length; j++) {
      const [a, b] = [posees[i]!, posees[j]!]
      // Au millième près : une étiquette posée bord à bord de la marge n'en recouvre pas une autre.
      if (Math.abs(a.y - b.y) < 14 && Math.abs(a.x - b.x) < (a.largeur + b.largeur) / 2 + marge - 1e-6) return false
    }
  return true
}

describe('la largeur estimée', () => {
  it('compte une capitale plus large qu’une minuscule, et ne sous-estime pas le gras', () => {
    expect(largeurDuTexte('BORNERIE', 10)).toBeGreaterThan(largeurDuTexte('bornerie', 10))
    // L'ancienne estimation (0,6 em par signe) sous-estimait « BORNERIE ».
    expect(largeurDuTexte('BORNERIE', 13)).toBeGreaterThan(8 * 13 * 0.6)
  })
})

describe('tronquer', () => {
  it('rend le nom entier s’il tient, coupé avec une ellipse sinon', () => {
    expect(tronquer('Provost', 200, 10.5)).toBe('Provost')
    const court = tronquer('Ducatteau', 40, 10.5)!
    expect(court.endsWith(ELLIPSE)).toBe(true)
    expect(largeurDuTexte(court, 10.5)).toBeLessThanOrEqual(40)
  })

  it('se tait plutôt que de laisser moins de trois signes', () => {
    expect(tronquer('Ducatteau', 12, 10.5)).toBeNull()
  })
})

describe('poserEtiquettes', () => {
  it('ne pose qu’une des deux étiquettes au même endroit, la plus prioritaire', () => {
    // Le même homme dans deux foyers voisins : « Provos Provost ».
    const posees = poserEtiquettes([demande('a', 100, 'Provost', 1, 20), demande('b', 112, 'Provost', 2, 20)])
    expect(posees.map((p) => p.cle)).toEqual(['b'])
  })

  it('décale une étiquette dans son intervalle plutôt que de la recouvrir', () => {
    const posees = poserEtiquettes([demande('a', 100, 'Provost', 2), demande('b', 130, 'Bornerie', 1)])
    expect(posees).toHaveLength(2)
    expect(disjointes(posees, 6)).toBe(true)
    const b = posees.find((p) => p.cle === 'b')!
    expect(b.x).toBeGreaterThan(130)
    expect(b.x + b.largeur / 2).toBeLessThanOrEqual(210)
  })

  it('tronque quand il ne reste qu’un creux trop étroit, et se tait s’il n’y en a pas', () => {
    const voisines = (ecart: number): EtiquettePosee[] =>
      poserEtiquettes(
        [
          demande('gauche', 100 - ecart, 'Savignac', 3, 30),
          demande('droite', 100 + ecart, 'Bonmartin', 3, 30),
          demande('milieu', 100, 'Becquelin', 1, 60),
        ],
        { marge: 6 },
      )
    const etroit = voisines(50)
    expect(disjointes(etroit, 6)).toBe(true)
    const milieu = etroit.find((p) => p.cle === 'milieu')!
    expect(milieu.nom.endsWith(ELLIPSE)).toBe(true)
    expect(milieu.nom.startsWith('Bec')).toBe(true)
    // Plus de creux du tout : pas d'étiquette, plutôt que deux noms l'un sur l'autre.
    const plein = voisines(30)
    expect(disjointes(plein, 6)).toBe(true)
    expect(plein.find((p) => p.cle === 'milieu')).toBeUndefined()
  })

  it('abandonne la seconde ligne avant de tronquer le nom', () => {
    const [p] = poserEtiquettes([{ ...demande('a', 100, 'Voulgre', 1, 40), sous: 'Père de la mère · 134', tailleSous: 11 }])
    expect(p!.nom).toBe('Voulgre')
    expect(p!.sous).toBe('')
  })

  it('ne gêne pas une étiquette d’une autre ligne', () => {
    const posees = poserEtiquettes([demande('a', 100, 'Provost', 2), { ...demande('b', 100, 'Provost', 1), y: 200 }])
    expect(posees).toHaveLength(2)
  })

  it('garde des boîtes disjointes sur une rangée dense, quelle que soit la place', () => {
    const noms = ['Provost', 'Bornerie', 'Savignac', 'Lalot', 'Bonmartin', 'Basset', 'Becquelin', 'Dumortier', 'Damay', 'Ducatteau']
    for (const pas of [8, 20, 35, 60, 120]) {
      const demandes = Array.from({ length: 40 }, (_, i) => demande(String(i), i * pas, noms[i % noms.length]!, (i * 7) % 13, pas))
      const posees = poserEtiquettes(demandes, { marge: 6 })
      expect(disjointes(posees, 6), `pas ${pas}`).toBe(true)
      for (const p of posees) {
        const d = demandes.find((x) => x.cle === p.cle)!
        expect(p.x - p.largeur / 2).toBeGreaterThanOrEqual(d.min - 0.001)
        expect(p.x + p.largeur / 2).toBeLessThanOrEqual(d.max + 0.001)
      }
    }
  })
})
