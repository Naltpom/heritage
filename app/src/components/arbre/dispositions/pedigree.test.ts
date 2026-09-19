import { describe, expect, it } from 'vitest'
import type { AreteGraphe, Graphe, NoeudFamille, NoeudGraphe, NoeudPersonne } from '@/lib/api'
import { ascendanceSeule } from './ascendanceSeule'
import { nombreDeParentsMax, pedigree } from './pedigree'

/**
 * L'éventail d'ascendance, sur un arbre où les places d'origine sont
 * **volontairement fausses** : tout le monde est posé au même x. Si l'éventail
 * lisait les positions d'entrée, rien ne bougerait ; s'il les déduit de l'arbre,
 * chaque couple se retrouve au-dessus de son enfant, le père à gauche.
 *
 * ```
 *        f2 (p5 × p6)        f4 (p10 × p11)
 *             │                    │
 *            p2 ──── f1 ──────── p3
 *                     │
 *              ┌──────┴──────┐
 *           p1 (ego)        p4          f3 (p1 × p7) → p8
 * ```
 */
function personne(id: string, generation: number, sex: NoeudPersonne['sex'] = 'masculin'): NoeudPersonne {
  return {
    id,
    type: 'personne',
    personId: Number(id.slice(1)),
    displayName: id,
    etiquetteNom: id,
    x: 0,
    y: generation * -150,
    w: 120,
    h: 40,
    sex,
    generation,
    branch: null,
    vivant: false,
    visibilite: 'complete',
    etiquetteDates: '—',
    // Une fixture : seuls `id`, `type`, `sex`, `x`, `y`, `w` comptent pour
    // l'éventail. Le reste est là pour la forme, d'où le passage par `unknown`.
  } as unknown as NoeudPersonne
}

function famille(id: string, generation: number): NoeudFamille {
  return {
    id,
    type: 'famille',
    familyId: Number(id.slice(1)),
    x: 0,
    y: generation * -150 + 46,
    w: 22,
    h: 14,
    divorced: false,
  } as unknown as NoeudFamille
}

function grapheEssai(): Graphe {
  const noeuds: NoeudGraphe[] = [
    personne('p1', 0),
    personne('p4', 0),
    personne('p7', 0, 'feminin'),
    personne('p8', -1),
    personne('p2', 1),
    personne('p3', 1, 'feminin'),
    personne('p5', 2),
    personne('p6', 2, 'feminin'),
    personne('p10', 2),
    personne('p11', 2, 'feminin'),
    famille('f1', 1),
    famille('f2', 2),
    famille('f3', 0),
    famille('f4', 2),
  ]
  const aretes: AreteGraphe[] = [
    { de: 'p2', vers: 'f1', genre: 'union', divorce: false },
    { de: 'p3', vers: 'f1', genre: 'union', divorce: false },
    { de: 'f1', vers: 'p1', genre: 'filiation' },
    { de: 'f1', vers: 'p4', genre: 'filiation' },
    { de: 'p5', vers: 'f2', genre: 'union', divorce: false },
    { de: 'p6', vers: 'f2', genre: 'union', divorce: false },
    { de: 'f2', vers: 'p2', genre: 'filiation' },
    { de: 'p10', vers: 'f4', genre: 'union', divorce: false },
    { de: 'p11', vers: 'f4', genre: 'union', divorce: false },
    { de: 'f4', vers: 'p3', genre: 'filiation' },
    { de: 'p1', vers: 'f3', genre: 'union', divorce: false },
    { de: 'p7', vers: 'f3', genre: 'union', divorce: false },
    { de: 'f3', vers: 'p8', genre: 'filiation' },
  ]
  return {
    empreinte: '0'.repeat(64),
    ego: 'p1',
    boite: { x: -1000, y: -400, w: 2000, h: 800 },
    calque: { actif: false, demandes: [], proposes: 0, places: 0, nonPlaces: [] },
    noeuds,
    aretes,
  }
}

function x(graphe: Graphe, id: string): number {
  const noeud = graphe.noeuds.find((n) => n.id === id)
  if (noeud === undefined) throw new Error(`${id} absent`)
  return noeud.x
}

describe('pedigree', () => {
  const eventail = pedigree(ascendanceSeule(grapheEssai()))

  it('ne garde que les ancêtres : deux parents au plus, et ni frère ni descendance', () => {
    expect(nombreDeParentsMax(eventail.aretes)).toBe(2)
    const ids = eventail.noeuds.map((n) => n.id)
    expect(ids).not.toContain('p4')
    expect(ids).not.toContain('p7')
    expect(ids).not.toContain('p8')
    expect(ids).not.toContain('f3')
  })

  it('met le père à gauche du lecteur et la mère à droite, symétriquement', () => {
    expect(x(eventail, 'p1')).toBe(0)
    expect(x(eventail, 'p2')).toBeLessThan(0)
    expect(x(eventail, 'p3')).toBeGreaterThan(0)
    expect(x(eventail, 'p2')).toBeCloseTo(-x(eventail, 'p3'), 6)
  })

  it('pose chaque couple exactement au-dessus de son enfant', () => {
    expect(x(eventail, 'f1')).toBeCloseTo(x(eventail, 'p1'), 6)
    expect(x(eventail, 'f2')).toBeCloseTo(x(eventail, 'p2'), 6)
    expect(x(eventail, 'f4')).toBeCloseTo(x(eventail, 'p3'), 6)
  })

  it('range la génération la plus haute sur des créneaux contigus, sans chevauchement', () => {
    const haut = ['p5', 'p6', 'p10', 'p11'].map((id) => x(eventail, id)).sort((a, b) => a - b)
    for (let i = 1; i < haut.length; i++) {
      // 120 de fiche + 20 d'espace : ni serrés, ni éparpillés.
      expect(haut[i] - haut[i - 1]).toBeCloseTo(140, 6)
    }
  })

  it('ne touche pas aux ordonnées : la génération est une donnée', () => {
    const origine = grapheEssai()
    for (const noeud of eventail.noeuds) {
      const avant = origine.noeuds.find((n) => n.id === noeud.id)
      expect(noeud.y).toBe(avant?.y)
    }
  })

  it('rend le graphe intact quand le lecteur en est absent', () => {
    const sansEgo = { ...grapheEssai(), ego: 'inconnu' }
    expect(pedigree(sansEgo)).toBe(sansEgo)
  })
})
