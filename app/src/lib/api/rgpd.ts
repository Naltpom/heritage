/**
 * Les demandes RGPD — dans l'application, sans courriel (ADR 0016, révision du
 * 3 octobre 2026).
 *
 * Une composante de date de naissance partagée reste visible au moins trente
 * jours : la retirer plus tôt est une **demande**, qu'un administrateur
 * applique ou refuse. Le même formulaire sert aux autres droits — rectifier une
 * donnée, toute autre demande —, qui se décrivent en texte libre.
 *
 * Côté membre : `GET` et `POST /api/session/demandes-rgpd`. Côté
 * administrateur : la file (`GET /api/administration/demandes-rgpd`, en
 * attente d'abord, de la plus ancienne à la plus récente, puis les traitées)
 * et la décision (`POST …/{id}/traiter`). Appliquer un `retrait_partage`
 * passe les composantes visées à « personne » **tout de suite**, verrou ou pas.
 */

import { requeteApi } from './client'
import type { ComposantesCochees } from './types'

export type NatureDemandeRgpd = 'retrait_partage' | 'rectification' | 'autre'
export type DecisionDemandeRgpd = 'appliquee' | 'refusee'

export interface DemandeRgpd {
  id: number
  nature: NatureDemandeRgpd
  /** Les composantes à retirer — toutes à `false` hors `retrait_partage`. */
  aRetirer: ComposantesCochees
  message: string | null
  creeeLe: string
  traiteeLe: string | null
  decision: DecisionDemandeRgpd | null
  reponse: string | null
}

/** La même, vue de l'administration : avec le compte qui l'a faite. */
export interface DemandeRgpdAdministration extends DemandeRgpd {
  memberId: number
  userId: number
  userEmail: string
}

/**
 * Le corps d'une demande. Pour `retrait_partage`, au moins une composante
 * (`422` sur `composantes` sinon) et le message est facultatif ; pour les
 * autres natures, le message est exigé (`422` sur `message`, 4 000 caractères
 * au plus).
 */
export interface CorpsDemandeRgpd {
  nature: NatureDemandeRgpd
  annee?: boolean
  mois?: boolean
  jour?: boolean
  message?: string
}

/** Longueur maximale du message, côté serveur comme ici. */
export const LONGUEUR_MAX_MESSAGE_RGPD = 4000

/** `GET /api/session/demandes-rgpd` — les demandes de ce compte. */
export function listerMesDemandesRgpd(): Promise<{ elements: DemandeRgpd[] }> {
  return requeteApi<{ elements: DemandeRgpd[] }>('/session/demandes-rgpd')
}

/**
 * `POST /api/session/demandes-rgpd` — `201`. `409 compte_non_rattache` sans
 * ligne membre ; `409 vue_comme_membre_active` sous la bascule « voir comme ».
 */
export function creerDemandeRgpd(corps: CorpsDemandeRgpd): Promise<DemandeRgpd> {
  return requeteApi<DemandeRgpd>('/session/demandes-rgpd', { methode: 'POST', corps })
}

/** `GET /api/administration/demandes-rgpd` — en attente d'abord, les plus anciennes en tête. */
export function listerDemandesRgpd(): Promise<{ elements: DemandeRgpdAdministration[] }> {
  return requeteApi<{ elements: DemandeRgpdAdministration[] }>('/administration/demandes-rgpd')
}

/**
 * `POST /api/administration/demandes-rgpd/{id}/traiter`. La réponse est exigée
 * pour un refus — c'est tout ce que le membre en saura. `409
 * demande_deja_traitee` : un autre administrateur est passé avant.
 */
export function traiterDemandeRgpd(
  id: number,
  decision: DecisionDemandeRgpd,
  reponse?: string,
): Promise<DemandeRgpdAdministration> {
  return requeteApi<DemandeRgpdAdministration>(`/administration/demandes-rgpd/${id}/traiter`, {
    methode: 'POST',
    corps: reponse !== undefined && reponse.trim() !== '' ? { decision, reponse: reponse.trim() } : { decision },
  })
}
