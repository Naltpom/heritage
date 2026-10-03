/**
 * Ce que la charge de l'année devient une fois posée sur un mois.
 *
 * ────────────────────────────────────────────────────────────────────
 * **Tout est client, et c'est le contrat.** `GET /api/anniversaires` rend
 * l'année entière en un appel — voir l'en-tête de `lib/api/evenements.ts`. Les
 * pastilles et les deux flèches sont des réglages de **rendu** : elles ne
 * redemandent rien. Ce fichier est donc l'endroit où le filtrage et le
 * regroupement se décident, et le seul.
 *
 * **Un jour vidé par le filtre n'a pas de clé.** `parJour()` n'inscrit que les
 * jours qui portent encore quelque chose. La case interroge la table, ne trouve
 * rien, et se désactive — sans que personne n'ait à distinguer « clé absente »
 * de « clé présente mais tableau vide », qui sont la même chose et se traitaient
 * inévitablement de deux façons.
 *
 * **L'âge est toujours calculé, et il peut être négatif.** Une clé, une formule,
 * aucune branche : `anneeAffichee - annee`. Remonter à 1890 pour une naissance
 * de 1902 rend −12, et ce n'est pas une anomalie — c'est un anniversaire qui n'a
 * pas encore eu lieu à l'année regardée. Le rendre `null` obligerait chaque
 * appelant à retrouver pourquoi, et la première branche oubliée peindrait
 * « −12 ans ». C'est le gabarit qui choisit de ne rien peindre.
 *
 * **`sansJour` n'est pas décoratif.** Le résumé du serveur compte à part ce qui
 * n'a ni jour ni mois connus. Sans ces trois compteurs, un lecteur qui voit
 * trente et une naissances en septembre n'apprend jamais qu'il en existe cent
 * soixante-six de plus dont personne n'a noté le jour — et il lit la grille
 * comme un inventaire complet, ce qu'elle n'est pas.
 * ────────────────────────────────────────────────────────────────────
 */
import type { EvenementAnniversaire, GenreEvenement, JourAnniversaire } from '@/lib/api'
import { GENRES } from '@/components/evenements/filtres'
import { cleJour, estBissextile, type CaseJour } from './calendrier'

/** Un genre et son nombre d'événements sans jour connu. */
export interface LigneSansJour {
  genre: GenreEvenement
  compte: number
}

/**
 * Les jours pourvus, rangés par clé `MM-JJ`, filtrés par les genres allumés.
 *
 * Les événements d'un même jour sont triés par année croissante : on lit un jour
 * du plus ancien au plus récent, ce qui est l'ordre d'une chronique. La clé sert
 * de départage pour que l'ordre soit **stable** — deux rendus successifs du même
 * jour ne doivent pas intervertir deux événements de la même année, sinon la
 * liste bouge sous les yeux à chaque bascule de pastille.
 *
 * Les tableaux d'un même jour sont fusionnés plutôt que remplacés. Le serveur
 * n'émet qu'une entrée par jour, mais une entrée écrasée perdrait des événements
 * **en silence**, et c'est une ligne.
 */
export function parJour(
  jours: readonly JourAnniversaire[],
  actifs: ReadonlySet<GenreEvenement>,
): Map<string, EvenementAnniversaire[]> {
  const table = new Map<string, EvenementAnniversaire[]>()

  for (const jour of jours) {
    const retenus = jour.evenements.filter((evenement) => actifs.has(evenement.genre))
    if (retenus.length === 0) continue

    const cle = cleJour(jour.mois, jour.jour)
    const deja = table.get(cle)
    if (deja === undefined) table.set(cle, retenus)
    else deja.push(...retenus)
  }

  for (const retenus of table.values()) {
    // Sans année (non partagée, ADR 0016) : en fin de jour, après la chronique.
    // Deux absentes donnent `NaN`, faux comme `0` : la clé départage.
    retenus.sort(
      (a, b) =>
        (a.annee ?? Infinity) - (b.annee ?? Infinity) || (a.cle < b.cle ? -1 : a.cle > b.cle ? 1 : 0),
    )
  }

  return table
}

/**
 * Les événements qui n'ont **aucune case** dans l'année affichée : les 29
 * février, quand l'année n'est pas bissextile.
 *
 * Ils sont rendus pour être listés sous la grille. Les reporter au 28 février ou
 * au 1er mars inventerait une date — voir la décision 3 de `calendrier.ts`.
 * L'appelant n'affiche cette liste que sous le mois de février : un 29 février
 * énuméré sous la grille de juillet serait une énigme.
 */
export function horsGrille(
  jours: readonly JourAnniversaire[],
  annee: number,
  actifs: ReadonlySet<GenreEvenement>,
): EvenementAnniversaire[] {
  if (estBissextile(annee)) return []
  return parJour(jours, actifs).get(cleJour(2, 29)) ?? []
}

/** L'écart entre l'année affichée et celle de l'événement. Négatif quand on
 *  remonte avant l'événement — voir l'en-tête. */
export function ageALAnniversaire(annee: number, anneeAffichee: number): number {
  return anneeAffichee - annee
}

/**
 * Combien d'événements la grille montre réellement.
 *
 * Seules les cases `dansLeMois` comptent. Les jours empruntés aux mois voisins
 * sont peints, mais ils ne sont pas du mois : les compter ferait passer pour
 * pourvu un mois entièrement vide dont le voisin porte quelque chose, et l'état
 * vide explicite ne paraîtrait jamais.
 */
export function compterSurLaGrille(
  grille: readonly CaseJour[],
  table: ReadonlyMap<string, readonly EvenementAnniversaire[]>,
): number {
  let total = 0
  for (const cellule of grille) {
    if (!cellule.dansLeMois) continue
    total += table.get(cellule.cle)?.length ?? 0
  }
  return total
}

/** Le nombre d'événements sans jour connu, pour les genres donnés. */
export function compterSansJour(
  sansJour: Readonly<Record<GenreEvenement, number>> | null,
  genres: Iterable<GenreEvenement>,
): number {
  if (sansJour === null) return 0
  let total = 0
  for (const genre of genres) total += sansJour[genre] ?? 0
  return total
}

/**
 * Le détail par genre **allumé**, dans l'ordre des pastilles.
 *
 * L'ordre vient de `GENRES` et non de l'ensemble `actifs` : un `Set` conserve
 * l'ordre d'insertion, c'est-à-dire l'ordre où le lecteur a cliqué, et le détail
 * changerait de rangée d'un clic à l'autre.
 */
export function detailSansJour(
  sansJour: Readonly<Record<GenreEvenement, number>> | null,
  actifs: ReadonlySet<GenreEvenement>,
): LigneSansJour[] {
  if (sansJour === null) return []
  return GENRES.filter((genre) => actifs.has(genre.id) && (sansJour[genre.id] ?? 0) > 0).map(
    (genre) => ({ genre: genre.id, compte: sansJour[genre.id] ?? 0 }),
  )
}

/**
 * Ce que les pastilles **éteintes** cachent encore.
 *
 * La ligne de tête compte les genres allumés, pour rester le compagnon de ce qui
 * est à l'écran. Ce reste-là est annoncé à part, dans le détail : sans lui, un
 * lecteur qui n'a que les naissances allumées ne saurait pas qu'il existe
 * d'autres événements sans jour — et avec lui dans le total, l'addition du
 * détail ne tomberait plus juste.
 */
export function compterSansJourMasques(
  sansJour: Readonly<Record<GenreEvenement, number>> | null,
  actifs: ReadonlySet<GenreEvenement>,
): number {
  return compterSansJour(
    sansJour,
    GENRES.filter((genre) => !actifs.has(genre.id)).map((genre) => genre.id),
  )
}
