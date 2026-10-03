/**
 * Le carnet de notes de l'administrateur — ce que l'écran calcule sans le
 * serveur : le tri, la recherche, l'aperçu d'une ligne, et ce qui reste à
 * enregistrer.
 */
import type { NoteDuCarnet, SaisieDeNote } from '@/lib/api'

export function saisieVide(): SaisieDeNote {
  return { titre: '', texte: '', epinglee: false }
}

export function saisieDepuisNote(note: NoteDuCarnet): SaisieDeNote {
  return { titre: note.titre, texte: note.texte, epinglee: note.epinglee }
}

/** Vrai quand la saisie diffère de la note enregistrée (ou d'une note vide). */
export function modifiee(saisie: SaisieDeNote, note: NoteDuCarnet | null): boolean {
  const reference = note === null ? saisieVide() : saisieDepuisNote(note)
  return (
    saisie.titre.trim() !== reference.titre.trim() ||
    saisie.texte !== reference.texte ||
    saisie.epinglee !== reference.epinglee
  )
}

/** Le même ordre que le serveur : épinglées d'abord, puis la plus récente. */
export function trierNotes(notes: readonly NoteDuCarnet[]): NoteDuCarnet[] {
  return [...notes].sort((a, b) => {
    if (a.epinglee !== b.epinglee) return a.epinglee ? -1 : 1
    if (a.updatedAt !== b.updatedAt) return a.updatedAt < b.updatedAt ? 1 : -1
    return b.id - a.id
  })
}

function sansAccents(texte: string): string {
  return texte.normalize('NFD').replace(/\p{Diacritic}/gu, '').toLowerCase()
}

/** Recherche dans le titre et le texte, sans accents ni casse. */
export function filtrerNotes(notes: readonly NoteDuCarnet[], terme: string): NoteDuCarnet[] {
  const cherche = sansAccents(terme.trim())
  if (cherche === '') return [...notes]
  return notes.filter((note) => sansAccents(`${note.titre}\n${note.texte}`).includes(cherche))
}

/** La première ligne non vide du texte, coupée, pour la liste. */
export function apercu(texte: string, longueur = 90): string {
  const ligne = texte.split('\n').map((l) => l.trim()).find((l) => l !== '') ?? ''
  return ligne.length > longueur ? `${ligne.slice(0, longueur - 1)}…` : ligne
}

/** « modifiée le 3 oct. 2026 à 14:05 ». */
export function dateDeModification(iso: string): string {
  const date = new Date(iso)
  const jour = date.toLocaleDateString('fr-FR', { day: 'numeric', month: 'short', year: 'numeric' })
  const heure = date.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })
  return `modifiée le ${jour} à ${heure}`
}
