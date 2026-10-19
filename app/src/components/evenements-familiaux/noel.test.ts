/**
 * Ce que disent les cartes d'un tirage de Noël, sans navigateur : les dates
 * lues au bon jour, le compte à rebours qui compte, où j'en suis, et l'adresse
 * sur le site de Noël.
 */
import { describe, expect, it } from 'vitest'
import {
  CREER_SUR_LE_SITE_DE_NOEL,
  aUnReceveur,
  adresseSurLeSiteDeNoel,
  budgetEnEuros,
  compteARebours,
  estPasse,
  etatDeMaReponse,
  lienLisible,
  lireInstant,
  participants,
} from './noel'

const AUJOURDHUI = new Date(2026, 9, 19, 9) // lundi 19 octobre 2026

describe('les dates d’un tirage de Noël', () => {
  it('lit un instant ISO au jour local, à midi', () => {
    const date = lireInstant('2026-12-24T00:00:00+01:00')
    expect(date && [date.getFullYear(), date.getMonth() + 1, date.getDate(), date.getHours()]).toEqual([2026, 12, 24, 12])
    expect(lireInstant('pas une date')).toBeNull()
  })

  it('est passé le lendemain de la remise, pas le jour même', () => {
    expect(estPasse({ dateEvenement: '2026-10-19T00:00:00+02:00' }, AUJOURDHUI)).toBe(false)
    expect(estPasse({ dateEvenement: '2026-10-18T00:00:00+02:00' }, AUJOURDHUI)).toBe(true)
  })

  it('compte jusqu’au tirage tant qu’il est ouvert, puis jusqu’à la remise', () => {
    const dates = { dateTirage: '2026-10-31T00:00:00+01:00', dateEvenement: '2026-12-24T00:00:00+01:00' }
    expect(compteARebours({ ...dates, statut: 'ouvert' }, AUJOURDHUI)).toBe('Tirage dans 12 jours')
    expect(compteARebours({ ...dates, dateTirage: '2026-10-20T00:00:00+02:00', statut: 'ouvert' }, AUJOURDHUI)).toBe('Tirage demain')
    expect(compteARebours({ ...dates, dateTirage: '2026-10-10T00:00:00+02:00', statut: 'ouvert' }, AUJOURDHUI)).toBe('Tirage imminent')
    expect(compteARebours({ ...dates, statut: 'tire' }, AUJOURDHUI)).toBe('Tirage fait · cadeaux dans 66 jours')
    expect(compteARebours({ ...dates, dateEvenement: '2026-10-01T00:00:00+02:00', statut: 'tire' }, AUJOURDHUI)).toBe('Cadeaux remis')
  })
})

describe('où j’en suis', () => {
  it('distingue participer, ne pas avoir répondu, et refuser', () => {
    expect(etatDeMaReponse({ statut: 'ouvert', reponse: 'oui' })).toBe('participe')
    expect(etatDeMaReponse({ statut: 'ouvert', reponse: null })).toBe('a_repondre')
    expect(etatDeMaReponse({ statut: 'ouvert', reponse: 'non' })).toBe('ne_participe_pas')
    // Tiré sans réponse : trop tard pour répondre.
    expect(etatDeMaReponse({ statut: 'tire', reponse: null })).toBe('hors_tirage')
  })

  it('n’a un receveur qu’une fois tiré, et seulement pour qui participe', () => {
    expect(aUnReceveur({ statut: 'tire', reponse: 'oui' })).toBe(true)
    expect(aUnReceveur({ statut: 'ouvert', reponse: 'oui' })).toBe(false)
    expect(aUnReceveur({ statut: 'tire', reponse: 'non' })).toBe(false)
  })
})

describe('les mots de la carte', () => {
  it('mène au site de Noël', () => {
    expect(adresseSurLeSiteDeNoel('AB12')).toBe('https://noel2026.naltpom.fr/t/AB12')
    expect(adresseSurLeSiteDeNoel('a b')).toBe('https://noel2026.naltpom.fr/t/a%20b')
    expect(CREER_SUR_LE_SITE_DE_NOEL).toBe('https://noel2026.naltpom.fr/creer')
  })

  it('dit le budget, le nombre et le lien', () => {
    expect(budgetEnEuros(30)).toBe('30 €')
    expect(budgetEnEuros(25.5)).toBe('25,5 €')
    expect(participants(1)).toBe('1 participant')
    expect(participants(7)).toBe('7 participants')
    expect(lienLisible('cousine germaine')).toBe('Cousine germaine')
  })
})
