/**
 * Le registre des dispositions, et ce que chacune annonce à l'écran.
 *
 * ────────────────────────────────────────────────────────────────────
 * **UNE SEULE VUE, PLUSIEURS MISES EN PAGE.**
 *
 * `/arbre-2`, `/arbre-3`… ne sont pas des écrans : ce sont **la même vue** avec
 * une disposition différente. Recopier `ArbreView` par variante aurait produit
 * cinq écrans qui divergeraient au premier correctif — et la comparaison n'aurait
 * plus rien dit, puisque deux choses auraient changé au lieu d'une.
 *
 * Chaque route porte donc `meta.disposition`, et la vue applique la
 * transformation à la charge reçue. Ce qui diffère entre deux URL, c'est
 * exactement une fonction pure.
 *
 * **`/arbre` n'en a aucune** — c'est la mise en page du moteur, telle quelle, et
 * c'est la référence contre laquelle on compare.
 * ────────────────────────────────────────────────────────────────────
 */

import type { Graphe } from '@/lib/api'
import { conjointsAccoles } from './conjointsAccoles'
import type { Disposition, NomDeDisposition } from './socle'

export * from './socle'
export { conjointsAccoles }

/** Ce que l'écran dit de la variante qu'on regarde. */
export interface PresentationDeDisposition {
  titre: string
  /** Une phrase : ce qu'elle fait, et ce qu'elle ne peut pas faire. */
  resume: string
  appliquer: Disposition
}

/**
 * **Le bandeau n'est pas décoratif.** Sans lui, deux onglets ouverts sur deux
 * variantes sont indiscernables dès qu'on a fait défiler l'arbre — et l'on
 * compare alors deux images dont on ne sait plus laquelle est laquelle.
 */
export const PRESENTATIONS: Record<NomDeDisposition, PresentationDeDisposition> = {
  conjoints: {
    titre: 'Conjoints accolés',
    resume:
      'Les conjoints rapportés — ceux qui n’ont aucun parent dans l’arbre — rejoignent leur ' +
      'partenaire. Les couples dont les deux époux sont enfants d’une famille de l’arbre ne ' +
      'bougent pas : les déplacer transférerait le trait long vers leur filiation.',
    appliquer: conjointsAccoles,
  },
  bornee: {
    titre: 'Vue bornée',
    resume: 'En construction.',
    appliquer: (graphe: Graphe) => graphe,
  },
  croisements: {
    titre: 'Minimisation de croisements',
    resume: 'En construction.',
    appliquer: (graphe: Graphe) => graphe,
  },
  duplication: {
    titre: 'Duplication',
    resume: 'En construction.',
    appliquer: (graphe: Graphe) => graphe,
  },
}

/**
 * La disposition d'une route, ou l'identité.
 *
 * L'identité et non `null` : l'appelant n'a alors aucun cas particulier à écrire,
 * et `/arbre` emprunte exactement le même chemin que les variantes — ce qui
 * garantit qu'un défaut de ce chemin se voit sur la route principale aussi, au
 * lieu de ne se manifester que sur les maquettes.
 */
export function dispositionDeLaRoute(nom: unknown): Disposition {
  return estUnNomDeDisposition(nom) ? PRESENTATIONS[nom].appliquer : (graphe: Graphe) => graphe
}

export function presentationDeLaRoute(nom: unknown): PresentationDeDisposition | null {
  return estUnNomDeDisposition(nom) ? PRESENTATIONS[nom] : null
}

function estUnNomDeDisposition(nom: unknown): nom is NomDeDisposition {
  return typeof nom === 'string' && nom in PRESENTATIONS
}
