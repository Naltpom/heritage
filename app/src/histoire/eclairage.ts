/**
 * La surbrillance d'une FAMILLE sur l'arbre : on clique un de ses traits (union, barre de
 * fratrie, chute vers un enfant) et la famille entière ressort — ses traits et les fiches
 * qu'ils relient, époux et enfants. Le reste de l'arbre ne change pas.
 *
 * Logique pure, testée seule ; la vue ne fait que la brancher.
 */
import type { TraceDeFamille } from './lignees'

/**
 * Le clic sur un trait : la famille s'allume, ou s'éteint si c'était déjà elle. `null`
 * (un clic hors de toute ligne) éteint.
 */
export function basculerFamille(eclairee: string | null, cliquee: string | null): string | null {
  if (cliquee === null || cliquee === eclairee) return null
  return cliquee
}

/**
 * La famille dont un élément cliqué est un trait : l'attribut `data-famille` du tracé, ou
 * `null` quand le clic est tombé ailleurs.
 */
export function familleDuClic(cible: EventTarget | null): string | null {
  if (!cible || typeof (cible as Element).getAttribute !== 'function') return null
  return (cible as Element).getAttribute('data-famille')
}

/** La famille éclairée, si elle est encore dessinée — une remise en page peut l'avoir fait disparaître. */
export function familleEclairee(familles: TraceDeFamille[], id: string | null): TraceDeFamille | null {
  if (id === null) return null
  return familles.find((f) => f.id === id) ?? null
}
