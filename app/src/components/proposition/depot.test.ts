import { describe, expect, it } from 'vitest'
import type { BrouillonDemande, Demande } from '@/lib/api'
import { personneDeposee } from './depot'

/**
 * Ce que le formulaire annonce après un dépôt. La propriété qui compte : le nœud
 * de la personne proposée est **lu** dans la réponse, jamais recollé à partir du
 * numéro de la demande — un identifiant de nœud est opaque (`lib/api/types.ts`).
 */

function brouillon(champs: Partial<BrouillonDemande> = {}): BrouillonDemande {
  return {
    localRef: '#p1',
    noeudId: '#42/p1',
    givenNames: 'Marie Louise',
    surname: 'Dupont',
    sex: 'feminin',
    deceased: true,
    resolvedPersonId: null,
    ...champs,
  }
}

function demande(champs: Partial<Demande> = {}): Demande {
  return {
    id: 42,
    kind: 'creation_personne',
    status: 'en_attente',
    authorLabel: 'Opale Bourrache',
    targetKind: null,
    targetId: null,
    targetLabel: null,
    message: null,
    decisionMessage: null,
    autoValidated: false,
    duplicateOfId: null,
    submittedAt: '2026-10-09T10:00:00+00:00',
    createdAt: '2026-10-09T10:00:00+00:00',
    ops: [],
    brouillons: [brouillon()],
    liens: [],
    revisions: [],
    ...champs,
  }
}

describe('personneDeposee', () => {
  it('lit le nœud dans la réponse, tel quel', () => {
    // Un nœud que la concaténation `#<id>/p1` ne donnerait pas : s'il ressort, c'est qu'il a été lu.
    const depot = personneDeposee(demande({ brouillons: [brouillon({ noeudId: '#42/p7' })] }))

    expect(depot).toEqual({
      nature: 'creation',
      demandeIds: [42],
      noeudId: '#42/p7',
      personId: null,
      libelle: 'Marie Louise Dupont',
      soumise: true,
    })
  })

  it('ne fabrique pas de nœud quand la réponse n’en porte pas', () => {
    const depot = personneDeposee(demande({ brouillons: [] }))

    expect(depot.noeudId).toBeNull()
    expect(depot.libelle).toBe('La personne proposée')
  })

  it('dit qu’un brouillon n’est pas parti, d’après le statut rendu', () => {
    expect(personneDeposee(demande({ status: 'brouillon', submittedAt: null })).soumise).toBe(false)
  })

  it('rend la personne de l’arbre quand la demande l’a déjà créée', () => {
    const depot = personneDeposee(demande({ status: 'validee', brouillons: [brouillon({ resolvedPersonId: 318 })] }))

    expect(depot.personId).toBe(318)
    expect(depot.soumise).toBe(true)
  })

  it('compose le libellé des seuls morceaux connus', () => {
    expect(personneDeposee(demande({ brouillons: [brouillon({ givenNames: null, surname: ' Dupont ' })] })).libelle).toBe(
      'Dupont',
    )
  })
})
