/**
 * **La surface éditable d'une personne** — la liste blanche du § 6.2, et rien
 * d'autre.
 *
 * Le contrat la donne au caractère près : `givenNames`, `surname`,
 * `marriedName`, `nickname`, `sex`, `deceased`, `birth`, `death`,
 * `forcePrivacy`, `privacyNote`. Et il donne aussi la liste de ce qui n'y est
 * **pas** : « ni `branch`, ni `generation`, ni `bestQuay`, ni `citationCount`,
 * ni `displayName`, ni `searchKey`, ni `version` : ce sont des colonnes
 * dérivées, écrites par un service de `Tree`. Une demande qui les vise est un
 * malentendu, pas une contribution. »
 *
 * Trois raisons de poser cette liste ici plutôt que dans le gabarit du
 * formulaire :
 *
 * 1. **Le serveur borne `field` par une table de correspondance explicite**
 *    (`field → callable`, § 9.4), jamais par réflexion. Un `field` hors table
 *    rend `422` et la demande n'est pas créée. Une liste côté front qui
 *    divergerait fabriquerait des demandes mort-nées ; une liste au même endroit
 *    que le constructeur d'ops se relit en une fois.
 * 2. **`valueKind` se déduit du champ, jamais de la valeur.** Le modèle en fait
 *    un discriminant : « `JSONB` seul ne dit pas ce qu'il contient » (§ 5.7). Un
 *    `deceased` à `false` et un `privacyNote` vide sont tous deux « faux » en
 *    JavaScript et n'ont pourtant ni le même `value_kind` ni le même contrôle.
 * 3. **Un bloc date est un seul op, jamais cinq** (§ 6.2, § 14.3). `birth` et
 *    `death` figurent donc ici comme **des champs**, au nom du bloc. `birthMin`
 *    n'existe pas et ne doit pas pouvoir s'écrire — c'est pour cela que le type
 *    `ChampPersonne` est une union fermée de littéraux et non `string`.
 *
 * ────────────────────────────────────────────────────────────────────
 * **Une table indexée par l'union, jamais une liste recopiée à côté d'elle.**
 *
 * `DESCRIPTIONS` est typée `Record<ChampPersonne, …>` : le compilateur **exige**
 * une entrée par champ. Ajouter une valeur à l'union sans la décrire ne compile
 * pas, et l'ordre de la table **est** l'ordre du formulaire — une seule
 * structure porte donc les deux garanties, au lieu d'une liste parallèle que
 * rien n'oblige à rester synchrone.
 *
 * C'est le motif qui a remplacé un `readonly string[]` de sept qualificatifs
 * dans `reprise.ts`, où il en manquait trois sans que rien ne le signale. Une
 * liste recopiée ne prévient jamais qu'elle a vieilli.
 * ────────────────────────────────────────────────────────────────────
 */

import type { Sexe } from '@/lib/api'

/** Les dix champs de la surface éditable. Union fermée : `'birthMin'` ne
 *  compile pas, et c'est tout l'intérêt. */
export type ChampPersonne =
  | 'givenNames'
  | 'surname'
  | 'marriedName'
  | 'nickname'
  | 'sex'
  | 'deceased'
  | 'birth'
  | 'death'
  | 'forcePrivacy'
  | 'privacyNote'

/** § 5.7 du modèle — le discriminant qui dit comment lire `oldValue` /
 *  `newValue`. Seules les quatre formes que la surface éditable produit. */
export type NatureValeur = 'texte' | 'booleen' | 'enum' | 'date_incertaine'

export interface DescriptionChamp {
  champ: ChampPersonne
  /** Le libellé français du formulaire et du récapitulatif. */
  etiquette: string
  valueKind: NatureValeur
  /** Une phrase d'aide, quand le champ se comprend mal seul. */
  aide?: string
}

/**
 * Chaque champ, décrit une fois.
 *
 * L'ordre des clés est celui du formulaire, et il n'est pas alphabétique : on
 * lit une fiche d'état civil dans cet ordre-là, et un formulaire qui suit
 * l'ordre de lecture se remplit sans chercher. Il devient aussi l'ordre des
 * `seq` des ops, que le modèle veut stable — « les ops se rejouent dans cet
 * ordre, toujours ».
 */
const DESCRIPTIONS = {
  givenNames: { champ: 'givenNames', etiquette: 'Prénoms', valueKind: 'texte' },
  surname: { champ: 'surname', etiquette: 'Nom', valueKind: 'texte' },
  marriedName: {
    champ: 'marriedName',
    etiquette: 'Nom d’usage',
    valueKind: 'texte',
    aide: 'Le nom porté après mariage, s’il diffère du nom de naissance.',
  },
  nickname: { champ: 'nickname', etiquette: 'Surnom', valueKind: 'texte' },
  sex: { champ: 'sex', etiquette: 'Sexe', valueKind: 'enum' },
  deceased: {
    champ: 'deceased',
    etiquette: 'Décès attesté',
    valueKind: 'booleen',
    // `deceased` est le `DEAT` du GEDCOM — **stocké**. `vivant` est **calculé**
    // par le serveur (seuil, repli par génération, `force_privacy`) et n'est pas
    // éditable : le confondre avec celui-ci ferait proposer la modification
    // d'une colonne dérivée, c'est-à-dire un `422`.
    aide: 'Coché quand le décès est attesté, même si sa date est inconnue.',
  },
  birth: { champ: 'birth', etiquette: 'Naissance', valueKind: 'date_incertaine' },
  death: { champ: 'death', etiquette: 'Décès', valueKind: 'date_incertaine' },
  forcePrivacy: {
    champ: 'forcePrivacy',
    etiquette: 'Fiche à protéger',
    valueKind: 'booleen',
    aide: 'Force le masquage de la fiche, quelle que soit la règle automatique.',
  },
  privacyNote: { champ: 'privacyNote', etiquette: 'Motif de la protection', valueKind: 'texte' },
  // `satisfies` et non une annotation : il impose une entrée par champ de
  // l'union **et** conserve les types littéraux, dont `valueKind`. C'est ce qui
  // permet de déduire `ChampDate` de cette table plutôt que de la réécrire
  // à côté — la seconde liste serait celle qui vieillit.
} as const satisfies Record<ChampPersonne, DescriptionChamp>

/**
 * La surface éditable, dans l'ordre du formulaire.
 *
 * Dérivée de `DESCRIPTIONS` plutôt que réécrite : `Object.values` suit l'ordre
 * d'insertion des clés de chaîne, garanti par le langage. Il n'existe donc
 * aucune seconde liste susceptible d'oublier un champ.
 */
export const CHAMPS_PERSONNE: readonly DescriptionChamp[] = Object.values(DESCRIPTIONS)

/** Le champ appartient-il à la surface éditable ? Écrit en garde de type :
 *  les `field` qui arrivent du serveur sont des chaînes quelconques. */
export function estChampPersonne(champ: string): champ is ChampPersonne {
  return Object.hasOwn(DESCRIPTIONS, champ)
}

/**
 * La description d'un champ, y compris quand il n'en a pas.
 *
 * Le repli n'est pas de la superstition : cette fonction reçoit des `field`
 * venus du serveur — d'un diff, d'un détail de caducité — et le contrat peut en
 * ajouter avant que ce fichier ne le sache. Un champ inconnu s'affiche alors
 * sous son nom brut, ce qui reste lisible ; le faire planter effacerait de
 * l'écran la seule information que l'auteur attendait.
 */
export function descriptionDeChamp(champ: string): DescriptionChamp {
  return estChampPersonne(champ)
    ? DESCRIPTIONS[champ]
    : { champ: champ as ChampPersonne, etiquette: champ, valueKind: 'texte' }
}

export function etiquetteDeChamp(champ: string): string {
  return descriptionDeChamp(champ).etiquette
}

/**
 * Les champs qui portent un **bloc date**, déduits de `valueKind`.
 *
 * Le type et le test à l'exécution sortent de la **même** table : un troisième
 * bloc date ajouté à `DESCRIPTIONS` entre ici sans qu'on y pense, et surtout
 * sans qu'on puisse l'y oublier. Écrire `'birth' | 'death'` à la main aurait
 * rendu `estChampDate` **faussement rassurant** le jour venu — il aurait rétréci
 * vers un type qui ne contient pas la valeur rendue, sans erreur nulle part.
 */
export type ChampDate = {
  [K in ChampPersonne]: (typeof DESCRIPTIONS)[K]['valueKind'] extends 'date_incertaine' ? K : never
}[ChampPersonne]

export function estChampDate(champ: ChampPersonne): champ is ChampDate {
  return DESCRIPTIONS[champ].valueKind === 'date_incertaine'
}

/** Les mêmes, en liste, pour les parcours. */
export const CHAMPS_DATE: readonly ChampDate[] = CHAMPS_PERSONNE.filter((description) =>
  estChampDate(description.champ),
).map((description) => description.champ as ChampDate)

/**
 * Les libellés des valeurs de `sex`.
 *
 * **La valeur reste celle du modèle** (`masculin`, `feminin`, `inconnu`) : c'est
 * elle qui part dans `newValue`, jamais le libellé (§ 1.2). La table est indexée
 * par l'union `Sexe` pour la même raison que `DESCRIPTIONS` l'est par
 * `ChampPersonne` : une valeur de plus au contrat doit faire échouer la
 * compilation, pas s'afficher en blanc.
 */
export const LIBELLES_SEXE: Record<Sexe, string> = {
  masculin: 'Masculin',
  feminin: 'Féminin',
  inconnu: 'Inconnu',
}
