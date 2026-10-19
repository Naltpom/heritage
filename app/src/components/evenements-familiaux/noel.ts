/**
 * Ce que disent les cartes d'un **tirage de Noël** (noel2026), sans navigateur.
 *
 * Heritage ne fait que montrer ces tirages : chaque carte renvoie vers le site
 * de Noël pour répondre ou régler. Ce fichier traduit le résumé de l'API
 * (`ResumeDuTirageDeNoel`) en phrases — les dates, où j'en suis, ce que le
 * tirage promet. Suite : `noel.test.ts`.
 *
 * Le site de Noël tutoie (« Tu participes ») : les cartes reprennent sa voix,
 * puisqu'elles parlent de lui et y mènent. Le reste d'heritage vouvoie.
 */

import type { ResumeDuTirageDeNoel } from '@/lib/api'
import { SITE_DE_NOEL } from '@/router/routes'
import { dansCombien, ecartEnJours, libelleDate } from '@/components/accueil/jours'

/**
 * Un instant ISO 8601 de l'API (`2026-12-24T00:00:00+01:00`), ramené à **son
 * jour**, à midi local — comme toutes les dates des écrans (`jours.ts`).
 *
 * On lit le jour écrit dans la chaîne plutôt que de convertir l'instant : le
 * serveur l'écrit à l'heure de Paris, et « minuit le 24 à +01:00 » converti
 * sur un téléphone à l'ouest (ou dans un test en UTC) tomberait le 23. Le jour
 * de Noël est celui que l'organisateur a choisi, où que l'on soit. `null` pour
 * une chaîne illisible.
 */
export function lireInstant(iso: string): Date | null {
  const morceaux = /^(\d{4})-(\d{2})-(\d{2})/.exec(iso)
  if (morceaux === null) return null
  const date = new Date(Number(morceaux[1]), Number(morceaux[2]) - 1, Number(morceaux[3]), 12)
  return Number.isNaN(date.getTime()) ? null : date
}

/** L'adresse du tirage sur le site de Noël : c'est là qu'on répond et qu'on règle. */
export function adresseSurLeSiteDeNoel(code: string): string {
  return `${SITE_DE_NOEL}/t/${encodeURIComponent(code)}`
}

/** Créer un tirage se fait sur le site de Noël. */
export const CREER_SUR_LE_SITE_DE_NOEL = `${SITE_DE_NOEL}/creer`

/** « 24 décembre 2026 ». */
export function dateLongue(date: Date): string {
  return `${libelleDate(date)} ${date.getFullYear()}`
}

/** La remise des cadeaux est passée : le tirage va dans « Passés ». */
export function estPasse(tirage: Pick<ResumeDuTirageDeNoel, 'dateEvenement'>, aujourdhui: Date): boolean {
  const remise = lireInstant(tirage.dateEvenement)
  return remise !== null && ecartEnJours(aujourdhui, remise) < 0
}

/**
 * Le compte à rebours qui compte **maintenant** : tant que le tirage est
 * ouvert, celui du tirage (c'est jusque-là qu'on répond) ; une fois tiré,
 * celui de la remise des cadeaux.
 */
export function compteARebours(tirage: Pick<ResumeDuTirageDeNoel, 'statut' | 'dateTirage' | 'dateEvenement'>, aujourdhui: Date): string {
  const remise = lireInstant(tirage.dateEvenement)
  if (tirage.statut === 'ouvert') {
    const date = lireInstant(tirage.dateTirage)
    if (date === null) return 'Tirage à venir'
    // Une échéance dépassée mais pas encore traitée par la tâche planifiée.
    if (ecartEnJours(aujourdhui, date) < 0) return 'Tirage imminent'
    return `Tirage ${dansCombien(date, aujourdhui)}`
  }
  if (remise === null) return 'Tirage fait'
  const ecart = ecartEnJours(aujourdhui, remise)
  if (ecart < 0) return 'Cadeaux remis'
  return `Tirage fait · cadeaux ${dansCombien(remise, aujourdhui)}`
}

/** Où j'en suis, dit en une pastille. */
export type EtatDeMaReponse = 'participe' | 'a_repondre' | 'ne_participe_pas' | 'hors_tirage'

export function etatDeMaReponse(tirage: Pick<ResumeDuTirageDeNoel, 'statut' | 'reponse'>): EtatDeMaReponse {
  if (tirage.reponse === 'oui') return 'participe'
  if (tirage.reponse === 'non') return 'ne_participe_pas'
  // Pas répondu : tant que c'est ouvert, il est encore temps ; après, on n'en est pas.
  return tirage.statut === 'ouvert' ? 'a_repondre' : 'hors_tirage'
}

export const LIBELLES_DE_REPONSE: Readonly<Record<EtatDeMaReponse, string>> = {
  participe: 'Tu participes',
  a_repondre: 'Tu n’as pas encore répondu',
  ne_participe_pas: 'Tu ne participes pas',
  hors_tirage: 'Tu n’es pas dans ce tirage',
}

/** « 30 € », « 25,50 € ». */
export function budgetEnEuros(budget: number): string {
  return `${budget.toLocaleString('fr-FR', { maximumFractionDigits: 2 })} €`
}

/** « 1 participant », « 7 participants ». */
export function participants(nombre: number): string {
  return `${nombre} participant${nombre > 1 ? 's' : ''}`
}

/** « Cousine germaine » : le lien rendu par l'API, une majuscule en tête. */
export function lienLisible(lien: string): string {
  return lien.charAt(0).toUpperCase() + lien.slice(1)
}

/** Ma réponse demande-t-elle de connaître mon receveur ? Tiré, et j'y suis. */
export function aUnReceveur(tirage: Pick<ResumeDuTirageDeNoel, 'statut' | 'reponse'>): boolean {
  return tirage.statut === 'tire' && tirage.reponse === 'oui'
}
