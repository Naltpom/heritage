/**
 * L'état de session du front — **un seul exemplaire pour toute l'application**.
 *
 * Le module est volontairement un singleton de module plutôt qu'un magasin
 * (Pinia) : la session est une donnée unique, lue partout, écrite à quatre
 * endroits (démarrage, connexion, déconnexion, bascule d'administration). Une
 * dépendance de plus ne servirait rien ici — « ne pas ajouter de dépendance qui
 * ne sert pas » (§ 3 du dossier de mission).
 *
 * **Rien n'est stocké.** Ni jeton, ni session, ni « je suis connecté » en
 * `localStorage`. La session est portée par un cookie `HttpOnly` que le front ne
 * voit pas (§ 1.6) ; la seule source de vérité est `GET /api/session`. Un
 * marqueur local survivrait à l'expiration du cookie et afficherait une
 * interface de membre à un visiteur déconnecté.
 */

import { computed, readonly, shallowRef, type ComputedRef, type Ref } from 'vue'
import type { Router } from 'vue-router'
import {
  ErreurApi,
  lireSession,
  oublierEtag,
  seConnecter,
  seDeconnecter,
  surSessionPerdue,
  type ChargeDeSession,
} from '@/lib/api'
import { regimeDeSession, type RegimeAcces } from '@/router/acces'

const session = shallowRef<ChargeDeSession | null>(null)
const chargement = shallowRef(false)
/** Promesse mémoïsée du premier chargement : la garde de route et le composant
 *  racine la réclament tous les deux, on n'appelle l'API qu'une fois. */
let premierChargement: Promise<void> | null = null

/**
 * Charge la session si ce n'est pas déjà fait.
 *
 * Une `401` est ici une **réponse normale** — le visiteur est anonyme — et non
 * une erreur : `lireSession()` passe donc `sansRattrapage`, sans quoi le
 * démarrage d'un visiteur anonyme déclencherait une redirection vers la
 * connexion depuis la connexion.
 */
export function assurerSessionChargee(): Promise<void> {
  // La promesse mémoïsée ne doit **jamais** pouvoir être rejetée : la garde de
  // route l'attend à chaque navigation, et une promesse rejetée gardée en
  // mémoire bloquerait toute navigation ultérieure — API éteinte au démarrage,
  // proxy mal configuré, et plus rien ne s'affiche jamais. On retombe en régime
  // anonyme et l'écran de connexion dira ce qui ne va pas au premier essai.
  premierChargement ??= rafraichirSession().catch((cause: unknown) => {
    console.error("Heritage : session illisible au démarrage, régime anonyme.", cause)
    session.value = null
  })
  return premierChargement
}

/** Relit `GET /api/session`. À appeler après toute action qui change le régime :
 *  activation d'un compte, entrée ou sortie de la bascule « voir comme ». */
export async function rafraichirSession(): Promise<void> {
  chargement.value = true
  try {
    session.value = await lireSession()
  } catch (erreur) {
    if (erreur instanceof ErreurApi && erreur.statut === 401) session.value = null
    else throw erreur
  } finally {
    chargement.value = false
    // L'ETag du graphe incorpore les demandes en attente du lecteur et, sous la
    // bascule, le membre emprunté (§ 5.2, § 10.4). Tout changement de session le
    // périme : le garder ferait revalider et recevoir un 304, c'est-à-dire le
    // graphe du lecteur précédent.
    oublierEtag()
  }
}

/** `POST /api/connexion`. Les erreurs (`identifiants_invalides`,
 *  `compte_suspendu`, `trop_de_tentatives`) remontent telles quelles à l'écran,
 *  qui les passe à `analyserErreur()`. */
export async function connecter(email: string, motDePasse: string): Promise<ChargeDeSession> {
  const charge = await seConnecter(email, motDePasse)
  session.value = charge
  premierChargement = Promise.resolve()
  oublierEtag()
  return charge
}

/** `POST /api/deconnexion`. L'état local est vidé **quoi qu'il arrive** : si
 *  l'appel échoue, le cookie peut déjà être mort, et garder l'interface d'un
 *  membre serait pire que de redemander une connexion. */
export async function deconnecter(): Promise<void> {
  try {
    await seDeconnecter()
  } finally {
    session.value = null
    premierChargement = Promise.resolve()
    oublierEtag()
  }
}

export interface EtatSession {
  /** La charge du § 2.2, ou `null` pour un visiteur anonyme. */
  session: Readonly<Ref<ChargeDeSession | null>>
  chargement: Readonly<Ref<boolean>>
  /** Un des **trois** régimes d'accès, plus l'administration (`acces.ts`). */
  regime: ComputedRef<RegimeAcces>
  connecte: ComputedRef<boolean>
  /** Le rattachement est-il validé ? Faux pour un compte en attente. */
  rattache: ComputedRef<boolean>
  administrateur: ComputedRef<boolean>
  /** Bascule « voir comme un membre » active (§ 10.4) : bandeau permanent, et
   *  toute écriture rend `409 vue_comme_membre_active`. */
  vueEmpruntee: ComputedRef<ChargeDeSession['voirComme']>
  nomAffiche: ComputedRef<string>
}

export function useSession(): EtatSession {
  const regime = computed(() => regimeDeSession(session.value))
  return {
    session: readonly(session) as Readonly<Ref<ChargeDeSession | null>>,
    chargement: readonly(chargement),
    regime,
    connecte: computed(() => regime.value !== 'anonyme'),
    rattache: computed(() => regime.value === 'membre' || regime.value === 'administrateur'),
    administrateur: computed(() => regime.value === 'administrateur'),
    vueEmpruntee: computed(() => session.value?.voirComme ?? null),
    // Retombe sur le courriel tant qu'il n'y a pas de rattachement validé
    // (§ 2.2 point 3) : le serveur ne renseigne jamais `displayName` depuis
    // `person_label` avant activation.
    nomAffiche: computed(() => session.value?.utilisateur.displayName ?? ''),
  }
}

/**
 * Câble le rattrapage global des `401` sur le routeur.
 *
 * Appelé une fois, depuis `main.ts`. Le client d'API ne connaît ni le routeur ni
 * l'état applicatif — il signale, il ne décide pas.
 *
 * Le rattrapage ne se déclenche que sur le code `authentification_requise` (le
 * client s'en assure) : `POST /api/connexion` rend lui aussi des `401`, et une
 * déconnexion déclenchée par un mot de passe erroné serait un bogue pénible à
 * diagnostiquer (§ 2.1).
 */
export function installerRattrapageSession(routeur: Router): void {
  surSessionPerdue(() => {
    session.value = null
    premierChargement = Promise.resolve()
    oublierEtag()

    const courante = routeur.currentRoute.value
    // Déjà sur un écran public : rien à faire, et surtout pas une redirection
    // qui écraserait le `?suite=` ou le jeton d'invitation en cours de saisie.
    if (courante.meta['acces'] === 'anonyme' || courante.meta['acces'] === 'public') return
    void routeur.replace({ name: 'connexion', query: { suite: courante.fullPath } })
  })
}
