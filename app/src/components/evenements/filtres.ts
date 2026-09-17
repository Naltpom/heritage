/**
 * Les pastilles de genre, partagées par la carte et les anniversaires.
 *
 * Même forme que `components/arbre/graphe.ts` : une table de définitions
 * exportée, l'état dans la vue, et l'interface en boutons `aria-pressed`.
 *
 * ────────────────────────────────────────────────────────────────────
 * **Cinq genres, et non onze types.** Le serveur distingue onze types
 * d'événement ; les pastilles en regroupent cinq, parce qu'un lecteur cherche
 * « les unions » et non « les mariages, et aussi les divorces ». Le type précis
 * reste sur chaque événement, et c'est lui qu'affiche la carte d'un lieu ou
 * d'un jour.
 *
 * **Les naissances sont allumées, et elles seules.** C'est la demande, et c'est
 * aussi le genre le mieux pourvu : 224 années de naissance connues, contre 171
 * décès et 64 mariages.
 *
 * **On ne peut pas éteindre le dernier filtre.** Un ensemble vide rend un écran
 * blanc, qui se lit comme une panne et non comme un choix. La règle existe déjà
 * pour les branches de la chronologie, et pour la même raison.
 *
 * **Un genre sans donnée est désactivé, avec une infobulle — jamais
 * silencieusement vide.** C'est la règle de `graphe.ts` : « un filtre dont la
 * donnée n'est pas servie est désactivé, pas silencieusement vide ». Sans elle,
 * une pastille qui ne trouve rien est indistinguable d'une pastille dont la
 * donnée n'est jamais arrivée.
 * ────────────────────────────────────────────────────────────────────
 */
import type { GenreEvenement } from '@/lib/api'

export interface DefinitionGenre {
  id: GenreEvenement
  libelle: string
  /** Ce que l'infobulle dit quand la pastille est disponible. */
  description: string
}

export const GENRES: readonly DefinitionGenre[] = [
  {
    id: 'naissance',
    libelle: 'Naissances',
    description: 'Les naissances, telles que les actes les datent.',
  },
  {
    id: 'deces',
    libelle: 'Décès',
    description: 'Les décès. Une personne vivante n’en a pas, et n’apparaît pas ici.',
  },
  {
    id: 'union',
    libelle: 'Unions',
    description: 'Mariages et divorces. Une union ne paraît que si ses deux époux sont visibles.',
  },
  {
    id: 'bapteme',
    libelle: 'Baptêmes',
    description: 'Baptêmes civils et religieux.',
  },
  {
    id: 'autre',
    libelle: 'Autres faits',
    description: 'Professions, résidences, recensements, inhumations et faits divers.',
  },
] as const

/** L'état de départ des deux écrans. */
export function genresParDefaut(): ReadonlySet<GenreEvenement> {
  return new Set<GenreEvenement>(['naissance'])
}

/**
 * Allume ou éteint un genre, **sauf s'il est le dernier allumé**.
 *
 * Rend un nouvel ensemble : l'état de la vue est remplacé, jamais muté, pour que
 * la réactivité de Vue le voie.
 */
export function basculerGenre(
  actifs: ReadonlySet<GenreEvenement>,
  id: GenreEvenement,
): ReadonlySet<GenreEvenement> {
  if (actifs.has(id)) {
    if (actifs.size <= 1) return actifs
    const suivant = new Set(actifs)
    suivant.delete(id)
    return suivant
  }
  return new Set(actifs).add(id)
}

/**
 * Un genre est disponible quand le résumé du serveur en compte au moins un.
 *
 * `resume` vient de la charge et compte **avant** les filtres du client : c'est
 * ce qui distingue « ce genre n'existe pas dans cet arbre » de « ce genre est
 * masqué par la sélection en cours ».
 */
export function genreDisponible(
  resume: Readonly<Record<GenreEvenement, number>> | null,
  id: GenreEvenement,
): boolean {
  // Résumé absent ⇒ tout est disponible. Griser une pastille parce que la charge
  // n'est pas arrivée serait dire « il n'y en a pas » là où la vérité est « on
  // ne sait pas encore ».
  if (resume === null) return true
  return (resume[id] ?? 0) > 0
}

/** Ce que l'infobulle dit. Une pastille désactivée doit dire pourquoi ; sans
 *  cela elle passe pour cassée. */
export function titreDeLaPastille(
  genre: DefinitionGenre,
  resume: Readonly<Record<GenreEvenement, number>> | null,
): string {
  if (!genreDisponible(resume, genre.id)) {
    return `Aucun événement de ce genre n’est visible dans votre arbre.`
  }
  const compte = resume === null ? null : (resume[genre.id] ?? 0)
  return compte === null ? genre.description : `${genre.description} (${compte})`
}

/** Le nombre d'éléments retenus par la sélection, pour l'annoncer. */
export function compterRetenus(
  resume: Readonly<Record<GenreEvenement, number>> | null,
  actifs: ReadonlySet<GenreEvenement>,
): number {
  if (resume === null) return 0
  let total = 0
  for (const id of actifs) total += resume[id] ?? 0
  return total
}
