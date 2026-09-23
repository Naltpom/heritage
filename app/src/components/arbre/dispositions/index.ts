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
    resume:
      'Chaque nœud est tiré vers la moyenne de ses voisins, et chaque rangée est résolue ' +
      '**exactement** sous la contrainte de non-chevauchement — la régression isotonique, en ' +
      'une passe, sans itération interne ni amortissement. Deux règles d’ordre s’y ajoutent, ' +
      'et elles passent AVANT l’optimisation : **le conjoint accolé est celui de l’union non ' +
      'rompue** — un arbre qui colle l’ex-époux et relègue la compagne dit quelque chose de ' +
      'faux —, et **quand les deux époux viennent d’une fratrie d’au moins trois enfants, le ' +
      'couple cède : chacun reste entier au milieu des siens et le trait d’union s’allonge**. ' +
      'Cette seconde règle est l’INVERSE de celle du serveur, qui garde le couple et casse la ' +
      'plus petite fratrie : les deux sont ici pour être comparées. Et l’ajout d’une personne ' +
      'ne déplace rien d’autre qu’elle. **Les nombres sont sous cette phrase, recalculés à ' +
      'chaque affichage** : un chiffre écrit ici se périmerait au premier correctif, et c’est ' +
      'arrivé.',
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
