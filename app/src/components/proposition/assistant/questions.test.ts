import { describe, expect, it } from 'vitest'
import { ErreurApi } from '@/lib/api/erreurs'
import {
  QUESTION_MAX,
  dateCourte,
  libelleDeFiche,
  mentionQuotaQuestions,
  morceaux,
  paragraphes,
  peutPoser,
  titreErreurQuestion,
} from './questions'

/**
 * « Poser une question », la logique : le découpage d'une réponse en texte et en
 * liens, la garde de la question, les titres d'erreur.
 */

describe('le découpage d’une réponse', () => {
  it('fait de chaque mention un lien vers la fiche, et garde le texte autour', () => {
    expect(morceaux('Fille de [Jean Becquelin](personne:12) et de [Rose Lenoir](personne:57).')).toEqual([
      { type: 'texte', texte: 'Fille de ' },
      { type: 'fiche', personId: 12, libelle: 'Jean Becquelin' },
      { type: 'texte', texte: ' et de ' },
      { type: 'fiche', personId: 57, libelle: 'Rose Lenoir' },
      { type: 'texte', texte: '.' },
    ])
  })

  it('laisse tel quel ce qui n’est pas une mention de fiche', () => {
    expect(morceaux('Voir [ce site](https://exemple.org) ou <b>ceci</b>.')).toEqual([
      { type: 'texte', texte: 'Voir [ce site](https://exemple.org) ou <b>ceci</b>.' },
    ])
  })

  it('sépare les paragraphes et reconnaît les puces', () => {
    const resultat = paragraphes('Trois personnes :\n\n- [Marne](personne:17), en 1887\n* Pivoine\n\nC’est tout.')
    expect(resultat.map((p) => p.puce)).toEqual([false, true, true, false])
    expect(resultat[1]?.morceaux[0]).toEqual({ type: 'fiche', personId: 17, libelle: 'Marne' })
    expect(resultat[2]?.morceaux).toEqual([{ type: 'texte', texte: 'Pivoine' }])
  })
})

describe('la question', () => {
  it('ne part ni vide ni trop longue', () => {
    expect(peutPoser('   ')).toBe(false)
    expect(peutPoser('Qui est né à Étaples ?')).toBe(true)
    expect(peutPoser('a'.repeat(QUESTION_MAX))).toBe(true)
    expect(peutPoser('a'.repeat(QUESTION_MAX + 1))).toBe(false)
  })

  it('dit le quota du jour', () => {
    expect(mentionQuotaQuestions(null)).toBe('')
    expect(mentionQuotaQuestions(1)).toBe('Encore 1 question possible aujourd’hui')
    expect(mentionQuotaQuestions(12)).toBe('Encore 12 questions possibles aujourd’hui')
    expect(mentionQuotaQuestions(0)).toBe('Plus aucune question possible aujourd’hui')
  })
})

describe('les erreurs', () => {
  it('distingue l’assistant indisponible, le modèle en échec et le quota', () => {
    expect(titreErreurQuestion(new ErreurApi(503, 'assistant_indisponible', 'Pas de clé.'))).toBe('Assistant indisponible')
    expect(titreErreurQuestion(new ErreurApi(502, 'assistant_erreur', 'Échec.'))).toBe('L’assistant n’a pas pu répondre')
    expect(titreErreurQuestion(new ErreurApi(429, 'trop_de_tentatives', 'Demain.'))).toBe('Quota du jour atteint')
    expect(titreErreurQuestion(new ErreurApi(422, 'donnees_invalides', 'Vide.'))).toBeNull()
  })
})

describe('les fiches citées', () => {
  it('n’affiche jamais les dates d’une personne restreinte', () => {
    expect(libelleDeFiche({ personId: 18, displayName: 'Personne vivante', ligneDates: '1990 –', visibilite: 'restreinte' })).toBe('Personne vivante')
    expect(libelleDeFiche({ personId: 17, displayName: 'Marne Sureau', ligneDates: '1887 – 1950', visibilite: 'complete' })).toBe('Marne Sureau (1887 – 1950)')
  })

  it('date une conversation sans répéter l’année courante', () => {
    const maintenant = new Date('2026-10-01T18:00:00')
    expect(dateCourte('2026-10-01T14:05:00', maintenant)).toMatch(/^aujourd’hui, 14:05$/)
    expect(dateCourte('2026-09-12T09:00:00', maintenant)).not.toContain('2026')
    expect(dateCourte('2025-09-12T09:00:00', maintenant)).toContain('2025')
    expect(dateCourte('pas une date', maintenant)).toBe('')
  })
})
