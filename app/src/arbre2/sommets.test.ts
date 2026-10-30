import { describe, expect, it } from 'vitest'
import type { AreteGraphe, NoeudGraphe, NoeudPersonne } from '@/lib/api'
import { sommetDe, sommetsDuGraphe } from './sommets'

/**
 * Les trois états au sommet d'une branche (ADR 0023 § 4), tels que `/arbre` les lit sur le
 * graphe : parents connus, recherche close, pas encore cherché — côté par côté.
 */
const REGISTRES = { reason: 'registres_inexistants', beforeYear: 1668 } as const
const DETRUITS = { reason: 'registres_detruits', beforeYear: null } as const

function personne(id: string, sex: NoeudPersonne['sex'], plus: Partial<NoeudPersonne> = {}): NoeudGraphe {
  return {
    id,
    type: 'personne',
    personId: Number(id.slice(1)),
    x: 0,
    y: 0,
    w: 100,
    h: 40,
    displayName: id,
    etiquetteNom: id,
    sex,
    generation: 0,
    branch: 'pp',
    vivant: false,
    visibilite: 'complete',
    ...plus,
  }
}
const famille = (id: string): NoeudGraphe => ({ id, type: 'famille', familyId: Number(id.slice(1)), x: 0, y: 0, w: 4, h: 4, divorced: false })
const union = (de: string, vers: string, plus: Partial<AreteGraphe> = {}): AreteGraphe => ({ de, vers, genre: 'union', ...plus })
const filiation = (de: string, vers: string, plus: Partial<AreteGraphe> = {}): AreteGraphe => ({ de, vers, genre: 'filiation', ...plus })

describe('les sommets du graphe', () => {
  it('un ancêtre sans parent ni fin de recherche : pas encore cherché, pas de mur', () => {
    const s = sommetsDuGraphe([personne('p1', 'masculin')], []).get('p1')!
    expect(s.etats).toEqual({ pere: 'a-chercher', mere: 'a-chercher' })
    expect(s.close).toBe(false)
    expect(s.infobulle).toBeNull()
  })

  it('un ancêtre sans père et avec une fin côté père : clos, avec l’infobulle du motif', () => {
    const s = sommetsDuGraphe([personne('p1', 'masculin', { finDeRecherchePere: REGISTRES })], []).get('p1')!
    expect(s.etats).toEqual({ pere: 'close', mere: 'a-chercher' })
    expect(s.close).toBe(true)
    expect(s.infobulle).toBe('Recherche close côté père : Registres inexistants avant 1668')
  })

  it('un père connu l’emporte sur une fin de recherche restée enregistrée', () => {
    const noeuds = [personne('p1', 'masculin'), personne('p3', 'masculin', { finDeRecherchePere: REGISTRES }), famille('f10')]
    const s = sommetsDuGraphe(noeuds, [union('p1', 'f10'), filiation('f10', 'p3')]).get('p3')!
    expect(s.etats.pere).toBe('connus')
    expect(s.close).toBe(false)
    expect(s.infobulle).toBeNull()
  })

  it('les deux côtés peuvent être clos : une ligne par côté', () => {
    const s = sommetsDuGraphe([personne('p1', 'feminin', { finDeRecherchePere: REGISTRES, finDeRechercheMere: DETRUITS })], []).get('p1')!
    expect(s.etats).toEqual({ pere: 'close', mere: 'close' })
    expect(s.infobulle?.split('\n')).toHaveLength(2)
  })

  it('un nœud restreint n’est jamais clos : il n’a pas de sommet', () => {
    const restreint = personne('p1', 'masculin', { visibilite: 'restreinte', finDeRecherchePere: REGISTRES })
    expect(sommetsDuGraphe([restreint], []).has('p1')).toBe(false)
  })

  it('un parent proposé (en attente) fait « connus » : la branche continue', () => {
    const noeuds = [personne('p1', 'masculin', { enAttente: true }), personne('p3', 'masculin', { finDeRecherchePere: REGISTRES }), famille('f10')]
    const s = sommetsDuGraphe(noeuds, [union('p1', 'f10', { enAttente: true }), filiation('f10', 'p3', { enAttente: true })]).get('p3')!
    expect(s.etats.pere).toBe('connus')
    expect(s.close).toBe(false)
  })

  it('cumule deux familles de parents : le père d’une, la mère de l’autre', () => {
    const noeuds = [
      personne('p1', 'masculin'),
      personne('p2', 'feminin'),
      personne('p3', 'masculin', { finDeRecherchePere: REGISTRES, finDeRechercheMere: DETRUITS }),
      famille('f10'),
      famille('f11'),
    ]
    const s = sommetsDuGraphe(noeuds, [union('p1', 'f10'), filiation('f10', 'p3'), union('p2', 'f11'), filiation('f11', 'p3')]).get('p3')!
    expect(s.etats).toEqual({ pere: 'connus', mere: 'connus' })
    expect(s.close).toBe(false)
  })

  it('lit les parents sur tout le graphe, même ceux qu’aucune carte ne dessine', () => {
    // Le sommet ne dépend que des arêtes reçues : ici le père n'a aucune autre place dans le graphe.
    const noeuds = [personne('p1', 'masculin'), personne('p3', 'feminin', { finDeRecherchePere: REGISTRES }), famille('f10')]
    expect(sommetsDuGraphe(noeuds, [union('p1', 'f10'), filiation('f10', 'p3')]).get('p3')?.close).toBe(false)
  })

  it('sommetDe : un côté connu, l’autre clos', () => {
    const s = sommetDe({ pere: true, mere: false }, { finDeRecherchePere: REGISTRES, finDeRechercheMere: DETRUITS })
    expect(s.etats).toEqual({ pere: 'connus', mere: 'close' })
    expect(s.close).toBe(true)
  })
})
