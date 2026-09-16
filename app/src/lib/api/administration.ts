/**
 * Les appels d'API de l'administration — § 3 et § 10 du contrat.
 *
 * Invitations, utilisateurs, et la bascule « voir comme un membre ».
 *
 * **Le piège de ce module tient en une phrase : il n'existe aucune route
 * « renvoyer l'invitation ».** Le serveur ne stocke que `hash(jeton)`, et le
 * lien en clair n'apparaît **que** dans la réponse de création, une seule fois
 * (§ 3.2). Un administrateur qui a perdu le lien **révoque et recrée** — c'est
 * écrit ainsi dans le contrat. La route paraît manquante alors qu'elle est
 * impossible, et c'est le bouton le plus probable qu'on ajouterait par erreur.
 *
 * D'où la forme des deux types ci-dessous : `InvitationCreee` porte `lien`,
 * `Invitation` ne le porte pas — pas à `null`, **absent** (§ 1.3). Le typeur
 * refuse donc de lire `lien` sur une ligne de la liste, ce qui est exactement la
 * garantie qu'on veut.
 */

import { requeteApi } from './client'
import type { ChargeDeSession, ListePaginee, StatutCompte, TypeRattachement } from './types'

/* ------------------------------------------------------------------ */
/* § 3 — invitations                                                   */
/* ------------------------------------------------------------------ */

export type StatutInvitation = 'active' | 'utilisee' | 'expiree' | 'revoquee'

/** § 3.3 — une ligne de la liste. **Sans `lien`** : il n'existe plus. */
export interface Invitation {
  id: number
  /** Les 8 premiers caractères du jeton, pour distinguer deux lignes à l'œil.
   *  8 sur 43 ne reconstruisent rien. */
  tokenPrefix: string
  personId: number | null
  personLabel: string | null
  email: string | null
  status: StatutInvitation
  expiresAt: string
  usedAt: string | null
  usedByEmail: string | null
  revokedAt: string | null
  revokedByEmail: string | null
  createdByEmail: string
  createdAt: string
  note: string | null
  /** Tentatives de rattachement de l'heure écoulée : c'est ce qui permet de voir
   *  qu'un lien est en train d'être sondé. Les critères soumis, eux, ne sont
   *  jamais rendus — `query_hash` est une empreinte, pas un texte (§ 16). */
  tentatives: number
}

export interface AvertissementApi {
  code: string
  message: string
}

/**
 * § 3.2 — **le seul moment où le jeton existe en clair.**
 *
 * `lien` est une URL **absolue**, bâtie sur le `Host` de la requête — c'est
 * pour cela que le proxy Vite pose `changeOrigin: false`. Le front l'affiche
 * **tel quel** et ne la reconstruit jamais : le jeton est dans le **chemin**
 * (`/inscription/<jeton>`), et recomposer l'URL à partir du `tokenPrefix` ne
 * produirait qu'un lien mort.
 */
export interface InvitationCreee extends Invitation {
  lien: string
  courrielEnvoye: boolean
  avertissements: AvertissementApi[]
}

/** `POST /api/administration/invitations` (§ 3.1). */
export function creerInvitation(entree: {
  personId?: number | undefined
  email?: string | undefined
  envoyerCourriel?: boolean | undefined
  note?: string | undefined
}): Promise<InvitationCreee> {
  return requeteApi<InvitationCreee>('/administration/invitations', {
    methode: 'POST',
    corps: {
      personId: entree.personId ?? null,
      email: entree.email ?? null,
      envoyerCourriel: entree.envoyerCourriel ?? false,
      note: entree.note ?? null,
    },
  })
}

/** `GET /api/administration/invitations` — liste paginée, `createdAt DESC`. */
export function listerInvitations(options: {
  status?: StatutInvitation | undefined
  page?: number | undefined
  parPage?: number | undefined
}): Promise<ListePaginee<Invitation>> {
  return requeteApi<ListePaginee<Invitation>>('/administration/invitations', {
    requete: { status: options.status, page: options.page, parPage: options.parPage },
  })
}

/**
 * `POST /api/administration/invitations/{id}/revoquer`.
 *
 * Sur une invitation **déjà consommée** : `409 invitation_deja_consommee` —
 * révoquer après coup ne défait pas le compte créé, et laisser croire le
 * contraire serait pire que refuser.
 */
export function revoquerInvitation(id: number): Promise<Invitation> {
  return requeteApi<Invitation>(`/administration/invitations/${id}/revoquer`, {
    methode: 'POST',
  })
}

/* ------------------------------------------------------------------ */
/* § 10.1 — utilisateurs et membres                                    */
/* ------------------------------------------------------------------ */

export interface MembreAdministration {
  id: number
  status: StatutCompte
  personId: number | null
  personLabel: string | null
  matchKind: TypeRattachement
  invitationId: number | null
  linkedAt: string | null
  activatedAt: string | null
}

export interface UtilisateurAdministration {
  id: number
  email: string
  displayName: string
  roles: string[]
  status: StatutCompte
  totpEnabledAt: string | null
  lastLoginAt: string | null
  createdAt: string
  /** `null` pour un compte sans rattachement (le compte d'amorçage). */
  membre: MembreAdministration | null
}

export function listerUtilisateurs(options: {
  status?: StatutCompte | undefined
  page?: number | undefined
  parPage?: number | undefined
}): Promise<ListePaginee<UtilisateurAdministration>> {
  return requeteApi<ListePaginee<UtilisateurAdministration>>('/administration/utilisateurs', {
    requete: { status: options.status, page: options.page, parPage: options.parPage },
  })
}

/**
 * `PATCH /api/administration/utilisateurs/{id}` (§ 10.1).
 *
 * `status` accepte `actif` et `suspendu`, **jamais `en_attente`** : on ne remet
 * pas un compte en attente, on le suspend. Et retirer `ROLE_ADMIN` au dernier
 * administrateur rend `409 dernier_administrateur` — se verrouiller dehors est
 * un incident sans porte de secours autre que la console.
 */
export function modifierUtilisateur(
  id: number,
  champs: {
    roles?: string[] | undefined
    status?: 'actif' | 'suspendu' | undefined
    displayName?: string | undefined
  },
): Promise<UtilisateurAdministration> {
  return requeteApi<UtilisateurAdministration>(`/administration/utilisateurs/${id}`, {
    methode: 'PATCH',
    corps: champs,
  })
}

/**
 * `POST /api/administration/membres/{id}/activer` (§ 10.1).
 *
 * **C'est le moment exact où le régime change** : `mbr_member.status` et
 * `core_user.status` passent à `actif`, et le compte **obtient un cercle** — il
 * n'en avait pas, ce n'était pas un cercle réduit mais un régime distinct
 * (§ 12). `personId` corrige au passage un rattachement erroné.
 */
export function activerMembre(
  id: number,
  options: { personId?: number | undefined } = {},
): Promise<MembreAdministration> {
  return requeteApi<MembreAdministration>(`/administration/membres/${id}/activer`, {
    methode: 'POST',
    corps: { personId: options.personId ?? null },
  })
}

/* ------------------------------------------------------------------ */
/* § 10.4 — la bascule « voir comme un membre »                        */
/* ------------------------------------------------------------------ */

/**
 * `POST /api/administration/voir-comme`.
 *
 * Elle **remplace un cercle par un autre**, elle n'en supprime jamais un : le
 * normaliseur n'a pas de mode « sans filtre », et l'administrateur passe par un
 * cercle complet, jamais par un `if ($estAdmin) { return $tout; }` (ADR 0003).
 *
 * Elle est portée par la **session**, pas par un en-tête par requête : une
 * bascule qu'on oublierait de rejouer sur un appel ne vérifierait plus rien.
 *
 * Deux conséquences que l'appelant doit traiter, et que cette fonction ne peut
 * pas traiter à sa place :
 *
 * 1. **recharger la session** — c'est elle qui porte `voirComme` et le cercle ;
 * 2. **oublier l'`ETag` du graphe** — il incorpore le membre emprunté (§ 5.2).
 *    Sans cela, l'administrateur revalide, reçoit `304`, et regarde **son
 *    propre graphe non filtré** en croyant tester celui de quelqu'un d'autre.
 *
 * `rafraichirSession()` fait les deux d'un coup : c'est par lui qu'il faut
 * passer, et jamais en posant `session.value` à la main.
 */
export function activerVueComme(memberId: number): Promise<ChargeDeSession> {
  return requeteApi<ChargeDeSession>('/administration/voir-comme', {
    methode: 'POST',
    corps: { memberId },
  })
}

/** `DELETE /api/administration/voir-comme` — retour à la session normale. */
export function quitterVueComme(): Promise<ChargeDeSession> {
  return requeteApi<ChargeDeSession>('/administration/voir-comme', { methode: 'DELETE' })
}
