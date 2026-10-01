/**
 * La logique de « Poser une question », sans Vue : ce qui se teste sans rendre.
 *
 * Le point délicat est le **découpage d'une réponse** : le serveur rend un texte
 * où les fiches citées sont écrites `[Marie Becquelin](personne:12)`. On le
 * découpe en paragraphes, puis en morceaux de texte et en liens — et l'écran
 * rend chaque morceau comme du **texte**, jamais comme du HTML. Un modèle qui
 * écrirait une balise ne ferait apparaître qu'une balise.
 */

import { analyserErreur, type PersonneCitee } from '@/lib/api'

/** Le contrat de la route : 1 à 1 000 caractères. */
export const QUESTION_MAX = 1000


/** La même forme que `MentionsDeFiches::MOTIF` côté serveur. */
const MENTION = /\[([^[\]\n]{1,160})\]\(personne:(\d{1,9})\)/gu

export type Morceau =
  | { type: 'texte'; texte: string }
  | { type: 'fiche'; personId: number; libelle: string }

/** Un paragraphe de la réponse ; `puce` : une ligne de liste (« - … »). */
export interface Paragraphe {
  puce: boolean
  morceaux: Morceau[]
}

/** Une ligne de la réponse, découpée en texte et en liens vers les fiches. */
export function morceaux(ligne: string): Morceau[] {
  const resultat: Morceau[] = []
  let depuis = 0

  for (const trouve of ligne.matchAll(MENTION)) {
    const debut = trouve.index ?? 0
    if (debut > depuis) resultat.push({ type: 'texte', texte: ligne.slice(depuis, debut) })
    resultat.push({ type: 'fiche', personId: Number(trouve[2]), libelle: trouve[1] ?? '' })
    depuis = debut + trouve[0].length
  }

  if (depuis < ligne.length) resultat.push({ type: 'texte', texte: ligne.slice(depuis) })
  return resultat
}

/**
 * La réponse en paragraphes : une ligne vide sépare deux paragraphes, une ligne
 * qui commence par « - », « * » ou « • » est une puce. Le reste des
 * conventions Markdown (gras, titres) n'est pas interprété : la consigne les
 * interdit, et un astérisque égaré reste un astérisque.
 */
export function paragraphes(reponse: string): Paragraphe[] {
  const resultat: Paragraphe[] = []

  for (const brute of reponse.split('\n')) {
    const ligne = brute.trim()
    if (ligne.length === 0) continue
    const puce = /^[-*•]\s+/.test(ligne)
    resultat.push({ puce, morceaux: morceaux(puce ? ligne.replace(/^[-*•]\s+/, '') : ligne) })
  }

  return resultat
}

/** Une question se pose si elle n'est pas vide et tient dans la limite. */
export function peutPoser(question: string): boolean {
  const propre = question.trim()
  return propre.length > 0 && propre.length <= QUESTION_MAX
}

export function mentionQuotaQuestions(restant: number | null): string {
  if (restant === null) return ''
  if (restant <= 0) return 'Plus aucune question possible aujourd’hui'
  return restant === 1
    ? 'Encore 1 question possible aujourd’hui'
    : `Encore ${restant} questions possibles aujourd’hui`
}

/**
 * Le titre posé au-dessus du message du serveur — trois attitudes différentes
 * à dire avant la phrase : attendre demain, réessayer, ou renoncer pour
 * l'instant (pas de clé sur cette installation).
 */
export function titreErreurQuestion(erreur: unknown): string | null {
  const { statut, code } = analyserErreur(erreur)
  if (statut === 429) return 'Quota du jour atteint'
  if (statut === 503 || code === 'assistant_indisponible') return 'Assistant indisponible'
  if (statut === 502 || code === 'assistant_erreur') return 'L’assistant n’a pas pu répondre'
  return null
}

/** « Restreinte » : le nom masqué seul, jamais de dates — la règle du graphe. */
export function libelleDeFiche(personne: PersonneCitee): string {
  if (personne.visibilite !== 'complete') return personne.displayName
  const dates = personne.ligneDates?.trim() ?? ''
  return dates.length > 0 ? `${personne.displayName} (${dates})` : personne.displayName
}

/** « 1 oct., 14:05 » — assez pour retrouver une conversation dans la liste. */
export function dateCourte(iso: string, maintenant: Date = new Date()): string {
  const date = new Date(iso)
  if (Number.isNaN(date.getTime())) return ''
  const memeJour = date.toDateString() === maintenant.toDateString()
  const heure = date.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })
  if (memeJour) return `aujourd’hui, ${heure}`
  const jour = date.toLocaleDateString('fr-FR', {
    day: 'numeric',
    month: 'short',
    ...(date.getFullYear() === maintenant.getFullYear() ? {} : { year: 'numeric' }),
  })
  return `${jour}, ${heure}`
}

/** Des exemples, pour un écran vide : ce qu'on peut demander, dit par l'exemple. */
export const EXEMPLES_DE_QUESTIONS: readonly string[] = [
  'Qui est né à Étaples ?',
  'Comment suis-je lié à Marie Becquelin ?',
  'Que sait-on de mon arrière-grand-père paternel ?',
]
