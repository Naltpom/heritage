/**
 * Un lecteur vidéo est-il ouvert ? — l'état partagé, de portée module.
 *
 * Une seule raison d'exister, et elle est concrète : **la touche Échap**. Le
 * lecteur l'écoute en capture sur `window` et l'arrête là, pour que l'écran
 * derrière ne l'entende pas (l'arbre désélectionnerait, le feuillet des
 * filtres se refermerait). Mais un écouteur de capture posé **avant** le sien
 * sur la même cible passe quand même en premier — c'est l'ordre d'inscription
 * qui décide. C'est le cas du menu « Filtres » du bureau (`BarreEcran`), d'où
 * l'on ouvre justement la vidéo des filtres : sans ce drapeau, le premier Échap
 * fermait le menu, invisible sous la vidéo, et seul le second fermait la vidéo.
 * `BarreEcran` lit donc `lecteurVideoOuvert` et cède la touche.
 *
 * Un compteur plutôt qu'un booléen : deux lecteurs ne sont jamais ouverts
 * ensemble aujourd'hui, mais un compteur ne ment pas le jour où cela arrive
 * (le premier fermé ne remettrait pas le drapeau à faux sous le second).
 *
 * Module sans dépendance (Vue seulement) : `BarreEcran` est rendu par plusieurs
 * suites de tests, et un import de plus ne doit rien y réveiller.
 */
import { computed, shallowRef, type ComputedRef } from 'vue'

const ouverts = shallowRef(0)

/** Vrai tant qu'au moins un lecteur vidéo est affiché. Lecture réactive. */
export const lecteurVideoOuvert: ComputedRef<boolean> = computed(() => ouverts.value > 0)

/**
 * Inscrit un lecteur ouvert ; rend la fonction qui le désinscrit. Appeler
 * cette fonction deux fois n'a d'effet qu'une fois.
 */
export function signalerLecteurOuvert(): () => void {
  ouverts.value++
  let libere = false
  return () => {
    if (libere) return
    libere = true
    ouverts.value = Math.max(0, ouverts.value - 1)
  }
}
