/**
 * La logique des écrans d'événements, sans navigateur : les dates dites en
 * français, ce qui est à venir, ce que dit chaque ligne du suivi, et quand la
 * relance est permise.
 */
import { describe, expect, it } from 'vitest'
import type { LigneDeSuivi, ResumeDEvenement, SuiviDEvenement } from '@/lib/api'
import {
  adresseDuLien,
  annonceAEnvoyer,
  bilan,
  compteDInvites,
  detailDeLaLigne,
  etatDeLEvenement,
  etatDeLaRelance,
  lireDate,
  messageDePartage,
  periode,
  periodeEnTete,
  phraseDeMaReponse,
  phraseDeReponseNotee,
  quand,
  rappelDesLiens,
  repartir,
  verifierAnnonce,
} from './evenements'

const AUJOURDHUI = new Date(2027, 6, 1, 10) // jeudi 1er juillet 2027

function evenement(partiel: Partial<ResumeDEvenement> = {}): ResumeDEvenement {
  return {
    id: 1,
    titre: 'Cousinade été 2027',
    debut: '2027-07-17',
    fin: '2027-07-18',
    lieu: 'Fleurance',
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
    nombreDInvites: 37,
    nombreDePresents: 12,
    ...partiel,
  }
}

function ligne(partiel: Partial<LigneDeSuivi> = {}): LigneDeSuivi {
  return {
    personId: 4,
    libelle: 'Léa Romarin',
    estMoi: false,
    aUnCompte: true,
    lienActif: false,
    peutPartagerLien: false,
    vuLe: null,
    reponduLe: null,
    relanceLe: null,
    noteePar: null,
    aConfirmer: false,
    peutNoterReponse: true,
    ...partiel,
  }
}

describe('les dates', () => {
  it('lit une date `AAAA-MM-JJ` en heure locale, à midi — jamais la veille', () => {
    const d = lireDate('2027-07-17')
    expect(d?.getDate()).toBe(17)
    expect(d?.getMonth()).toBe(6)
    expect(d?.getHours()).toBe(12)
    expect(lireDate('17/07/2027')).toBeNull()
  })

  it('dit la période sans répéter ce qui est commun', () => {
    expect(periode('2027-07-17', '2027-07-18')).toBe('du sam. 17 au dim. 18 juillet 2027')
    expect(periode('2027-07-17', '2027-07-17')).toBe('le sam. 17 juillet 2027')
    expect(periode('2027-07-30', '2027-08-01')).toBe('du ven. 30 juillet au dim. 1er août 2027')
    expect(periode('2026-12-31', '2027-01-01')).toBe('du jeu. 31 décembre 2026 au ven. 1er janvier 2027')
    expect(periodeEnTete('2027-07-17', '2027-07-18')).toBe('Du sam. 17 au dim. 18 juillet 2027')
  })

  it('ne rend jamais « Invalid Date »', () => {
    expect(periode('bientôt', '2027-07-18')).toBe('bientôt – 2027-07-18')
  })
})

describe('où en est l’événement', () => {
  it('à venir, en cours, terminé, annulé — l’annulation l’emporte', () => {
    expect(etatDeLEvenement(evenement(), AUJOURDHUI)).toBe('a_venir')
    expect(etatDeLEvenement(evenement(), new Date(2027, 6, 18, 20))).toBe('en_cours')
    expect(etatDeLEvenement(evenement(), new Date(2027, 6, 19, 8))).toBe('passe')
    expect(etatDeLEvenement(evenement({ annule: true }), AUJOURDHUI)).toBe('annule')
  })

  it('dit quand, en mots', () => {
    expect(quand(evenement(), AUJOURDHUI)).toBe('dans 16 jours')
    expect(quand(evenement(), new Date(2027, 6, 16, 9))).toBe('demain')
    expect(quand(evenement(), new Date(2027, 6, 17, 9))).toBe('en cours')
    expect(quand(evenement(), new Date(2027, 7, 1))).toBe('terminé')
    expect(quand(evenement({ annule: true }), AUJOURDHUI)).toBe('annulé')
  })

  it('range à venir (le plus proche d’abord) et passés ou annulés (le plus récent d’abord)', () => {
    const loin = evenement({ id: 2, debut: '2027-12-24', fin: '2027-12-24' })
    const proche = evenement({ id: 3 })
    const annule = evenement({ id: 4, annule: true })
    const ancien = evenement({ id: 5, debut: '2026-07-17', fin: '2026-07-18' })
    const recent = evenement({ id: 6, debut: '2027-05-01', fin: '2027-05-02' })

    const { aVenir, passes } = repartir([loin, ancien, proche, annule, recent], AUJOURDHUI)
    expect(aVenir.map((e) => e.id)).toEqual([3, 2])
    expect(passes.map((e) => e.id)).toEqual([4, 6, 5])
  })
})

describe('les comptes et les phrases', () => {
  it('accorde', () => {
    expect(compteDInvites(37)).toBe('37 invités')
    expect(compteDInvites(1)).toBe('1 invité')
    expect(compteDInvites(0)).toBe('Aucun invité')
    expect(bilan(3, 12)).toBe('3 présents sur 12 invités')
    expect(bilan(1, 1)).toBe('1 présent sur 1 invité')
  })

  it('dit ma réponse — et l’absence de réponse', () => {
    expect(phraseDeMaReponse('present')).toBe('Vous avez dit : présent.')
    expect(phraseDeMaReponse(null)).toBe('Vous n’avez pas encore répondu.')
  })

  it('le message de partage nomme l’invité et l’événement, rien d’autre', () => {
    expect(messageDePartage('Cousinade été 2027', 'Mamie Rose', '2027-07-17', '2027-07-18')).toBe(
      'Mamie Rose, vous êtes invité·e : Cousinade été 2027, du sam. 17 au dim. 18 juillet 2027. Dites si vous venez en ouvrant ce lien :',
    )
  })
})

describe('le suivi de l’organisateur', () => {
  const MAINTENANT = new Date(2027, 6, 1, 10)
  const ilYA = (heures: number): string => new Date(MAINTENANT.getTime() - heures * 3_600_000).toISOString()

  it('dit depuis quand, en relatif', () => {
    expect(detailDeLaLigne(ligne({ vuLe: ilYA(48) }), 'fantomes', MAINTENANT)).toBe('vu il y a 2 j')
    expect(detailDeLaLigne(ligne({ reponduLe: ilYA(3) }), 'presents', MAINTENANT)).toBe('a répondu il y a 3 h')
    expect(detailDeLaLigne(ligne({ vuLe: ilYA(5), aUnCompte: false, lienActif: true }), 'fantomes', MAINTENANT)).toBe('vu il y a 5 h · par son lien')
  })

  it('dit qu’une réponse a été notée par l’organisateur, et qu’elle est à confirmer après un changement de dates', () => {
    expect(detailDeLaLigne(ligne({ reponduLe: ilYA(3), noteePar: 'Nathan' }), 'presents', MAINTENANT)).toBe('noté par Nathan il y a 3 h')
    expect(detailDeLaLigne(ligne({ reponduLe: ilYA(3), aConfirmer: true }), 'absents', MAINTENANT)).toBe(
      'a répondu il y a 3 h · à confirmer (dates changées)',
    )
    expect(phraseDeReponseNotee('Nathan')).toBe('Réponse notée par Nathan.')
    expect(phraseDeReponseNotee(null)).toBeNull()
  })

  it('dit, pour qui n’a pas vu, s’il a un compte ou un lien — et s’il a été relancé', () => {
    expect(detailDeLaLigne(ligne(), 'nonVus', MAINTENANT)).toBe('a un compte')
    expect(detailDeLaLigne(ligne({ aUnCompte: false }), 'nonVus', MAINTENANT)).toBe('sans compte · pas encore de lien')
    expect(detailDeLaLigne(ligne({ aUnCompte: false, lienActif: true }), 'nonVus', MAINTENANT)).toBe('sans compte · lien créé')
    expect(detailDeLaLigne(ligne({ relanceLe: ilYA(30) }), 'nonVus', MAINTENANT)).toBe('a un compte · relancé hier')
  })

  it('permet la relance, l’éteint dans les 24 h, et quand personne n’est à relancer', () => {
    expect(etatDeLaRelance({ aRelancer: 3, prochaineRelancePossibleLe: null }, MAINTENANT)).toMatchObject({ libelle: 'Relancer 3 invités', actif: true })
    expect(etatDeLaRelance({ aRelancer: 1, prochaineRelancePossibleLe: null }, MAINTENANT).libelle).toBe('Relancer 1 invité')

    const bientot = new Date(MAINTENANT.getTime() + 4.5 * 3_600_000).toISOString()
    const trop = etatDeLaRelance({ aRelancer: 3, prochaineRelancePossibleLe: bientot }, MAINTENANT)
    expect(trop.actif).toBe(false)
    expect(trop.aide).toContain('dans 5 h')

    expect(etatDeLaRelance({ aRelancer: 0, prochaineRelancePossibleLe: null }, MAINTENANT).actif).toBe(false)
  })

  it('rappelle de renvoyer leur lien aux invités sans compte qui n’ont pas répondu', () => {
    const suivi: Pick<SuiviDEvenement, 'fantomes' | 'nonVus'> = {
      fantomes: [ligne({ aUnCompte: false })],
      nonVus: [ligne(), ligne({ aUnCompte: false })],
    }
    expect(rappelDesLiens(suivi)).toBe('2 invités sans compte n’ont pas répondu : renvoyez-leur leur lien vous-même.')
    expect(rappelDesLiens({ fantomes: [], nonVus: [ligne()] })).toBeNull()
  })
})

describe('l’annonce', () => {
  it('nomme chaque champ fautif avant d’envoyer', () => {
    expect(Object.keys(verifierAnnonce({ titre: ' ', debut: '', fin: '', lieu: null, description: null }, AUJOURDHUI)).sort()).toEqual(['debut', 'titre'])
    expect(verifierAnnonce({ titre: 'A', debut: '2027-07-18', fin: '2027-07-17', lieu: null, description: null }, AUJOURDHUI)['fin']).toContain('précéder')
    expect(verifierAnnonce({ titre: 'A', debut: '2027-06-01', fin: '2027-06-02', lieu: null, description: null }, AUJOURDHUI)['fin']).toContain('passée')
    expect(verifierAnnonce({ titre: 'A', debut: '2027-07-17', fin: '', lieu: null, description: null }, AUJOURDHUI)).toEqual({})
  })

  it('envoie une annonce propre : blancs retirés, vides à `null`, fin vide = début', () => {
    expect(annonceAEnvoyer({ titre: ' Cousinade ', debut: '2027-07-17', fin: '', lieu: '  ', description: ' Venez !\nAvec un plat. ' })).toEqual({
      titre: 'Cousinade',
      debut: '2027-07-17',
      fin: '2027-07-17',
      lieu: null,
      description: 'Venez !\nAvec un plat.',
    })
  })
})

describe('adresseDuLien', () => {
  it('rend absolue l’url relative de l’API', () => {
    expect(adresseDuLien('/e/abc', 'https://heritage.local')).toBe('https://heritage.local/e/abc')
    expect(adresseDuLien('/e/abc', 'https://heritage.local/')).toBe('https://heritage.local/e/abc')
    expect(adresseDuLien('e/abc', 'http://localhost:5173')).toBe('http://localhost:5173/e/abc')
  })

  it('laisse une url déjà absolue telle quelle', () => {
    expect(adresseDuLien('https://a.b/e/x', 'https://c.d')).toBe('https://a.b/e/x')
  })
})
