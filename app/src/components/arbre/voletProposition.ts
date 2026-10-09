/**
 * Le volet « Ajouter une personne » de `/arbre` (`VoletProposition.vue`) : ce qui,
 * de son voisinage avec l'arbre, se décide sans DOM — et se teste donc sans lui.
 *
 * L'arbre écoute le clavier sur toute la fenêtre (`/` chercher, `0` tout l'arbre,
 * `+` et `-` zoomer, Échap désélectionner). Un formulaire ouvert à côté ne doit
 * pas les déclencher : taper « Jean-Marie » dans un champ ne dézoome pas l'arbre.
 */

/** Ce que `toucheIgnoree` lit d'une cible de clavier — de quoi s'écrire à la main
 *  dans un test, sans DOM. Un `HTMLElement` en est un. */
export interface CibleDeTouche {
  tagName?: string
  isContentEditable?: boolean
  closest?: (selecteur: string) => unknown
}

/** L'attribut qui marque le volet, posé sur son `<aside>`. */
export const SELECTEUR_VOLET_PROPOSITION = '[data-volet-proposition]'

/** Les champs où une touche est une saisie. `SELECT` en est : Échap y referme la
 *  liste, `-` et `0` y choisissent une option. */
const CHAMPS_DE_SAISIE = new Set(['INPUT', 'TEXTAREA', 'SELECT'])

/**
 * Une touche que les raccourcis de l'arbre doivent laisser passer : frappée dans un
 * champ de saisie, dans un élément éditable, ou **n'importe où dans le volet** — un
 * bouton du formulaire qui a le focus, Échap compris : le formulaire n'est pas
 * l'arbre, et une sélection ne se perd pas d'un Échap tapé ailleurs.
 */
export function toucheIgnoree(cible: CibleDeTouche | null | undefined): boolean {
  if (!cible) return false
  if (cible.tagName !== undefined && CHAMPS_DE_SAISIE.has(cible.tagName)) return true
  if (cible.isContentEditable === true) return true
  return typeof cible.closest === 'function' && cible.closest(SELECTEUR_VOLET_PROPOSITION) != null
}
