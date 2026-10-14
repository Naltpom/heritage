import { describe, expect, it } from 'vitest'
import { bornesDeLaPage, nombreDePages, pageApresRetrait, pageDepuisRequete } from './pagination'

describe('la pagination d’une liste servie par l’API', () => {
  it('compte les pages, jamais moins d’une', () => {
    expect(nombreDePages(102, 25)).toBe(5)
    expect(nombreDePages(100, 25)).toBe(4)
    expect(nombreDePages(0, 25)).toBe(1)
  })

  it('dit les lignes de la page, la dernière comprise', () => {
    expect(bornesDeLaPage(1, 25, 102)).toEqual({ debut: 1, fin: 25 })
    expect(bornesDeLaPage(5, 25, 102)).toEqual({ debut: 101, fin: 102 })
    expect(bornesDeLaPage(1, 25, 0)).toEqual({ debut: 0, fin: 0 })
  })

  it('relit `?page=` sans se laisser bricoler', () => {
    expect(pageDepuisRequete('3')).toBe(3)
    expect(pageDepuisRequete(['2', '9'])).toBe(2)
    for (const brut of [undefined, '', '0', '-1', '1.5', 'abc']) expect(pageDepuisRequete(brut)).toBe(1)
  })

  it('recule d’une page quand la page s’est vidée, et seulement alors', () => {
    expect(pageApresRetrait(3, 0)).toBe(2)
    expect(pageApresRetrait(3, 4)).toBe(3)
    expect(pageApresRetrait(1, 0)).toBe(1)
  })
})
