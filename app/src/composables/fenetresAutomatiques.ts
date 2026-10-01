/**
 * Une seule fenêtre **automatique** à la fois.
 *
 * Trois fenêtres s'ouvrent d'elles-mêmes, sans que le lecteur les ait
 * demandées : « Se connecter plus vite ? » (`useClesDAcces`) et « Activer les
 * notifications » (`usePush`), à l'arrivée dans l'application, puis « Comment
 * afficher les fiches de l'arbre ? » (`formatDesCartes`), à la première
 * arrivée sur l'arbre depuis un téléphone. Deux fenêtres empilées, c'est un
 * lecteur qui ferme la première sans la lire pour atteindre la seconde — et
 * qui refuse les deux.
 *
 * L'ordre est fixé :
 *
 * 1. **la clé d'accès** — elle ne revient qu'au bout de trente jours, et elle
 *    s'efface pour de bon une fois la clé créée ;
 * 2. **les notifications**, dans le même chargement, une fois la première
 *    réglée (« Plus tard », clé créée) ou écartée (pas de Face ID…) ;
 * 3. **le format des fiches**, une fois les deux autres passées.
 *
 * Le mécanisme est un registre des places prises. Chaque fenêtre y inscrit sa
 * clé tant qu'elle est en route, et chacune s'abstient tant qu'**une autre**
 * y est inscrite (`uneAutreFenetreQue`) — jamais la sienne : un pilote qui lit
 * sa propre réservation annule son attente, la libère, la relance… et tourne
 * sans fin. Les pilotes repartent d'eux-mêmes quand la place se libère : la
 * lecture est réactive.
 *
 * L'ordre ne tient pas à une file mais au **moment** où chacune réserve :
 *
 * - la clé d'accès réserve dès que la session est là — **avant** de savoir si
 *   elle s'ouvrira, ce qui demande une question asynchrone au navigateur ;
 * - les notifications réservent dès que leur attente démarre ;
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
export type FenetreAutomatique = 'cle-d-acces' | 'notifications' | 'format-des-cartes'

/** Remplacé à chaque changement (jamais muté) : c'est ce qui le rend réactif. */
const occupees = shallowRef<ReadonlySet<FenetreAutomatique>>(new Set())

/** Inscrit `f` : les autres fenêtres automatiques attendront qu'elle libère. */
export function occuper(f: FenetreAutomatique): void {
  if (occupees.value.has(f)) return
  occupees.value = new Set([...occupees.value, f])
}

/** Retire `f` du registre ; sans effet si elle n'y était pas. */
export function liberer(f: FenetreAutomatique): void {
  if (!occupees.value.has(f)) return
  const suite = new Set(occupees.value)
  suite.delete(f)
  occupees.value = suite
}

/** Une **autre** fenêtre que `f` est-elle en route ? Lecture réactive. */
export function uneAutreFenetreQue(f: FenetreAutomatique): boolean {
  for (const autre of occupees.value) if (autre !== f) return true
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
