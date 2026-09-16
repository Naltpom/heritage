/**
 * Les appels d'API du module `Timeline` — § 9 du contrat.
 *
 * Trois routes, et une séparation qui porte tout l'écran :
 *
 * - `GET /api/chronologie` **lit** avec des paramètres facultatifs ; sans eux,
 *   la réponse applique la préférence enregistrée du compte ;
 * - `PUT /api/chronologie/preferences` **écrit**, et lui seul.
 *
 * « Un paramètre fourni n'écrit pas la préférence — c'est `PUT` qui écrit. Sans
 * cette séparation, chaque exploration d'un réglage devient une écriture, et la
 * préférence ne veut plus rien dire » (§ 9.1). D'où deux fonctions distinctes
 * ici, et aucun appel à `enregistrerPreferences()` depuis un mouvement de
 * curseur : l'écran ne l'appelle que sur un geste explicite.
 *
 * ────────────────────────────────────────────────────────────────────
 * **Pourquoi `BarreChronologie` est une union discriminée sur `ouverte`.**
 * Une personne vivante a `fin: null` et `ouverte: true` : c'est le **rendu** qui
 * arrête sa barre à l'année courante, « l'API n'invente pas une date de fin »
 * (§ 9.1). Si le type déclarait `fin: IntervalleAnnees | null`, on pourrait
 * écrire `barre.fin.max` et ne s'en apercevoir qu'au premier vivant affiché —
 * c'est-à-dire chez le commanditaire. Avec l'union, `barre.fin` n'est lisible
 * qu'après avoir discriminé sur `ouverte`, exactement comme `CercleDeSession`
 * rend `cercle.taille === 0` non compilable (§ 12).
 *
 * **`debut` et `fin` sont des intervalles d'années, jamais des points.** Il n'y
 * a ici aucune clé qui rende un point : « un appelant qui veut un point pour
 * dessiner le centre d'une barre l'écrit lui-même, à partir de `min` et `max` »
 * (§ 14.3, piège n° 4). C'est ce qui permet la hachure des extrémités
 * incertaines, exigée au § 7.4 du dossier de mission.
 *
 * **Les personnes vivantes hors cercle sont absentes de `barres`** (§ 9.1) :
 * une barre *est* faite de dates, et une personne vivante hors cercle n'en
 * expose aucune. Conséquence assumée : un membre voit moins de barres que de
 * nœuds dans l'arbre. Le front ne corrige pas cela et n'affiche aucun décompte
 * qui trahirait le nombre de barres manquantes.
 * ────────────────────────────────────────────────────────────────────
 */

import { requeteApi, type OptionsRequete } from './client'
import type { Branche, Sexe } from './types'

/** § 9.1 — sens de lecture. `lignes` : une **ligne** par tranche de N années,
 *  donc un axe du temps **vertical** (le plus ancien en haut), conforme au
 *  § 7.4 du dossier. `colonnes` : une colonne par tranche, axe horizontal. */
export type OrientationChronologie = 'lignes' | 'colonnes'

/**
 * Un intervalle d'**années** (des entiers, pas des `YYYY-MM-DD`).
 *
 * `certain` vaut `qualifier === 'exacte'` côté serveur. Il ne se déduit **pas**
 * de `min === max` : `1930` seul donne déjà `1930-01-01` / `1930-12-31`, donc
 * `min === max` en années et pourtant une date au jour près inconnue — « un
 * client qui déduirait “min ≠ max donc fourchette” classerait les trois quarts
 * de l'arbre en incertain » (§ 14.3, piège n° 1). On lit `certain`, rien d'autre.
 */
export interface IntervalleAnnees {
  /**
   * Borne basse **inclusive**, ou `null` : **non bornée à gauche**.
   *
   * C'est la forme d'un `BEF` (§ 14.1 : « `null` = non bornée à gauche »).
   * Six barres de la charge réelle la portent — « Silice-Opaline Glycine,
   * † <1899 » : `{ min: null, max: 1898 }`.
   *
   * Le déclarer `number` coûtait cher et en silence : `(null - 1655) * 8` ne
   * lève rien en JavaScript, `null` se coerce en `0`, et la barre partait
   * **1 644 ans avant l'origine de l'axe**. Elle traversait tout le canevas,
   * mangeait une piste entière et n'avait l'air de rien d'autre qu'un dessin.
   * Aucune exception, aucun test rouge — le pire des deux mondes.
   */
  min: number | null
  /** Borne haute inclusive, ou `null` : non bornée à droite (`AFT`, `FROM`). */
  max: number | null
  /** `qualifier === 'exacte'`. Ne se déduit **pas** de `min === max` (§ 14.3). */
  certain: boolean
}

/**
 * Une bande de N années, **calculée par le serveur** à partir de `bande` et de
 * `bornes` (§ 9.1) : `debut(k) = bornes.min + k × bande`,
 * `fin(k) = debut(k) + bande − 1`. Le front ne les recalcule pas — deux calculs
 * du même découpage divergeraient un jour, et ce jour-là personne ne saurait
 * lequel a raison. La dernière bande est partielle et sa `fin` dépasse
 * `bornes.max` : c'est correct et voulu.
 */
export interface BandeChronologie {
  debut: number
  fin: number
  /** Déjà rédigé (« 1657–1681 ») : on l'affiche, on ne le recompose pas. */
  libelle: string
}

interface BarreCommune {
  personId: number
  displayName: string
  branch: Branche
  generation: number
  sex: Sexe
  vivant: boolean
  /**
   * Naissance, en intervalle d'années. Jamais un point — et **`null` quand
   * aucune borne de naissance n'est connue**.
   *
   * Le § 9.1 n'écrit pas ce cas, mais la charge réelle le porte : 11 barres sur
   * 235 n'ont qu'une date de décès (« Béryl Lavande, † 1995 »). Déclarer
   * `debut` non nullable ferait planter le rendu sur ces onze-là, et seulement
   * sur elles — c'est-à-dire pas avant la production.
   */
  debut: IntervalleAnnees | null
  /** La ligne de dates déjà rendue, **forme compacte** du § 14.4
   *  (« 1901-1909 – 1987 », « n. 26.9.2012 »). On l'affiche telle quelle. */
  ligneDates: string
}

/**
 * Une personne vivante : `fin` est `null`, et c'est le **rendu** qui arrête la
 * barre à l'année courante. L'API n'invente aucune date de fin (§ 9.1).
 */
export interface BarreOuverte extends BarreCommune {
  ouverte: true
  fin: null
}

/** Une personne décédée dont on connaît l'année du décès. */
export interface BarreDatee extends BarreCommune {
  ouverte: false
  fin: IntervalleAnnees
}

/**
 * Une personne **décédée dont on ignore la date du décès**.
 *
 * Ce troisième cas n'est écrit nulle part dans le § 9.1, qui pose l'équivalence
 * `fin: null` ⟺ `ouverte: true`. La charge réelle la contredit : 53 barres sur
 * 235 portent `fin: null` **avec** `ouverte: false` — ce sont les personnes
 * sans `DEAT` daté du § 4 du dossier (« 103 = personnes dont on ignore la date
 * du décès »). `ouverte` y dit « vivante », et `fin: null` dit « fin inconnue » :
 * deux choses différentes, que le contrat confond.
 *
 * Le type les sépare plutôt que de les aplatir. Conséquence voulue :
 * `barre.fin.max` ne compile **toujours** pas après un simple
 * `if (!barre.ouverte)` — il faut aussi écarter `fin === null`. C'est ce qui
 * évite le `TypeError` que la charge réelle produirait sur cinquante-trois
 * barres.
 */
export interface BarreSansFin extends BarreCommune {
  ouverte: false
  fin: null
}

export type BarreChronologie = BarreOuverte | BarreDatee | BarreSansFin

/** Un `tree_fact` daté (recensement, résidence) d'une personne visible. */
export interface JalonChronologie {
  /** Valeur du modèle (`recensement`, `residence`…), jamais traduite. */
  type: string
  annee: number
  libelle: string
  personId: number
}

/** Les bornes de l'arbre visible. Ne sert **pas** à construire l'axe — l'axe se
 *  lit sur `bandes`, que le serveur a déjà calculées. */
export interface BornesChronologie {
  min: number
  max: number
}

/** La réponse de `GET /api/chronologie` (§ 9.1). Les quatre premiers champs
 *  sont l'écho des réglages effectivement appliqués. */
export interface VueChronologie {
  bande: number
  orientation: OrientationChronologie
  branches: Branche[]
  /**
   * `true` par défaut, « parce que masquer l'incertitude est précisément ce que
   * le § 4 du dossier interdit » (§ 9.2). Le front peut l'éteindre sur geste du
   * lecteur ; il ne l'éteint **jamais** de lui-même au premier rendu.
   */
  showUncertainty: boolean
  bornes: BornesChronologie
  bandes: BandeChronologie[]
  barres: BarreChronologie[]
  jalons: JalonChronologie[]
}

/** § 9.2 — la préférence enregistrée. Quatre champs, tous obligatoires : le
 *  `PUT` est un **remplacement complet**, pas un `PATCH`, « aucune ambiguïté
 *  sur ce que “absent” veut dire ». */
export interface PreferenceChronologie {
  /** 1 à 500 (`ck_tml_preference_bande`). Hors bornes ⇒ `422`. */
  bandYears: number
  orientation: OrientationChronologie
  branches: Branche[]
  showUncertainty: boolean
}

/** Les trois paramètres de `GET /api/chronologie`, tous facultatifs. Omis, la
 *  préférence enregistrée du compte s'applique. */
export interface ParametresChronologie {
  bande?: number
  orientation?: OrientationChronologie
  branches?: readonly Branche[]
}

/** `exactOptionalPropertyTypes` interdit de poser `signal: undefined` sur une
 *  propriété déclarée `signal?: AbortSignal`. On construit donc l'objet — même
 *  motif que dans `arbre.ts`. */
function options(signal?: AbortSignal): OptionsRequete {
  const o: OptionsRequete = {}
  if (signal !== undefined) o.signal = signal
  return o
}

/**
 * `GET /api/chronologie?bande=&orientation=&branches=`.
 *
 * **Lecture pure.** Aucun des trois paramètres n'écrit la préférence ; explorer
 * un réglage ne laisse aucune trace côté serveur (§ 9.1).
 *
 * `bande` hors de 1–500 rend `422 donnees_invalides` : la fonction **ne borne
 * pas** la valeur avant de l'envoyer. C'est délibéré — le serveur est l'arbitre
 * de sa propre contrainte, et un front qui écrête en silence ferait diverger ce
 * qu'on demande de ce qu'on croit avoir demandé.
 */
export function chargerChronologie(
  parametres: ParametresChronologie = {},
  signal?: AbortSignal,
): Promise<VueChronologie> {
  const o = options(signal)
  o.requete = {
    bande: parametres.bande,
    orientation: parametres.orientation,
    // Liste séparée par des virgules (§ 9.1). Une liste vide n'est pas envoyée :
    // « absent » veut dire « la préférence décide », et c'est le seul sens que
    // le contrat donne à l'absence de ce paramètre.
    branches:
      parametres.branches !== undefined && parametres.branches.length > 0
        ? parametres.branches.join(',')
        : undefined,
  }
  return requeteApi<VueChronologie>('/chronologie', o)
}

/** `GET /api/chronologie/preferences`. */
export function lirePreferencesChronologie(
  signal?: AbortSignal,
): Promise<PreferenceChronologie> {
  return requeteApi<PreferenceChronologie>('/chronologie/preferences', options(signal))
}

/**
 * `PUT /api/chronologie/preferences` — **le seul appel qui écrit**.
 *
 * Remplacement complet : les quatre champs partent ensemble, toujours. Un
 * `PATCH` laisserait croire qu'un champ omis « ne change pas », alors que rien
 * dans le contrat ne le dit (§ 9.2).
 */
export function enregistrerPreferencesChronologie(
  preference: PreferenceChronologie,
  signal?: AbortSignal,
): Promise<PreferenceChronologie> {
  const o = options(signal)
  o.methode = 'PUT'
  o.corps = preference
  return requeteApi<PreferenceChronologie>('/chronologie/preferences', o)
}
