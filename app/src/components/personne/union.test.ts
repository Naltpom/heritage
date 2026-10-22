import { describe, expect, it } from 'vitest'
import type { BlocDate } from '@/lib/api'
import {
  mariageRenseigne,
  motDeLaRupture,
  natureEffective,
  natureEnClair,
  phraseDeLaRupture,
  phraseDuMariage,
  quandDUnEvenement,
} from './union'

/**
 * La nature d'une union (ADR 0020), en mots. Ce que ces tests protègent :
 *
 * - **un mariage daté ou situé est un mariage**, même quand la clé `married` dit
 *   autre chose — un lien déposé avant la nature porte `null` sous sa date ;
 * - on ne divorce que d'un mariage : « séparés » pour une union libre **et** pour une
 *   nature inconnue ;
 * - une date se dit avec le texte du serveur, jamais recomposée depuis ses bornes.
 */

const bloc = (surcharge: Partial<BlocDate>): BlocDate => ({
  raw: null,
  qualifier: 'inconnue',
  min: null,
  max: null,
  place: null,
  texte: '',
  ...surcharge,
})

describe('ce qu’un bloc de mariage atteste', () => {
  it('une date, une chaîne illisible ou un lieu : chacun suffit', () => {
    expect(mariageRenseigne(bloc({ qualifier: 'annee', raw: '1927', texte: '1927' }))).toBe(true)
    expect(mariageRenseigne(bloc({ raw: 'vers la Saint-Jean' }))).toBe(true)
    expect(mariageRenseigne(bloc({ place: 'Saule' }))).toBe(true)
  })

  it('un bloc vide, ou absent, n’atteste rien', () => {
    expect(mariageRenseigne(bloc({}))).toBe(false)
    expect(mariageRenseigne(null)).toBe(false)
    expect(mariageRenseigne(bloc({ place: '  ' }))).toBe(false)
  })

  it('la date l’emporte sur la clé', () => {
    expect(natureEffective(null, bloc({ place: 'Saule' }))).toBe(true)
    expect(natureEffective(false, bloc({ qualifier: 'annee', texte: '1927' }))).toBe(true)
    expect(natureEffective(false, null)).toBe(false)
    expect(natureEffective(undefined, null)).toBeNull()
  })
})

describe('quand', () => {
  it('reçoit la préposition de son qualificatif', () => {
    expect(quandDUnEvenement(bloc({ qualifier: 'exacte', texte: '23 septembre 1927' }))).toBe('le 23 septembre 1927')
    expect(quandDUnEvenement(bloc({ qualifier: 'annee', texte: '1927' }))).toBe('en 1927')
    expect(quandDUnEvenement(bloc({ qualifier: 'mois', texte: 'septembre 1927' }))).toBe('en septembre 1927')
    expect(quandDUnEvenement(bloc({ qualifier: 'environ', texte: 'vers 1934' }))).toBe('vers 1934')
  })

  it('ne recompose jamais une fourchette depuis ses bornes', () => {
    expect(quandDUnEvenement(bloc({ qualifier: 'entre', min: '1901-01-01', max: '1909-12-31', texte: 'entre 1901 et 1909' }))).toBe(
      'entre 1901 et 1909',
    )
    expect(quandDUnEvenement(bloc({ qualifier: 'entre', min: '1901-01-01', max: '1909-12-31' }))).toBeNull()
  })

  it('dit une chaîne illisible telle quelle', () => {
    expect(quandDUnEvenement(bloc({ raw: 'vers la Saint-Jean' }))).toBe('(vers la Saint-Jean)')
  })
})

describe('la phrase du mariage', () => {
  it('mariés, datés ou non ; union libre ; rien quand on ne sait pas', () => {
    expect(phraseDuMariage({ married: true, marriage: bloc({ qualifier: 'annee', texte: '1962' }) })).toBe('Mariés en 1962')
    expect(phraseDuMariage({ married: true, marriage: bloc({}) })).toBe('Mariés — date inconnue')
    expect(phraseDuMariage({ married: null, marriage: bloc({ place: 'Saule' }) })).toBe('Mariés à Saule — date inconnue')
    expect(phraseDuMariage({ married: false, marriage: bloc({}) })).toBe('Union libre')
    expect(phraseDuMariage({ married: null, marriage: bloc({}) })).toBeNull()
  })
})

describe('la rupture', () => {
  it('« divorcés » après un mariage, « séparés » sinon', () => {
    expect(motDeLaRupture({ married: true })).toBe('Divorcés')
    expect(motDeLaRupture({ married: false })).toBe('Séparés')
    expect(motDeLaRupture({ married: null })).toBe('Séparés')
    // Un mariage daté est un mariage, même sous une clé nulle.
    expect(motDeLaRupture({ married: null, marriage: bloc({ qualifier: 'annee', texte: '1962' }) })).toBe('Divorcés')
  })

  it('se date quand elle peut, et ne se dit pas sans rupture', () => {
    expect(phraseDeLaRupture({ married: true, divorced: true, divorce: bloc({ qualifier: 'annee', texte: '1998' }) })).toBe('Divorcés en 1998')
    expect(phraseDeLaRupture({ married: false, divorced: true, divorce: null })).toBe('Séparés')
    expect(phraseDeLaRupture({ married: true, divorced: false })).toBeNull()
  })
})

describe('la nature en un mot', () => {
  it('reprend les mots du serveur', () => {
    expect(natureEnClair(true)).toBe('oui')
    expect(natureEnClair(false)).toBe('non (union libre)')
    expect(natureEnClair(null)).toBe('on ne sait pas')
  })
})
