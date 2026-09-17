/**
 * Le mois affiché : son nom, et les deux flèches.
 *
 * ────────────────────────────────────────────────────────────────────
 * **Une table figée, et non `Intl.DateTimeFormat`.** Deux raisons, et la
 * seconde est la vraie.
 *
 * D'abord la dépendance : un binaire Node compilé sans ICU complet ne connaît
 * que `en-US`, et `Intl` rend alors « March » sans rien signaler — un mois
 * anglais dans une application française, décelé en production. Le produit
 * n'ajoute pas `full-icu` pour douze mots.
 *
 * Ensuite la testabilité : une table se compare à un almanach, une ligne à la
 * fois, sans que le résultat dépende de la machine qui exécute la suite. Douze
 * chaînes qui ne changeront plus ne méritent pas un formateur.
 *
 * **`moisCourant()` ne lit l'horloge qu'à l'appel.** Un `new Date()` posé à la
 * portée du module fixerait « aujourd'hui » au chargement du fichier — c'est-à-
 * dire, dans une suite de tests, au jour où elle tourne. Le paramètre par défaut
 * est évalué à chaque appel, ce qui donne les deux propriétés d'un coup : le
 * produit lit l'heure vraie, la suite passe la sienne.
 *
 * **Une date de case n'est pas une date d'événement.** `libelleDeDate()`
 * compose « 14 mars » à partir de coordonnées de calendrier, ce qui n'a rien à
 * voir avec l'interdiction du § 14 : celle-ci porte sur la date **d'un
 * événement**, que le serveur écrit en toutes lettres dans `texte` (« vers
 * 1903 », « entre 1901 et 1909 ») et que le front recopie sans jamais la
 * reformer. Un panneau de jour affiche donc les deux : son titre, qui est une
 * case, et les `texte`, qui sont des dates.
 * ────────────────────────────────────────────────────────────────────
 */

/** Le mois affiché, tel que la vue le tient. `mois` va de 1 à 12 — et non de 0 à
 *  11 comme `Date` : la moitié des erreurs de calendrier vient de ce mélange, et
 *  la conversion se fait donc en un seul endroit, au contact de `Date`. */
export interface MoisAffiche {
  annee: number
  mois: number
}

export const LIBELLES_MOIS: readonly string[] = Object.freeze([
  'janvier',
  'février',
  'mars',
  'avril',
  'mai',
  'juin',
  'juillet',
  'août',
  'septembre',
  'octobre',
  'novembre',
  'décembre',
])

/** Le nom du mois, ou la chaîne vide hors de 1–12. La chaîne vide plutôt
 *  qu'`undefined` : c'est du texte destiné au gabarit, et `undefined` s'y peint
 *  « undefined ». */
export function libelleMois(mois: number): string {
  return LIBELLES_MOIS[mois - 1] ?? ''
}

/** « mars 2026 » — le titre de l'écran. */
export function titreDuMois(affiche: MoisAffiche): string {
  return `${libelleMois(affiche.mois)} ${affiche.annee}`
}

/** « 1er mars », « 14 mars ». Le premier du mois se dit « 1er » en français, et
 *  « 1 mars » s'entend mal dans un lecteur d'écran. */
export function libelleDeDate(mois: number, jour: number): string {
  return `${jour === 1 ? '1er' : String(jour)} ${libelleMois(mois)}`
}

/** Décembre → janvier de l'année précédente. Le passage d'année est la moitié
 *  du travail de cette fonction, et c'est celle qui se casse. */
export function moisPrecedent(affiche: MoisAffiche): MoisAffiche {
  return affiche.mois === 1
    ? { annee: affiche.annee - 1, mois: 12 }
    : { annee: affiche.annee, mois: affiche.mois - 1 }
}

/** Décembre → janvier de l'année suivante. */
export function moisSuivant(affiche: MoisAffiche): MoisAffiche {
  return affiche.mois === 12
    ? { annee: affiche.annee + 1, mois: 1 }
    : { annee: affiche.annee, mois: affiche.mois + 1 }
}

/** Le mois d'aujourd'hui — voir l'en-tête pour le paramètre. */
export function moisCourant(reference: Date = new Date()): MoisAffiche {
  return { annee: reference.getFullYear(), mois: reference.getMonth() + 1 }
}
