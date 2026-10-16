/**
 * Le **suivi en direct** de l'administration (`/administration/suivi`).
 *
 * ────────────────────────────────────────────────────────────────────
 * **Aucune route nouvelle.** Le tableau de bord ne lit que des routes qui
 * existaient déjà, toutes en lecture et toutes derrière `ROLE_ADMIN` :
 *
 * - `GET /api/sante` — la sonde publique (§ 26) : « la base répond-elle ? ».
 *   L'écran en mesure lui-même la latence, aller-retour compris ;
 * - `GET /api/administration/journal` — le journal d'audit (§ 10.3), filtrable
 *   par action, acteur, type et identifiant de sujet, paginé à 100 au plus ;
 * - les compteurs déjà servis à l'en-tête et aux écrans d'administration
 *   (file « À traiter », comptes, chantier).
 *
 * Pourquoi ne pas avoir écrit une route d'agrégation `/api/administration/suivi` :
 * elle aurait dû lire les tables de cinq modules, alors qu'une table ne se lit
 * que par son propriétaire (§ 0.4). Les routes existantes sont déjà ce que
 * chaque propriétaire accepte de dire.
 * ────────────────────────────────────────────────────────────────────
 */

import { requeteApi } from './client'
import type { ListePaginee } from './types'

/** Les actions du journal (`App\Module\Core\Enum\AuditAction`). */
export const ACTIONS_DU_JOURNAL = [
  'creation',
  'modification',
  'suppression',
  'connexion',
  'connexion_echouee',
  'deconnexion',
  'invitation_creee',
  'invitation_revoquee',
  'invitation_utilisee',
  'rattachement_tente',
  'demande_soumise',
  'demande_validee',
  'demande_refusee',
  'demande_rejouee',
  'demande_caduque',
  'role_modifie',
  'compte_suspendu',
  'mot_de_passe_reinitialise',
  'reglage_modifie',
  'televersement',
  'media_supprime',
  'vue_comme_membre',
  'incarnation_debut',
  'incarnation_fin',
  'partage_naissance',
  'autorisation_naissance',
  'restriction_naissance',
  'demande_rgpd',
  'appel_modele_langage',
] as const

export type ActionDuJournal = (typeof ACTIONS_DU_JOURNAL)[number]

/** Une ligne de `core_audit_log`, telle que `AuditService::normaliser()` la rend. */
export interface LigneDuJournal {
  id: number
  /** ISO 8601 avec fuseau. */
  occurredAt: string
  actorUserId: number | null
  actorEmail: string | null
  /** Une valeur d'`AuditAction` — `string` : une action ajoutée côté serveur ne casse pas l'écran. */
  action: string
  subjectType: string
  subjectId: string | null
  subjectLabel: string | null
  changes: Record<string, unknown> | null
  ip: string | null
  userAgent: string | null
  impersonatorUserId: number | null
  impersonatorEmail: string | null
}

export interface FiltresDuJournal {
  action?: string | undefined
  actorUserId?: number | undefined
  subjectType?: string | undefined
  subjectId?: string | undefined
  page?: number | undefined
  /** Ramené à 100 par le serveur, sans erreur (§ 1.5). */
  parPage?: number | undefined
}

/** `GET /api/administration/journal` — administration seulement. */
export function lireJournal(filtres: FiltresDuJournal = {}, signal?: AbortSignal): Promise<ListePaginee<LigneDuJournal>> {
  return requeteApi<ListePaginee<LigneDuJournal>>('/administration/journal', {
    requete: {
      action: filtres.action === '' ? undefined : filtres.action,
      actorUserId: filtres.actorUserId,
      subjectType: filtres.subjectType === '' ? undefined : filtres.subjectType,
      subjectId: filtres.subjectId === '' ? undefined : filtres.subjectId,
      page: filtres.page,
      parPage: filtres.parPage,
    },
    ...(signal === undefined ? {} : { signal }),
  })
}

/** Ce que la sonde a vu : l'état, et le temps de l'aller-retour. */
export interface MesureDeSante {
  ok: boolean
  /** Millisecondes, aller-retour HTTP compris — ce que voit le navigateur. */
  latenceMs: number
  /** `Date.now()` de la mesure. */
  le: number
}

/**
 * `GET /api/sante`, chronométrée. Ne lève jamais : une panne est une mesure
 * (`ok: false`), pas une exception — c'est précisément ce que l'écran affiche.
 * `sansRattrapage` : la sonde ne lit pas la session, une réponse étrange ne
 * doit pas déconnecter l'administrateur.
 */
export async function sonderSante(maintenant: () => number = () => performance.now()): Promise<MesureDeSante> {
  const debut = maintenant()
  try {
    const corps = await requeteApi<{ statut?: string }>('/sante', { sansRattrapage: true })
    return { ok: corps.statut === 'ok', latenceMs: Math.round(maintenant() - debut), le: Date.now() }
  } catch {
    return { ok: false, latenceMs: Math.round(maintenant() - debut), le: Date.now() }
  }
}
