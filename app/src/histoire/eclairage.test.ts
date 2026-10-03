import { describe, expect, it } from 'vitest'
import type { AreteGraphe, Graphe, NoeudFamille, NoeudPersonne, Sexe } from '@/lib/api'
import { basculerFamille, familleDuClic, familleEclairee } from './eclairage'
import { disposerLignees } from './lignees'

/**
 * La surbrillance d'une famille au clic sur l'un de ses traits : chaque famille dessinée
 * porte ses propres segments et les fiches qu'ils relient, et le dessin ordinaire n'en
 * est pas changé.
 */

function graphe(): { graphe: Graphe; id: Record<string, string> } {
  let n = 1
  const noeuds: Graphe['noeuds'] = []
  const aretes: AreteGraphe[] = []
  const id: Record<string, string> = {}
  const personne = (cle: string, sex: Sexe, annee: number): string => {
    const p: NoeudPersonne = {
      id: `p${n}`,
      type: 'personne',
      personId: n++,
      x: 0,
      y: 0,
      w: 150,
      h: 56,
      displayName: cle,
      etiquetteNom: `${cle} Nom`,
      sex,
      generation: 0,
      branch: 'tr',
      vivant: false,
      visibilite: 'complete',
      etiquetteDates: `${annee} – `,
    }
    noeuds.push(p)
    id[cle] = p.id
    return p.id
  }
  const famille = (cle: string, epoux: string[], enfants: string[]): void => {
    const f: NoeudFamille = { id: `f${n++}`, type: 'famille', familyId: n, x: 0, y: 0, w: 10, h: 10, divorced: false }
    noeuds.push(f)
    id[cle] = f.id
    for (const e of epoux) aretes.push({ de: e, vers: f.id, genre: 'union' })
    for (const e of enfants) aretes.push({ de: f.id, vers: e, genre: 'filiation' })
  }

  const ego = personne('ego', 'masculin', 1998)
  const soeur = personne('soeur', 'feminin', 2001)
  const pere = personne('pere', 'masculin', 1969)
  const mere = personne('mere', 'feminin', 1971)
  famille('parents', [pere, mere], [ego, soeur])
  const gp = personne('gp', 'masculin', 1946)
  const gm = personne('gm', 'feminin', 1949)
  famille('grandsParents', [gp, gm], [pere])

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

describe('les traits d’une famille', () => {
  const { graphe: g, id } = graphe()
  const d = disposerLignees(g)

  it('chaque famille dessinée porte ses traits et ses fiches : époux puis enfants', () => {
    const parents = familleEclairee(d.traces.familles, id.parents!)
    expect(parents).not.toBeNull()
    expect([...parents!.personnes].sort()).toEqual([id.pere, id.mere, id.ego, id.soeur].sort())
    expect(parents!.d).toMatch(/^M/)

    const grandsParents = familleEclairee(d.traces.familles, id.grandsParents!)
    expect([...grandsParents!.personnes].sort()).toEqual([id.gp, id.gm, id.pere].sort())
  })

  it('les traits des familles sont exactement ceux du dessin ordinaire, regroupés', () => {
    const { ordinaires, directs, divorces, attente } = d.traces
    const segments = (s: string): string[] => (s.match(/M[^M]+/g) ?? []).sort()
    expect(segments(d.traces.familles.map((f) => f.d).join(''))).toEqual(segments(ordinaires + directs + divorces + attente))
  })

  it('une famille qui n’existe plus après une remise en page n’est plus éclairée', () => {
    expect(familleEclairee(d.traces.familles, 'f-disparue')).toBeNull()
    expect(familleEclairee(d.traces.familles, null)).toBeNull()
  })
})

describe('la bascule', () => {
  it('un clic allume, un second clic sur la même famille éteint, une autre famille prend la place', () => {
    expect(basculerFamille(null, 'f1')).toBe('f1')
    expect(basculerFamille('f1', 'f1')).toBeNull()
    expect(basculerFamille('f1', 'f2')).toBe('f2')
    expect(basculerFamille('f1', null)).toBeNull()
  })

  it('seul un trait de famille désigne une famille', () => {
    const trait = { getAttribute: (nom: string) => (nom === 'data-famille' ? 'f7' : null) } as unknown as EventTarget
    const fond = { getAttribute: () => null } as unknown as EventTarget
    expect(familleDuClic(trait)).toBe('f7')
    expect(familleDuClic(fond)).toBeNull()
    expect(familleDuClic(null)).toBeNull()
  })
})
