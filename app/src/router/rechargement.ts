/**
 * Les écrans paresseux **après une mise en production** — un rechargement, une
 * fois, et jamais en boucle.
 *
 * ────────────────────────────────────────────────────────────────────
 * ## La panne que ce module rattrape
 *
 * Toutes les routes sont paresseuses (`() => import(…)` dans `routes.ts`), et
 * Vite empreinte chaque morceau : `PersonneView-3f9a1c.js`. nginx sert
 * `/assets/` un an `immutable`, et `index.html` en `no-cache`. Un onglet ouvert
 * la veille — ou l'application installée sur le téléphone, qu'on ne ferme
 * jamais — garde donc en mémoire l'`index.html` d'**avant** la mise en
 * production, qui nomme des morceaux que le serveur n'a plus. Au clic suivant,
 * `import()` échoue (nginx rend même la coque HTML à la place du `.js`, refusée
 * pour son type MIME), le routeur abandonne la navigation… et le clic ne fait
 * **rien**. Aucun message, aucune erreur visible : le lecteur conclut que
 * l'application est cassée.
 *
 * Le service worker (`public/sw.js`) n'y est pour rien : il ne met rien en
 * cache, et laisse passer `index.html` comme les actifs. C'est la page déjà
 * chargée qui est vieille, pas une copie.
 *
 * ## Ce qu'on fait
 *
 * Un échec de chargement de morceau — vu par `routeur.onError`, par l'événement
 * `vite:preloadError`, ou remonté jusqu'au filet global (`erreursGlobales.ts`) —
 * déclenche un **rechargement complet vers la destination** : le lecteur arrive
 * là où il voulait aller, avec l'`index.html` du jour.
 *
 * **Une fois.** Avant de recharger, on pose une garde dans `sessionStorage`
 * (propre à l'onglet, et qui survit au rechargement), horodatée. Si la page
 * rechargée échoue encore dans la minute, la garde est là : on ne recharge
 * plus, on **le dit**, avec un bouton « Recharger » — un serveur à moitié
 * déployé ne doit pas faire clignoter l'onglet à l'infini. La garde est levée à
 * la première navigation réussie ; périmée (plus d'une minute), elle ne compte
 * plus : une nouvelle mise en production, des heures plus tard, a droit à son
 * propre rechargement.
 *
 * Sans `sessionStorage` (navigation privée stricte, stockage refusé), aucune
 * garde ne peut tenir : on ne recharge **pas** à l'aveugle, on prévient.
 * Hors connexion non plus : un rechargement n'amènerait que la page « Pas de
 * connexion » du service worker, et ferait perdre l'écran en cours.
 *
 * ## Pourquoi ici, et en fonctions pures
 *
 * `index.ts` appelle `createWebHistory()` dès son chargement : rien de ce qui y
 * vit n'est à portée d'un test. Ici, tout ce qui touche au navigateur — le
 * stockage, `location`, `window`, l'avis affiché — arrive par `Environnement`,
 * et `rechargement.test.ts` monte un vrai routeur en mémoire avec des doublures.
 * ────────────────────────────────────────────────────────────────────
 */
import type { Router } from 'vue-router'
import { afficherAvis } from '@/composables/avisGlobal'

/** La clé de la garde dans `sessionStorage`. */
export const CLE_GARDE = 'heritage:rechargement-apres-mise-en-production'

/** Au-delà, une garde oubliée ne compte plus. Une minute couvre largement le
 *  rechargement et la première navigation, même sur un réseau lent. */
export const DELAI_GARDE_MS = 60_000

export const MESSAGE_APRES_RECHARGEMENT =
  'Cette page n’a pas pu s’ouvrir, même après un rechargement. Heritage vient sans doute d’être mis à jour : rechargez dans un instant.'

export const MESSAGE_HORS_CONNEXION =
  'Cette page n’a pas pu s’ouvrir : la connexion semble coupée. Rechargez quand le réseau revient.'

/**
 * Ce que disent les navigateurs quand un morceau manque. Le texte est la seule
 * prise : Chrome et Safari rendent un `TypeError`, Firefox aussi, sans code.
 *
 * Les deux derniers motifs sont des **suites** d'un `vite:preloadError` qu'on a
 * empêché : l'import rend alors `undefined`, et c'est le routeur (ou un
 * composant asynchrone) qui s'en plaint, avec ses mots.
 */
const MOTIFS_D_ECHEC: readonly RegExp[] = [
  /Failed to fetch dynamically imported module/i, // Chrome, Edge
  /error loading dynamically imported module/i, // Firefox
  /Importing a module script failed/i, // Safari
  /Unable to preload CSS/i, // Vite : la feuille d'un écran
  /Loading (?:CSS )?chunk \S+ failed/i,
  /Couldn't resolve component/i, // vue-router, après un préchargement empêché
]

/** Un échec de chargement de morceau, et rien d'autre. */
export function estEchecDeChargement(erreur: unknown): boolean {
  if (erreur === null || typeof erreur !== 'object') return false
  const { name, message } = erreur as { name?: unknown; message?: unknown }
  if (name === 'ChunkLoadError') return true
  return typeof message === 'string' && MOTIFS_D_ECHEC.some((motif) => motif.test(message))
}

/* ------------------------------------------------------------------ */
/* La garde                                                            */
/* ------------------------------------------------------------------ */

export type Stockage = Pick<Storage, 'getItem' | 'setItem' | 'removeItem'>

export interface Garde {
  /** La destination du rechargement — pour le diagnostic, rien ne la relit. */
  cible: string
  horodatage: number
}

/** La garde posée, ou `null`. Illisible ou stockage refusé : `null`. */
export function lireGarde(stockage: Stockage | null): Garde | null {
  if (stockage === null) return null
  try {
    const brut = stockage.getItem(CLE_GARDE)
    if (brut === null) return null
    const garde = JSON.parse(brut) as Partial<Garde> | null
    if (garde === null || typeof garde.horodatage !== 'number' || typeof garde.cible !== 'string') return null
    return { cible: garde.cible, horodatage: garde.horodatage }
  } catch {
    return null
  }
}

/** Rend `false` si la garde n'a pas pu être écrite : on ne recharge alors pas. */
export function poserGarde(stockage: Stockage | null, garde: Garde): boolean {
  if (stockage === null) return false
  try {
    stockage.setItem(CLE_GARDE, JSON.stringify(garde))
    return true
  } catch {
    return false
  }
}

export function leverGarde(stockage: Stockage | null): void {
  try {
    stockage?.removeItem(CLE_GARDE)
  } catch {
    // Stockage refusé : il n'y avait rien à lever.
  }
}

export type DecisionApresEchec =
  /** Recharger vers la destination : la garde vient d'être posée. */
  | 'recharger'
  /** Une garde fraîche est là — on a déjà rechargé — ou aucune ne peut l'être. */
  | 'avertir'
  /** Le réseau est coupé : recharger n'amènerait rien. */
  | 'hors_connexion'

/**
 * Que faire d'un échec de chargement. **Pose la garde** quand la réponse est
 * `recharger` : l'appelant n'a plus qu'à recharger.
 */
export function deciderApresEchec(contexte: {
  stockage: Stockage | null
  cible: string
  maintenant: number
  enLigne: boolean
}): DecisionApresEchec {
  if (!contexte.enLigne) return 'hors_connexion'

  const garde = lireGarde(contexte.stockage)
  const age = garde === null ? Infinity : contexte.maintenant - garde.horodatage
  // Un âge négatif (horloge reculée) vaut une garde fraîche : dans le doute, on
  // ne recharge pas.
  if (age < DELAI_GARDE_MS) return 'avertir'

  return poserGarde(contexte.stockage, { cible: contexte.cible, horodatage: contexte.maintenant })
    ? 'recharger'
    : 'avertir'
}

/* ------------------------------------------------------------------ */
/* Le branchement                                                      */
/* ------------------------------------------------------------------ */

/** Tout ce que le module touche du navigateur — remplacé par des doublures en test. */
export interface Environnement {
  stockage: Stockage | null
  enLigne: () => boolean
  maintenant: () => number
  /** Rechargement complet vers `href` (base comprise). */
  recharger: (href: string) => void
  /** Dire au lecteur que la page n'a pas pu s'ouvrir, avec « Recharger ». */
  avertir: (message: string, recharger: () => void) => void
  /** Où écouter `vite:preloadError` ; `null` hors navigateur. */
  fenetre: Pick<Window, 'addEventListener'> | null
  journal: (...elements: unknown[]) => void
}

export interface RattrapageChargement {
  /**
   * Traite `erreur` si c'est un échec de chargement de morceau, et rend `true` ;
   * rend `false` sans rien faire sinon. Pour le filet global : un composant
   * asynchrone (`defineAsyncComponent`) échoue hors de toute navigation.
   */
  signalerEchec: (erreur: unknown) => boolean
}

/**
 * Branche le rattrapage sur `routeur`. À appeler **avant** `app.use(routeur)` :
 * la navigation initiale part à ce moment-là, et elle peut échouer elle aussi.
 */
export function installerRattrapageChargement(
  routeur: Router,
  env: Environnement = environnementDuNavigateur(),
): RattrapageChargement {
  /** La destination de la navigation en cours : `vite:preloadError` ne la donne pas. */
  let cibleEnCours: string | null = null
  /** Une fois le rechargement lancé, les échecs qui suivent (l'import qui
   *  continue après un préchargement empêché) ne comptent plus. */
  let rechargementLance = false

  function adresse(cheminComplet: string): string {
    return routeur.resolve(cheminComplet).href
  }

  function traiter(erreur: unknown, href: string): void {
    if (rechargementLance) return
    const decision = deciderApresEchec({
      stockage: env.stockage,
      cible: href,
      maintenant: env.maintenant(),
      enLigne: env.enLigne(),
    })
    const recharger = (): void => env.recharger(href)

    if (decision === 'recharger') {
      rechargementLance = true
      env.journal('Heritage : un écran manque (nouvelle version en ligne ?), rechargement vers', href, erreur)
      recharger()
      return
    }
    env.journal('Heritage : un écran n’a pas pu se charger.', erreur)
    env.avertir(decision === 'hors_connexion' ? MESSAGE_HORS_CONNEXION : MESSAGE_APRES_RECHARGEMENT, recharger)
  }

  function cibleCourante(): string {
    return cibleEnCours ?? adresse(routeur.currentRoute.value.fullPath)
  }

  routeur.beforeEach((vers) => {
    cibleEnCours = adresse(vers.fullPath)
  })

  routeur.afterEach((_vers, _de, echec) => {
    cibleEnCours = null
    // Une navigation **réussie** : la page d'aujourd'hui fonctionne, la garde
    // n'a plus lieu d'être. Un échec de navigation (redirection, doublon) ne
    // prouve rien : la garde reste.
    if (echec === undefined) leverGarde(env.stockage)
  })

  routeur.onError((erreur, vers) => {
    if (!estEchecDeChargement(erreur)) {
      // Déclarer un `onError` fait taire le `console.error` de vue-router : on
      // le rend nous-mêmes, sans rien afficher de plus.
      env.journal('Heritage : erreur pendant la navigation vers', vers.fullPath, erreur)
      return
    }
    traiter(erreur, adresse(vers.fullPath))
  })

  env.fenetre?.addEventListener('vite:preloadError', (evenement) => {
    // Sans `preventDefault`, Vite relance l'erreur ; avec, l'import continue
    // et rend `undefined` — on recharge avant que quiconque ne s'en serve.
    evenement.preventDefault()
    traiter(evenement.payload, cibleCourante())
  })

  return {
    signalerEchec(erreur) {
      if (!estEchecDeChargement(erreur)) return false
      traiter(erreur, cibleCourante())
      return true
    },
  }
}

/* ------------------------------------------------------------------ */
/* Le navigateur                                                       */
/* ------------------------------------------------------------------ */

/**
 * Recharge **vraiment** vers `href`. `location.assign()` vers la même adresse à
 * l'ancre près ne recharge pas — il défile : on remplace alors l'adresse et on
 * recharge la page.
 */
export function rechargerVers(href: string, lieu: Pick<Location, 'href' | 'assign' | 'reload'>, historique: Pick<History, 'replaceState' | 'state'>): void {
  const cible = new URL(href, lieu.href)
  const ici = new URL(lieu.href)
  if (cible.origin === ici.origin && cible.pathname === ici.pathname && cible.search === ici.search) {
    if (cible.hash !== ici.hash) historique.replaceState(historique.state, '', cible.href)
    lieu.reload()
    return
  }
  lieu.assign(cible.href)
}

function environnementDuNavigateur(): Environnement {
  const navigateur = typeof window !== 'undefined'
  let stockage: Stockage | null = null
  try {
    stockage = navigateur ? window.sessionStorage : null
  } catch {
    // `SecurityError` : stockage refusé. Pas de garde possible.
    stockage = null
  }
  return {
    stockage,
    enLigne: () => typeof navigator === 'undefined' || navigator.onLine !== false,
    maintenant: () => Date.now(),
    recharger: (href) => rechargerVers(href, window.location, window.history),
    avertir: (message, recharger) =>
      afficherAvis({ ton: 'erreur', message, action: { libelle: 'Recharger', executer: recharger } }),
    fenetre: navigateur ? window : null,
    journal: (...elements) => console.warn(...elements),
  }
}
