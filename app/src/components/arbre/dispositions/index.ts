/**
 * Le registre des dispositions, et ce que la disposition servie annonce à l'écran.
 *
 * ────────────────────────────────────────────────────────────────────
 * **UNE SEULE VUE, UNE SEULE MISE EN PAGE — DÉSORMAIS.**
 *
 * `/arbre-2` à `/arbre-8` étaient la même vue avec une disposition différente :
 * des maquettes de comparaison, proposées au commanditaire pour qu'il choisisse.
 * Le choix est fait, et les routes sont parties. **`/arbre` sert maintenant
 * `ressorts`** ; auparavant il ne portait aucune disposition, c'est-à-dire la
 * sortie brute du moteur — la seule mise en page que personne n'avait retenue, et
 * pourtant la seule que les membres voyaient.
 *
 * ────────────────────────────────────────────────────────────────────
 * **⚠ UN MODULE SURVIT À SA PAGE. NE PAS LE SUPPRIMER PARCE QUE SA ROUTE EST MORTE.**
 *
 * Ce fichier réexporte des **fonctions de mesure** qui ne servent à aucune page :
 * elles servent aux tests et aux relevés. Supprimer un module dont une fonction
 * est encore importée fait rougir `vue-tsc` — c'est le cas facile. Supprimer un
 * module dont **seules les mesures** dépendent ne casse rien du tout et fait
 * disparaître un instrument en silence. C'est celui-là qu'il faut chercher.
 *
 * Restent donc ici, sans route :
 *
 * | module | ce qui l'importe encore |
 * |---|---|
 * | `blocs` | `duplication` (`absorbes`, `topologie`) et deux chantiers de mesure |
 * | `croisements` | `ArbreView` (`compterLesCroisements`) et `ressorts.test` |
 * | `duplication` | ses mesures (`compterLesEchos`, `estUnEcho`, `originalDeLEcho`) |
 * | `pedigree` | `ArbreView` |
 * | `ascendanceSeule` | `ArbreView`, sous le nom `reduireALAscendance` |
 *
 * Leurs fichiers de test tiennent ces mesures : **les retirer serait retirer
 * l'instrument**, et aucune porte ne le dirait.
 * ────────────────────────────────────────────────────────────────────
 */

import type { Graphe } from '@/lib/api'
import { ascendanceSeule } from './ascendanceSeule'
import { ressortsIsotoniques } from './ressorts'
import type { Disposition, NomDeDisposition, OptionsDeDisposition } from './socle'

export * from './socle'
export { ascendanceSeule }
export { pedigree } from './pedigree'
export { croisementsMinimises, compterLesCroisements } from './croisements'
export { duplication, compterLesEchos, estUnEcho, originalDeLEcho } from './duplication'
export {
  blocsRigides,
  abscissesDesBlocs,
  topologie,
  absorbes,
  groupes,
  blocsEnfants,
  largeurPropre,
  largeurDuBloc,
  racines,
  degagerLesRangees,
  personnesNonPlacees,
  unionsInterBlocs,
  traitLePlusLong,
} from './blocs'
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
  ressorts: {
    titre: 'Ressorts et isotonie',
    /*
     * **Ce texte est lu par la famille, plus par un relecteur de maquettes.** Tant qu'il
     * s'affichait sur `/arbre-7`, son public venait comparer sept mises en page ; il
     * pouvait parler de régression isotonique et de somme des carrés. Il s'affiche
     * maintenant sur l'écran d'accueil de tous les membres : il dit ce que la mise en
     * page fait, en français ordinaire. Les nombres restent — ce sont eux la mesure —,
     * c'est le vocabulaire qui a changé.
     */
    resume:
      'Chaque personne est rapprochée de ses proches, et les fiches d’une même rangée ne ' +
      'se chevauchent jamais. Deux règles passent avant tout le reste : **un couple est ' +
      'dessiné côte à côte quand son mariage n’a pas été rompu** — coller l’ex-époux et ' +
      'reléguer la compagne dirait quelque chose de faux —, et **quand les deux époux ont ' +
      'chacun des frères et sœurs dans l’arbre, chacun reste au milieu des siens** : le ' +
      'trait qui les relie s’allonge plutôt que de couper une fratrie en deux. Ajouter ' +
      'une personne ne déplace qu’elle, et rien d’autre autour. Les nombres ci-dessous ' +
      'sont recalculés à chaque affichage.',
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
