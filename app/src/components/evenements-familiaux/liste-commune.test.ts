/**
 * La liste commune des événements — rassemblements et tirages de Noël —, sans
 * navigateur (02/10/2026, « le tirage au sort c'est un événement en soi » ;
 * les tirages sont ceux du site de Noël depuis le retrait du module `Tirage`).
 *
 * Ce que ces tests protègent :
 *
 * 1. **le rangement** : tout ce qui arrive, le plus proche d'abord, quelle que
 *    soit la sorte ; un tirage de Noël se range au jour de la remise des
 *    cadeaux, et passe dans les passés le lendemain ;
 * 2. **le filtre** par type, lu dans l'adresse sans rien deviner — l'ancien
 *    `?type=tirage` compris ;
 * 3. **l'accueil** : tous mes événements à venir — invité, organisateur, ou
 *    dans mes tirages de Noël.
 */
import { describe, expect, it } from 'vitest'
import type { ResumeDEvenement, ResumeDuTirageDeNoel } from '@/lib/api'
import { fusionner, lireTypeDEvenement, mesEvenements, titreDeLElement } from './liste-commune'

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

function noel(partiel: Partial<ResumeDuTirageDeNoel> = {}): ResumeDuTirageDeNoel {
  return {
    code: 'ROMARIN',
    titre: 'Noël des Romarin',
    dateTirage: '2027-12-01T00:00:00+01:00',
    dateEvenement: '2027-12-24T00:00:00+01:00',
    statut: 'ouvert',
    estCreateur: false,
    reponse: 'oui',
    nbParticipants: 5,
    budget: 30,
    lieu: null,
    ...partiel,
  }
}

const cles = (elements: { cle: string }[]): string[] => elements.map((e) => e.cle)

describe('la liste commune', () => {
  it('mêle rassemblements et tirages de Noël, le plus proche d’abord — au jour de la remise', () => {
    const romarin = noel()
    // Tiré avant la cousinade, mais ses cadeaux se remettent en août : il se range en août.
    const ete = noel({ code: 'ETE', titre: 'Cadeaux d’été', dateTirage: '2027-06-01T00:00:00+02:00', dateEvenement: '2027-08-15T00:00:00+02:00', statut: 'tire' })
    const cousinade = rassemblement({ id: 1 })
    const toussaint = rassemblement({ id: 2, titre: 'Toussaint', debut: '2027-11-01', fin: '2027-11-01' })

    const { aVenir, passes } = fusionner([toussaint, cousinade], [romarin, ete], AUJOURDHUI)

    expect(cles(aVenir)).toEqual(['rassemblement-1', 'noel-ETE', 'rassemblement-2', 'noel-ROMARIN'])
    expect(passes).toEqual([])
    expect(titreDeLElement(aVenir[3]!)).toBe('Noël des Romarin')
  })

  it('range à part les rassemblements passés ou annulés et les Noëls dont la remise est passée', () => {
    const ancien = rassemblement({ id: 3, debut: '2027-05-01', fin: '2027-05-02' })
    const annule = rassemblement({ id: 4, annule: true })
    const noelDernier = noel({ code: 'N2026', dateTirage: '2026-12-01T00:00:00+01:00', dateEvenement: '2026-12-24T00:00:00+01:00', statut: 'tire' })
    const aujourdhui = noel({ code: 'AUJ', dateEvenement: '2027-07-01T00:00:00+02:00' })

    const { aVenir, passes } = fusionner([ancien, annule], [noelDernier, aujourdhui], AUJOURDHUI)

    // Le jour même de la remise, c'est encore « à venir ».
    expect(cles(aVenir)).toEqual(['noel-AUJ'])
    // Le plus récent d'abord : l'annulé (17 juillet), l'ancien (1er mai), Noël 2026.
    expect(cles(passes)).toEqual(['rassemblement-4', 'rassemblement-3', 'noel-N2026'])
  })

  it('filtre sur une sorte', () => {
    const tout = [[rassemblement()], [noel()]] as const
    expect(cles(fusionner(...tout, AUJOURDHUI, 'noel').aVenir)).toEqual(['noel-ROMARIN'])
    expect(cles(fusionner(...tout, AUJOURDHUI, 'rassemblement').aVenir)).toEqual(['rassemblement-1'])
    expect(fusionner(...tout, AUJOURDHUI, null).aVenir).toHaveLength(2)
  })

  it('lit le type dans l’adresse sans rien deviner — l’ancien « tirage » vaut Noël', () => {
    expect(lireTypeDEvenement('noel')).toBe('noel')
    expect(lireTypeDEvenement('tirage')).toBe('noel')
    expect(lireTypeDEvenement('rassemblement')).toBe('rassemblement')
    expect(lireTypeDEvenement(undefined)).toBeNull()
    expect(lireTypeDEvenement('Noel')).toBeNull()
    expect(lireTypeDEvenement(['noel'])).toBeNull()
  })
})

describe('l’accueil : mes événements', () => {
  it('montre tout ce qui m’attend — invité, organisateur, mes Noëls —, le plus proche d’abord', () => {
    const invite = rassemblement({ id: 1 })
    const organise = rassemblement({ id: 2, debut: '2027-07-05', fin: '2027-07-05', suisInvite: false, peutRepondre: false, organisateur: { libelle: 'Moi', estMoi: true } })
    const etranger = rassemblement({ id: 3, suisInvite: false, peutRepondre: false }) // un administrateur voit tout
    const annule = rassemblement({ id: 4, annule: true })
    const monNoel = noel()
    const refuse = noel({ code: 'NON', titre: 'Autre Noël', reponse: 'non' })
    const passe = noel({ code: 'VIEUX', dateEvenement: '2026-12-24T00:00:00+01:00', statut: 'tire' })

    const elements = mesEvenements([invite, organise, etranger, annule], [monNoel, refuse, passe], AUJOURDHUI)

    expect(cles(elements)).toEqual(['rassemblement-2', 'rassemblement-1', 'noel-NON', 'noel-ROMARIN'])
  })

  it('ne montre rien quand rien ne m’attend', () => {
    expect(mesEvenements([rassemblement({ suisInvite: false })], [], AUJOURDHUI)).toEqual([])
  })
})
