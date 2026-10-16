/**
 * L'arbre en 3D — où pousse chaque feuille, sans WebGL.
 *
 * Ce que cette suite tient : le lecteur est au sommet du tronc ; ses ancêtres
 * montent et s'écartent, ses descendants pendent sous l'île ; un étage peuplé
 * prend plusieurs anneaux sans qu'aucune feuille n'en recouvre une autre ; la
 * confidentialité du serveur est conservée (un nœud restreint reste anonyme,
 * sans portrait, introuvable par la recherche) ; la ligne directe est bien celle
 * du lecteur.
 *
 * Les noms de ce jeu d'essai sont inventés (ADR 0006).
 */
import { describe, expect, it } from 'vitest'
import type { AreteGraphe, Graphe, NoeudFamille, NoeudPersonne } from '@/lib/api'
import { ECART_MIN, HAUT_DU_TRONC, capacite, chercher, disposerArbre } from './disposition'

let x = 0
function p(id: string, generation: number, partiel: Partial<NoeudPersonne> = {}): NoeudPersonne {
  x += 160
  return {
    id,
    type: 'personne',
    personId: Number(id.replace(/\D/g, '')),
    x,
    y: generation * 180,
    w: 150,
    h: 60,
    displayName: `Prénom ${id} Essai`,
    etiquetteNom: `Prénom ${id} Essai`,
    sex: 'inconnu',
    generation,
    branch: 'tr',
    vivant: false,
    visibilite: 'complete',
    etiquetteDates: '1900 – 1980',
    ...partiel,
  }
}
function fam(id: string): NoeudFamille {
  return { id, type: 'famille', familyId: Number(id.replace(/\D/g, '')), divorced: false, x: 0, y: 0, w: 10, h: 10 }
}
const u = (de: string, vers: string): AreteGraphe => ({ de, vers, genre: 'union' })
const fi = (de: string, vers: string): AreteGraphe => ({ de, vers, genre: 'filiation' })

/** L'ego @I1@, ses parents, un grand-père, une sœur, un enfant, une cousine restreinte. */
function petitArbre(): Graphe {
  x = 0
  return {
    empreinte: 'e'.repeat(64),
    ego: '@I1@',
    boite: { x: 0, y: 0, w: 2000, h: 1000 },
    calque: {} as Graphe['calque'],
    noeuds: [
      p('@I4@', -2, { branch: 'pp' }),
      p('@I2@', -1, { branch: 'pp' }),
      p('@I3@', -1, { branch: 'mm', portrait: '/api/medias/9/vignette' }),
      p('@I1@', 0, { vivant: true }),
      p('@I5@', 0, { vivant: true }),
      p('@I7@', 0, { vivant: true, visibilite: 'restreinte', displayName: 'Personne vivante', etiquetteNom: 'Personne vivante', portrait: '/api/medias/1/vignette' }),
      p('@I6@', 1, { vivant: true, enAttente: true }),
      p('@I8@', -1, { parente: 'alliance', branch: 'pm' }),
      fam('@F1@'),
      fam('@F2@'),
      fam('@F3@'),
    ],
    aretes: [u('@I2@', '@F1@'), u('@I3@', '@F1@'), fi('@F1@', '@I1@'), fi('@F1@', '@I5@'), u('@I4@', '@F2@'), fi('@F2@', '@I2@'), u('@I1@', '@F3@'), fi('@F3@', '@I6@')],
  }
}

describe('disposerArbre', () => {
  const d = disposerArbre(petitArbre())
  const f = (id: string) => d.feuilles.find((x) => x.id === id)!

  it('met le lecteur au sommet du tronc, au centre', () => {
    expect(f('@I1@')).toMatchObject({ ego: true, position: [0, HAUT_DU_TRONC, 0], ligne: 'ascendant' })
  })

  it('fait monter et s’écarter les ancêtres, pendre les descendants sous l’île', () => {
    const [, yParent] = f('@I2@').position
    const [, yGrand] = f('@I4@').position
    expect(yParent).toBeGreaterThan(HAUT_DU_TRONC)
    expect(yGrand).toBeGreaterThan(yParent)
    expect(Math.hypot(f('@I4@').position[0], f('@I4@').position[2])).toBeGreaterThan(Math.hypot(f('@I2@').position[0], f('@I2@').position[2]))
    expect(f('@I6@').position[1]).toBeLessThan(0)
    expect(f('@I6@').ligne).toBe('descendant')
  })

  it('range la ligne directe : parents et grand-père oui, la sœur non', () => {
    expect(['@I2@', '@I3@', '@I4@'].map((id) => f(id).ligne)).toEqual(['ascendant', 'ascendant', 'ascendant'])
    expect(f('@I5@').ligne).toBeNull()
  })

  it('garde l’anonymat d’un nœud restreint : libellé neutre, aucun portrait, introuvable', () => {
    expect(f('@I7@')).toMatchObject({ restreinte: true, nom: 'Personne vivante', nomComplet: 'Personne vivante', portrait: null })
    expect(chercher(d.feuilles, 'Personne')).toEqual([])
    expect(chercher(d.feuilles, 'prenom @i5')).toHaveLength(1)
  })

  it('n’écrit pas les dates d’un vivant, garde celles d’un défunt', () => {
    expect(f('@I1@').dates).toBe('')
    expect(f('@I4@').dates).toBe('1900 – 1980')
  })

  it('colore une famille par alliance en « hors », quelle que soit sa branche', () => {
    expect(f('@I8@').teinte).toBe('hors')
    expect(f('@I2@').teinte).toBe('pp')
  })

  it('pose un bourgeon sur une fiche en attente, et le portrait d’une fiche complète', () => {
    expect(f('@I6@').enAttente).toBe(true)
    expect(f('@I3@').portrait).toBe('/api/medias/9/vignette')
  })

  it('relie chaque couple : un nœud par famille, un rameau par lien, la ligne directe en bois épais', () => {
    expect(d.noeuds).toHaveLength(3)
    expect(d.rameaux).toHaveLength(8)
    expect(d.rameaux.filter((r) => r.direct).length).toBeGreaterThanOrEqual(5)
    expect(d.parGeneration.get(0)).toBe(3)
  })
})

describe('un étage très peuplé', () => {
  it('prend plusieurs anneaux, sans qu’aucune feuille n’en touche une autre', () => {
    x = 0
    const n = 60
    const noeuds: NoeudPersonne[] = [p('@I1@', 0)]
    for (let i = 0; i < n; i++) noeuds.push(p(`@I${100 + i}@`, -1))
    const d = disposerArbre({ empreinte: '', ego: '@I1@', boite: { x: 0, y: 0, w: 1, h: 1 }, calque: {} as Graphe['calque'], noeuds, aretes: [] })
    const etage = d.feuilles.filter((f) => f.generation === -1)
    expect(n).toBeGreaterThan(capacite(3.55))
    let min = Infinity
    for (let i = 0; i < etage.length; i++)
      for (let j = i + 1; j < etage.length; j++) {
        const [a, b] = [etage[i]!.position, etage[j]!.position]
        min = Math.min(min, Math.hypot(a[0] - b[0], a[1] - b[1], a[2] - b[2]))
      }
    expect(min).toBeGreaterThanOrEqual(ECART_MIN * 0.99)
    expect(new Set(etage.map((f) => f.position[1])).size).toBeGreaterThan(1)
  })
})
