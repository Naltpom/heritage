/**
 * Module `Soutien` : la page « Soutenir le site » (`/soutenir`).
 *
 * ────────────────────────────────────────────────────────────────────
 * `GET /api/soutien` — tout membre. Les dons passent par **Liberapay** :
 * le site ne touche à aucun paiement, il affiche le lien de don et ce que
 * Liberapay publie du compte (le rythme hebdomadaire, le nombre de
 * donateurs), relevé une fois par jour côté serveur.
 *
 * **Tous les montants sont en centimes entiers.** Ce sont des dons
 * *promis* (le rythme en cours), pas de l'argent encaissé — l'écran le dit.
 *
 * Les deux réglages (`soutien.liberapay`, `soutien.objectif_mensuel`) se
 * changent par la route générique des réglages, réservée à l'administration.
 * ────────────────────────────────────────────────────────────────────
 */

import { requeteApi } from './client'

export const CLE_PSEUDO_LIBERAPAY = 'soutien.liberapay'
export const CLE_OBJECTIF_MENSUEL = 'soutien.objectif_mensuel'

export interface RythmeDuSoutien {
  parSemaine: number
  parMois: number
  devise: string
  donateurs: number
  /** ISO 8601 : le moment du dernier relevé. */
  releveLe: string
}

export interface MoisDuSoutien {
  /** `AAAA-MM`. */
  mois: string
  centimes: number
  /** `false` pour le mois en cours, compté jusqu'à aujourd'hui. */
  complet: boolean
}

export interface AnneeDuSoutien {
  annee: number
  centimes: number
}

export type BilanDuSoutien =
  | { configure: false; objectifMensuel: number }
  | {
      configure: true
      pseudo: string
      /** La page de don Liberapay. */
      lien: string
      /** En euros entiers ; `0` : pas de jauge. */
      objectifMensuel: number
      /** `null` : aucun relevé encore (Liberapay injoignable au premier passage). */
      actuel: RythmeDuSoutien | null
      /** `AAAA-MM-JJ` du premier relevé. */
      depuis: string | null
      /** Le plus récent d'abord. */
      mois: MoisDuSoutien[]
      /** La plus récente d'abord. */
      annees: AnneeDuSoutien[]
    }

export function lireSoutien(): Promise<BilanDuSoutien> {
  return requeteApi<BilanDuSoutien>('/soutien')
}

/** `PATCH /api/administration/reglages/{cle}` — administration seulement. */
export function modifierReglageDuSoutien(cle: string, valeur: string | number): Promise<unknown> {
  return requeteApi<unknown>(`/administration/reglages/${encodeURIComponent(cle)}`, {
    methode: 'PATCH',
    corps: { value: valeur },
  })
}
