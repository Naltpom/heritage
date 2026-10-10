import { describe, expect, it } from 'vitest'
import { formaterMontant, libelleDuMois, partDeLObjectif, pseudoValide } from './soutien'

// `Intl` sépare les milliers et le symbole par des espaces insécables : on les
// ramène à des espaces ordinaires pour écrire les attentes lisiblement.
const lisible = (texte: string): string => texte.replace(/[  ]/g, ' ')

describe('la page « Soutenir le site »', () => {
  it('met les centimes en euros, sans décimales quand le compte est rond', () => {
    expect(lisible(formaterMontant(3250))).toBe('32,50 €')
    expect(lisible(formaterMontant(4500))).toBe('45 €')
    expect(lisible(formaterMontant(0))).toBe('0 €')
  })

  it('nomme le mois en toutes lettres', () => {
    expect(libelleDuMois('2026-10')).toBe('octobre 2026')
    expect(libelleDuMois('n’importe quoi')).toBe('n’importe quoi')
  })

  it('remplit la jauge vers l’objectif, sans la faire déborder', () => {
    expect(partDeLObjectif(2250, 45)).toBe(50)
    expect(partDeLObjectif(9000, 45)).toBe(100)
    expect(partDeLObjectif(1000, 0)).toBeNull()
  })

  it('refuse un pseudo que Liberapay n’accepterait pas', () => {
    expect(pseudoValide('Naltpom')).toBe(true)
    expect(pseudoValide('nathan.p-2')).toBe(true)
    expect(pseudoValide('')).toBe(false)
    expect(pseudoValide('..')).toBe(false)
    expect(pseudoValide('a/b')).toBe(false)
    expect(pseudoValide('x'.repeat(33))).toBe(false)
  })
})
