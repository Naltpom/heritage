/**
 * Une seule fenêtre **automatique** à la fois.
 *
 * Quatre fenêtres s'ouvrent d'elles-mêmes, sans que le lecteur les ait
 * demandées : « Se connecter plus vite ? » (`useClesDAcces`), « Installer
 * Heritage sur votre téléphone » (`invitationInstallation`) et « Activer les
 * notifications » (`usePush`), à l'arrivée dans l'application, puis « Comment
 * afficher les fiches de l'arbre ? » (`formatDesCartes`), à la première
 * arrivée sur l'arbre depuis un téléphone. Deux fenêtres empilées, c'est un
 * lecteur qui ferme la première sans la lire pour atteindre la seconde — et
 * qui refuse les deux.
 *
 * L'ordre est fixé (`ORDRE`) :
 *
 * 1. **la clé d'accès** — elle ne revient qu'au bout de trente jours, et elle
 *    s'efface pour de bon une fois la clé créée ;
 * 2. **l'installation**, sur téléphone — avant les notifications, parce que
 *    l'iPhone n'offre le push qu'à l'application installée, et qu'une
 *    application ouverte depuis l'écran d'accueil est celle qu'on garde ;
 * 3. **les notifications**, dans le même chargement, une fois les premières
 *    réglées (« Plus tard », clé créée, application installée) ou écartées
 *    (pas de Face ID, déjà installée…) ;
 * 4. **le format des fiches**, une fois les autres passées.
 *
 * Le mécanisme est un registre à deux colonnes : les places **prises** (une
 * fenêtre en route : en attente de son délai, ou ouverte) et, parmi elles,
 * les fenêtres **ouvertes** (`marquerOuverte`). Chaque fenêtre s'abstient tant
 * qu'une autre la précède — et jamais à cause de sa propre inscription : un
 * pilote qui lit sa propre réservation annule son attente, la libère, la
 * relance… et tourne sans fin. Les pilotes repartent d'eux-mêmes quand la
 * place se libère : la lecture est réactive.
 *
 * Deux façons de s'abstenir :
 *
 * - **`uneAutreFenetreQue(f)`** — céder à **toute** autre inscrite. C'est la
 *   règle des notifications et du format ;
 * - **`doitCeder(f)`** — céder à une inscrite **de rang supérieur** dans
 *   `ORDRE`, ou à une autre **ouverte**. C'est la règle de l'installation.
 *
 * Pourquoi l'installation a la sienne : son invitation (l'événement
 * `beforeinstallprompt` de Chrome) et l'état du push arrivent tous deux, sans
 * ordre garanti, après l'enregistrement du service worker — souvent dans le
 * même instant. Si chacune cédait à l'autre dès qu'elle la voit en attente,
 * l'ordre tiendrait au hasard de ces deux arrivées : la première réservée
 * ferait taire l'autre, et les notifications passeraient avant l'installation
 * une fois sur deux. Avec un rang, l'issue ne dépend plus de l'arrivée, et
 * rien ne se relance en boucle : les notifications cèdent à l'installation en
 * attente ; l'installation, elle, ne cède aux notifications (ou au format)
 * **qu'ouvertes** — on ne referme pas
 * une fenêtre sous les yeux du lecteur pour en ouvrir une autre ; elle attend
 * sa fermeture et passe ensuite, dans le même chargement.
 *
 * L'ordre tient aussi au **moment** où chacune réserve :
 *
 * - la clé d'accès réserve dès que la session est là — **avant** de savoir si
 *   elle s'ouvrira, ce qui demande une question asynchrone au navigateur ;
 * - l'installation et les notifications réservent dès que leur attente
 *   démarre, et jusqu'à leur fermeture ;
 * - le format ne réserve **qu'à l'ouverture**, jamais pendant son attente. S'il
 *   réservait aussi en attendant, lui et les notifications pourraient s'annuler
 *   l'un l'autre à tour de rôle. Il passe donc après tout ce qui s'annonce.
 *
 * Chaque pilote **revérifie** au déclenchement de sa minuterie, et JavaScript
 * n'exécute qu'un rappel à la fois : deux fenêtres ne s'ouvrent jamais
 * ensemble, même si leurs attentes se croisent.
 *
 * Module sans dépendance (Vue seulement) : `usePush` l'importe, et ses tests
 * simulent l'API et la session — un import de plus ne doit rien y réveiller.
 */
import { computed, shallowRef, type Ref } from 'vue'

/** Les fenêtres qui s'ouvrent d'elles-mêmes. */
export type FenetreAutomatique = 'cle-d-acces' | 'installation' | 'notifications' | 'format-des-cartes'

/** L'ordre de passage : une fenêtre passe après toutes celles qui la précèdent. */
export const ORDRE: readonly FenetreAutomatique[] = ['cle-d-acces', 'installation', 'notifications', 'format-des-cartes']

/** Remplacés à chaque changement (jamais mutés) : c'est ce qui les rend réactifs. */
const occupees = shallowRef<ReadonlySet<FenetreAutomatique>>(new Set())
const ouvertes = shallowRef<ReadonlySet<FenetreAutomatique>>(new Set())

function avec(ensemble: ReadonlySet<FenetreAutomatique>, f: FenetreAutomatique): ReadonlySet<FenetreAutomatique> {
  return new Set([...ensemble, f])
}

function sans(ensemble: ReadonlySet<FenetreAutomatique>, f: FenetreAutomatique): ReadonlySet<FenetreAutomatique> {
  const suite = new Set(ensemble)
  suite.delete(f)
  return suite
}

/** Inscrit `f` : les autres fenêtres automatiques attendront qu'elle libère. */
export function occuper(f: FenetreAutomatique): void {
  if (occupees.value.has(f)) return
  occupees.value = avec(occupees.value, f)
}

/**
 * Signale que `f` est ouverte — ou qu'elle ne l'est plus. Ouverte implique
 * inscrite : `marquerOuverte(f, true)` l'inscrit au besoin. Fermée, elle
 * **reste** inscrite jusqu'à `liberer(f)` : c'est au pilote de dire si une
 * autre attente suit.
 */
export function marquerOuverte(f: FenetreAutomatique, ouverte: boolean): void {
  if (ouverte) {
    occuper(f)
    if (!ouvertes.value.has(f)) ouvertes.value = avec(ouvertes.value, f)
  } else if (ouvertes.value.has(f)) {
    ouvertes.value = sans(ouvertes.value, f)
  }
}

/** Retire `f` du registre — inscription et ouverture ; sans effet si elle n'y
 *  était pas. */
export function liberer(f: FenetreAutomatique): void {
  if (ouvertes.value.has(f)) ouvertes.value = sans(ouvertes.value, f)
  if (occupees.value.has(f)) occupees.value = sans(occupees.value, f)
}

/** Une **autre** fenêtre que `f` est-elle en route ? Lecture réactive. */
export function uneAutreFenetreQue(f: FenetreAutomatique): boolean {
  for (const autre of occupees.value) if (autre !== f) return true
  return false
}

/**
 * `f` doit-elle attendre ? Oui si une fenêtre qui la **précède** dans `ORDRE`
 * est en route (en attente ou ouverte), ou si une **autre** fenêtre, quelle
 * qu'elle soit, est ouverte. Jamais à cause de `f` elle-même. Lecture réactive.
 */
export function doitCeder(f: FenetreAutomatique): boolean {
  const rang = ORDRE.indexOf(f)
  for (const autre of occupees.value) if (autre !== f && ORDRE.indexOf(autre) < rang) return true
  for (const autre of ouvertes.value) if (autre !== f) return true
  return false
}

/** Vrai tant que l'invitation à la clé d'accès est en attente ou ouverte. */
export const invitationCleDAccesEnCours: Readonly<Ref<boolean>> = computed(() => occupees.value.has('cle-d-acces'))

/** Raccourcis d'origine, gardés pour `useClesDAcces` et ses tests. */
export function reserverPourLaCleDAcces(): void {
  occuper('cle-d-acces')
}

export function libererApresLaCleDAcces(): void {
  liberer('cle-d-acces')
}
