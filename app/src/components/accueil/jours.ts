/**
 * Les jours de l'accueil, sans navigateur : la fête du jour, « En mémoire » et
 * les jours qui viennent. Suite : `jours.test.ts`.
 *
 * Toutes les dates sont **locales et à midi** : un décalage de jour calculé à
 * minuit tombe dans le trou d'un changement d'heure, et « demain » devient
 * « aujourd'hui » deux nuits par an.
 */
import type { EvenementAnniversaire, JourAnniversaire } from '@/lib/api'
import { estBissextile } from '@/components/anniversaires/calendrier'
import { libelleDeType } from '@/components/anniversaires/libelles'
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
    // Sans année (non partagée, ADR 0016) : en dernier. Voir `parJour()`.
    .sort((a, b) => (a.annee ?? Infinity) - (b.annee ?? Infinity) || a.cle.localeCompare(b.cle))
}

/** « aujourd'hui », « demain », « dans 12 jours ». */
export function dansCombien(date: Date, aujourdhui: Date): string {
  const ecart = ecartEnJours(aujourdhui, date)
  if (ecart === 0) return 'aujourd’hui'
  if (ecart === 1) return 'demain'
  return `dans ${ecart} jours`
}

/** « Vendredi 30 octobre » — l'en-tête de l'accueil ; l'année est à part. */
export function jourEnToutesLettres(date: Date): string {
  const jour = date.toLocaleDateString('fr-FR', { weekday: 'long' })
  return `${jour.charAt(0).toUpperCase()}${jour.slice(1)} ${libelleDate(date)}`
}

/** « Demain », sinon « Dim. 1 » : la colonne étroite des jours qui viennent. */
export function libelleJourAVenir(date: Date, aujourdhui: Date): string {
  if (ecartEnJours(aujourdhui, date) === 1) return 'Demain'
  const court = jourCourt(date)
  return `${court.charAt(0).toUpperCase()}${court.slice(1)} ${date.getDate()}`
}

/**
 * Un événement est « des vivants » dès qu'un de ses sujets l'est : un mariage
 * dont un époux vit encore se fête encore. Sinon, il va sous « En mémoire ».
 */
export function estDesVivants(e: EvenementAnniversaire): boolean {
  return e.sujets.some((s) => s.vivant)
}

/** « Séverine CHEMIN », « Jean PROVOST & Odile PROVOST ». */
export function nomsDesSujets(e: EvenementAnniversaire): string {
  return e.sujets.map((s) => s.displayName).join(' & ')
}

/** Les initiales de la pastille : prénom et nom pour une personne, les deux
 *  prénoms pour un couple. */
export function initiales(e: EvenementAnniversaire): string {
  const mots = (nom: string) => nom.trim().split(/\s+/).filter(Boolean)
  if (e.sujets.length >= 2) return e.sujets.slice(0, 2).map((s) => mots(s.displayName)[0]?.charAt(0) ?? '').join('').toUpperCase()
  const m = mots(e.sujets[0]?.displayName ?? '')
  const premier = m[0]?.charAt(0) ?? ''
  const dernier = m.length > 1 ? (m[m.length - 1]?.charAt(0) ?? '') : ''
  return `${premier}${dernier}`.toUpperCase()
}

/** La teinte de la pastille, celle des fiches de l'arbre ; un couple n'a pas de sexe. */
export function teinte(e: EvenementAnniversaire): 'homme' | 'femme' | 'inconnu' {
  if (e.sujets.length !== 1) return 'inconnu'
  const sexe = e.sujets[0]?.sex
  return sexe === 'masculin' ? 'homme' : sexe === 'feminin' ? 'femme' : 'inconnu'
}

/** Combien d'années on fête — `null` sans année partagée (ADR 0016) ou sous un an. */
export function anneesFetees(e: EvenementAnniversaire, annee: number): number | null {
  if (e.annee === undefined) return null
  const n = annee - e.annee
  return n >= 1 ? n : null
}

function ans(n: number): string {
  return n === 1 ? '1 an' : `${n} ans`
}

/** « Anniversaire », « Anniversaire de mariage », sinon le type tel quel. */
export function natureDeLaFete(e: EvenementAnniversaire): string {
  if (e.type === 'naissance') return 'Anniversaire'
  if (e.type === 'mariage') return 'Anniversaire de mariage'
  return libelleDeType(e.type)
}

/** Le titre de la carte du jour : « Séverine CHEMIN a 53 ans », sinon les noms. */
export function titreDeLaFete(e: EvenementAnniversaire, annee: number): string {
  const n = anneesFetees(e, annee)
  if (e.type === 'naissance' && n !== null && e.sujets.length === 1) return `${nomsDesSujets(e)} a ${ans(n)}`
  return nomsDesSujets(e)
}

/** La ligne sous le titre : la date telle que le serveur l'écrit, le lieu, et
 *  pour un mariage le nombre d'années. */
export function detailDeLaFete(e: EvenementAnniversaire, annee: number): string {
  const morceaux = [e.texte]
  if (e.lieu) morceaux.push(e.lieu)
  const n = anneesFetees(e, annee)
  if (e.type === 'mariage' && n !== null) morceaux.push(`${ans(n)} de mariage`)
  return morceaux.filter(Boolean).join(' · ')
}

/**
 * La dernière colonne des jours qui viennent, en deux mots : « 24 ans »,
 * « 58 ans de mariage ». En mémoire, l'âge d'un défunt ne se fête pas : on dit
 * le type et l'année.
 */
export function detailCourt(e: EvenementAnniversaire, annee: number): string {
  const n = anneesFetees(e, annee)
  if (!estDesVivants(e)) return e.annee === undefined ? libelleDeType(e.type) : `${libelleDeType(e.type)} ${e.annee}`
  if (n === null) return natureDeLaFete(e)
  if (e.type === 'naissance') return ans(n)
  if (e.type === 'mariage') return `${ans(n)} de mariage`
  return `${libelleDeType(e.type)} · ${ans(n)}`
}

export interface JourQuiVient {
  cle: string
  date: Date
  evenement: EvenementAnniversaire
}

/** Les fêtes des `nombre` jours qui suivent aujourd'hui, jour par jour, les
 *  vivants avant les défunts dans chaque jour. */
export function joursQuiViennent(jours: readonly JourAnniversaire[], aujourdhui: Date, nombre = 7): JourQuiVient[] {
  const resultat: JourQuiVient[] = []
  for (let d = 1; d <= nombre; d++) {
    const date = decaler(aujourdhui, d)
    const du = anniversairesDuJour(jours, date)
    for (const evenement of [...du.filter(estDesVivants), ...du.filter((e) => !estDesVivants(e))]) {
      resultat.push({ cle: `${d}:${evenement.cle}`, date, evenement })
    }
  }
  return resultat
}
