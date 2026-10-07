/**
 * Les appels d'API de la **messagerie** — module `Messagerie` : un membre écrit
 * à l'administration (une question, une suggestion, un bug), l'administration
 * lui répond, et chacun voit ce que l'autre a lu.
 *
 * ────────────────────────────────────────────────────────────────────
 * **Ouvrir un fil pose l'accusé de lecture** sur les messages de l'autre côté
 * (`GET …/fils/{id}`). La liste, elle, ne marque rien : elle compte
 * seulement les `nonLus`.
 * ────────────────────────────────────────────────────────────────────
 *
 * Chaque écriture rend le fil **relu** : l'écran remplace son état par la
 * réponse, sans recoller de morceaux.
 */

import { requeteApi } from './client'

export type NatureDuFil = 'question' | 'suggestion' | 'bug'
/** `clos` se dit « Réglé » : le membre peut toujours y écrire, ce qui le rouvre. */
export type StatutDuFil = 'ouvert' | 'clos'

export interface ResumeDeFil {
  id: number
  nature: NatureDuFil
  natureLibelle: string
  sujet: string
  statut: StatutDuFil
  statutLibelle: string
  membre: { userId: number; libelle: string }
  /** De quel côté du fil se tient le lecteur. */
  cote: 'membre' | 'administration'
  nombreDeMessages: number
  /** Les messages de l'autre côté que le lecteur n'a pas encore ouverts. */
  nonLus: number
  dernierMessage: {
    extrait: string
    deLAdministration: boolean
    envoyeLe: string
    vuLe: string | null
  } | null
  creeLe: string
  modifieLe: string
}

export interface MessageDuFil {
  id: number
  texte: string
  deLAdministration: boolean
  auteur: string
  estMoi: boolean
  envoyeLe: string
  /** L'accusé de lecture : posé à la première ouverture par l'autre côté. */
  vuLe: string | null
  /** L'administrateur qui a lu le message d'un membre. */
  vuPar: string | null
}

export interface DetailDeFil extends ResumeDeFil {
  messages: MessageDuFil[]
}

export interface ListeDeFils {
  elements: ResumeDeFil[]
  total: number
}

export interface ListeDeFilsAdministration extends ListeDeFils {
  /** La somme des `nonLus` : la pastille de l'onglet. */
  nonLus: number
}

/* ───────────── Côté membre ───────────── */

export function listerMesFils(signal?: AbortSignal): Promise<ListeDeFils> {
  return requeteApi<ListeDeFils>('/messagerie/fils', signal === undefined ? {} : { signal })
}

export function lireMonFil(id: number, signal?: AbortSignal): Promise<DetailDeFil> {
  return requeteApi<DetailDeFil>(`/messagerie/fils/${id}`, signal === undefined ? {} : { signal })
}

/** `sujet` facultatif : à défaut, la première ligne du message en tient lieu. */
export function ouvrirUnFil(saisie: { nature: NatureDuFil; texte: string; sujet?: string }): Promise<DetailDeFil> {
  const corps: Record<string, string> = { nature: saisie.nature, texte: saisie.texte }
  if (saisie.sujet !== undefined && saisie.sujet.trim() !== '') corps.sujet = saisie.sujet
  return requeteApi<DetailDeFil>('/messagerie/fils', { methode: 'POST', corps })
}

export function ecrireDansMonFil(id: number, texte: string): Promise<DetailDeFil> {
  return requeteApi<DetailDeFil>(`/messagerie/fils/${id}/messages`, { methode: 'POST', corps: { texte } })
}

/* ───────────── Côté administration ───────────── */

export function listerFilsAdministration(
  filtre: { statut?: StatutDuFil } = {},
  signal?: AbortSignal,
): Promise<ListeDeFilsAdministration> {
  const requete: Record<string, string> = {}
  if (filtre.statut !== undefined) requete.statut = filtre.statut
  return requeteApi<ListeDeFilsAdministration>(
    '/administration/messagerie/fils',
    signal === undefined ? { requete } : { requete, signal },
  )
}

export function lireFilAdministration(id: number, signal?: AbortSignal): Promise<DetailDeFil> {
  return requeteApi<DetailDeFil>(`/administration/messagerie/fils/${id}`, signal === undefined ? {} : { signal })
}

export function repondreAuFil(id: number, texte: string): Promise<DetailDeFil> {
  return requeteApi<DetailDeFil>(`/administration/messagerie/fils/${id}/messages`, {
    methode: 'POST',
    corps: { texte },
  })
}

export function changerStatutDuFil(id: number, statut: StatutDuFil): Promise<DetailDeFil> {
  return requeteApi<DetailDeFil>(`/administration/messagerie/fils/${id}/statut`, {
    methode: 'PUT',
    corps: { statut },
  })
}
