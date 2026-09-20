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
import { blocsRigides } from './blocs'
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
      'croisements. Elle rapproche des couples que nul échange local ne peut rapprocher, mais ' +
      'elle en sépare d’autres : le compte exact est sous cette phrase, avant et après. ' +
      '(La version précédente annonçait « corrige les 36 couples séparés » ; mesuré le ' +
      '19/09/2026, c’était l’inverse — elle les créait.) Son prix est l’instabilité : ajouter ' +
      'une personne peut réorganiser une branche entière.',
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
      'une passe, sans itération interne ni amortissement. Deux règles d’ordre s’y ajoutent, ' +
      'et elles passent AVANT l’optimisation : **le conjoint accolé est celui de l’union non ' +
      'rompue** — un arbre qui colle l’ex-époux et relègue la compagne dit quelque chose de ' +
      'faux —, et **quand les deux époux viennent d’une fratrie d’au moins trois enfants, le ' +
      'couple cède : chacun reste entier au milieu des siens et le trait d’union s’allonge**. ' +
      'Cette seconde règle est l’INVERSE de celle du serveur, qui garde le couple et casse la ' +
      'plus petite fratrie : les deux sont ici pour être comparées. Elle ne s’applique QUE si ' +
      'les deux fratries sont déjà voisines dans la rangée — ailleurs, elle écarterait le ' +
      'couple d’un bout à l’autre au lieu de le décaler de quelques fiches. Et l’ajout d’une ' +
      'personne ne déplace rien d’autre qu’elle. **Les nombres sont sous cette phrase, ' +
      'recalculés à chaque affichage** : un chiffre écrit ici se périmerait au premier ' +
      'correctif, et c’est arrivé.',
    appliquer: ressortsIsotoniques,
  },
  blocs: {
    titre: 'Blocs rigides',
    resume:
      'La méthode de Reingold et Tilford : chaque sous-arbre est un bloc qui réserve sa largeur ' +
      'maximale sur tous ses étages, et les blocs frères sont posés côte à côte. L’ordre des ' +
      'enfants n’est pas corrigé, il est CONSTRUIT — deux branches ne peuvent pas se traverser, ' +
      'et un enfant tombe sous ses parents. Le prix est sous cette phrase, recalculé : la ' +
      'largeur double presque, et les croisements ne baissent pas. La cause en est mesurée le ' +
      '20/09/2026 : 71 des 79 croisements de l’arbre d’origine viennent des 61 unions où les ' +
      'DEUX époux sont enfants de l’arbre ; l’un des deux doit quitter sa fratrie et traîne un ' +
      'trait à travers tout. Aucune géométrie ne les supprime, et la duplication telle qu’elle ' +
      'est écrite non plus : elle ne duplique qu’un conjoint SANS parents dans l’arbre, donc ' +
      'aucune de ces 61 unions. Les supprimer demanderait de dupliquer aussi celui qui a des ' +
      'parents — mesuré possible, pas écrit.',
    appliquer: blocsRigides,
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
