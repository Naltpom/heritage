/**
 * Le nom d'un type d'événement, au singulier.
 *
 * `filtres.ts` porte les cinq **genres** au pluriel — ce sont des libellés de
 * pastille, « Naissances », « Unions ». Un panneau de jour énumère des
 * événements un par un : « Mariage » y est juste, « Unions » n'y veut rien dire.
 *
 * **La table est un `Record<TypeEvenement, string>` exprès.** C'est le typeur
 * qui garantit qu'elle est complète : le jour où le contrat ajoute un douzième
 * type, ce fichier ne compile plus, au lieu de peindre une chaîne vide dans le
 * panneau. Un `Partial` avec un repli aurait laissé passer l'oubli en silence.
 *
 * Sans cette ligne, un jour qui porte une naissance, un décès et un mariage
 * n'affiche que trois noms et trois dates : rien ne distingue l'anniversaire de
 * naissance de la date de mort.
 */
import type { TypeEvenement } from '@/lib/api'

export const LIBELLES_TYPE: Readonly<Record<TypeEvenement, string>> = Object.freeze({
  naissance: 'Naissance',
  deces: 'Décès',
  mariage: 'Mariage',
  divorce: 'Divorce',
  profession: 'Profession',
  residence: 'Résidence',
  inhumation: 'Inhumation',
  recensement: 'Recensement',
  bapteme_religieux: 'Baptême religieux',
  bapteme: 'Baptême civil',
  autre: 'Fait',
})

export function libelleDeType(type: TypeEvenement): string {
  return LIBELLES_TYPE[type]
}
