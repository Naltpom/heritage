/**
 * Le mois affiché : les deux passages d'année, et les douze noms.
 *
 * Ce fichier est court et il est le plus rentable des trois : les deux flèches
 * sont le geste principal de l'écran, et un passage d'année raté ne se voit
 * qu'une fois par an — en décembre, au moment où un calendrier familial sert.
 */
import { describe, expect, it } from 'vitest'
import {
  LIBELLES_MOIS,
  libelleDeDate,
  libelleMois,
  moisCourant,
  moisPrecedent,
  moisSuivant,
  titreDuMois,
} from './mois'

describe('les deux flèches', () => {
  it('passe de décembre à janvier en changeant d’année', () => {
    expect(moisSuivant({ annee: 2026, mois: 12 })).toEqual({ annee: 2027, mois: 1 })
  })

  it('passe de janvier à décembre en changeant d’année', () => {
    expect(moisPrecedent({ annee: 2026, mois: 1 })).toEqual({ annee: 2025, mois: 12 })
  })

  it('ne touche pas à l’année au milieu de l’année', () => {
    expect(moisSuivant({ annee: 2026, mois: 3 })).toEqual({ annee: 2026, mois: 4 })
    expect(moisPrecedent({ annee: 2026, mois: 3 })).toEqual({ annee: 2026, mois: 2 })
  })

  it('revient exactement d’où elle vient — douze allers-retours', () => {
    /* Une flèche qui avance et une qui recule sont deux fonctions, et rien
       n'oblige la seconde à défaire la première. Sur les douze mois, si. */
    for (let mois = 1; mois <= 12; mois += 1) {
      const depart = { annee: 2026, mois }
      expect(moisPrecedent(moisSuivant(depart))).toEqual(depart)
      expect(moisSuivant(moisPrecedent(depart))).toEqual(depart)
    }
  })

  it('n’altère pas le mois qu’on lui donne', () => {
    /* La vue remplace son état, elle ne le mute pas : une flèche qui modifierait
       son argument ferait diverger l'état de ce que Vue a vu changer. */
    const depart = { annee: 2026, mois: 12 }
    moisSuivant(depart)
    moisPrecedent(depart)
    expect(depart).toEqual({ annee: 2026, mois: 12 })
  })
})

describe('les noms de mois', () => {
  it('donne les douze noms français, sans dépendre d’une locale installée', () => {
    /* LE DÉFAUT TENU. `Intl` sur un Node sans ICU complet rend « March » sans
       rien signaler. Ces douze chaînes-là ne dépendent de rien. */
    expect(LIBELLES_MOIS).toEqual([
      'janvier',
      'février',
      'mars',
      'avril',
      'mai',
      'juin',
      'juillet',
      'août',
      'septembre',
      'octobre',
      'novembre',
      'décembre',
    ])
  })

  it('numérote de 1 à 12, et non de 0 à 11 comme `Date`', () => {
    expect(libelleMois(1)).toBe('janvier')
    expect(libelleMois(12)).toBe('décembre')
  })

  it('rend une chaîne vide hors de 1–12, jamais « undefined » peint à l’écran', () => {
    expect(libelleMois(0)).toBe('')
    expect(libelleMois(13)).toBe('')
  })

  it('écrit le titre de l’écran', () => {
    expect(titreDuMois({ annee: 2026, mois: 3 })).toBe('mars 2026')
  })
})

describe('la date d’une case', () => {
  it('dit « 1er mars » et non « 1 mars »', () => {
    expect(libelleDeDate(3, 1)).toBe('1er mars')
  })

  it('dit « 14 mars » pour tous les autres jours', () => {
    expect(libelleDeDate(3, 14)).toBe('14 mars')
    expect(libelleDeDate(2, 29)).toBe('29 février')
  })
})

describe('le mois courant', () => {
  it('lit l’horloge qu’on lui passe, et non celle du jour où la suite tourne', () => {
    /*
     * LE DÉFAUT TENU. Un `new Date()` à la portée du module fixerait
     * « aujourd'hui » au chargement du fichier. La suite passerait tous les
     * jours sauf ceux où elle ne passerait plus, et personne ne saurait
     * pourquoi.
     */
    expect(moisCourant(new Date(2026, 8, 17))).toEqual({ annee: 2026, mois: 9 })
    expect(moisCourant(new Date(1999, 11, 31))).toEqual({ annee: 1999, mois: 12 })
  })

  it('rend bien un mois de 1 à 12 quand on ne lui donne rien', () => {
    const maintenant = moisCourant()
    expect(maintenant.mois).toBeGreaterThanOrEqual(1)
    expect(maintenant.mois).toBeLessThanOrEqual(12)
  })
})
