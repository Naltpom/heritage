import { describe, expect, it } from 'vitest'
import {
  bornes,
  compte,
  enRomain,
  generationEtEpoque,
  moisLisible,
  nomDeBranche,
  part,
  pourcent,
  siecleCourt,
  siecleLong,
  surN,
} from './format'

/**
 * Les mots d'Explorer › Stats. Ce qui se casse ici se voit à l'écran comme une
 * faute : « XVIIIIᵉ siècle », « sur 1 personnes », « NaN % ».
 */
describe('les siècles', () => {
  it('s’écrivent en chiffres romains', () => {
    expect(enRomain(19)).toBe('XIX')
    expect(enRomain(14)).toBe('XIV')
    expect(enRomain(21)).toBe('XXI')
    expect(siecleCourt(18)).toBe('XVIIIᵉ s.')
    expect(siecleLong(20)).toBe('XXᵉ siècle')
  })
})

describe('les parts', () => {
  it('ne rendent jamais NaN sur un total nul', () => {
    expect(part(3, 0)).toBe(0)
    expect(pourcent(3, 0)).toBe('—')
  })

  it('arrondissent au pour cent, sans écrire 0 % pour une part non nulle', () => {
    expect(pourcent(1, 3)).toBe('33 %')
    expect(pourcent(1, 1000)).toBe('< 1 %')
    expect(pourcent(0, 10)).toBe('0 %')
  })
})

describe('la taille de l’échantillon', () => {
  it('accorde au singulier jusqu’à un, au pluriel au-delà', () => {
    expect(surN(1, 'personne')).toBe('sur 1 personne')
    expect(surN(0, 'couple')).toBe('sur 0 couple')
    expect(surN(412, 'personne')).toBe('sur 412 personnes')
    expect(surN(2, 'décès', 'décès')).toBe('sur 2 décès')
    expect(compte(1012, 'personne').replace(/\s/g, ' ')).toBe('1 012 personnes')
  })
})

describe('les libellés', () => {
  it('un mois du serveur se lit en français', () => {
    expect(moisLisible('2026-09')).toBe('sept. 2026')
    expect(moisLisible('n’importe quoi')).toBe('n’importe quoi')
  })

  it('une génération se situe par son époque, sans nom de parenté', () => {
    expect(generationEtEpoque(-4, 1850)).toBe('Génération −4 · vers 1850')
    expect(generationEtEpoque(0, null)).toBe('Génération 0')
  })

  it('une branche prend son patronyme de tête, ou son côté à défaut', () => {
    expect(nomDeBranche('pp', 'Romarin')).toBe('Romarin')
    expect(nomDeBranche('pm', null)).toBe('Mère du père')
    expect(nomDeBranche('hors', null)).toBe('Hors des branches')
  })

  it('les bornes d’une vie ou d’une union', () => {
    expect(bornes(1702, 1805)).toBe('1702 – 1805')
    expect(bornes(1952, null, true)).toBe('depuis 1952')
    expect(bornes(null, 1805)).toBe('')
  })
})
