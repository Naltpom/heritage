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
import { ascendanceSeule } from './ascendanceSeule'
import { conjointsAccoles } from './conjointsAccoles'
import { croisementsMinimises } from './croisements'
import { duplication } from './duplication'
import { ressortsIsotoniques } from './ressorts'
import { vueBornee } from './vueBornee'
import type { Disposition, NomDeDisposition, OptionsDeDisposition } from './socle'

export * from './socle'
export { ascendanceSeule }
export { pedigree } from './pedigree'
export { conjointsAccoles }
export { vueBornee }
export { croisementsMinimises, compterLesCroisements } from './croisements'
export { duplication, compterLesEchos, estUnEcho, originalDeLEcho } from './duplication'
export {
  ressortsIsotoniques,
  pava,
  placerParIsotonie,
  bornesDesLosanges,
  losangesHorsDeLeursEpoux,
  mouvements,
  sommeDesCarres,
} from './ressorts'

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
    resume:
      'Une fenêtre autour de vous, comme chez MyHeritage ou webtrees : quelques générations ' +
      'au-dessus, quelques-unes en dessous, et les conjoints de tout le monde. Elle ne déplace ' +
      'rien — ce qui reste est exactement où l’arbre d’origine le met. La profondeur se règle ' +
      'dans l’adresse : ?haut=3&bas=2.',
    appliquer: vueBornee,
  },
  croisements: {
    titre: 'Minimisation de croisements',
    resume:
      'La méthode de GraphViz : chaque rangée se retrie par barycentre de ses voisins, on ' +
      'balaie de haut en bas puis de bas en haut, et on garde le meilleur ordre — mesuré en ' +
      'croisements. Elle corrige les 36 couples séparés, y compris ceux que nul échange local ' +
      'ne peut rapprocher. Son prix est l’instabilité : ajouter une personne peut réorganiser ' +
      'une branche entière.',
    appliquer: croisementsMinimises,
  },
  duplication: {
    titre: 'Duplication',
    resume:
      'La méthode des éventails d’ascendance : plutôt que de choisir entre la place d’enfant ' +
      'et la place de conjoint, on dessine la personne deux fois — une fois pleine, une fois en ' +
      'écho tireté à côté de son partenaire. Le trait long disparaît, remplaçé par un doublon ' +
      'assumé. L’écho porte le même identifiant de personne : cliquer dessus mène à la même fiche.',
    appliquer: duplication,
  },
  ressorts: {
    titre: 'Ressorts et isotonie',
    resume:
      'Chaque nœud est tiré vers la moyenne de ses voisins, et chaque rangée est résolue ' +
      '**exactement** sous la contrainte de non-chevauchement — la régression isotonique, en ' +
      'une passe, sans itération interne ni amortissement. Elle ne réordonne rien : elle garde ' +
      'l’ordre du serveur, donc les couples accolés et la règle du remariage restent tels quels, ' +
      'et le nombre de croisements ne bouge pas. Ce qu’elle gagne est la longueur des traits : ' +
      'la somme de leurs carrés passe de 119,8 à 109,8 millions sur l’arbre réel (mesuré le ' +
      '20/09/2026, 644 nœuds), et l’ajout d’une personne ne déplace rien d’autre qu’elle. Deux ' +
      'prix, dits ici plutôt que découverts à l’écran : le trait le PLUS long s’allonge de 9 % ' +
      '(3 185 → 3 486 px), et comme l’arbre d’origine elle laisse une soixantaine de losanges ' +
      'hors de l’intervalle de leurs époux — 64 contre 63.',
    appliquer: ressortsIsotoniques,
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
  return estUnNomDeDisposition(nom)
    ? PRESENTATIONS[nom].appliquer
    : (graphe: Graphe, _options: OptionsDeDisposition) => graphe
}

export function presentationDeLaRoute(nom: unknown): PresentationDeDisposition | null {
  return estUnNomDeDisposition(nom) ? PRESENTATIONS[nom] : null
}

function estUnNomDeDisposition(nom: unknown): nom is NomDeDisposition {
  return typeof nom === 'string' && nom in PRESENTATIONS
}
