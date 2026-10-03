import { describe, expect, it } from 'vitest'
import { ErreurApi } from '@/lib/api'
import {
  carenceDuRefus,
  enCarence,
  erreursDePartage,
  initialesPartage,
  memePartage,
  partageParDefaut,
  partageSeul,
  resumePartage,
  retireUneComposante,
} from './partageNaissance'

/**
 * Le choix de partage de la date de naissance (ADR 0016), sans composant :
 * rien de coché d'avance, trois composantes indépendantes, les refus du
 * serveur remis sous la bonne case, et la carence de trente jours.
 */

const TOUT = { annee: true, mois: true, jour: true }
const RIEN = { annee: false, mois: false, jour: false }

describe('le choix', () => {
  it('ne présume aucun consentement', () => {
    expect(partageParDefaut()).toEqual(RIEN)
  })

  it('ne garde que les trois cases, jamais la date de modification ni la carence', () => {
    expect(partageSeul({ ...TOUT, modifieLe: '2026-10-01', carenceJusquAu: {} } as typeof TOUT)).toEqual(TOUT)
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

describe('la carence', () => {
  const CARENCE = { annee: '2026-11-02T00:00:00+01:00' }

  it('éteint une case non cochée en carence, jamais une case cochée', () => {
    expect(enCarence({ ...RIEN }, CARENCE, 'annee')).toBe(CARENCE.annee)
    expect(enCarence({ ...TOUT }, CARENCE, 'annee')).toBeNull()
    expect(enCarence({ ...RIEN }, CARENCE, 'mois')).toBeNull()
  })

  it('lit `details.jusquAu` d’un refus 409, et rien d’une autre erreur', () => {
    const refus = new ErreurApi(409, 'partage_en_carence', 'Trop tôt.', null, {
      jusquAu: { mois: '2026-11-02T00:00:00+01:00', jour: 12 },
    })
    expect(carenceDuRefus(refus)).toEqual({ mois: '2026-11-02T00:00:00+01:00' })
    expect(carenceDuRefus(new ErreurApi(409, 'vue_comme_membre_active', 'Non.'))).toBeNull()
    expect(carenceDuRefus(new Error('réseau'))).toBeNull()
  })

  it('sait quand un choix retire une composante partagée', () => {
    expect(retireUneComposante(TOUT, { ...TOUT, jour: false })).toBe(true)
    expect(retireUneComposante({ ...RIEN, mois: true }, { ...TOUT })).toBe(false)
    expect(retireUneComposante(RIEN, RIEN)).toBe(false)
  })
})
