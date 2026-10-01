import { describe, expect, it, vi } from 'vitest'

vi.mock('@/composables/useSession', () => ({ useSession: () => ({}), rafraichirSession: () => Promise.resolve() }))

import { STATUTS_RETIRABLES, comptesApresRetrait, confirmationDeRetrait, peutRetirer } from './retrait'

/**
 * Qui peut annuler, et quand — **la règle du serveur, recopiée** :
 * `DemandeVoter::MODIFIER` (l'auteur seulement, administrateur compris) et
 * `RequestStatus::estDefinitif()` (brouillon et en attente seulement).
 */
describe('peutRetirer()', () => {
  it('l’auteur, sur un brouillon ou une demande en attente', () => {
    expect(STATUTS_RETIRABLES).toEqual(['brouillon', 'en_attente'])
    expect(peutRetirer({ id: 1, status: 'en_attente', authorUserId: 17 }, 17)).toBe(true)
    expect(peutRetirer({ id: 1, status: 'brouillon', authorUserId: 17 }, 17)).toBe(true)
  })

  it('jamais sur une demande tranchée', () => {
    for (const status of ['validee', 'refusee', 'caduque', 'annulee'] as const) {
      expect(peutRetirer({ id: 1, status, authorUserId: 17 }, 17)).toBe(false)
    }
  })

  it('jamais sur la demande d’un autre, ni quand on ne sait pas qui l’a écrite', () => {
    expect(peutRetirer({ id: 1, status: 'en_attente', authorUserId: 18 }, 17)).toBe(false)
    expect(peutRetirer({ id: 1, status: 'en_attente' }, 17)).toBe(false)
    expect(peutRetirer({ id: 1, status: 'en_attente', authorUserId: 17 }, null)).toBe(false)
  })
})

describe('confirmationDeRetrait()', () => {
  it('nomme la demande, dit qu’elle reste consultable, et agit par un verbe', () => {
    const question = confirmationDeRetrait({ id: 12, status: 'en_attente' })
    expect(question.titre).toBe('Annuler la demande n° 12 ?')
    expect(question.message).toMatch(/restera visible/)
    expect(question.confirmer).toBe('Annuler ma demande')
    expect(question.danger).toBe(true)
  })

  it('sous incarnation, dit au nom de qui le geste s’écrit', () => {
    expect(confirmationDeRetrait({ id: 12, status: 'en_attente' }, 'Natron').message).toContain(
      'enregistré au nom de Natron',
    )
  })
})

describe('comptesApresRetrait()', () => {
  it('déplace une demande de son statut vers « annulée », sur les seuls comptes connus', () => {
    expect(comptesApresRetrait({ '': 5, en_attente: 2, annulee: 1 }, 'en_attente')).toEqual({
      '': 5,
      en_attente: 1,
      annulee: 2,
    })
    // Inconnu reste inconnu : on n'invente pas de zéro.
    expect(comptesApresRetrait({ '': 5 }, 'en_attente')).toEqual({ '': 5 })
  })
})
