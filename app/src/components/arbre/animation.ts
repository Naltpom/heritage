/**
 * **L'animation d'arrivée : les cartes glissent de la place que le serveur leur
 * donnait vers celle que la disposition a calculée, puis s'immobilisent.**
 *
 * ────────────────────────────────────────────────────────────────────
 * **CE QUI EST ANIMÉ, ET CE QUI NE L'EST PAS**
 *
 * Le canevas est bâti **avec les positions finales**. L'animation les ramène au
 * départ, puis les rend. C'est l'inverse de l'intuition, et c'est délibéré :
 *
 * > **la position finale est celle de la construction, pas celle du dernier
 * > pas de l'animation.** À la fin, `figerLesPositions()` efface les
 * > `transform` et le dessin redevient exactement celui qu'on aurait eu sans
 * > animation du tout — au pixel, pas « à peu près ».
 *
 * Un compromis d'interpolation ne peut donc pas laisser l'arbre légèrement de
 * travers : il n'y a rien à rattraper, il y a un attribut à retirer.
 *
 * ## Pourquoi pas une transition CSS
 *
 * Parce qu'une fiche est un `<g>` qu'une translation suffit à bouger, mais
 * qu'une **arête est un tracé dont la forme dépend des deux extrémités**. Une
 * transition CSS animerait les cartes en laissant les traits sur place. Les
 * deux doivent être recalculés ensemble à chaque image — c'est le travail de
 * `PiloteCanevas.appliquerPositions`.
 *
 * ## Les gardes
 *
 * - **durée fixe**, et l'animation se termine : il n'y a pas de condition
 *   d'arrêt à atteindre, donc pas de boucle qui puisse tourner indéfiniment ;
 * - **un annulateur est rendu** : un changement de route ou un démontage arrête
 *   la boucle au lieu de la laisser écrire dans un canevas détruit ;
 * - **`prefers-reduced-motion` est respecté** — qui a demandé moins de mouvement
 *   reçoit l'arbre posé, pas l'arbre qui glisse ;
 * - **aucune position n'est inventée** : l'interpolation est une moyenne entre
 *   deux graphes donnés, et les ordonnées ne bougent jamais.
 * ────────────────────────────────────────────────────────────────────
 */

import type { Graphe } from '@/lib/api'
import type { PiloteCanevas } from './canevas'

/** Durée de l'arrivée. Le commanditaire a demandé « une à deux secondes ». */
export const DUREE_MS = 1200

/**
 * Un adoucissement **cubique sortant** : vif au départ, lent à l'arrivée.
 *
 * C'est la forme qui donne l'impression que les cartes « se rangent » plutôt
 * qu'elles ne sont poussées : le mouvement est franc quand il commence, et il
 * s'éteint au lieu de s'arrêter net. Borné à `[0, 1]` pour qu'une horloge qui
 * saute — un onglet remis au premier plan — ne produise pas de position hors
 * du segment.
 */
export function adoucir(t: number): number {
  const borne = Math.min(1, Math.max(0, t))
  const reste = 1 - borne

  return 1 - reste * reste * reste
}

/**
 * Le graphe intermédiaire, à l'instant `t` entre `depart` et `arrivee`.
 *
 * **Les ordonnées ne sont pas interpolées** : elles sont imposées par la
 * génération et identiques des deux côtés. Les interpoler ne changerait rien et
 * laisserait croire qu'elles peuvent bouger.
 *
 * Les nœuds absents de `depart` gardent leur position d'arrivée : une fiche que
 * la disposition ajoute — un écho de duplication, par exemple — apparaît en
 * place plutôt que de venir du néant.
 */
export function interpoler(depart: Graphe, arrivee: Graphe, t: number): Graphe {
  const avant = new Map(depart.noeuds.map((noeud) => [noeud.id, noeud.x]))
  const part = adoucir(t)

  return {
    ...arrivee,
    noeuds: arrivee.noeuds.map((noeud) => {
      const origine = avant.get(noeud.id)
      if (origine === undefined || origine === noeud.x) return noeud

      return { ...noeud, x: origine + (noeud.x - origine) * part }
    }),
  }
}

/**
 * **De quelle fraction de sa place finale l'arbre part.**
 *
 * `0,2` : chaque nœud commence à un cinquième de son abscisse, donc l'arbre part
 * replié sur le lecteur et se **déplie**.
 *
 * ## Pourquoi pas « de la place du serveur vers la solution »
 *
 * C'était la première version, et elle était invisible. Mesuré le 20/09/2026 sur
 * l'arbre réel : le déplacement médian d'un nœud entre les deux était de **105 px
 * sur un arbre large de 21 912** — et, dans le cadre que le téléphone ouvre au
 * chargement (le tronc du lecteur, vingt nœuds sur six cent quarante-quatre), la
 * médiane tombait à **21 px, avec neuf nœuds sur vingt bougeant de moins de vingt
 * pixels et six pas du tout** — les six étant précisément le tronc, qu'on fige.
 *
 * > **Le commanditaire regardait la seule région de l'arbre garantie immobile.**
 *
 * Avec le repli, la médiane du même cadre passe à **350 px** et un seul nœud sur
 * vingt reste sous vingt pixels. Et ça **montre la mécanique** au lieu de la
 * laisser deviner : l'arbre s'ouvre depuis le lecteur.
 *
 * Un cinquième et non zéro : à zéro, les six cent quarante-quatre fiches se
 * superposent à la première image, ce qui se lit comme un défaut d'affichage
 * plutôt que comme un départ.
 */
export const REPLI = 0.2

/**
 * L'arbre **replié sur le lecteur** : le point de départ de l'arrivée.
 *
 * Les ordonnées ne bougent pas, et l'abscisse zéro reste zéro — le lecteur est
 * l'ancre, il ne se déplace pas pendant que son arbre s'ouvre autour de lui.
 */
export function replie(arrivee: Graphe, fraction: number = REPLI): Graphe {
  return {
    ...arrivee,
    noeuds: arrivee.noeuds.map((noeud) =>
      noeud.x === 0 ? noeud : { ...noeud, x: noeud.x * fraction },
    ),
  }
}

/** Ce que l'animation a besoin de connaître de son environnement. */
export interface HorlogeDAnimation {
  maintenant: () => number
  prochaineImage: (suite: (instant: number) => void) => number
  annuler: (jeton: number) => void
  mouvementReduit: () => boolean
}

/** L'horloge du navigateur. Isolée pour qu'un test puisse en fournir une autre. */
export function horlogeDuNavigateur(): HorlogeDAnimation {
  return {
    maintenant: () => performance.now(),
    prochaineImage: (suite) => requestAnimationFrame(suite),
    annuler: (jeton) => cancelAnimationFrame(jeton),
    mouvementReduit: () =>
      typeof globalThis.matchMedia === 'function' &&
      globalThis.matchMedia('(prefers-reduced-motion: reduce)').matches,
  }
}

/**
 * Fait glisser le canevas de `depart` vers `arrivee`, puis l'immobilise.
 *
 * @returns un annulateur, à appeler au démontage ou au changement de route.
 */
export function animerLArrivee(
  pilote: PiloteCanevas,
  depart: Graphe,
  arrivee: Graphe,
  horloge: HorlogeDAnimation = horlogeDuNavigateur(),
  duree: number = DUREE_MS,
): () => void {
  // Qui a demandé moins de mouvement reçoit l'arbre posé. On ne pose rien et
  // l'on ne fige rien : le canevas est DÉJÀ dans son état final.
  if (horloge.mouvementReduit() || duree <= 0) return () => {}

  const debut = horloge.maintenant()
  let jeton = 0
  let vivante = true

  const pas = (instant: number): void => {
    if (!vivante) return

    const t = (instant - debut) / duree

    if (t >= 1) {
      pilote.figerLesPositions()
      vivante = false
      return
    }

    pilote.appliquerPositions(interpoler(depart, arrivee, t))
    jeton = horloge.prochaineImage(pas)
  }

  // Le premier pas est posé tout de suite : sans lui, la première image
  // montrerait l'arbre déjà arrivé, et l'animation commencerait par un saut.
  pilote.appliquerPositions(interpoler(depart, arrivee, 0))
  jeton = horloge.prochaineImage(pas)

  return () => {
    if (!vivante) return
    vivante = false
    horloge.annuler(jeton)
    // **On termine où l'on serait arrivé**, jamais là où l'animation s'est
    // arrêtée : un arbre figé au tiers du chemin serait faux.
    pilote.figerLesPositions()
  }
}
