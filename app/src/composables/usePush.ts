/**
 * Les notifications **sur le téléphone** (Web Push) — un seul état pour toute
 * l'application, même parti que `useSession` et `useNotifications`.
 *
 * La cloche ne se voit qu'application ouverte. Le push, lui, fait sonner le
 * téléphone application fermée : le serveur (`NotificationService::notifier()`)
 * envoie chaque notification déposée à tous les abonnements du destinataire, et
 * le service worker (`public/sw.js`) l'affiche.
 *
 * ────────────────────────────────────────────────────────────────────
 * **Pourquoi « le jeton cassait à chaque mise en prod », et ce qui l'empêche.**
 *
 * Un abonnement push est lié à la **clé publique VAPID** du serveur. Si cette
 * clé change — clés régénérées au déploiement —, tous les abonnements existants
 * deviennent muets, sans erreur visible nulle part. Deux protections :
 *
 * 1. côté serveur, la clé est créée **une fois** et gardée en base : un
 *    redéploiement ne la change plus ;
 * 2. côté front, **`synchroniserPush()` à chaque démarrage** d'une session :
 *    si la permission est accordée, on relit la clé du serveur, on compare à
 *    celle de l'abonnement du navigateur, on se réabonne si elle diffère ou si
 *    l'abonnement a disparu — et **dans tous les cas** on renvoie l'abonnement
 *    au serveur (`PUT` idempotent). Un navigateur qui a fait tourner son
 *    endpoint, une base restaurée, un abonnement supprimé côté serveur après un
 *    `410` : tout se répare à la prochaine ouverture de l'application, sans
 *    que personne ait à revenir cliquer « Activer ».
 *
 * Le service worker rattrape aussi `pushsubscriptionchange` quand le navigateur
 * le signale (voir `sw.js`) ; ce n'est qu'un complément : tous ne l'émettent pas.
 * ────────────────────────────────────────────────────────────────────
 *
 * **Les états**, dans l'ordre où on les teste :
 *
 * - `a_installer` : iPhone ou iPad **hors écran d'accueil**. Safari n'y offre le
 *   push qu'à une application installée (iOS 16.4 et suivants) ; le dire vaut
 *   mieux qu'un bouton qui ne ferait rien ;
 * - `indisponible` : pas de service worker, pas de `PushManager` — vieux
 *   navigateur, ou serveur de développement (le service worker n'y est pas
 *   enregistré, voir `installation.ts`) ;
 * - `bloque` : la permission a été **refusée**. Le navigateur ne redemandera
 *   plus ; seul le lecteur peut la rendre, dans les réglages du site ;
 * - `active` : permission accordée et abonnement présent ;
 * - `desactive` : tout le reste — jamais demandé, ou désactivé ici.
 *
 * **« Désactiver » est retenu sur l'appareil** (`localStorage`). Sans cela, le
 * prochain `synchroniserPush()` verrait une permission accordée sans abonnement
 * et… réabonnerait aussitôt. Le lecteur qui a dit non doit rester à non.
 *
 * **Jamais pendant une incarnation** (un administrateur connecté en tant qu'un
 * autre compte, ADR 0010) : l'abonnement serait réattribué au compte incarné,
 * et le téléphone de l'administrateur recevrait les notifications d'un autre.
 *
 * L'accès au navigateur passe par un `EnvironnementPush` injectable :
 * l'environnement de vitest est `node`, sans `Notification` ni service worker.
 */

import { readonly, shallowRef, watch, type Ref } from 'vue'
import { enregistrerAbonnementPush, lireClePush, supprimerAbonnementPush } from '@/lib/api'
import { useSession } from '@/composables/useSession'
import { useInstallation } from '@/composables/installation'
import { rafraichirCompteur } from '@/composables/useNotifications'

export type EtatPush = 'inconnu' | 'indisponible' | 'a_installer' | 'desactive' | 'active' | 'bloque'

export type PermissionPush = 'default' | 'granted' | 'denied'

/** Ce qu'on lit d'un `PushSubscription`. */
export interface AbonnementNavigateur {
  readonly endpoint: string
  readonly options?: { readonly applicationServerKey?: ArrayBuffer | null }
  toJSON(): { endpoint?: string; keys?: Record<string, string> }
  unsubscribe(): Promise<boolean>
}

/** Ce qu'on utilise d'un `PushManager`. */
export interface GestionnairePush {
  getSubscription(): Promise<AbonnementNavigateur | null>
  subscribe(options: { userVisibleOnly: true; applicationServerKey: Uint8Array<ArrayBuffer> }): Promise<AbonnementNavigateur>
}

/** Le navigateur, vu par ce module. */
export interface EnvironnementPush {
  /** Service worker, `PushManager` et `Notification` présents (et production). */
  readonly pushPossible: boolean
  /** iPhone ou iPad hors écran d'accueil : le push n'existe qu'une fois installé. */
  readonly aInstallerDabord: boolean
  permission(): PermissionPush
  demanderPermission(): Promise<PermissionPush>
  /** Le `PushManager` du service worker **prêt** (`navigator.serviceWorker.ready`). */
  gestionnaire(): Promise<GestionnairePush>
  /** Le lecteur a-t-il désactivé le push sur cet appareil ? */
  lireRefus(): boolean
  ecrireRefus(refus: boolean): void
}

/** Clé `localStorage` du refus explicite, par appareil. */
const CLE_REFUS = 'heritage.push.desactive'

/** `navigator.serviceWorker.ready` ne se résout **jamais** si l'enregistrement a
 *  échoué : sans délai, un bouton « Activer » attendrait pour toujours. */
const DELAI_SERVICE_WORKER_MS = 10_000

function avecDelai<T>(promesse: Promise<T>, ms: number): Promise<T> {
  return new Promise<T>((resoudre, rejeter) => {
    const minuterie = setTimeout(() => rejeter(new Error('délai dépassé')), ms)
    promesse.then(
      (v) => {
        clearTimeout(minuterie)
        resoudre(v)
      },
      (e: unknown) => {
        clearTimeout(minuterie)
        rejeter(e instanceof Error ? e : new Error(String(e)))
      },
    )
  })
}

/** L'environnement réel. Relu à chaque appel : l'installation peut changer
 *  l'état (`appinstalled`), et la permission change hors de notre vue. */
function environnementNavigateur(): EnvironnementPush {
  const navigateur = typeof window !== 'undefined' && typeof navigator !== 'undefined'
  const { ios, installee } = useInstallation()
  const pushPossible =
    navigateur &&
    import.meta.env.PROD &&
    'serviceWorker' in navigator &&
    'PushManager' in window &&
    'Notification' in window
  return {
    pushPossible,
    aInstallerDabord: navigateur && ios && !installee.value,
    permission: () => (typeof Notification === 'undefined' ? 'default' : Notification.permission),
    demanderPermission: () => Notification.requestPermission(),
    gestionnaire: () =>
      avecDelai(navigator.serviceWorker.ready, DELAI_SERVICE_WORKER_MS).then(
        (r) => r.pushManager as unknown as GestionnairePush,
      ),
    lireRefus: () => {
      try {
        return globalThis.localStorage?.getItem(CLE_REFUS) === '1'
      } catch {
        return false
      }
    },
    ecrireRefus: (refus) => {
      try {
        if (refus) globalThis.localStorage?.setItem(CLE_REFUS, '1')
        else globalThis.localStorage?.removeItem(CLE_REFUS)
      } catch {
        // Stockage bloqué : le refus ne survivra pas au rechargement, tant pis.
      }
    },
  }
}

let environnementInjecte: EnvironnementPush | null = null
function env(): EnvironnementPush {
  return environnementInjecte ?? environnementNavigateur()
}

/** Pour les tests : remplace le navigateur. `null` rend le vrai. */
export function definirEnvironnementPush(e: EnvironnementPush | null): void {
  environnementInjecte = e
}

const etat = shallowRef<EtatPush>('inconnu')
const enCours = shallowRef(false)
const erreur = shallowRef<string | null>(null)
/** Le lecteur a touché « Désactiver » sur cet appareil : on ne le lui repropose
 *  qu'à l'endroit où il l'a dit (Moi), jamais ailleurs. */
const refuse = shallowRef(false)

/** Remet l'état à zéro — pour les tests, qui partagent le module. */
export function reinitialiserPush(): void {
  etat.value = 'inconnu'
  enCours.value = false
  erreur.value = null
  refuse.value = false
  file = Promise.resolve()
}

/**
 * Les opérations passent l'une après l'autre. `synchroniserPush()` part au
 * démarrage, et un lecteur rapide peut toucher « Activer » avant qu'il ait
 * fini : deux `subscribe()` concurrents sur deux clés différentes laisseraient
 * au serveur un abonnement que le navigateur a déjà remplacé.
 */
let file: Promise<unknown> = Promise.resolve()
function enFile<T>(operation: () => Promise<T>): Promise<T> {
  const suite = file.then(operation, operation)
  file = suite.catch(() => undefined)
  return suite
}

/* ------------------------------------------------------------------ */
/* Clés                                                                */
/* ------------------------------------------------------------------ */

/** base64url (paddé ou non) → octets. C'est la forme qu'attend
 *  `applicationServerKey`, et celle que rend `GET /api/push/cle`. */
export function octetsDepuisBase64Url(texte: string): Uint8Array<ArrayBuffer> {
  const base64 = texte.replace(/-/g, '+').replace(/_/g, '/')
  const complete = base64 + '='.repeat((4 - (base64.length % 4)) % 4)
  const binaire = atob(complete)
  const octets = new Uint8Array(new ArrayBuffer(binaire.length))
  for (let i = 0; i < binaire.length; i++) octets[i] = binaire.charCodeAt(i)
  return octets
}

/** L'abonnement a-t-il été pris avec **cette** clé ? Un navigateur qui ne dit
 *  pas sa clé (`options` absent) est traité comme différent : se réabonner
 *  coûte un aller-retour, garder un abonnement muet coûte toutes les
 *  notifications. */
export function memeCle(abonnement: AbonnementNavigateur, cle: Uint8Array): boolean {
  const sienne = abonnement.options?.applicationServerKey
  if (!sienne) return false
  const octets = new Uint8Array(sienne)
  if (octets.length !== cle.length) return false
  return octets.every((o, i) => o === cle[i])
}

/* ------------------------------------------------------------------ */
/* Opérations                                                          */
/* ------------------------------------------------------------------ */

function incarnationActive(): boolean {
  return useSession().incarnation?.value != null
}

/** Relit l'état depuis le navigateur, sans rien changer. Ne lève jamais. */
export async function evaluerPush(): Promise<EtatPush> {
  const e = env()
  refuse.value = e.lireRefus()
  let nouveau: EtatPush
  if (e.aInstallerDabord) nouveau = 'a_installer'
  else if (!e.pushPossible) nouveau = 'indisponible'
  else {
    const permission = e.permission()
    if (permission === 'denied') nouveau = 'bloque'
    else if (permission !== 'granted') nouveau = 'desactive'
    else {
      try {
        const abonnement = await (await e.gestionnaire()).getSubscription()
        nouveau = abonnement ? 'active' : 'desactive'
      } catch {
        nouveau = 'indisponible'
      }
    }
  }
  etat.value = nouveau
  return nouveau
}

/**
 * S'abonne si besoin (ou se réabonne si la clé a changé), puis envoie
 * l'abonnement au serveur. Lève en cas d'échec : les appelants décident.
 */
async function abonnerEtEnvoyer(e: EnvironnementPush): Promise<void> {
  const { clePublique } = await lireClePush()
  const cle = octetsDepuisBase64Url(clePublique)
  const gestionnaire = await e.gestionnaire()

  let abonnement = await gestionnaire.getSubscription()
  if (abonnement && !memeCle(abonnement, cle)) {
    // Pris avec une autre clé : il est muet pour ce serveur. On le retire des
    // deux côtés — le serveur l'aurait fait seul au premier `410`, mais autant
    // ne pas lui laisser une adresse morte.
    const ancien = abonnement.endpoint
    await abonnement.unsubscribe().catch(() => false)
    await supprimerAbonnementPush(ancien).catch(() => undefined)
    abonnement = null
  }
  abonnement ??= await gestionnaire.subscribe({ userVisibleOnly: true, applicationServerKey: cle })

  const json = abonnement.toJSON()
  const p256dh = json.keys?.['p256dh']
  const auth = json.keys?.['auth']
  if (!p256dh || !auth) throw new Error('abonnement sans clés de chiffrement')
  await enregistrerAbonnementPush({ endpoint: json.endpoint ?? abonnement.endpoint, keys: { p256dh, auth } })
}

/**
 * « Activer » — **à appeler depuis un geste** (clic) : Safari et Firefox
 * refusent `requestPermission()` hors geste. La permission est donc demandée
 * **en premier**, avant tout aller-retour réseau qui ferait perdre le geste.
 * Rend `true` si le téléphone recevra les notifications.
 */
export function activerPush(): Promise<boolean> {
  const e = env()
  erreur.value = null
  if (e.aInstallerDabord || !e.pushPossible || incarnationActive()) {
    void evaluerPush()
    return Promise.resolve(false)
  }
  // Hors file : la demande de permission doit partir dans le même tour que le
  // clic. Seul l'abonnement attend son tour.
  enCours.value = true
  const permission = e.demanderPermission()
  return enFile(async () => {
    try {
      const reponse = await permission
      if (reponse !== 'granted') {
        etat.value = reponse === 'denied' ? 'bloque' : 'desactive'
        return false
      }
      e.ecrireRefus(false)
      refuse.value = false
      await abonnerEtEnvoyer(e)
      etat.value = 'active'
      return true
    } catch {
      erreur.value = 'Les notifications n’ont pas pu être activées. Réessayez dans un moment.'
      await evaluerPush()
      return false
    } finally {
      enCours.value = false
    }
  })
}

/** « Désactiver » : retiré du serveur, puis du navigateur, et retenu sur
 *  l'appareil pour que la synchronisation ne réabonne pas derrière. */
export function desactiverPush(): Promise<void> {
  const e = env()
  erreur.value = null
  enCours.value = true
  return enFile(async () => {
    try {
      e.ecrireRefus(true)
      refuse.value = true
      if (!e.pushPossible) return
      const abonnement = await (await e.gestionnaire()).getSubscription()
      if (abonnement) {
        // D'abord le serveur, tant que l'endpoint est encore valable. Un échec
        // n'empêche rien : le serveur recevra un `410` et l'oubliera seul.
        await supprimerAbonnementPush(abonnement.endpoint).catch(() => undefined)
        await abonnement.unsubscribe()
      }
    } catch {
      erreur.value = 'Les notifications n’ont pas pu être désactivées. Réessayez dans un moment.'
    } finally {
      enCours.value = false
      await evaluerPush()
    }
  })
}

/**
 * Au démarrage d'une session : rétablit l'abonnement si la permission est
 * accordée, **et le renvoie au serveur dans tous les cas**. C'est ce qui rend
 * le push insensible aux mises en production (voir l'en-tête). Silencieux :
 * aucun écran n'attend ce résultat, et une panne réseau au démarrage ne doit
 * rien afficher. Rien sans session — un appel anonyme rendrait une `401`, et
 * la `401` renvoie à la connexion.
 */
export function synchroniserPush(): Promise<void> {
  return enFile(async () => {
    if (!useSession().connecte.value || incarnationActive()) return
    const e = env()
    if (e.aInstallerDabord || !e.pushPossible || e.permission() !== 'granted' || e.lireRefus()) {
      await evaluerPush()
      return
    }
    try {
      await abonnerEtEnvoyer(e)
      etat.value = 'active'
    } catch {
      await evaluerPush()
    }
  })
}

/**
 * Avant la déconnexion : l'appareil cesse de recevoir les notifications de ce
 * compte. L'abonnement du navigateur, lui, reste — la prochaine session qui
 * s'ouvre ici le renverra au serveur sous **son** compte. Ne lève jamais et ne
 * retarde pas la déconnexion de plus de deux secondes.
 */
export async function oublierPushAvantDeconnexion(): Promise<void> {
  try {
    const e = env()
    if (!e.pushPossible || e.permission() !== 'granted' || incarnationActive()) return
    await avecDelai(
      (async () => {
        const abonnement = await (await e.gestionnaire()).getSubscription()
        if (abonnement) await supprimerAbonnementPush(abonnement.endpoint)
      })(),
      2_000,
    )
  } catch {
    // Au pire, ce téléphone reçoit encore une notification de l'ancien compte
    // jusqu'à la prochaine connexion — qui réattribue l'abonnement.
  }
}

/**
 * Branché une fois, par `main.ts`, après le chargement de la session :
 *
 * - à chaque passage à « connecté » (démarrage, connexion), `synchroniserPush()` ;
 * - un message du service worker (« une notification vient d'arriver ») relit
 *   le compteur de la cloche : l'application ouverte voit la pastille changer
 *   sans attendre son battement de 60 s.
 *
 * Rien ne bloque l'affichage : tout part en tâche de fond.
 */
export function demarrerPush(): () => void {
  const arreterSession = watch(
    () => useSession().connecte.value,
    (connecte) => {
      if (connecte) void synchroniserPush()
      else etat.value = 'inconnu'
    },
    { immediate: true },
  )

  const conteneur = typeof navigator !== 'undefined' && 'serviceWorker' in navigator ? navigator.serviceWorker : null
  const surMessage = (m: MessageEvent): void => {
    if ((m.data as { type?: unknown } | null)?.type === 'heritage:notification') void rafraichirCompteur()
  }
  conteneur?.addEventListener('message', surMessage)

  return () => {
    arreterSession()
    conteneur?.removeEventListener('message', surMessage)
  }
}

export interface EtatPushExpose {
  etat: Readonly<Ref<EtatPush>>
  enCours: Readonly<Ref<boolean>>
  erreur: Readonly<Ref<string | null>>
  refuse: Readonly<Ref<boolean>>
  evaluer: typeof evaluerPush
  activer: typeof activerPush
  desactiver: typeof desactiverPush
  synchroniser: typeof synchroniserPush
}

export function usePush(): EtatPushExpose {
  return {
    etat: readonly(etat),
    enCours: readonly(enCours),
    erreur: readonly(erreur),
    refuse: readonly(refuse),
    evaluer: evaluerPush,
    activer: activerPush,
    desactiver: desactiverPush,
    synchroniser: synchroniserPush,
  }
}
