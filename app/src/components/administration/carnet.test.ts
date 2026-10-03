import { describe, expect, it } from 'vitest'
import type { NoteDuCarnet } from '@/lib/api'
import { apercu, filtrerNotes, modifiee, saisieDepuisNote, saisieVide, trierNotes } from './carnet'

function note(id: number, champs: Partial<NoteDuCarnet> = {}): NoteDuCarnet {
  return {
    id,
    titre: `Note ${id}`,
    texte: '',
    epinglee: false,
    createdAt: '2026-10-01T10:00:00+02:00',
    updatedAt: '2026-10-01T10:00:00+02:00',
    ...champs,
  }
}

describe('carnet — tri', () => {
  it('met les épinglées devant, puis la plus récente', () => {
    const notes = [
      note(1, { updatedAt: '2026-10-03T09:00:00+02:00' }),
      note(2, { epinglee: true, updatedAt: '2026-09-01T09:00:00+02:00' }),
      note(3, { updatedAt: '2026-10-02T09:00:00+02:00' }),
    ]
    expect(trierNotes(notes).map((n) => n.id)).toEqual([2, 1, 3])
  })
})

describe('carnet — recherche', () => {
  const notes = [note(1, { titre: 'Vidéo', texte: 'voix, musique' }), note(2, { titre: 'Idées', texte: 'Généalogie' })]

  it('cherche dans le titre et le texte, sans accents ni casse', () => {
    expect(filtrerNotes(notes, 'VIDEO').map((n) => n.id)).toEqual([1])
    expect(filtrerNotes(notes, 'genealogie').map((n) => n.id)).toEqual([2])
    expect(filtrerNotes(notes, 'MUSIQUE').map((n) => n.id)).toEqual([1])
  })

  it('rend tout sans terme', () => {
    expect(filtrerNotes(notes, '  ')).toHaveLength(2)
  })
})

describe('carnet — aperçu', () => {
  it('prend la première ligne non vide et la coupe', () => {
    expect(apercu('\n\n  Première ligne \nseconde')).toBe('Première ligne')
    expect(apercu('a'.repeat(100), 10)).toBe(`${'a'.repeat(9)}…`)
  })
})

describe('carnet — modifications en cours', () => {
  it('ne voit rien à enregistrer sur une saisie intacte', () => {
    const n = note(1, { texte: 'x' })
    expect(modifiee(saisieDepuisNote(n), n)).toBe(false)
    expect(modifiee(saisieVide(), null)).toBe(false)
  })

  it('voit un texte, un titre ou une épingle changés', () => {
    const n = note(1)
    expect(modifiee({ ...saisieDepuisNote(n), texte: 'y' }, n)).toBe(true)
    expect(modifiee({ ...saisieDepuisNote(n), epinglee: true }, n)).toBe(true)
    expect(modifiee({ ...saisieVide(), titre: 'Nouvelle' }, null)).toBe(true)
  })
})
