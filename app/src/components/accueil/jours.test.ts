/**
 * Le carrousel des jours : les décalages, les libellés, le 29 février, et
 * « dans combien » de jours.
 */
import { describe, expect, it } from 'vitest'
import type { EvenementAnniversaire, JourAnniversaire, SujetEvenement } from '@/lib/api'
import {
  anniversairesDuJour,
  dansCombien,
  decaler,
  detailCourt,
  detailDeLaFete,
  ecartEnJours,
  initiales,
  jourEnToutesLettres,
  joursQuiViennent,
  libelleDate,
  libelleJourAVenir,
  libelleRelatif,
  natureDeLaFete,
  teinte,
  titreDeLaFete,
} from './jours'

function evenement(cle: string, annee: number): EvenementAnniversaire {
  return { cle, genre: 'naissance', type: 'naissance', annee, texte: '', lieu: null, sujets: [], familyId: null, libelle: null }
}

const AUJOURDHUI = new Date(2026, 8, 27, 9, 30)

describe('les jours', () => {
  it('décale d’un jour, d’un mois à l’autre et d’une année à l’autre', () => {
    expect(decaler(new Date(2026, 8, 30), 1).getDate()).toBe(1)
    expect(decaler(new Date(2026, 11, 31), 1).getFullYear()).toBe(2027)
    expect(decaler(new Date(2026, 0, 1), -1).getMonth()).toBe(11)
  })

  it('ne perd pas un jour au changement d’heure', () => {
    // Nuit du 25 octobre 2026 : l'heure recule en Europe.
    expect(ecartEnJours(new Date(2026, 9, 24), decaler(new Date(2026, 9, 24), 2))).toBe(2)
    expect(decaler(new Date(2026, 9, 24, 0, 30), 1).getDate()).toBe(25)
  })

  it('dit aujourd’hui, demain, hier, sinon le jour de la semaine', () => {
    expect(libelleRelatif(AUJOURDHUI, AUJOURDHUI)).toBe('Aujourd’hui')
    expect(libelleRelatif(decaler(AUJOURDHUI, 1), AUJOURDHUI)).toBe('Demain')
    expect(libelleRelatif(decaler(AUJOURDHUI, -1), AUJOURDHUI)).toBe('Hier')
    expect(libelleRelatif(decaler(AUJOURDHUI, 2), AUJOURDHUI)).toBe('Mardi')
    expect(libelleDate(new Date(2026, 4, 1))).toBe('1er mai')
  })
})

describe('les anniversaires du jour', () => {
  const jours: JourAnniversaire[] = [
    { mois: 9, jour: 27, evenements: [evenement('b', 1990), evenement('a', 1930)] },
    { mois: 2, jour: 29, evenements: [evenement('bissextile', 1964)] },
  ]

  it('rend ceux du jour, du plus ancien au plus récent', () => {
    expect(anniversairesDuJour(jours, AUJOURDHUI).map((e) => e.cle)).toEqual(['a', 'b'])
    expect(anniversairesDuJour(jours, decaler(AUJOURDHUI, 1))).toEqual([])
  })

  it('fête le 29 février le 28 les années non bissextiles, et seulement celles-là', () => {
    expect(anniversairesDuJour(jours, new Date(2027, 1, 28)).map((e) => e.cle)).toEqual(['bissextile'])
    expect(anniversairesDuJour(jours, new Date(2028, 1, 28))).toEqual([])
    expect(anniversairesDuJour(jours, new Date(2028, 1, 29)).map((e) => e.cle)).toEqual(['bissextile'])
  })
})

describe('dans combien', () => {
  it('dit aujourd’hui, demain, puis le nombre de jours', () => {
    expect(dansCombien(new Date(2026, 8, 27, 20), AUJOURDHUI)).toBe('aujourd’hui')
    expect(dansCombien(new Date(2026, 8, 28), AUJOURDHUI)).toBe('demain')
    expect(dansCombien(new Date(2026, 11, 24), AUJOURDHUI)).toBe('dans 88 jours')
  })
})

function sujet(personId: number, displayName: string, sex: SujetEvenement['sex'], vivant = true): SujetEvenement {
  return { personId, displayName, sex, vivant }
}

function fete(partiel: Partial<EvenementAnniversaire>): EvenementAnniversaire {
  return { ...evenement('x', 1973), texte: '30 octobre 1973', ...partiel }
}

const VENDREDI = new Date(2026, 9, 30, 12)

describe('la fête du jour', () => {
  const severine = fete({ cle: 's', sujets: [sujet(1, 'Séverine CHEMIN', 'feminin')] })
  const noces = fete({
    cle: 'm',
    genre: 'union',
    type: 'mariage',
    annee: 1968,
    texte: '28 octobre 1968',
    lieu: 'Limoges',
    sujets: [sujet(2, 'Jean PROVOST', 'masculin'), sujet(3, 'Odile PROVOST', 'feminin')],
  })

  it('écrit la date de l’en-tête et celle des jours qui viennent', () => {
    expect(jourEnToutesLettres(VENDREDI)).toBe('Vendredi 30 octobre')
    expect(libelleJourAVenir(decaler(VENDREDI, 1), VENDREDI)).toBe('Demain')
    expect(libelleJourAVenir(decaler(VENDREDI, 2), VENDREDI)).toBe('Dim. 1')
  })

  it('dit l’âge d’une naissance, les années d’un mariage', () => {
    expect(natureDeLaFete(severine)).toBe('Anniversaire')
    expect(titreDeLaFete(severine, 2026)).toBe('Séverine CHEMIN a 53 ans')
    expect(detailDeLaFete(severine, 2026)).toBe('30 octobre 1973')
    expect(natureDeLaFete(noces)).toBe('Anniversaire de mariage')
    expect(titreDeLaFete(noces, 2026)).toBe('Jean PROVOST & Odile PROVOST')
    expect(detailDeLaFete(noces, 2026)).toBe('28 octobre 1968 · Limoges · 58 ans de mariage')
  })

  it('sans année partagée, aucun âge (ADR 0016)', () => {
    const { annee: _, ...sansAnnee } = severine
    expect(titreDeLaFete(sansAnnee, 2026)).toBe('Séverine CHEMIN')
    expect(detailCourt(sansAnnee, 2026)).toBe('Anniversaire')
  })

  it('les initiales et la teinte de la pastille', () => {
    expect(initiales(severine)).toBe('SC')
    expect(teinte(severine)).toBe('femme')
    expect(initiales(noces)).toBe('JO')
    expect(teinte(noces)).toBe('inconnu')
  })

  it('en mémoire, on ne fête pas l’âge d’un défunt', () => {
    const marcel = fete({ annee: 1902, sujets: [sujet(4, 'Marcel PROVOST', 'masculin', false)] })
    expect(detailCourt(marcel, 2026)).toBe('Naissance 1902')
    expect(detailCourt(severine, 2026)).toBe('53 ans')
    expect(detailCourt(noces, 2026)).toBe('58 ans de mariage')
  })
})

describe('les jours qui viennent', () => {
  it('les sept jours suivants, dans l’ordre, les vivants avant les défunts', () => {
    const jours: JourAnniversaire[] = [
      { mois: 10, jour: 30, evenements: [evenement('aujourdhui', 1973)] },
      {
        mois: 10,
        jour: 31,
        evenements: [
          fete({ cle: 'mort', annee: 1900, sujets: [sujet(1, 'A', 'masculin', false)] }),
          fete({ cle: 'vif', annee: 2002, sujets: [sujet(2, 'B', 'masculin')] }),
        ],
      },
      { mois: 11, jour: 6, evenements: [evenement('septieme', 2000)] },
      { mois: 11, jour: 7, evenements: [evenement('trop-loin', 2000)] },
    ]
    expect(joursQuiViennent(jours, VENDREDI).map((j) => j.evenement.cle)).toEqual(['vif', 'mort', 'septieme'])
  })
})
