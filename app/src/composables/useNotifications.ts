/**
 * Les notifications du lecteur — **un seul état pour toute l'application**.
 *
 * Même parti que `useSession` : un singleton de module, pas un magasin. Le
 * compteur non lu est une donnée unique, lue par la cloche aujourd'hui, peut-
 * être demain par l'onglet « Moi » de la barre du bas ; deux composants qui
 * interrogent chacun `GET /api/notifications/compteur` de leur côté, c'est deux
 * fois la requête la plus chaude de l'API pour le même nombre.
 *
 * ────────────────────────────────────────────────────────────────────
 * **Quand le compteur est-il relu ?** Il n'y a ni file, ni Redis, ni push
 * (voir l'en-tête de `NotificationController.php`) : le front **va chercher**.
 *
 * 1. Au montage de la cloche ;
 * 2. toutes les 60 s, **tant que l'onglet est visible** — un onglet oublié en
 *    arrière-plan toute une nuit ne doit pas interroger le serveur 600 fois ;
 * 3. au retour de visibilité — c'est le moment où le lecteur regarde, et le
 *    chiffre a pu vieillir d'une heure ;
 * 4. à la connexion (la session passe à « connecté »).
 *
 * **Jamais sans session.** Un visiteur anonyme recevrait une 401, et une 401
 * déclenche le rattrapage global qui renvoie à la connexion (`useSession`) :
 * interroger la cloche d'un anonyme le chasserait de la page publique où il
 * est. D'où le garde `connecte` devant **chaque** appel, pas seulement au
 * démarrage : la session peut tomber entre deux battements.
 *
 * La liste, elle, n'est lue **qu'à l'ouverture** de la cloche : elle pèse
 * vingt-cinq lignes, et la plupart des pages vues ne l'ouvrent pas.
 * ────────────────────────────────────────────────────────────────────
 */

import { getCurrentInstance, onBeforeUnmount, onMounted, readonly, shallowRef, watch, type Ref } from 'vue'
import {
  compterNotificationsNonLues,
  listerNotifications,
  marquerNotificationLue,
  marquerToutesNotificationsLues,
  type Notification,
} from '@/lib/api'
import { useSession } from '@/composables/useSession'

/** Une minute : assez court pour qu'une validation arrive « tout de suite »,
 *  assez long pour rester invisible dans les journaux du serveur. */
export const PERIODE_SONDAGE_MS = 60_000

/** Combien de notifications la cloche montre. Au-delà, la plus ancienne n'a
 *  plus rien à dire que le compteur ne dise déjà. */
export const TAILLE_LISTE = 30

export type EtatListe = 'repos' | 'chargement' | 'prete' | 'erreur'

const nonLues = shallowRef(0)
const notifications = shallowRef<readonly Notification[]>([])
const etatListe = shallowRef<EtatListe>('repos')

function connecte(): boolean {
  return useSession().connecte.value
}

/**
 * Relit le compteur. **Silencieux en cas d'échec** : la cloche garde son dernier
 * chiffre. Une pastille qui clignote en erreur toutes les minutes parce que le
 * réseau du train a sauté serait pire que douze secondes de chiffre périmé.
 */
export async function rafraichirCompteur(): Promise<void> {
  if (!connecte()) {
    nonLues.value = 0
    return
  }
  try {
    const { nonLues: n } = await compterNotificationsNonLues()
    nonLues.value = n
  } catch {
    /* voir le commentaire de la fonction */
  }
}

/** Charge la liste (à l'ouverture de la cloche). L'erreur, elle, se dit :
 *  le lecteur a demandé à voir, il doit savoir pourquoi il ne voit rien. */
export async function chargerNotifications(): Promise<void> {
  if (!connecte()) {
    notifications.value = []
    etatListe.value = 'repos'
    return
  }
  etatListe.value = 'chargement'
  try {
    const page = await listerNotifications({ page: 1, parPage: TAILLE_LISTE })
    notifications.value = page.elements
    etatListe.value = 'prete'
    // La liste est fraîche ; le compteur l'est-il ? On le recalcule depuis
    // elle quand elle est complète, sans second appel.
    if (page.total <= page.elements.length) {
      nonLues.value = page.elements.filter((n) => n.readAt === null).length
    }
  } catch {
    etatListe.value = 'erreur'
  }
}

/**
 * Marque une notification lue — au clic, avant la navigation.
 *
 * **Optimiste** : la ligne perd sa marque et la pastille décompte tout de
 * suite, parce que le clic navigue et que personne n'attendra la réponse. Si
 * le serveur refuse, on relit le compteur : le chiffre revient juste, et la
 * notification restée non lue réapparaîtra telle à la prochaine ouverture.
 */
export async function marquerLue(notification: Notification): Promise<void> {
  if (notification.readAt !== null || !connecte()) return
  const maintenant = new Date().toISOString()
  notifications.value = notifications.value.map((n) => (n.id === notification.id ? { ...n, readAt: maintenant } : n))
  nonLues.value = Math.max(0, nonLues.value - 1)
  try {
    await marquerNotificationLue(notification.id)
  } catch {
    await rafraichirCompteur()
  }
}

/**
 * « Tout marquer comme lu ». Ici **pas** d'optimisme : l'action ne navigue
 * pas, le lecteur regarde la liste, et lui montrer tout lu puis tout non lu
 * serait un mensonge visible. Rend `false` si le serveur a refusé.
 */
export async function toutMarquerLu(): Promise<boolean> {
  if (!connecte()) return false
  try {
    await marquerToutesNotificationsLues()
  } catch {
    return false
  }
  const maintenant = new Date().toISOString()
  notifications.value = notifications.value.map((n) => (n.readAt === null ? { ...n, readAt: maintenant } : n))
  nonLues.value = 0
  return true
}

/** Ce qu'il faut de `document` pour suivre la visibilité — injectable, parce
 *  que l'environnement de vitest est `node` et n'a pas de `document`. */
export interface CibleVisibilite {
  readonly visibilityState: string
  addEventListener(type: 'visibilitychange', ecouteur: () => void): void
  removeEventListener(type: 'visibilitychange', ecouteur: () => void): void
}

/**
 * Lance le suivi : un premier compte, le battement de 60 s, l'écoute de la
 * visibilité et de la session. Rend la fonction qui arrête tout.
 *
 * Le battement **tourne** quand l'onglet est caché mais **n'appelle pas** :
 * `setInterval` est de toute façon ralenti par le navigateur en arrière-plan,
 * et le retour de visibilité rattrape d'un coup.
 */
export function demarrerSuivi(cible: CibleVisibilite | null = globalThis.document ?? null): () => void {
  const visible = (): boolean => cible === null || cible.visibilityState === 'visible'

  void rafraichirCompteur()
  const minuterie = setInterval(() => {
    if (visible()) void rafraichirCompteur()
  }, PERIODE_SONDAGE_MS)

  const surVisibilite = (): void => {
    if (visible()) void rafraichirCompteur()
  }
  cible?.addEventListener('visibilitychange', surVisibilite)

  // Connexion, déconnexion, bascule « voir comme » : le compteur change de
  // propriétaire. Déconnecté, il retombe à zéro et la liste est oubliée — la
  // cloche du membre précédent ne doit pas s'afficher au suivant.
  const arreterSession = watch(
    () => useSession().connecte.value,
    (estConnecte) => {
      notifications.value = []
      etatListe.value = 'repos'
      if (estConnecte) void rafraichirCompteur()
      else nonLues.value = 0
    },
  )

  return () => {
    clearInterval(minuterie)
    cible?.removeEventListener('visibilitychange', surVisibilite)
    arreterSession()
  }
}

/** Remet l'état à zéro — pour les tests, qui partagent le module. */
export function reinitialiserNotifications(): void {
  nonLues.value = 0
  notifications.value = []
  etatListe.value = 'repos'
}

export interface EtatNotifications {
  nonLues: Readonly<Ref<number>>
  notifications: Readonly<Ref<readonly Notification[]>>
  etatListe: Readonly<Ref<EtatListe>>
  charger: typeof chargerNotifications
  marquerLue: typeof marquerLue
  toutMarquerLu: typeof toutMarquerLu
  rafraichirCompteur: typeof rafraichirCompteur
}

/**
 * Le composable. Appelé dans un composant, il **suit** le compteur le temps
 * que ce composant est monté ; plusieurs composants peuvent l'appeler, un seul
 * battement tourne (compte de références).
 */
let abonnes = 0
let arreter: (() => void) | null = null

export function useNotifications(): EtatNotifications {
  if (getCurrentInstance() !== null) {
    onMounted(() => {
      abonnes += 1
      if (abonnes === 1) arreter = demarrerSuivi()
    })
    onBeforeUnmount(() => {
      abonnes = Math.max(0, abonnes - 1)
      if (abonnes === 0) {
        arreter?.()
        arreter = null
      }
    })
  }
  return {
    nonLues: readonly(nonLues),
    notifications: readonly(notifications) as Readonly<Ref<readonly Notification[]>>,
    etatListe: readonly(etatListe),
    charger: chargerNotifications,
    marquerLue,
    toutMarquerLu,
    rafraichirCompteur,
  }
}
