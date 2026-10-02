/**
 * La liste commune des événements — rassemblements et tirages au sort —, sans
 * navigateur (02/10/2026, « le tirage au sort c'est un événement en soi »).
 *
 * Ce que ces tests protègent :
 *
 * 1. **le rangement** : tout ce qui arrive, le plus proche d'abord, quelle que
 *    soit la sorte ; un tirage n'est jamais « passé » — il revient chaque
 *    année ;
 * 2. **la date d'un tirage** : l'édition ouverte quand elle est à venir (un
 *    Noël fêté le 26), sinon le jour habituel ;
 * 3. **le filtre** par type, lu dans l'adresse sans rien deviner ;
 * 4. **l'accueil** : tous mes événements à venir — invité, participant ou
 *    organisateur —, pas un tirage simplement ouvert à la famille.
 */
import { describe, expect, it } from 'vitest'
import type { EditionDuTirage, ResumeDEvenement, ResumeDeTirage } from '@/lib/api'
import { dateDuTirage, fusionner, lireTypeDEvenement, mesEvenements, titreDeLElement } from './liste-commune'

const AUJOURDHUI = new Date(2027, 6, 1, 10) // jeudi 1er juillet 2027

function rassemblement(partiel: Partial<ResumeDEvenement> = {}): ResumeDEvenement {
  return {
    id: 1,
    titre: 'Cousinade été 2027',
    debut: '2027-07-17',
    fin: '2027-07-18',
    lieu: null,
    description: null,
    annule: false,
    passe: false,
    organisateur: { libelle: 'Opale Tilleul', estMoi: false },
    peutGerer: false,
    suisInvite: true,
    maReponse: null,
    maReponseNoteePar: null,
    reponseAConfirmer: false,
    peutRepondre: true,
    nombreDInvites: 12,
    nombreDePresents: 3,
    ...partiel,
  }
}

function edition(partiel: Partial<EditionDuTirage> = {}): EditionDuTirage {
  return {
    annee: 2027,
    date: '2027-12-24',
    passee: false,
    statut: 'ouverte',
    statutLibelle: 'Ouverte',
    memoireAppliquee: null,
    tireeLe: null,
    nombreDAttributions: 0,
    presences: [],
    arrivesApresLeTirage: [],
    mesAttributions: [],
    ...partiel,
  }
}

function tirage(partiel: Partial<ResumeDeTirage> = {}): ResumeDeTirage {
  return {
    id: 7,
    nom: 'Noël des Romarin',
    jour: 24,
    mois: 12,
    lieu: null,
    ouvert: false,
    memoire: 2,
    exclureConjoints: true,
    organisateur: { libelle: 'Opale Tilleul', estMoi: false },
    peutGerer: false,
    suisParticipant: true,
    peutRejoindre: false,
    nombreDeParticipants: 5,
    prochaineEdition: null,
    ...partiel,
  }
}

const cles = (elements: { cle: string }[]): string[] => elements.map((e) => e.cle)

describe('la liste commune', () => {
  it('mêle rassemblements et tirages, le plus proche d’abord', () => {
    const noel = tirage({ id: 7 })
    const paques = tirage({ id: 8, nom: 'Œufs de Pâques', jour: 5, mois: 4 }) // l'an prochain : avril 2028
    const cousinade = rassemblement({ id: 1 })
    const toussaint = rassemblement({ id: 2, titre: 'Toussaint', debut: '2027-11-01', fin: '2027-11-01' })

    const { aVenir, passes } = fusionner([toussaint, cousinade], [paques, noel], AUJOURDHUI)

    expect(cles(aVenir)).toEqual(['rassemblement-1', 'rassemblement-2', 'tirage-7', 'tirage-8'])
    expect(passes).toEqual([])
    expect(titreDeLElement(aVenir[2]!)).toBe('Noël des Romarin')
  })

  it('range les rassemblements passés et annulés à part ; un tirage n’est jamais passé', () => {
    const ancien = rassemblement({ id: 3, debut: '2027-05-01', fin: '2027-05-02' })
    const annule = rassemblement({ id: 4, annule: true })
    const noel = tirage({ prochaineEdition: edition({ annee: 2026, date: '2026-12-24', passee: true }) })

    const { aVenir, passes } = fusionner([ancien, annule], [noel], AUJOURDHUI)

    expect(cles(aVenir)).toEqual(['tirage-7'])
    // Le plus récent d'abord : l'annulé (17 juillet) avant l'ancien (1er mai).
    expect(cles(passes)).toEqual(['rassemblement-4', 'rassemblement-3'])
  })

  it('filtre sur une sorte', () => {
    const tout = [[rassemblement()], [tirage()]] as const
    expect(cles(fusionner(...tout, AUJOURDHUI, 'tirage').aVenir)).toEqual(['tirage-7'])
    expect(cles(fusionner(...tout, AUJOURDHUI, 'rassemblement').aVenir)).toEqual(['rassemblement-1'])
    expect(fusionner(...tout, AUJOURDHUI, null).aVenir).toHaveLength(2)
  })

  it('lit le type dans l’adresse sans rien deviner', () => {
    expect(lireTypeDEvenement('tirage')).toBe('tirage')
    expect(lireTypeDEvenement('rassemblement')).toBe('rassemblement')
    expect(lireTypeDEvenement(undefined)).toBeNull()
    expect(lireTypeDEvenement('Tirage')).toBeNull()
    expect(lireTypeDEvenement(['tirage'])).toBeNull()
  })
})

describe('la date d’un tirage', () => {
  it('prend l’édition ouverte quand elle est à venir — même décalée du jour habituel', () => {
    const date = dateDuTirage(tirage({ prochaineEdition: edition({ date: '2027-12-26' }) }), AUJOURDHUI)
    expect([date.getFullYear(), date.getMonth() + 1, date.getDate()]).toEqual([2027, 12, 26])
  })

  it('retombe sur le jour habituel sans édition, ou quand l’édition est passée', () => {
    const sans = dateDuTirage(tirage(), AUJOURDHUI)
    expect([sans.getFullYear(), sans.getMonth() + 1, sans.getDate()]).toEqual([2027, 12, 24])

    const passee = dateDuTirage(tirage({ jour: 1, mois: 3, prochaineEdition: edition({ annee: 2027, date: '2027-03-01', passee: true }) }), AUJOURDHUI)
    expect([passee.getFullYear(), passee.getMonth() + 1, passee.getDate()]).toEqual([2028, 3, 1])
  })
})

describe('l’accueil : mes événements', () => {
  it('montre tout ce qui m’attend — invité, participant ou organisateur —, le plus proche d’abord', () => {
    const invite = rassemblement({ id: 1 })
    const organise = rassemblement({ id: 2, debut: '2027-07-05', fin: '2027-07-05', suisInvite: false, peutRepondre: false, organisateur: { libelle: 'Moi', estMoi: true } })
    const etranger = rassemblement({ id: 3, suisInvite: false, peutRepondre: false }) // un administrateur voit tout
    const annule = rassemblement({ id: 4, annule: true })
    const monNoel = tirage({ id: 7 })
    const ouvert = tirage({ id: 8, suisParticipant: false, peutRejoindre: true, ouvert: true })
    const jOrganise = tirage({ id: 9, nom: 'Anniversaire', jour: 10, mois: 9, suisParticipant: false, organisateur: { libelle: 'Moi', estMoi: true } })

    const elements = mesEvenements([invite, organise, etranger, annule], [monNoel, ouvert, jOrganise], AUJOURDHUI)

    expect(cles(elements)).toEqual(['rassemblement-2', 'rassemblement-1', 'tirage-9', 'tirage-7'])
  })

  it('ne montre rien quand rien ne m’attend', () => {
    expect(mesEvenements([rassemblement({ suisInvite: false })], [tirage({ suisParticipant: false })], AUJOURDHUI)).toEqual([])
  })
})
