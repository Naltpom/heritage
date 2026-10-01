/**
 * Les appels d'API des **actes demandés** — module `Demarche`.
 *
 * Une démarche, c'est une pièce demandée à une mairie ou à des archives, et sa
 * suite : chaque message envoyé ou reçu, **avec son texte entier**. Celui qui
 * reprend une démarche n'a pas la boîte de celui qui l'a lancée : relire mot
 * pour mot ce qui a été demandé dit ce qu'on peut compléter.
 *
 * ────────────────────────────────────────────────────────────────────
 * **Le serveur ne rend que les démarches dont le lecteur voit toutes les
 * personnes.** Une démarche sur un vivant hors du cercle n'existe pas pour lui,
 * ni en liste ni au détail (`404`). L'écran n'a rien à masquer.
 * ────────────────────────────────────────────────────────────────────
 *
 * Chaque écriture rend la démarche **relue** : l'écran remplace son état par
 * la réponse, sans recoller de morceaux.
 */

import { requeteApi } from './client'

export type NatureDActe = 'naissance' | 'mariage' | 'deces' | 'autre'
export type FormeDActe = 'copie_integrale' | 'extrait_filiation' | 'extrait_sans_filiation' | 'autre'
/**
 * `expediee` (révision du 2026-10-01) : la mairie a répondu « traité, l'acte
 * part par la poste ». La démarche attend encore la pièce (`enAttente`), mais
 * plus la mairie (`attendUneReponse` faux) : elle n'est jamais « à relancer ».
 */
export type StatutDeDemarche = 'envoyee' | 'relancee' | 'expediee' | 'recue' | 'refusee' | 'sans_suite'
export type SensDeMessage = 'envoye' | 'recu'
export type CanalDeMessage = 'courriel' | 'service_public' | 'courrier' | 'guichet' | 'telephone' | 'autre'

export interface PersonneDeLaDemarche {
  personId: number
  libelle: string
}

/** Le lien « suite de » : la démarche reprise, en court. */
export interface LienDeDemarche {
  id: number
  statut: StatutDeDemarche
  /** Le service à qui elle avait été demandée. */
  demandeeA: string
}

export interface ResumeDeDemarche {
  id: number
  nature: NatureDActe
  natureLibelle: string
  forme: FormeDActe
  formeLibelle: string
  intitule: string
  commune: string | null
  dateDeLActe: string | null
  destinataire: string
  statut: StatutDeDemarche
  statutLibelle: string
  /** La pièce n'est pas encore là : envoyée, relancée ou expédiée. */
  enAttente: boolean
  /** Le service n'a pas encore répondu : envoyée ou relancée — la seule
   *  attente qui se relance. */
  attendUneReponse: boolean
  resultat: string | null
  personnes: PersonneDeLaDemarche[]
  auteur: { libelle: string; estMoi: boolean }
  /** Les adresses des messages (de, à), sans leur texte : c'est par
   *  l'expéditeur d'une réponse qu'on retrouve la démarche. */
  correspondants: string[]
  nombreDeMessages: number
  /** `AAAA-MM-JJ` du dernier message envoyé, `null` s'il n'y en a pas. */
  dernierEnvoiLe: string | null
  dernierMessageLe: string | null
  /** Depuis le dernier envoi, tant que la démarche attend ; `null` sinon. */
  joursDAttente: number | null
  /** Calculé par le serveur : sans réponse depuis un mois ou plus. Une
   *  démarche expédiée par courrier ne l'est jamais. */
  aRelancer: boolean
  /** La démarche que celle-ci reprend autrement (« suite de »), si le lecteur
   *  la voit. */
  precedente: LienDeDemarche | null
  /** Les démarches qui reprennent celle-ci, parmi celles que le lecteur voit. */
  suites: Array<{ id: number; statut: StatutDeDemarche }>
  peutSupprimer: boolean
  creeeLe: string
  modifieeLe: string
}

export interface MessageDeDemarche {
  id: number
  sens: SensDeMessage
  sensLibelle: string
  canal: CanalDeMessage
  canalLibelle: string
  /** `AAAA-MM-JJ`. */
  date: string
  de: string | null
  a: string | null
  objet: string | null
  texte: string
  reference: string | null
  saisiPar: string
  peutRetirer: boolean
}

export interface DetailDeDemarche extends ResumeDeDemarche {
  messages: MessageDeDemarche[]
}

export interface ListeDeDemarches {
  elements: ResumeDeDemarche[]
  total: number
}

export interface DescriptionDeDemarche {
  nature: NatureDActe
  forme: FormeDActe
  intitule: string
  commune: string | null
  dateDeLActe: string | null
  destinataire: string
  personIds: number[]
}

export interface MessageASaisir {
  sens: SensDeMessage
  canal: CanalDeMessage
  date: string
  de: string | null
  a: string | null
  objet: string | null
  texte: string
  reference: string | null
}

export function listerDemarches(
  filtre: { personId?: number; enAttente?: boolean } = {},
  signal?: AbortSignal,
): Promise<ListeDeDemarches> {
  const requete: Record<string, string> = {}
  if (filtre.personId !== undefined) requete.personId = String(filtre.personId)
  if (filtre.enAttente !== undefined) requete.enAttente = filtre.enAttente ? '1' : '0'
  return requeteApi<ListeDeDemarches>('/demarches', signal === undefined ? { requete } : { requete, signal })
}

export function lireDemarche(id: number, signal?: AbortSignal): Promise<DetailDeDemarche> {
  return requeteApi<DetailDeDemarche>(`/demarches/${id}`, signal === undefined ? {} : { signal })
}

/**
 * `precedenteId` : la démarche que celle-ci **reprend autrement** — après un
 * refus, par un autre canal. Une démarche que le lecteur ne voit pas est
 * refusée en `422` sur ce champ.
 */
export function creerDemarche(
  description: DescriptionDeDemarche,
  premierMessage: MessageASaisir | null,
  precedenteId: number | null = null,
): Promise<DetailDeDemarche> {
  return requeteApi<DetailDeDemarche>('/demarches', {
    methode: 'POST',
    corps: {
      ...description,
      ...(premierMessage === null ? {} : { premierMessage }),
      ...(precedenteId === null ? {} : { precedenteId }),
    },
  })
}

export function decrireDemarche(id: number, description: DescriptionDeDemarche): Promise<DetailDeDemarche> {
  return requeteApi<DetailDeDemarche>(`/demarches/${id}`, { methode: 'PUT', corps: description })
}

export function suivreDemarche(
  id: number,
  suivi: { statut: StatutDeDemarche; resultat: string | null },
): Promise<DetailDeDemarche> {
  return requeteApi<DetailDeDemarche>(`/demarches/${id}/suivi`, { methode: 'PUT', corps: suivi })
}

export function supprimerDemarche(id: number): Promise<void> {
  return requeteApi<void>(`/demarches/${id}`, { methode: 'DELETE' })
}

export function ajouterMessageADemarche(id: number, message: MessageASaisir): Promise<DetailDeDemarche> {
  return requeteApi<DetailDeDemarche>(`/demarches/${id}/messages`, { methode: 'POST', corps: message })
}

export function retirerMessageDeDemarche(id: number, messageId: number): Promise<DetailDeDemarche> {
  return requeteApi<DetailDeDemarche>(`/demarches/${id}/messages/${messageId}`, { methode: 'DELETE' })
}
