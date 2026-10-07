import { describe, expect, it } from 'vitest'
import type { AreteGraphe, Branche, Graphe, NoeudFamille, NoeudPersonne } from '@/lib/api'
import { quartsPresents, sansBranches } from './branches'

/**
 * Ce que ces tests protègent : la décision du 2026-10-07 — masquer une branche la RETIRE
 * de la disposition (l'arbre se refait sans elle), et le tronc reste : le lecteur, ses
 * parents, sa fratrie, ses descendants.
 */

function arbre(): { graphe: Graphe; id: Record<string, string> } {
  const noeuds: Graphe['noeuds'] = []
  const aretes: AreteGraphe[] = []
  const id: Record<string, string> = {}
  let n = 1
  const p = (cle: string, branch: Branche): string => {
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
      branch,
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

  const ego = p('ego', 'tr')
  f('fParents', [p('pere', 'tr'), p('mere', 'tr')], [ego, p('soeur', 'tr')])
  f('fEgo', [ego, p('conjoint', 'tr')], [p('fils', 'tr')])
  // Les parents du père : un de chaque quart.
  f('fGpPere', [p('gpPere', 'pp'), p('gmPere', 'pm')], [id['pere']!, p('oncle', 'pp')])
  f('fOncle', [id['oncle']!, p('tanteAlliee', 'pp')], [p('cousin', 'pp')])
  // Le père du grand-père : tout entier dans `pp`.
  f('fBisaieul', [p('bisaieul', 'pp')], [id['gpPere']!])
  // Les parents de la mère, dans `mp`.
  f('fGpMere', [p('gpMere', 'mp'), p('gmMere', 'mm')], [id['mere']!])

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

const ids = (g: Graphe): Set<string> => new Set(g.noeuds.map((n) => n.id))

describe('sansBranches', () => {
  const { graphe, id } = arbre()

  it('rend le graphe tel quel quand rien n’est masqué', () => {
    expect(sansBranches(graphe, [])).toBe(graphe)
  })

  it('retire les fiches du quart masqué, et ses foyers', () => {
    const reste = ids(sansBranches(graphe, ['pp']))
    for (const cle of ['gpPere', 'oncle', 'tanteAlliee', 'cousin', 'bisaieul', 'fOncle', 'fBisaieul'])
      expect(reste.has(id[cle]!), cle).toBe(false)
    // La grand-mère, d'un autre quart, garde son foyer avec le père.
    expect(reste.has(id['gmPere']!)).toBe(true)
    expect(reste.has(id['fGpPere']!)).toBe(true)
  })

  it('garde le tronc : le lecteur, ses parents, sa fratrie, ses descendants', () => {
    const reste = ids(sansBranches(graphe, ['pp', 'pm', 'mp', 'mm']))
    for (const cle of ['ego', 'pere', 'mere', 'soeur', 'conjoint', 'fils', 'fParents', 'fEgo'])
      expect(reste.has(id[cle]!), cle).toBe(true)
  })

  it('retire le foyer des grands-parents quand les deux époux sont masqués', () => {
    const g = sansBranches(graphe, ['pp', 'pm'])
    expect(ids(g).has(id['fGpPere']!)).toBe(false)
    expect(g.aretes.some((a) => a.de === id['fGpPere'] || a.vers === id['fGpPere'])).toBe(false)
  })

  it('ne laisse aucune arête vers un nœud retiré', () => {
    const g = sansBranches(graphe, ['pp', 'mm'])
    const reste = ids(g)
    for (const a of g.aretes) expect(reste.has(a.de) && reste.has(a.vers)).toBe(true)
  })
})

describe('quartsPresents', () => {
  it('liste les quarts portés par le graphe, sans le tronc', () => {
    const { graphe } = arbre()
    expect([...quartsPresents(graphe)].sort()).toEqual(['mm', 'mp', 'pm', 'pp'])
  })
})
