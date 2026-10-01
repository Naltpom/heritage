import { describe, expect, it } from 'vitest'
import type { Demande } from '@/lib/api'
import { ficheVisee, legendeDeLaFiche } from './fiche-de-la-demande'

function demandeDe(surcharge: Partial<Demande> = {}): Demande {
  return {
    id: 12,
    kind: 'modification_personne',
    status: 'en_attente',
    authorUserId: 17,
    authorLabel: 'Opale Bourrache',
    targetKind: null,
    targetId: null,
    targetLabel: null,
    message: null,
    decisionMessage: null,
    autoValidated: false,
    duplicateOfId: null,
    submittedAt: null,
    createdAt: '2026-09-16T10:56:18+00:00',
    ops: [],
    brouillons: [],
    liens: [],
    revisions: [],
    ...surcharge,
  }
}

const BROUILLON = {
  localRef: '#p1',
  noeudId: '#12/p1',
  givenNames: 'Céleste',
  surname: 'Sureau',
  sex: 'feminin' as const,
  deceased: true,
}

/**
 * Quelle fiche montre « Voir la fiche » — l'ordre de `ficheVisee()`, cas par cas.
 */
describe('ficheVisee()', () => {
  it('la cible, quand c’est une personne', () => {
    expect(ficheVisee(demandeDe({ targetKind: 'personne', targetId: 41 }))).toEqual({ personId: 41, raison: 'cible' })
  })

  it('la famille, quand c’est une famille — un époux sera lu', () => {
    expect(ficheVisee(demandeDe({ targetKind: 'famille', targetId: 8 }))).toEqual({ familleId: 8, raison: 'famille' })
  })

  it('une création validée : la personne créée', () => {
    expect(
      ficheVisee(
        demandeDe({
          kind: 'creation_personne',
          brouillons: [{ ...BROUILLON, resolvedPersonId: 77 }],
          liens: [{ seq: 1, kind: 'enfant_de', subjectDraftRef: '#p1', otherPersonId: 9 }],
        }),
      ),
    ).toEqual({ personId: 77, raison: 'creee' })
  })

  it('une création en attente : le parent ou le conjoint lié', () => {
    expect(
      ficheVisee(
        demandeDe({
          kind: 'creation_personne',
          brouillons: [{ ...BROUILLON, resolvedPersonId: null }],
          liens: [
            { seq: 1, kind: 'conjoint_de', subjectDraftRef: '#p1', otherDraftRef: '#p2' },
            { seq: 2, kind: 'conjoint_de', subjectDraftRef: '#p1', otherPersonId: 5 },
          ],
        }),
      ),
    ).toEqual({ personId: 5, raison: 'liee' })
  })

  it('rien quand rien n’existe encore, ni quand la cible est une citation', () => {
    expect(ficheVisee(demandeDe({ kind: 'creation_personne', brouillons: [{ ...BROUILLON, resolvedPersonId: null }] }))).toBeNull()
    expect(ficheVisee(demandeDe({ kind: 'modification_citation', targetKind: 'citation', targetId: 3 }))).toBeNull()
  })

  it('chaque raison a sa légende', () => {
    for (const raison of ['cible', 'famille', 'creee', 'liee'] as const) expect(legendeDeLaFiche(raison).length).toBeGreaterThan(0)
  })
})
