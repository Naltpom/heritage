/**
 * Les tirages de Noël — ceux du site noel2026.naltpom.fr, dont le serveur est
 * celui d'heritage (module `Noel`).
 *
 * Heritage avait son propre tirage au sort récurrent (module `Tirage`, ADR
 * 0009) ; il a été retiré au profit de celui de noel2026, plus simple et déjà
 * adopté par la famille. Heritage ne fait plus que **montrer** ces tirages
 * dans ses événements : répondre, régler, lancer le tirage se font sur
 * noel2026 (`SITE_DE_NOEL`, route `noel.tirage`). D'où trois lectures, et
 * aucune écriture ici.
 *
 * ────────────────────────────────────────────────────────────────────
 * **Deux listes, deux publics.**
 *
 * - `GET /api/noel/mes-tirages` : ceux que j'ai créés ou auxquels j'ai
 *   répondu — tout compte connecté, **en attente compris** (c'est le public
 *   du site de Noël) ;
 * - `GET /api/noel/tirages` : **tous** les tirages, membres actifs
 *   seulement — un compte en attente reçoit `403 regime_en_attente`, et
 *   l'écran retombe alors sur les siens (`listerTiragesDeNoelVisibles`).
 * ────────────────────────────────────────────────────────────────────
 *
 * Les deux rendent un **tableau nu**, pas une enveloppe.
 */

import { requeteApi } from './client'
import { estErreurApi } from './erreurs'

/** `ouvert` : on répond encore ; `tire` : chacun connaît (ou peut connaître) son receveur. */
export type StatutDuTirageDeNoel = 'ouvert' | 'tire'

export type ReponseAuTirageDeNoel = 'oui' | 'non'

export interface ResumeDuTirageDeNoel {
  /** L'identifiant public, celui de l'adresse `noel2026…/t/{code}`. */
  code: string
  titre: string
  /** ISO 8601 : le jour où le tirage se fait (automatiquement, s'il n'est pas lancé avant). */
  dateTirage: string
  /** ISO 8601 : le jour de la remise des cadeaux. */
  dateEvenement: string
  statut: StatutDuTirageDeNoel
  estCreateur: boolean
  /** `null` : je n'ai pas (encore) répondu — ou je ne suis pas convié. */
  reponse: ReponseAuTirageDeNoel | null
  nbParticipants: number
  /** En euros ; `null` : pas de budget fixé. */
  budget: number | null
  lieu: string | null
}

/** La personne à qui j'offre. `photoUrl` est de même origine (`/api/noel/photos/{id}`). */
export interface AttributionDeNoel {
  userId: number
  prenom: string
  nom: string
  photoUrl: string | null
  /** Son âge le jour de la remise ; `null` quand l'arbre ne le dit pas. */
  age?: number | null
  /** « cousine germaine », « conjoint » ; `null` sans lien établi dans l'arbre. */
  lien?: string | null
}

function options(signal?: AbortSignal): { signal?: AbortSignal } {
  return signal === undefined ? {} : { signal }
}

export function listerMesTiragesDeNoel(signal?: AbortSignal): Promise<ResumeDuTirageDeNoel[]> {
  return requeteApi<ResumeDuTirageDeNoel[]>('/noel/mes-tirages', options(signal))
}

/** Tous les tirages — `403` pour un compte en attente. */
export function listerTousLesTiragesDeNoel(signal?: AbortSignal): Promise<ResumeDuTirageDeNoel[]> {
  return requeteApi<ResumeDuTirageDeNoel[]>('/noel/tirages', options(signal))
}

/**
 * Tous les tirages pour un membre, les siens pour un compte en attente : le
 * refus (`403`) n'est pas une panne, il dit seulement « pas encore membre ».
 * Toute autre erreur remonte.
 */
export async function listerTiragesDeNoelVisibles(signal?: AbortSignal): Promise<ResumeDuTirageDeNoel[]> {
  try {
    return await listerTousLesTiragesDeNoel(signal)
  } catch (e) {
    if (estErreurApi(e) && e.statut === 403) return listerMesTiragesDeNoel(signal)
    throw e
  }
}

/**
 * À qui j'offre. `404` tant que le tirage n'est pas fait, ou quand je n'y
 * participe pas : l'appelant ne le demande que pour un tirage `tire` où j'ai
 * répondu `oui`.
 */
export function lireMonAttributionDeNoel(code: string, signal?: AbortSignal): Promise<AttributionDeNoel> {
  return requeteApi<AttributionDeNoel>(`/noel/tirages/${encodeURIComponent(code)}/mon-attribution`, options(signal))
}
