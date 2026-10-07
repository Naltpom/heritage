import { describe, expect, it } from 'vitest'
import { ErreurApi } from '@/lib/api'
import type { PartageNaissance } from '@/lib/api'
import {
  dateDuVerrou,
  erreursDePartage,
  memePartage,
  ouvreATous,
  partageParDefaut,
  partageSeul,
  resumeComposantes,
  resumePartage,
  verrouDuRefus,
  verrouillee,
} from './partageNaissance'

/**
 * Le choix de partage de la date de naissance (ADR 0016, révision du 7 octobre
 * 2026), sans composant : « mes proches » d'avance, trois composantes
 * indépendantes à trois niveaux, les refus du serveur remis sous la bonne
 * ligne, et le verrou de trente jours sur « tous ».
 */

const TOUT: PartageNaissance = { annee: 'tous', mois: 'tous', jour: 'tous' }
const PROCHES: PartageNaissance = { annee: 'proches', mois: 'proches', jour: 'proches' }
const RIEN: PartageNaissance = { annee: 'personne', mois: 'personne', jour: 'personne' }

describe('le choix', () => {
  it('montre aux proches par défaut, et à eux seuls', () => {
    expect(partageParDefaut()).toEqual(PROCHES)
  })

  it('ne garde que les trois cases, jamais la date de modification ni le verrou', () => {
    expect(partageSeul({ ...TOUT, modifieLe: '2026-10-01', verrouJusquAu: {} } as typeof TOUT)).toEqual(TOUT)
  })

  it('compare composante par composante', () => {
    expect(memePartage(TOUT, { ...TOUT })).toBe(true)
    expect(memePartage(TOUT, { ...TOUT, mois: 'proches' })).toBe(false)
  })

  it('se résume par niveau, du plus ouvert au plus fermé', () => {
    expect(resumePartage(TOUT)).toBe('année, mois et jour : tous')
    expect(resumePartage(PROCHES)).toBe('année, mois et jour : proches')
    expect(resumePartage({ annee: 'personne', mois: 'tous', jour: 'tous' })).toBe('jour et mois : tous · année : personne')
    expect(resumePartage({ annee: 'proches', mois: 'personne', jour: 'tous' })).toBe('jour : tous · année : proches · mois : personne')
  })

  it('résume des cases cochées dans l’ordre d’une date française', () => {
    expect(resumeComposantes({ annee: true, mois: true, jour: true })).toBe('année, mois et jour')
    expect(resumeComposantes({ annee: false, mois: false, jour: false })).toBe('rien')
    expect(resumeComposantes({ annee: false, mois: true, jour: true })).toBe('jour et mois')
    expect(resumeComposantes({ annee: true, mois: false, jour: true })).toBe('jour et année')
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

  it('verrouille une composante à « tous », jamais une à « proches » ou « personne »', () => {
    expect(verrouillee({ ...TOUT }, VERROU, 'annee')).toBe(VERROU.annee)
    expect(verrouillee({ ...PROCHES }, VERROU, 'annee')).toBeNull()
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

  it('sait quand un choix ouvre une composante à tous — le verrou va courir', () => {
    expect(ouvreATous(PROCHES, { ...PROCHES, jour: 'tous' })).toBe(true)
    expect(ouvreATous(RIEN, { ...RIEN, jour: 'proches' })).toBe(false)
    expect(ouvreATous(TOUT, { ...TOUT, mois: 'proches' })).toBe(false)
    expect(ouvreATous(RIEN, RIEN)).toBe(false)
  })
})
