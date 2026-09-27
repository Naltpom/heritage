/**
 * Le carrousel des jours de l'accueil, sans navigateur. Suite : `jours.test.ts`.
 *
 * Toutes les dates sont **locales et à midi** : un décalage de jour calculé à
 * minuit tombe dans le trou d'un changement d'heure, et « demain » devient
 * « aujourd'hui » deux nuits par an.
 */
import type { EvenementAnniversaire, JourAnniversaire, ResumeDeTirage } from '@/lib/api'
import { estBissextile } from '@/components/anniversaires/calendrier'
import { libelleMois } from '@/components/anniversaires/mois'

export function aMidi(date: Date): Date {
  return new Date(date.getFullYear(), date.getMonth(), date.getDate(), 12)
}

export function decaler(date: Date, jours: number): Date {
  const d = aMidi(date)
  d.setDate(d.getDate() + jours)
  return d
}

/** L'écart en jours entiers entre deux dates, `b − a`. */
export function ecartEnJours(a: Date, b: Date): number {
  return Math.round((aMidi(b).getTime() - aMidi(a).getTime()) / 86_400_000)
}

/** « Aujourd'hui », « Demain », « Hier », sinon le jour de la semaine. */
export function libelleRelatif(date: Date, aujourdhui: Date): string {
  const ecart = ecartEnJours(aujourdhui, date)
  if (ecart === 0) return 'Aujourd’hui'
  if (ecart === 1) return 'Demain'
  if (ecart === -1) return 'Hier'
  const jour = date.toLocaleDateString('fr-FR', { weekday: 'long' })
  return jour.charAt(0).toUpperCase() + jour.slice(1)
}

/** « 27 septembre », « 1er mai ». */
export function libelleDate(date: Date): string {
  const jour = date.getDate()
  return `${jour === 1 ? '1er' : String(jour)} ${libelleMois(date.getMonth() + 1)}`
}

/** « sam. », pour les pastilles du bandeau des jours. */
export function jourCourt(date: Date): string {
  return date.toLocaleDateString('fr-FR', { weekday: 'short' })
}

/**
 * Le 29 février est fêté le 28 les années non bissextiles.
 *
 * Sans cette règle, une personne née un 29 février n'apparaîtrait sur
 * l'accueil qu'une année sur quatre — le défaut qu'on ne découvre qu'en
 * février, justement chez elle.
 */
function correspond(mois: number, jour: number, date: Date): boolean {
  const m = date.getMonth() + 1
  const j = date.getDate()
  if (mois === m && jour === j) return true
  return mois === 2 && jour === 29 && m === 2 && j === 28 && !estBissextile(date.getFullYear())
}

/** Les anniversaires d'un jour, du plus ancien au plus récent. */
export function anniversairesDuJour(jours: readonly JourAnniversaire[], date: Date): EvenementAnniversaire[] {
  return jours
    .filter((j) => correspond(j.mois, j.jour, date))
    .flatMap((j) => j.evenements)
    .slice()
    .sort((a, b) => a.annee - b.annee || a.cle.localeCompare(b.cle))
}

/** Les tirages dont le jour habituel tombe ce jour-là. */
export function tiragesDuJour(tirages: readonly ResumeDeTirage[], date: Date): ResumeDeTirage[] {
  return tirages.filter((t) => correspond(t.mois, t.jour, date))
}

/** La prochaine occurrence du jour habituel d'un tirage, aujourd'hui compris. */
export function prochaineOccurrence(tirage: Pick<ResumeDeTirage, 'jour' | 'mois'>, aujourdhui: Date): Date {
  for (const annee of [aujourdhui.getFullYear(), aujourdhui.getFullYear() + 1, aujourdhui.getFullYear() + 2]) {
    const jour = tirage.mois === 2 && tirage.jour === 29 && !estBissextile(annee) ? 28 : tirage.jour
    const date = new Date(annee, tirage.mois - 1, jour, 12)
    if (ecartEnJours(aujourdhui, date) >= 0) return date
  }
  return new Date(aujourdhui.getFullYear() + 1, tirage.mois - 1, tirage.jour, 12)
}

/** « aujourd'hui », « demain », « dans 12 jours ». */
export function dansCombien(date: Date, aujourdhui: Date): string {
  const ecart = ecartEnJours(aujourdhui, date)
  if (ecart === 0) return 'aujourd’hui'
  if (ecart === 1) return 'demain'
  return `dans ${ecart} jours`
}

/** Ce qu'on dit d'un tirage sur la carte d'un jour — sans jamais nommer le receveur. */
export function etatDuTirageCeJour(tirage: ResumeDeTirage, date: Date): string {
  const edition = tirage.prochaineEdition
  if (edition === null || edition.annee !== date.getFullYear()) return 'Pas encore d’édition ouverte pour cette année'
  if (edition.statut === 'tiree') return edition.mesAttributions.length > 0 ? 'Tirage fait — à découvrir' : 'Tirage fait'
  return 'Tirage à venir'
}
