/**
 * Ce que décident les écrans du lien personnel, sans navigateur.
 */
import { describe, expect, it } from 'vitest'
import type { LectureParLien } from '@/lib/api'
import {
  adresseDuLien,
  boutonDeCadeauParLien,
  confirmationDeRegeneration,
  confirmationDeRevocation,
  etatVuParLien,
} from './tirages'

function lecture(partiel: Partial<LectureParLien> = {}): LectureParLien {
  return {
    tirage: { nom: 'Noël', lieu: null },
    edition: { annee: 2026, date: '2026-12-24', statut: 'ouverte', passee: false },
    participant: { personId: 1, libelle: 'Léa', presence: 'sur_place', peutChangerPresence: true },
    attributions: [],
    ...partiel,
  }
}

describe('adresseDuLien', () => {
  it('rend absolue l’url relative de l’API', () => {
    expect(adresseDuLien('/t/abc', 'https://heritage.local')).toBe('https://heritage.local/t/abc')
    expect(adresseDuLien('/t/abc', 'https://heritage.local/')).toBe('https://heritage.local/t/abc')
    expect(adresseDuLien('t/abc', 'http://localhost:5173')).toBe('http://localhost:5173/t/abc')
  })

  it('laisse une url déjà absolue telle quelle', () => {
    expect(adresseDuLien('https://a.b/t/x', 'https://c.d')).toBe('https://a.b/t/x')
  })
})

describe('etatVuParLien', () => {
  it('distingue les étapes de l’année', () => {
    expect(etatVuParLien(lecture({ edition: null }))).toContain('Aucune année')
    expect(etatVuParLien(lecture())).toContain('pas encore eu lieu')
    expect(etatVuParLien(lecture({ edition: { annee: 2026, date: '2026-12-24', statut: 'ouverte', passee: true } }))).toContain('passée')
  })

  it('tirage fait : avec lui, sans lui, ou absent', () => {
    const tiree = { annee: 2026, date: '2026-12-24', statut: 'tiree' as const, passee: false }
    const a = { donneur: { personId: 1, libelle: 'Léa' }, receveur: { personId: 2, libelle: 'X' }, presenceDuDonneur: 'sur_place' as const }

    expect(etatVuParLien(lecture({ edition: tiree, attributions: [a] }))).toBe('Le tirage est fait.')
    expect(etatVuParLien(lecture({ edition: tiree }))).toContain('refaire')
    expect(
      etatVuParLien(lecture({ edition: tiree, participant: { personId: 1, libelle: 'Léa', presence: 'absente', peutChangerPresence: false } })),
    ).toContain('n’y participez pas')
  })
})

describe('boutonDeCadeauParLien', () => {
  it('« je » pour soi, le nom pour celui qu’on accompagne', () => {
    const r = { personId: 9, libelle: 'X' }
    expect(boutonDeCadeauParLien({ donneur: { personId: 1, libelle: 'Léa' }, receveur: r, presenceDuDonneur: 'sur_place' }, 1)).toBe(
      'Voir à qui je fais le cadeau',
    )
    expect(boutonDeCadeauParLien({ donneur: { personId: 2, libelle: 'Tom' }, receveur: r, presenceDuDonneur: 'sur_place' }, 1)).toBe(
      'Voir à qui Tom fait le cadeau',
    )
  })
})

describe('confirmations du lien', () => {
  it('régénérer dit que l’ancien lien cesse de marcher', () => {
    expect(confirmationDeRegeneration('Léa').message).toContain('ne marchera plus')
  })

  it('révoquer est un geste de danger', () => {
    expect(confirmationDeRevocation('Léa').danger).toBe(true)
  })
})
