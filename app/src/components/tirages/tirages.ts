/**
 * Ce que les écrans des tirages décident, sorti des composants pour se tester
 * sans navigateur. Suite : `tirages.test.ts`.
 */

import type { DetailDeTirage, MonAttribution, PresenceAuTirage, ResumeDeTirage } from '@/lib/api'

const MOIS = [
  'janvier', 'février', 'mars', 'avril', 'mai', 'juin',
  'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre',
] as const

/** Les options du sélecteur de mois : `{ valeur: 12, libelle: 'décembre' }`. */
export const OPTIONS_DE_MOIS = MOIS.map((libelle, i) => ({ valeur: i + 1, libelle }))

export const PRESENCES: { valeur: PresenceAuTirage; libelle: string }[] = [
  { valeur: 'sur_place', libelle: 'Sur place' },
  { valeur: 'a_distance', libelle: 'À distance' },
  { valeur: 'absente', libelle: 'Ne participe pas' },
]

/** « 24 décembre », « 1er mai ». */
export function libelleDuJour(jour: number, mois: number): string {
  const nomDuMois = MOIS[mois - 1] ?? ''
  return `${jour === 1 ? '1er' : String(jour)} ${nomDuMois}`.trim()
}

/**
 * « jeudi 24 décembre 2026 », depuis `AAAA-MM-JJ`.
 *
 * On découpe la chaîne plutôt que de la confier à `new Date()` : une date sans
 * heure y est lue en **UTC**, et affichée à l'ouest de Greenwich elle tombe la
 * veille. Un Noël annoncé le 23 serait le pire défaut de cet écran.
 */
export function dateLongue(iso: string): string {
  const [a, m, j] = iso.split('-').map(Number)
  if (a === undefined || m === undefined || j === undefined || Number.isNaN(a + m + j)) return iso

  const jourDeSemaine = new Date(a, m - 1, j).toLocaleDateString('fr-FR', { weekday: 'long' })
  return `${jourDeSemaine} ${libelleDuJour(j, m)} ${a}`
}

/** La règle de mémoire, en français courant. */
export function libelleDeMemoire(memoire: number): string {
  if (memoire <= 0) return 'On peut retomber sur la même personne d’une année sur l’autre'
  if (memoire === 1) return 'Jamais la même personne que l’année précédente'
  return `Jamais la même personne pendant ${memoire} tirages`
}

/**
 * L'avertissement du réglage de mémoire, ou `null`.
 *
 * Au-delà de la mémoire conseillée, certains participants n'ont plus personne à
 * tirer : le tirage oubliera alors les années les plus anciennes. Le dire au
 * moment du réglage évite de le découvrir au moment du tirage.
 */
export function avertissementDeMemoire(memoire: number, conseillee: number): string | null {
  if (memoire <= conseillee) return null

  return conseillee === 0
    ? 'Avec si peu de participants, cette règle ne pourra pas être respectée : le tirage l’ignorera.'
    : `Avec ce nombre de participants, au-delà de ${conseillee} tirage${conseillee > 1 ? 's' : ''} la règle ne pourra pas toujours être respectée : le tirage oubliera alors les années les plus anciennes.`
}

/** « Vous offrez un cadeau à… » ou, pour un participant accompagné, « Léa offre un cadeau à… ». */
export function phraseDAttribution(attribution: MonAttribution, monPersonId: number | null): string {
  return attribution.donneur.personId === monPersonId
    ? 'Vous offrez un cadeau à'
    : `${attribution.donneur.libelle} offre un cadeau à`
}

/** Ce que le donneur doit faire du cadeau, selon qu'il vient ou non. */
export function consigneDeRemise(presenceDuDonneur: PresenceAuTirage): string {
  return presenceDuDonneur === 'a_distance'
    ? 'Vous ne serez pas là : faites transmettre le cadeau pour qu’il soit au rendez-vous le jour J.'
    : 'À apporter le jour J.'
}

/**
 * L'année que l'organisateur ouvrira vraisemblablement : celle-ci si son jour
 * n'est pas passé et qu'elle n'existe pas encore, sinon la suivante libre.
 */
export function anneeProposee(tirage: Pick<DetailDeTirage, 'jour' | 'mois' | 'editions'>, aujourdhui: Date): number {
  const existantes = new Set(tirage.editions.map((e) => e.annee))
  const cetteAnnee = aujourdhui.getFullYear()
  const jourDeCetteAnnee = new Date(cetteAnnee, tirage.mois - 1, tirage.jour)
  const debutDuJour = new Date(aujourdhui.getFullYear(), aujourdhui.getMonth(), aujourdhui.getDate())

  let annee = jourDeCetteAnnee >= debutDuJour ? cetteAnnee : cetteAnnee + 1
  while (existantes.has(annee)) annee += 1

  return annee
}

/** Le personId du lecteur, s'il participe — lu dans la liste, pas deviné. */
export function monPersonId(tirage: Pick<DetailDeTirage, 'participants'>): number | null {
  return tirage.participants.find((p) => p.estMoi)?.personId ?? null
}

/**
 * La ligne d'état d'un tirage dans la liste.
 *
 * **Elle ne nomme jamais le receveur**, même au lecteur : la liste s'ouvre
 * volontiers devant d'autres, un soir de famille, et c'est l'écran du tirage
 * qui le dévoile — derrière un geste.
 */
export function etatDansLaListe(tirage: ResumeDeTirage): string {
  const edition = tirage.prochaineEdition
  if (edition === null) return 'Aucune édition ouverte'
  if (edition.statut === 'tiree') {
    return edition.mesAttributions.length > 0
      ? `${edition.annee} · tirage fait — à découvrir`
      : `${edition.annee} · tirage fait`
  }
  return `${edition.annee} · tirage à venir`
}
