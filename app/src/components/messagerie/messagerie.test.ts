/**
 * La messagerie : l'accusé « Envoyé » / « Vu à… », les horodatages et le
 * rangement des fils.
 */
import { describe, expect, it } from 'vitest'
import type { MessageDuFil } from '@/lib/api'
import {
  accuseDeLecture,
  dernierDeMonCote,
  estNature,
  horodatageCourt,
  libelleDuJour,
  libelleNonLus,
  rangerLesFils,
} from './messagerie'

const MAINTENANT = new Date(2026, 9, 7, 16, 0)

function message(deLAdministration: boolean, vuLe: string | null = null, vuPar: string | null = null): MessageDuFil {
  return { id: 1, texte: 'x', deLAdministration, auteur: 'A', estMoi: false, envoyeLe: '', vuLe, vuPar }
}

describe('accuseDeLecture', () => {
  it('dit « Envoyé » tant que l’autre côté n’a pas ouvert le fil', () => {
    expect(accuseDeLecture(message(false), MAINTENANT)).toBe('Envoyé')
  })

  it('dit l’heure du premier regard, et qui a lu côté administration', () => {
    expect(accuseDeLecture(message(true, new Date(2026, 9, 7, 14, 32).toISOString()), MAINTENANT)).toBe('Vu à 14:32')
    expect(accuseDeLecture(message(false, new Date(2026, 9, 7, 9, 5).toISOString(), 'Nathan'), MAINTENANT)).toBe(
      'Vu par Nathan à 09:05',
    )
  })

  it('dit « hier », puis la date', () => {
    expect(accuseDeLecture(message(true, new Date(2026, 9, 6, 23, 10).toISOString()), MAINTENANT)).toBe('Vu hier à 23:10')
    expect(accuseDeLecture(message(true, new Date(2026, 9, 1, 8, 0).toISOString()), MAINTENANT)).toBe('Vu le 1er oct. à 08:00')
  })
})

describe('horodatages', () => {
  it('l’heure seule aujourd’hui, « hier », puis la date', () => {
    expect(horodatageCourt(new Date(2026, 9, 7, 8, 3).toISOString(), MAINTENANT)).toBe('08:03')
    expect(horodatageCourt(new Date(2026, 9, 6, 8, 3).toISOString(), MAINTENANT)).toBe('hier 08:03')
    expect(horodatageCourt(new Date(2025, 11, 24, 8, 3).toISOString(), MAINTENANT)).toBe('24 déc. 2025 08:03')
    expect(horodatageCourt('pas une date', MAINTENANT)).toBe('')
  })

  it('les séparateurs de jour', () => {
    expect(libelleDuJour(new Date(2026, 9, 7, 1).toISOString(), MAINTENANT)).toBe('Aujourd’hui')
    expect(libelleDuJour(new Date(2026, 9, 6, 1).toISOString(), MAINTENANT)).toBe('Hier')
    expect(libelleDuJour(new Date(2026, 9, 5, 1).toISOString(), MAINTENANT)).toBe('lundi 5 oct.')
  })
})

describe('le fil', () => {
  it('trouve le dernier message de mon côté', () => {
    const messages = [message(false), message(true), message(false), message(true)]
    expect(dernierDeMonCote(messages, 'membre')).toBe(2)
    expect(dernierDeMonCote(messages, 'administration')).toBe(3)
    expect(dernierDeMonCote([], 'membre')).toBe(-1)
  })

  it('range les non lus d’abord, puis le plus récent', () => {
    const fils = [
      { id: 1, nonLus: 0, modifieLe: '2026-10-07T10:00:00+02:00' },
      { id: 2, nonLus: 2, modifieLe: '2026-10-01T10:00:00+02:00' },
      { id: 3, nonLus: 0, modifieLe: '2026-10-07T12:00:00+02:00' },
    ]
    expect(rangerLesFils(fils).map((f) => f.id)).toEqual([2, 3, 1])
  })

  it('reconnaît les natures et dit les non-lus', () => {
    expect(estNature('bug')).toBe(true)
    expect(estNature('plainte')).toBe(false)
    expect(libelleNonLus(0)).toBe('')
    expect(libelleNonLus(1)).toBe('1 message non lu')
    expect(libelleNonLus(3)).toBe('3 messages non lus')
  })
})
