/**
 * Les appels d'API des tirages au sort récurrents — ADR 0009.
 *
 * « Noël côté papa, le 24 décembre » : un tirage porte des participants (des
 * personnes de l'arbre) et, chaque année, une **édition** qui dit qui offre à
 * qui.
 *
 * ────────────────────────────────────────────────────────────────────
 * **Le serveur ne rend jamais que ses propres attributions au lecteur.**
 *
 * `mesAttributions` contient celle du lecteur et celles des participants dont
 * il est le référent — rien d'autre, pas même pour l'organisateur. Le front
 * n'a donc rien à masquer : ce qu'il ne reçoit pas, il ne peut pas l'afficher
 * par erreur.
 * ────────────────────────────────────────────────────────────────────
 *
 * Chaque écriture rend le tirage **relu**, sérialisé pour le lecteur : l'écran
 * remplace son état par la réponse, sans recoller de morceaux.
 */

import { requeteApi } from './client'

export type PresenceAuTirage = 'sur_place' | 'a_distance' | 'absente'

export type StatutDEdition = 'ouverte' | 'tiree'

export interface PersonneDuTirage {
  personId: number
  libelle: string
}

export interface MonAttribution {
  donneur: PersonneDuTirage
  receveur: PersonneDuTirage
  /** La présence de celui qui offre : sur place, il apporte ; à distance, il fait transmettre. */
  presenceDuDonneur: PresenceAuTirage
}

export interface PresenceDUnParticipant extends PersonneDuTirage {
  presence: PresenceAuTirage
  presenceLibelle: string
}

export interface EditionDuTirage {
  annee: number
  /** `AAAA-MM-JJ`. */
  date: string
  passee: boolean
  statut: StatutDEdition
  statutLibelle: string
  /** Les années passées réellement respectées ; `null` avant le tirage. */
  memoireAppliquee: number | null
  tireeLe: string | null
  nombreDAttributions: number
  presences: PresenceDUnParticipant[]
  /** Ajoutés après le tirage : ils n'ont personne à qui offrir tant qu'il n'est pas refait. */
  arrivesApresLeTirage: number[]
  mesAttributions: MonAttribution[]
}

export interface ReglesDuTirage {
  nom: string
  jour: number
  mois: number
  lieu: string | null
  ouvert: boolean
  memoire: number
  exclureConjoints: boolean
}

export interface ResumeDeTirage extends ReglesDuTirage {
  id: number
  organisateur: { libelle: string; estMoi: boolean }
  peutGerer: boolean
  suisParticipant: boolean
  peutRejoindre: boolean
  nombreDeParticipants: number
  prochaineEdition: EditionDuTirage | null
}

export interface ParticipantDuTirage extends PersonneDuTirage {
  referent: PersonneDuTirage | null
  estMoi: boolean
  /** Le lecteur lit son attribution : c'est lui, ou il en est le référent. */
  suivi: boolean
}

export interface DetailDeTirage extends ResumeDeTirage {
  /** Au-delà, le tirage oubliera des années à coup sûr. */
  memoireConseillee: number
  participantsMin: number
  participants: ParticipantDuTirage[]
  editions: EditionDuTirage[]
}

export interface ListeDeTirages {
  /** `null` : le compte n'est rattaché à aucune personne, il ne peut rien rejoindre. */
  moi: PersonneDuTirage | null
  tirages: ResumeDeTirage[]
}

export function listerTirages(signal?: AbortSignal): Promise<ListeDeTirages> {
  return requeteApi<ListeDeTirages>('/tirages', signal === undefined ? {} : { signal })
}

export function lireTirage(id: number, signal?: AbortSignal): Promise<DetailDeTirage> {
  return requeteApi<DetailDeTirage>(`/tirages/${id}`, signal === undefined ? {} : { signal })
}

export function creerTirage(regles: ReglesDuTirage, participants: number[]): Promise<DetailDeTirage> {
  return requeteApi<DetailDeTirage>('/tirages', { methode: 'POST', corps: { ...regles, participants } })
}

export function reglerTirage(id: number, regles: ReglesDuTirage): Promise<DetailDeTirage> {
  return requeteApi<DetailDeTirage>(`/tirages/${id}`, { methode: 'PUT', corps: regles })
}

export function supprimerTirage(id: number): Promise<void> {
  return requeteApi<void>(`/tirages/${id}`, { methode: 'DELETE' })
}

export function ajouterParticipant(
  id: number,
  personId: number,
  referentPersonId: number | null = null,
): Promise<DetailDeTirage> {
  return requeteApi<DetailDeTirage>(`/tirages/${id}/participants`, {
    methode: 'POST',
    corps: { personId, referentPersonId },
  })
}

export function retirerParticipant(id: number, personId: number): Promise<DetailDeTirage> {
  return requeteApi<DetailDeTirage>(`/tirages/${id}/participants/${personId}`, { methode: 'DELETE' })
}

export function rejoindreTirage(id: number): Promise<DetailDeTirage> {
  return requeteApi<DetailDeTirage>(`/tirages/${id}/rejoindre`, { methode: 'POST' })
}

/** `date` omise : le jour habituel du tirage, cette année-là. */
export function ouvrirEdition(id: number, annee: number, date: string | null = null): Promise<DetailDeTirage> {
  return requeteApi<DetailDeTirage>(`/tirages/${id}/editions`, {
    methode: 'POST',
    corps: date === null ? { annee } : { annee, date },
  })
}

export function definirPresence(
  id: number,
  annee: number,
  personId: number,
  presence: PresenceAuTirage,
): Promise<DetailDeTirage> {
  return requeteApi<DetailDeTirage>(`/tirages/${id}/editions/${annee}/presences/${personId}`, {
    methode: 'PUT',
    corps: { presence },
  })
}

export function lancerLeTirage(id: number, annee: number): Promise<DetailDeTirage> {
  return requeteApi<DetailDeTirage>(`/tirages/${id}/editions/${annee}/tirage`, { methode: 'POST' })
}

export function annulerLeTirage(id: number, annee: number): Promise<DetailDeTirage> {
  return requeteApi<DetailDeTirage>(`/tirages/${id}/editions/${annee}/tirage`, { methode: 'DELETE' })
}
