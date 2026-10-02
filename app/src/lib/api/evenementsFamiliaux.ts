/**
 * Les appels d'API des événements de famille — « Cousinade été 2027 ».
 *
 * Le nom du fichier n'est pas `evenements.ts` : celui-là est pris par le module
 * `Almanach` (naissances, mariages, décès de l'arbre — les « événements » au
 * sens généalogique). Ici, ce sont des **rendez-vous** que la famille se donne :
 * un titre, deux dates, des invités, et qui vient.
 *
 * ────────────────────────────────────────────────────────────────────
 * **Le serveur décide de ce que chacun voit, l'écran n'a rien à cacher.**
 *
 * Un invité reçoit l'événement, sa réponse et **qui vient** (`quiVient`) — pas
 * qui a décliné, pas qui a vu sans répondre, aucun horodatage de vue. Le
 * **suivi** (`suivi`) n'arrive qu'à l'organisateur et aux administrateurs, et
 * vaut `null` pour les autres. Ce que l'écran ne reçoit pas, il ne peut pas
 * l'afficher par erreur.
 * ────────────────────────────────────────────────────────────────────
 *
 * **Ouvrir la page marque la vue.** `GET /api/evenements/{id}` pose la première
 * vue de l'invité qui la lit — sans geste, quel que soit le chemin qui y mène.
 * L'accueil, qui montre l'annonce sans ouvrir la page, appelle `marquerVu()`.
 *
 * Chaque écriture rend l'événement **relu**, sérialisé pour le lecteur : l'écran
 * remplace son état par la réponse, sans recoller de morceaux.
 */

import { requeteApi } from './client'

/** Deux réponses, et pas de troisième : pas de « peut-être » (demande du commanditaire). */
export type ReponseAInvitation = 'present' | 'absent'

export interface PersonneInvitable {
  personId: number
  libelle: string
}

/** Ce que l'organisateur écrit : l'annonce. Les dates en `AAAA-MM-JJ`. */
export interface AnnonceDEvenement {
  titre: string
  debut: string
  fin: string
  lieu: string | null
  description: string | null
}

export interface ResumeDEvenement extends AnnonceDEvenement {
  id: number
  annule: boolean
  /** Le dernier jour est passé. */
  passe: boolean
  organisateur: { libelle: string; estMoi: boolean }
  peutGerer: boolean
  suisInvite: boolean
  /** `null` : pas encore répondu — ou pas invité (`suisInvite`). */
  maReponse: ReponseAInvitation | null
  /**
   * Le nom de qui a **noté** ma réponse à ma place (l'organisateur, de vive
   * voix) ; `null` quand je l'ai donnée moi-même.
   */
  maReponseNoteePar: string | null
  /** Les dates ont changé depuis ma réponse : elle tient, mais l'écran demande de la confirmer. */
  reponseAConfirmer: boolean
  /** Invité, événement maintenu et pas terminé : les boutons Présent / Absent servent. */
  peutRepondre: boolean
  nombreDInvites: number
  nombreDePresents: number
}

/**
 * Une ligne du suivi de l'organisateur. Les horodatages en ISO 8601, `null`
 * quand la chose n'a pas eu lieu.
 */
export interface LigneDeSuivi extends PersonneInvitable {
  estMoi: boolean
  /** Rattachée à un compte : elle reçoit la notification, et n'a pas de lien. */
  aUnCompte: boolean
  /** Un lien personnel actif — un booléen, jamais la clé : le serveur ne la connaît plus. */
  lienActif: boolean
  /** Sans compte, et l'événement est encore modifiable. Le serveur en décide. */
  peutPartagerLien: boolean
  vuLe: string | null
  reponduLe: string | null
  relanceLe: string | null
  /** La réponse a été notée par l'organisateur (ou un administrateur) : son nom. */
  noteePar: string | null
  /** Répondu avant le dernier changement de dates : à reconfirmer. */
  aConfirmer: boolean
  /** Le lecteur peut noter la réponse de cet invité à sa place. Le serveur en décide. */
  peutNoterReponse: boolean
}

export interface SuiviDEvenement {
  presents: LigneDeSuivi[]
  absents: LigneDeSuivi[]
  /** Vus, sans réponse — les fantômes. */
  fantomes: LigneDeSuivi[]
  /** Pas encore ouvert l'annonce. */
  nonVus: LigneDeSuivi[]
  derniereRelanceLe: string | null
  /** `null` : une relance est permise maintenant (une par 24 h au plus). */
  prochaineRelancePossibleLe: string | null
  /** Les invités avec un compte qu'une relance toucherait maintenant. */
  aRelancer: number
}

export interface DetailDEvenement extends ResumeDEvenement {
  quiVient: {
    /** Les présents que le lecteur voit dans l'arbre. */
    presents: (PersonneInvitable & { estMoi: boolean })[]
    /** Les autres présents, sans nom : des fiches que le lecteur ne voit pas (ADR 0003). */
    autres: number
  }
  /** Combien ont décliné — jamais qui, sauf dans le suivi. */
  nombreDAbsents: number
  /** Réservé à qui gère l'événement ; `null` pour un invité. */
  suivi: SuiviDEvenement | null
}

export interface ListeDEvenements {
  /** `null` : le compte n'est rattaché à aucune personne — il organise, il n'est pas invité. */
  moi: PersonneInvitable | null
  evenements: ResumeDEvenement[]
}

export function listerEvenements(signal?: AbortSignal): Promise<ListeDEvenements> {
  return requeteApi<ListeDEvenements>('/evenements', signal === undefined ? {} : { signal })
}

/** Lire, c'est aussi **voir** : le serveur pose la première vue de l'invité. */
export function lireEvenement(id: number, signal?: AbortSignal): Promise<DetailDEvenement> {
  return requeteApi<DetailDEvenement>(`/evenements/${id}`, signal === undefined ? {} : { signal })
}

export function creerEvenement(annonce: AnnonceDEvenement, invites: number[]): Promise<DetailDEvenement> {
  return requeteApi<DetailDEvenement>('/evenements', { methode: 'POST', corps: { ...annonce, invites } })
}

export function modifierEvenement(id: number, annonce: AnnonceDEvenement): Promise<DetailDEvenement> {
  return requeteApi<DetailDEvenement>(`/evenements/${id}`, { methode: 'PUT', corps: annonce })
}

export function annulerEvenement(id: number): Promise<DetailDEvenement> {
  return requeteApi<DetailDEvenement>(`/evenements/${id}/annulation`, { methode: 'POST' })
}

/** L'annonce a été vue sur l'accueil. Idempotent ; sans effet sous « voir comme ». */
export function marquerVu(id: number): Promise<void> {
  return requeteApi<void>(`/evenements/${id}/vu`, { methode: 'POST' })
}

export function repondreAEvenement(id: number, reponse: ReponseAInvitation): Promise<DetailDEvenement> {
  return requeteApi<DetailDEvenement>(`/evenements/${id}/reponse`, { methode: 'PUT', corps: { reponse } })
}

/**
 * L'organisateur note la réponse d'un invité **à sa place** — « grand-mère a
 * répondu par téléphone ». Tracée « notée par … » ; elle ne compte pas comme
 * une vue de l'annonce.
 */
export function noterLaReponseDUnInvite(id: number, personId: number, reponse: ReponseAInvitation): Promise<DetailDEvenement> {
  return requeteApi<DetailDEvenement>(`/evenements/${id}/invites/${personId}/reponse`, { methode: 'PUT', corps: { reponse } })
}

/** Les personnes déjà invitées sont ignorées sans erreur ; les nouvelles sont prévenues. */
export function inviterAEvenement(id: number, personIds: number[]): Promise<DetailDEvenement> {
  return requeteApi<DetailDEvenement>(`/evenements/${id}/invites`, { methode: 'POST', corps: { personIds } })
}

export function retirerDeLEvenement(id: number, personId: number): Promise<DetailDEvenement> {
  return requeteApi<DetailDEvenement>(`/evenements/${id}/invites/${personId}`, { methode: 'DELETE' })
}

/** Notifie les invités avec un compte qui n'ont pas répondu. Une fois par 24 h. */
export function relancerLesInvites(id: number): Promise<DetailDEvenement> {
  return requeteApi<DetailDEvenement>(`/evenements/${id}/relance`, { methode: 'POST' })
}

/* ------------------------------------------------------------------ */
/* Le lien personnel d'un invité sans compte                           */
/* ------------------------------------------------------------------ */

/*
 * ────────────────────────────────────────────────────────────────────
 * **Un invité sans compte répond par un lien : `/e/<clé>`.**
 *
 * L'organisateur le crée depuis la page de l'événement et le lui envoie. Comme
 * le lien d'un tirage, **la clé n'existe en clair qu'au moment où on la crée** :
 * le serveur n'en garde qu'une empreinte. « Renvoyer son lien » après coup,
 * c'est en fabriquer un nouveau — et l'ancien cesse de marcher.
 *
 * Les deux routes publiques ne disent rien d'autre que cette invitation : ni
 * les autres invités, ni qui vient, rien de l'arbre. Une clé inconnue, révoquée
 * ou remplacée rend la même `404`.
 * ────────────────────────────────────────────────────────────────────
 */

/** Ce qu'un lien personnel donne à lire. Rien de plus n'est rendu. */
export interface AnnonceParLien {
  evenement: AnnonceDEvenement & { annule: boolean; passe: boolean }
  invite: {
    libelle: string
    reponse: ReponseAInvitation | null
    /** Les dates ont changé depuis la réponse : à confirmer. */
    aConfirmer: boolean
    peutRepondre: boolean
  }
}

/** Rendu une seule fois, à la création : la clé ne se redemande pas. */
export interface LienDInvite {
  cle: string
  /** Relatif : `/e/<clé>`. L'écran le rend absolu sur sa propre origine. */
  url: string
}

/**
 * `GET /api/evenements/lien/{clé}` — route **publique**. L'ouvrir pose la vue.
 *
 * `sansRattrapage` : sans session, une 401 y serait un défaut de câblage, pas
 * une session perdue — le rattrapage global renverrait vers la connexion une
 * personne qui, précisément, n'a pas de compte.
 */
export function lireAnnonceParLien(cle: string, signal?: AbortSignal): Promise<AnnonceParLien> {
  return requeteApi<AnnonceParLien>(`/evenements/lien/${encodeURIComponent(cle)}`, {
    sansRattrapage: true,
    ...(signal === undefined ? {} : { signal }),
  })
}

/** `PUT /api/evenements/lien/{clé}/reponse` — publique, tant que l'événement tient. */
export function repondreParLien(cle: string, reponse: ReponseAInvitation): Promise<AnnonceParLien> {
  return requeteApi<AnnonceParLien>(`/evenements/lien/${encodeURIComponent(cle)}/reponse`, {
    methode: 'PUT',
    corps: { reponse },
    sansRattrapage: true,
  })
}

/** Crée le lien de l'invité, ou le **régénère** : l'ancien cesse aussitôt de marcher. */
export function creerLienDInvite(id: number, personId: number): Promise<LienDInvite> {
  return requeteApi<LienDInvite>(`/evenements/${id}/invites/${personId}/lien`, { methode: 'POST' })
}

export function revoquerLienDInvite(id: number, personId: number): Promise<void> {
  return requeteApi<void>(`/evenements/${id}/invites/${personId}/lien`, { methode: 'DELETE' })
}
