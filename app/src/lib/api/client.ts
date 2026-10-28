/**
 * Le client d'API — la **seule** porte de sortie du front vers `/api`.
 *
 * Trois choses y sont centralisées, parce qu'aucune ne peut être laissée aux
 * écrans sans qu'un écran finisse par l'oublier :
 *
 * 1. **La session est portée par un cookie `HttpOnly`** (§ 1.6). Le front ne
 *    manipule aucun jeton, n'en lit aucun, n'en stocke aucun — ni en
 *    `localStorage`, ni en mémoire. `credentials: 'same-origin'` suffit, parce
 *    que le front et l'API partagent la même origine : le proxy Vite en
 *    développement, nginx en production. Il n'y a donc **aucun en-tête
 *    d'autorisation à poser nulle part**. L'API admet bien un
 *    `Authorization: Bearer hrt_…`, mais seulement hors navigateur (jeton
 *    d'API, ADR 0021) : ce n'est pas l'affaire du front, et si vous en
 *    cherchez un ici, c'est que vous vous trompez de client.
 * 2. **L'enveloppe d'erreur du § 15.1** est décodée une fois, ici, et rendue aux
 *    écrans sous forme d'`ErreurApi`. Voir `erreurs.ts` pour l'interprétation.
 * 3. **Le rattrapage 401** : une session perdue renvoie à la connexion, une fois,
 *    par un point unique.
 *
 * Piège du rattrapage 401, écrit ici parce qu'il coûterait une heure à trouver :
 * `POST /api/connexion` rend **aussi** des 401 (`identifiants_invalides`,
 * `compte_suspendu`), et le contrat prend soin de préciser qu'un mot de passe
 * actuel erroné rend `422` et non `401` précisément « parce qu'une 401 ferait
 * déconnecter le front par sa logique de rattrapage globale » (§ 2.1). Le
 * rattrapage branche donc sur le **code** `authentification_requise`, jamais sur
 * le statut HTTP seul.
 */

import { CODE_ANNULE, CODE_AUTHENTIFICATION_REQUISE, CODE_RESEAU, ErreurApi, erreurDepuisCorps } from './erreurs'

/** Préfixe de toutes les routes (§ 1.1). Les chemins passés aux fonctions de ce
 *  module commencent par `/` et n'incluent pas ce préfixe. */
const PREFIXE = '/api'

export type MethodeHttp = 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE'

/** Valeurs admises dans une chaîne de requête. `undefined` et `null` sont
 *  simplement omis — c'est ce qui permet d'écrire `{ page, nonLues }` sans
 *  reconstruire l'objet à chaque appel. */
export type ValeurRequete = string | number | boolean | undefined | null

export interface OptionsRequete {
  methode?: MethodeHttp
  /** Sérialisé en JSON, sauf `FormData` (téléversement, § 7.1). */
  corps?: unknown
  requete?: Record<string, ValeurRequete>
  entetes?: Record<string, string>
  signal?: AbortSignal
  /**
   * Désactive le rattrapage global sur `authentification_requise`.
   * À employer pour les appels qui *sondent* la session (`GET /api/session` au
   * démarrage) : une 401 y est une réponse normale, pas une déconnexion.
   */
  sansRattrapage?: boolean
}

/* ------------------------------------------------------------------ */
/* Rattrapage 401                                                      */
/* ------------------------------------------------------------------ */

type RappelSessionPerdue = (erreur: ErreurApi) => void

let rappelSessionPerdue: RappelSessionPerdue | null = null

/**
 * Enregistre le geste à faire quand le serveur dit que la session n'existe plus.
 * Appelé une fois au démarrage par le module de session ; le client ne connaît
 * ni le routeur ni l'état applicatif, et c'est voulu.
 */
export function surSessionPerdue(rappel: RappelSessionPerdue): void {
  rappelSessionPerdue = rappel
}

/* ------------------------------------------------------------------ */
/* Construction de la requête                                          */
/* ------------------------------------------------------------------ */

export function construireUrl(chemin: string, requete?: Record<string, ValeurRequete>): string {
  const base = chemin.startsWith(PREFIXE) ? chemin : PREFIXE + chemin
  if (!requete) return base

  const parametres = new URLSearchParams()
  for (const [cle, valeur] of Object.entries(requete)) {
    if (valeur === undefined || valeur === null) continue
    parametres.append(cle, String(valeur))
  }
  const chaine = parametres.toString()
  return chaine.length > 0 ? `${base}?${chaine}` : base
}

/** Réponse brute : statut, en-têtes et corps déjà décodé. Utile aux appels qui
 *  ont besoin des en-têtes (`ETag`) ou du statut (`304`). */
export interface ReponseBrute {
  statut: number
  entetes: Headers
  /** `null` sur 204 et 304, ou quand le corps n'est pas du JSON. */
  corps: unknown
}

async function lireCorps(reponse: Response): Promise<unknown> {
  if (reponse.status === 204 || reponse.status === 304) return null
  const type = reponse.headers.get('content-type') ?? ''
  if (!type.includes('json')) {
    // Le contrat exige du JSON partout (§ 15.1, « une 404 de routeur qui rendrait
    // du HTML casserait le front sur exactement le cas où il a le plus besoin
    // d'un JSON »). On lit quand même le texte : il aide au diagnostic.
    const texte = await reponse.text().catch(() => '')
    return texte.length > 0 ? { __texte: texte } : null
  }
  return reponse.json().catch(() => null)
}

/**
 * Exécute un appel et rend la réponse **sans lever sur les statuts d'erreur**.
 * Les échecs de transport, eux, sont emballés en `ErreurApi` : un écran n'a
 * jamais deux formes d'échec à distinguer.
 */
export async function appelBrut(chemin: string, options: OptionsRequete = {}): Promise<ReponseBrute> {
  const { methode = 'GET', corps, requete, entetes, signal, sansRattrapage = false } = options

  const enTetes = new Headers({ Accept: 'application/json' })
  for (const [cle, valeur] of Object.entries(entetes ?? {})) enTetes.set(cle, valeur)

  let charge: BodyInit | undefined
  if (corps instanceof FormData) {
    // Surtout pas de Content-Type ici : le navigateur doit poser lui-même la
    // frontière multipart, et l'écraser casse le téléversement (§ 7.1).
    charge = corps
  } else if (corps !== undefined) {
    if (!enTetes.has('Content-Type')) enTetes.set('Content-Type', 'application/json; charset=utf-8')
    charge = JSON.stringify(corps)
  }

  const init: RequestInit = {
    method: methode,
    headers: enTetes,
    // Le cookie de session, et rien d'autre. Same-origin : aucun CORS n'est
    // ouvert côté API, et il ne doit pas l'être.
    credentials: 'same-origin',
    // La revalidation du graphe est pilotée à la main (`If-None-Match`, voir
    // `revalidation.ts`). Sans `no-store`, le cache du navigateur intercepterait
    // le 304 et nous rendrait un 200 depuis sa mémoire : on ne saurait plus si
    // le graphe a changé. Les autres routes portent `Cache-Control: no-store`
    // côté serveur — on ne fait ici que ne pas le contredire.
    cache: 'no-store',
    redirect: 'error',
  }
  if (charge !== undefined) init.body = charge
  if (signal !== undefined) init.signal = signal

  let reponse: Response
  try {
    reponse = await fetch(construireUrl(chemin, requete), init)
  } catch (cause) {
    if (cause instanceof DOMException && cause.name === 'AbortError') {
      throw new ErreurApi(0, CODE_ANNULE, 'Requête interrompue.')
    }
    throw new ErreurApi(0, CODE_RESEAU, "Le serveur n'a pas répondu.")
  }

  const brut: ReponseBrute = {
    statut: reponse.status,
    entetes: reponse.headers,
    corps: await lireCorps(reponse),
  }

  if (reponse.status === 401 && !sansRattrapage) {
    const erreur = erreurDepuisCorps(401, brut.corps)
    // Branchement sur le CODE, pas sur le statut : voir l'en-tête de ce fichier.
    if (erreur.code === CODE_AUTHENTIFICATION_REQUISE) rappelSessionPerdue?.(erreur)
  }

  return brut
}

/**
 * L'appel nominal : rend le corps typé, lève une `ErreurApi` sur tout statut
 * ≥ 400.
 *
 * `T` n'est pas vérifié à l'exécution — le contrat fait foi. Il est là pour que
 * les écrans se trompent à la compilation plutôt qu'au clic.
 */
export async function requeteApi<T>(chemin: string, options: OptionsRequete = {}): Promise<T> {
  const brut = await appelBrut(chemin, options)
  if (brut.statut >= 400) throw erreurDepuisCorps(brut.statut, brut.corps)
  return brut.corps as T
}
