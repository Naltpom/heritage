import { describe, expect, it } from 'vitest'
import { ErreurApi } from '@/lib/api'
import {
  ajouteUneComposante,
  dateDuVerrou,
  erreursDePartage,
  initialesPartage,
  memePartage,
  partageParDefaut,
  partageSeul,
  resumePartage,
  verrouDuRefus,
  verrouillee,
} from './partageNaissance'

/**
 * Le choix de partage de la date de naissance (ADR 0016), sans composant :
 * rien de coché d'avance, trois composantes indépendantes, les refus du
 * serveur remis sous la bonne case, et le verrou de trente jours.
 */

const TOUT = { annee: true, mois: true, jour: true }
const RIEN = { annee: false, mois: false, jour: false }

describe('le choix', () => {
  it('ne présume aucun consentement', () => {
    expect(partageParDefaut()).toEqual(RIEN)
  })

  it('ne garde que les trois cases, jamais la date de modification ni le verrou', () => {
    expect(partageSeul({ ...TOUT, modifieLe: '2026-10-01', verrouJusquAu: {} } as typeof TOUT)).toEqual(TOUT)
  })

  it('compare composante par composante', () => {
    expect(memePartage(TOUT, { ...TOUT })).toBe(true)
    expect(memePartage(TOUT, { ...TOUT, mois: false })).toBe(false)
  })

  it('se résume dans l’ordre d’une date française, chaque composante pour elle-même', () => {
    expect(resumePartage(TOUT)).toBe('année, mois et jour')
    expect(resumePartage(RIEN)).toBe('rien')
    expect(resumePartage({ annee: false, mois: true, jour: true })).toBe('jour et mois')
    expect(resumePartage({ annee: true, mois: false, jour: false })).toBe('année')
    expect(resumePartage({ annee: true, mois: false, jour: true })).toBe('jour et année')
    expect(initialesPartage({ annee: true, mois: false, jour: true })).toBe('A J')
    expect(initialesPartage(RIEN)).toBe('—')
  })
})

describe('les refus du serveur', () => {
  it('reviennent sous la case qu’ils concernent', () => {
    expect(
      erreursDePartage({ 'partageNaissance.mois': 'Valeur invalide.', email: 'Pris.', 'partageNaissance.jour': '' }),
    ).toEqual({ mois: 'Valeur invalide.' })
    expect(erreursDePartage(null)).toEqual({})
  })
})

describe('le verrou', () => {
  const VERROU = { annee: '2026-11-02T00:00:00+01:00' }

  it('éteint une case cochée verrouillée, jamais une case non cochée', () => {
    expect(verrouillee({ ...TOUT }, VERROU, 'annee')).toBe(VERROU.annee)
    expect(verrouillee({ ...RIEN }, VERROU, 'annee')).toBeNull()
    expect(verrouillee({ ...TOUT }, VERROU, 'mois')).toBeNull()
  })

  it('lit `details.jusquAu` d’un refus 409 partage_verrouille, et rien d’une autre erreur', () => {
    const refus = new ErreurApi(409, 'partage_verrouille', 'Trop tôt.', null, {
      jusquAu: { mois: '2026-11-02T00:00:00+01:00', jour: 12 },
    })
    expect(verrouDuRefus(refus)).toEqual({ mois: '2026-11-02T00:00:00+01:00' })
    expect(verrouDuRefus(new ErreurApi(409, 'partage_en_carence', 'Ancien code.'))).toBeNull()
    expect(verrouDuRefus(new ErreurApi(409, 'vue_comme_membre_active', 'Non.'))).toBeNull()
    expect(verrouDuRefus(new Error('réseau'))).toBeNull()
  })

  it('dit la fin du verrou en toutes lettres', () => {
    expect(dateDuVerrou('2026-11-02T12:00:00+01:00')).toBe('2 novembre 2026')
    expect(dateDuVerrou('pas une date')).toBe('pas une date')
  })

  it('sait quand un choix ajoute une composante', () => {
    expect(ajouteUneComposante(RIEN, { ...RIEN, jour: true })).toBe(true)
    expect(ajouteUneComposante(TOUT, { ...TOUT, mois: false })).toBe(false)
    expect(ajouteUneComposante(RIEN, RIEN)).toBe(false)
  })
})
