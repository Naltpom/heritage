import { describe, expect, it } from 'vitest'
import type { AreteGraphe, Graphe, NoeudFamille, NoeudPersonne } from '@/lib/api'
import { sansFamillesParAlliance } from './alliances'
import { effectifSansLien, estSansLien, noeudsSansLien, sansPersonnesSansLien } from './sansLien'

/**
 * Ce que ces tests protègent : la décision du 2026-10-04 — les personnes qu'aucun chemin
 * ne relie au lecteur sont masquées par défaut, et RETIRÉES de la disposition avec leurs
 * familles ; une famille par alliance, reliée par un mariage, n'en est pas.
 */

function arbre(): { graphe: Graphe; id: Record<string, string> } {
  const noeuds: Graphe['noeuds'] = []
  const aretes: AreteGraphe[] = []
  const id: Record<string, string> = {}
  let n = 1
  const p = (cle: string): string => {
    const noeud: NoeudPersonne = {
      id: `p${n}`,
      type: 'personne',
      personId: n++,
      x: 0,
      y: 0,
      w: 140,
      h: 56,
      displayName: cle,
      etiquetteNom: `${cle} Nom`,
      sex: 'inconnu',
      generation: 0,
      branch: 'tr',
      vivant: false,
      visibilite: 'complete',
    }
    noeuds.push(noeud)
    id[cle] = noeud.id
    return noeud.id
  }
  const f = (cle: string, epoux: string[], enfants: string[]): void => {
    const fam: NoeudFamille = { id: `f${n++}`, type: 'famille', familyId: n, x: 0, y: 0, w: 10, h: 10, divorced: false }
    noeuds.push(fam)
    id[cle] = fam.id
    for (const e of epoux) aretes.push({ de: e, vers: fam.id, genre: 'union' })
    for (const e of enfants) aretes.push({ de: fam.id, vers: e, genre: 'filiation' })
  }

  const ego = p('ego')
  f('fParents', [p('pere'), p('mere')], [ego, p('tante')])
  // La tante épouse un homme dont les parents sont connus : une famille par alliance.
  const mari = p('mari')
  f('fTante', [id['tante']!, mari], [])
  f('fMari', [p('mariPere')], [mari])
  // Un îlot : un couple et leur fille, reliés à rien.
  f('fIlot', [p('ilotPere'), p('ilotMere')], [p('ilotFille')])
  // Une fiche seule.
  p('seule')

  return {
    graphe: {
      empreinte: '0'.repeat(64),
      ego,
      boite: { x: 0, y: 0, w: 1, h: 1 },
      calque: { actif: false, demandes: [], proposes: 0, places: 0, nonPlaces: [] },
      noeuds,
      aretes,
    },
    id,
  }
}

describe('noeudsSansLien', () => {
  const { graphe, id } = arbre()
  const detaches = noeudsSansLien(graphe)

  it('retient les îlots, leurs familles comprises, et les fiches seules', () => {
    expect([...detaches].sort()).toEqual(
      ['ilotPere', 'ilotMere', 'ilotFille', 'fIlot', 'seule'].map((c) => id[c]!).sort(),
    )
  })

  it('ne retient ni la famille ni les familles par alliance, reliées par un mariage', () => {
    for (const cle of ['ego', 'pere', 'mere', 'tante', 'mari', 'mariPere', 'fParents', 'fTante', 'fMari'])
      expect(detaches.has(id[cle]!), cle).toBe(false)
  })

  it('compte les personnes, pas les familles', () => {
    expect(effectifSansLien(graphe)).toBe(4)
  })

  it('reconnaît une personne sans lien par son identifiant de fiche', () => {
    const personId = (cle: string) => (graphe.noeuds.find((n) => n.id === id[cle]) as NoeudPersonne).personId!
    expect(estSansLien(graphe, personId('ilotFille'))).toBe(true)
    expect(estSansLien(graphe, personId('mariPere'))).toBe(false)
    expect(estSansLien(graphe, 9999)).toBe(false)
  })

  it('ne retire rien quand l’ego est absent du graphe', () => {
    expect(noeudsSansLien({ ...graphe, ego: 'p404' }).size).toBe(0)
  })
})

describe('sansPersonnesSansLien', () => {
  const { graphe, id } = arbre()

  it('retire les nœuds et les liens qui les touchent', () => {
    const reste = sansPersonnesSansLien(graphe, noeudsSansLien(graphe))
    expect(reste.noeuds.some((n) => n.id === id['ilotFille'] || n.id === id['seule'])).toBe(false)
    expect(reste.aretes.some((a) => a.de === id['fIlot'] || a.vers === id['fIlot'])).toBe(false)
    expect(reste.noeuds.some((n) => n.id === id['mariPere'])).toBe(true)
  })

  it('s’applique sur un graphe déjà réduit par une autre bascule', () => {
    const retires = noeudsSansLien(graphe)
    const reste = sansPersonnesSansLien(sansFamillesParAlliance(graphe), retires)
    expect(reste.noeuds.map((n) => n.id)).not.toContain(id['ilotPere'])
    expect(reste.noeuds.map((n) => n.id)).toContain(id['ego'])
  })

  it('rend le même objet quand il n’y a rien à retirer', () => {
    const relie = sansPersonnesSansLien(graphe, noeudsSansLien(graphe))
    expect(sansPersonnesSansLien(relie, noeudsSansLien(graphe))).toBe(relie)
    expect(sansPersonnesSansLien(graphe, new Set())).toBe(graphe)
  })
})
