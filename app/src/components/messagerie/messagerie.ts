/**
 * La messagerie avec l'administration, sans navigateur — donc testable
 * (`messagerie.test.ts`) : les trois natures, l'heure d'un message, les
 * séparateurs de jour et l'accusé « Envoyé » / « Vu à 14:32 ».
 */
import type { MessageDuFil, NatureDuFil, ResumeDeFil } from '@/lib/api'

export interface ChoixDeNature {
  valeur: NatureDuFil
  libelle: string
  /** La phrase qui invite à écrire, dans la zone de texte. */
  invite: string
}

export const NATURES: readonly ChoixDeNature[] = [
  { valeur: 'question', libelle: 'Une question', invite: 'Votre question, avec ce que vous cherchiez à faire…' },
  { valeur: 'suggestion', libelle: 'Une idée', invite: 'Ce qui vous manque, ou ce qui serait plus simple…' },
  { valeur: 'bug', libelle: 'Un bug', invite: 'Ce que vous avez fait, ce qui s’est passé, et sur quel appareil…' },
]

export function estNature(valeur: unknown): valeur is NatureDuFil {
  return valeur === 'question' || valeur === 'suggestion' || valeur === 'bug'
}

const MOIS = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'] as const
const JOURS = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'] as const

function deuxChiffres(n: number): string {
  return String(n).padStart(2, '0')
}

/** « 14:32 ». */
export function heure(date: Date): string {
  return `${deuxChiffres(date.getHours())}:${deuxChiffres(date.getMinutes())}`
}

/** Écart en jours **calendaires** : hier soir 23 h vu ce matin, c'est « hier ». */
function ecartEnJours(date: Date, maintenant: Date): number {
  const jour = (d: Date): number => new Date(d.getFullYear(), d.getMonth(), d.getDate(), 12).getTime()
  return Math.round((jour(maintenant) - jour(date)) / 86_400_000)
}

function dateCourte(date: Date, maintenant: Date): string {
  const base = `${date.getDate() === 1 ? '1er' : String(date.getDate())} ${MOIS[date.getMonth()]}`
  return date.getFullYear() === maintenant.getFullYear() ? base : `${base} ${date.getFullYear()}`
}

/** « 14:32 », « hier 14:32 », « 3 oct. 14:32 » — l'horodatage d'une liste. */
export function horodatageCourt(iso: string, maintenant: Date): string {
  const date = new Date(iso)
  if (Number.isNaN(date.getTime())) return ''
  const ecart = ecartEnJours(date, maintenant)
  if (ecart <= 0) return heure(date)
  if (ecart === 1) return `hier ${heure(date)}`
  return `${dateCourte(date, maintenant)} ${heure(date)}`
}

/** Le séparateur de jour dans la conversation : « Aujourd’hui », « Hier », « lundi 3 oct. ». */
export function libelleDuJour(iso: string, maintenant: Date): string {
  const date = new Date(iso)
  if (Number.isNaN(date.getTime())) return ''
  const ecart = ecartEnJours(date, maintenant)
  if (ecart <= 0) return 'Aujourd’hui'
  if (ecart === 1) return 'Hier'
  return `${JOURS[date.getDay()]} ${dateCourte(date, maintenant)}`
}

/** La clé du jour d'un message, pour savoir où poser un séparateur. */
export function cleDuJour(iso: string): string {
  const d = new Date(iso)
  return `${d.getFullYear()}-${d.getMonth()}-${d.getDate()}`
}

/**
 * L'accusé d'un message **que j'ai écrit** (ou que mon côté a écrit) :
 * « Envoyé », ou « Vu à 14:32 », « Vu hier à 14:32 », « Vu le 3 oct. à 14:32 ».
 * Côté administration, on dit aussi **qui** a lu : « Vu par Nathan à 14:32 »
 * n'a de sens que pour un message du membre — c'est l'administration qui lit.
 */
export function accuseDeLecture(message: Pick<MessageDuFil, 'vuLe' | 'vuPar'>, maintenant: Date): string {
  if (message.vuLe === null) return 'Envoyé'
  const date = new Date(message.vuLe)
  if (Number.isNaN(date.getTime())) return 'Vu'
  const par = message.vuPar === null || message.vuPar === '' ? '' : ` par ${message.vuPar}`
  const ecart = ecartEnJours(date, maintenant)
  if (ecart <= 0) return `Vu${par} à ${heure(date)}`
  if (ecart === 1) return `Vu${par} hier à ${heure(date)}`
  return `Vu${par} le ${dateCourte(date, maintenant)} à ${heure(date)}`
}

/** Le message est-il « de mon côté » du fil — le mien, ou celui d'un collègue administrateur ? */
export function estDeMonCote(message: Pick<MessageDuFil, 'deLAdministration'>, cote: ResumeDeFil['cote']): boolean {
  return message.deLAdministration === (cote === 'administration')
}

/**
 * L'index du dernier message de mon côté : c'est sous lui seul que l'accusé
 * s'écrit en toutes lettres ; les précédents n'en portent que la coche.
 */
export function dernierDeMonCote(messages: readonly MessageDuFil[], cote: ResumeDeFil['cote']): number {
  for (let i = messages.length - 1; i >= 0; i--) {
    const m = messages[i]
    if (m !== undefined && estDeMonCote(m, cote)) return i
  }
  return -1
}

/** « 2 non lus », pour une pastille et son lecteur d'écran. */
export function libelleNonLus(n: number): string {
  return n <= 0 ? '' : n === 1 ? '1 message non lu' : `${n} messages non lus`
}

/** Les fils qui attendent une lecture d'abord, puis le plus récent. */
export function rangerLesFils<T extends Pick<ResumeDeFil, 'nonLus' | 'modifieLe'>>(fils: readonly T[]): T[] {
  return [...fils].sort((a, b) => {
    if ((a.nonLus > 0) !== (b.nonLus > 0)) return a.nonLus > 0 ? -1 : 1
    return b.modifieLe.localeCompare(a.modifieLe)
  })
}
