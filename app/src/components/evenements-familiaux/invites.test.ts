/**
 * Le choix des invités, sans navigateur.
 *
 * Le premier cas est le plus important : **une fiche restreinte n'est jamais
 * proposée** — ni sous son nom, ni masquée. Le graphe la porte (en nœud
 * « Personne vivante ») ; l'écran ne doit pas en faire une ligne à cocher.
 */
import { describe, expect, it } from 'vitest'
import type { Graphe, NoeudGraphe, NoeudPersonne } from '@/lib/api'
import {
  basculer,
  filtrerParNom,
  groupesDisponibles,
  invitablesDuGraphe,
  libelleDuGroupe,
  normaliser,
  toutCoche,
  type Invitable,
} from './invites'

function personne(personId: number | null, displayName: string, partiel: Partial<NoeudPersonne> = {}): NoeudPersonne {
  return {
    type: 'personne',
    id: `p${personId ?? 'x'}`,
    x: 0,
    y: 0,
    w: 10,
    h: 10,
    personId,
    displayName,
    etiquetteNom: displayName,
    sex: 'inconnu',
    generation: 0,
    branch: 'tr',
    vivant: true,
    visibilite: 'complete',
    ...partiel,
  } as NoeudPersonne
}

function graphe(noeuds: NoeudGraphe[]): Pick<Graphe, 'noeuds'> {
  return { noeuds }
}

describe('qui peut être invité', () => {
  it('les vivants dont la fiche est complète, et eux seuls', () => {
    const invitables = invitablesDuGraphe(graphe([
      personne(1, 'Cobalt Romarin', { branch: 'pp' }),
      personne(2, 'Personne vivante', { visibilite: 'restreinte' }),
      personne(3, 'Marne Sureau', { vivant: false }),
      personne(null, 'Fiche proposée', { enAttente: true }),
      personne(4, 'Fiche en attente', { enAttente: true }),
      personne(5, 'Ambre Chardon', { branch: 'mp' }),
      { type: 'famille', id: 'f1', x: 0, y: 0, w: 1, h: 1, familyId: 1, divorced: false },
    ]))

    expect(invitables.map((i) => i.personId)).toEqual([5, 1])
    expect(JSON.stringify(invitables)).not.toContain('Personne vivante')
  })

  it('une personne entrée par alliance forme son propre groupe, quelle que soit sa branche', () => {
    const [i] = invitablesDuGraphe(graphe([personne(7, 'Hêtre Provost', { branch: 'mm', parente: 'alliance' })]))
    expect(i?.groupe).toBe('alliance')
    expect(libelleDuGroupe('alliance')).toBe('Par alliance')
    expect(libelleDuGroupe('pp')).toBe('Romarin')
  })

  it('ne propose pas deux fois la même personne', () => {
    expect(invitablesDuGraphe(graphe([personne(1, 'Cobalt'), personne(1, 'Cobalt')]))).toHaveLength(1)
  })
})

describe('les raccourcis par branche', () => {
  it('dans l’ordre de la légende, avec leur effectif', () => {
    const invitables: Invitable[] = [
      { personId: 1, libelle: 'A', groupe: 'tr' },
      { personId: 2, libelle: 'B', groupe: 'alliance' },
      { personId: 3, libelle: 'C', groupe: 'pp' },
      { personId: 4, libelle: 'D', groupe: 'tr' },
    ]
    expect(groupesDisponibles(invitables)).toEqual([
      { cle: 'pp', libelle: 'Romarin', nombre: 1 },
      { cle: 'tr', libelle: 'Tronc', nombre: 2 },
      { cle: 'alliance', libelle: 'Par alliance', nombre: 1 },
    ])
  })
})

describe('la recherche par nom', () => {
  const invitables = [
    { personId: 1, libelle: 'Élise Romarin' },
    { personId: 2, libelle: 'Cobalt Romarin' },
    { personId: 3, libelle: 'Élise Tilleul' },
  ]

  it('ignore les accents, la casse et l’ordre des mots', () => {
    expect(normaliser('  Élise ')).toBe('elise')
    expect(filtrerParNom(invitables, 'elise').map((i) => i.personId)).toEqual([1, 3])
    expect(filtrerParNom(invitables, 'romarin ELISE').map((i) => i.personId)).toEqual([1])
    expect(filtrerParNom(invitables, '   ')).toHaveLength(3)
  })
})

describe('cocher par lots', () => {
  it('coche et décoche un lot sans toucher au reste', () => {
    const depart = new Set([1, 9])
    const coches = basculer(depart, [1, 2, 3], true)
    expect([...coches].sort()).toEqual([1, 2, 3, 9])
    expect([...depart]).toEqual([1, 9])
    expect([...basculer(coches, [2, 3], false)].sort()).toEqual([1, 9])
  })

  it('sait si tout un lot est déjà coché — un lot vide ne l’est pas', () => {
    expect(toutCoche(new Set([1, 2]), [1, 2])).toBe(true)
    expect(toutCoche(new Set([1]), [1, 2])).toBe(false)
    expect(toutCoche(new Set([1]), [])).toBe(false)
  })
})
