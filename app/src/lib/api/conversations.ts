/**
 * Les appels d'API de **« Poser une question »** : des conversations suivies avec
 * l'assistant, sur l'arbre, en lecture seule (`docs/api-contract.md` § 25).
 *
 * ────────────────────────────────────────────────────────────────────
 * **Une conversation ne se crée pas vide.** `POST /assistant/conversations`
 * porte la première question et rend la conversation avec sa réponse ; tant que
 * le modèle n'a pas répondu, rien n'existe côté serveur. L'écran garde donc la
 * question en attente lui-même, et la remet dans le champ si l'appel échoue.
 *
 * **Une réponse est un texte avec des mentions** de la forme
 * `[Marie Becquelin](personne:12)`, que l'écran découpe en texte et en liens
 * (`components/proposition/assistant/questions.ts`) — jamais du HTML. Les noms
 * de `personnes` et des mentions sont relus par le serveur **à chaque lecture**,
 * à travers le cercle du moment : une personne masquée l'est partout.
 * ────────────────────────────────────────────────────────────────────
 */

import { requeteApi } from './client'
import type { PersonneCitee } from './assistant'

/** Une ligne de « mes conversations ». */
export interface ResumeConversation {
  id: number
  /** La première question, coupée à 120 signes. Ne change jamais. */
  titre: string
  createdAt: string
  /** La date du dernier échange : elle ordonne la liste. */
  updatedAt: string
  nombreDEchanges: number
}

/** Une question et sa réponse. */
export interface EchangeAssistant {
  id: number
  question: string
  /** Le texte de la réponse, avec ses mentions `[Nom](personne:12)`. */
  reponse: string
  /** Les fiches citées, vues par le lecteur (`restreinte` : le nom masqué seul). */
  personnes: PersonneCitee[]
  createdAt: string
}

export interface ConversationAssistant extends ResumeConversation {
  echanges: EchangeAssistant[]
}

/** La réponse `201` d'une question : la conversation relue, et le quota. */
export interface ReponseAQuestion {
  conversation: ConversationAssistant
  /** Questions restantes aujourd'hui (60 par 24 heures glissantes). */
  restant: number
}

/** `GET /api/assistant/conversations` — les miennes, la plus récente d'abord. */
export async function listerConversations(): Promise<ResumeConversation[]> {
  const reponse = await requeteApi<{ elements: ResumeConversation[] }>('/assistant/conversations')
  return reponse.elements
}

/** `GET /api/assistant/conversations/{id}` — `404` pour celle d'un autre. */
export async function lireConversation(id: number): Promise<ConversationAssistant> {
  const reponse = await requeteApi<{ conversation: ConversationAssistant }>(
    `/assistant/conversations/${id}`,
  )
  return reponse.conversation
}

/**
 * Pose une question : dans une conversation existante si `conversationId` est
 * donné, dans une nouvelle sinon.
 *
 * **Long** : 20 à 60 secondes. Le `signal` permet « Annuler ».
 *
 * Erreurs : `422` (question vide ou trop longue, conversation trop longue),
 * `429` (quota du jour), `502 assistant_erreur`, `503 assistant_indisponible`,
 * `403 regime_en_attente`, `409 vue_comme_membre_active`, `404`.
 */
export function poserQuestion(
  question: string,
  conversationId: number | null,
  signal?: AbortSignal,
): Promise<ReponseAQuestion> {
  const chemin =
    conversationId === null
      ? '/assistant/conversations'
      : `/assistant/conversations/${conversationId}/questions`
  return requeteApi<ReponseAQuestion>(chemin, {
    methode: 'POST',
    corps: { question },
    ...(signal === undefined ? {} : { signal }),
  })
}

/** `DELETE /api/assistant/conversations/{id}` — `204`. */
export async function supprimerConversation(id: number): Promise<void> {
  await requeteApi<null>(`/assistant/conversations/${id}`, { methode: 'DELETE' })
}
