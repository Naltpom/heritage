/**
 * Le carrousel des jours : les décalages, les libellés, le 29 février, et
 * « dans combien » de jours.
 */
import { describe, expect, it } from 'vitest'
import type { EvenementAnniversaire, JourAnniversaire } from '@/lib/api'
import {
  anniversairesDuJour,
  dansCombien,
  decaler,
  ecartEnJours,
  libelleDate,
  libelleRelatif,
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
