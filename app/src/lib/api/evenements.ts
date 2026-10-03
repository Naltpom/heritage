/**
 * Module `Almanach` : la carte des lieux et les anniversaires (§ 19, § 20).
 *
 * ────────────────────────────────────────────────────────────────────
 * **Deux écrans, un seul flux.** Naissance, décès, mariage et divorce sont des
 * **colonnes** — sur `tree_person` et sur `tree_family` — et les autres faits
 * sont des lignes de `tree_fact`. Aucune énumération du modèle ne les réunit :
 * `FactType` les exclut explicitement, et son en-tête interdit de les y ajouter.
 * C'est le serveur qui compose le vocabulaire commun, et ces deux routes en sont
 * les deux projections.
 *
 * **Aucun paramètre, et un seul appel par écran.** Les filtres et la navigation
 * de mois en mois sont des **réglages de rendu** : ils ne changent pas ce que le
 * serveur calcule. À l'échelle de cet arbre — 274 personnes, ~500 événements
 * datés après le cercle, une trentaine de kilo-octets — une charge unique coûte
 * moins qu'un aller-retour par bascule, et rend le geste instantané. C'est la
 * différence avec la chronologie, dont `bande` et `branches` changent réellement
 * le calcul du serveur.
 *
 * Si la charge venait à grossir, la clé de demande s'écrirait ici, sur la forme
 * de `cleDeDemande()` dans `ChronologieView` — pour que personne ne la
 * réinvente.
 *
 * **Ce qui est absent l'est entièrement.** Une personne vivante hors du cercle
 * du lecteur ne produit aucun événement : ni bulle, ni jour, ni compteur. Elle
 * n'est pas réduite, elle est absente — la décision de la chronologie, reprise
 * mot pour mot. Un total « sur N » n'existe donc nulle part dans ces charges :
 * il dirait par soustraction combien de personnes le lecteur ne voit pas.
 * ────────────────────────────────────────────────────────────────────
 */
import { requeteApi, type OptionsRequete } from './client'

/** Le vocabulaire des pastilles — cinq genres, les mêmes sur les deux écrans. */
export type GenreEvenement = 'naissance' | 'deces' | 'union' | 'bapteme' | 'autre'

/** Le type précis. Les sept derniers reprennent les valeurs de `FactType`, les
 *  quatre premiers sont ceux que `FactType` s'interdit de porter. */
export type TypeEvenement =
  | 'naissance'
  | 'deces'
  | 'mariage'
  | 'divorce'
  | 'profession'
  | 'residence'
  | 'inhumation'
  | 'recensement'
  | 'bapteme_religieux'
  | 'bapteme'
  | 'autre'

export interface SujetEvenement {
  personId: number
  displayName: string
  sex: 'masculin' | 'feminin' | 'inconnu'
  vivant: boolean
}

/** Un lieu résolu en coordonnées par `tree_place`. */
export interface LieuCarte {
  placeId: number
  libelle: string
  lat: number
  lon: number
  /** `commune`, `departement` ou `pays` — jusqu'où le rapprochement est allé. */
  precision: 'commune' | 'departement' | 'pays'
  /** 0 à 100. Un rapprochement de commune seule vaut moins qu'un triplet. */
  confiance: number
  source: string
  /** Les graphies brutes qui ont abouti ici. C'est ce qui permet de repérer un
   *  mauvais géocodage : deux orthographes qui n'auraient pas dû se rejoindre. */
  brut: readonly string[]
}

export interface EvenementCarte {
  cle: string
  genre: GenreEvenement
  type: TypeEvenement
  /** `null` quand le lieu n'a pas pu être situé — l'événement est alors compté
   *  dans `resume.lieuNonResolu`, et jamais posé à une coordonnée inventée. */
  placeId: number | null
  /** Le `PLAC` verbatim, **toujours présent**, même sans `placeId` : c'est ce
   *  qui rend « 14 événements sans lieu situé » énumérable plutôt qu'un nombre
   *  nu. */
  place: string
  /** Jamais `null` dans cette charge : un événement sans année utilisable est
   *  omis et compté dans `resume.sansDate`. */
  annee: number
  /** La date en toutes lettres, telle que le serveur l'écrit. Le front ne
   *  recompose jamais une date (§ 14). */
  texte: string
  qualifier: string
  certain: boolean
  sujets: readonly SujetEvenement[]
  familyId: number | null
  /** La valeur du fait quand il en porte une — « Tisserand » pour une
   *  profession — ou, pour une union, les deux époux joints. **Composé après le
   *  filtre du cercle**, jamais avant : le composer en amont mettrait les deux
   *  noms en mémoire du côté non filtré du port. `null` quand il n'y a rien à
   *  dire de plus que le type. */
  libelle: string | null
}

export interface ResumeCarte {
  /** Compté **avant** tout filtre du client et **après** le cercle. Sans ces
   *  totaux, un filtre qui ne trouve légitimement rien est indistinguable d'un
   *  filtre dont la donnée n'est jamais arrivée. */
  parGenre: Readonly<Record<GenreEvenement, number>>
  /** L'événement n'a aucun lieu écrit — un trou dans la donnée. */
  sansLieu: number
  /** Le lieu est écrit mais n'a pas de coordonnées — un trou que la commande
   *  `heritage:resoudre-les-lieux` peut combler. Les deux sont comptés à part
   *  exprès : fusionnés, la couverture du gazetteer deviendrait invisible. */
  lieuNonResolu: number
  sansDate: number
  bornes: { min: number; max: number }
}

export interface VueCarte {
  lieux: readonly LieuCarte[]
  evenements: readonly EvenementCarte[]
  resume: ResumeCarte
}

export interface EvenementAnniversaire {
  cle: string
  genre: GenreEvenement
  type: TypeEvenement
  /**
   * **Absente** quand l'année n'est pas partagée (ADR 0016) : la naissance d'une
   * personne vivante dont le lecteur et elle ne partagent pas tous deux
   * l'année. Le jour et le mois placent l'événement sur la grille ; aucun âge
   * ne se calcule.
   */
  annee?: number
  /** La date en toutes lettres — partielle quand elle est réduite (« 12 mars »). */
  texte: string
  /** Le lieu brut, sans coordonnées : un calendrier n'en a pas besoin. */
  lieu: string | null
  sujets: readonly SujetEvenement[]
  familyId: number | null
  /** Idem : la valeur du fait, ou les deux époux joints. Voir `EvenementCarte`. */
  libelle: string | null
}

/** Un jour pourvu. La liste est **creuse** : seuls les jours qui portent
 *  quelque chose y figurent, et le client construit la grille. */
export interface JourAnniversaire {
  mois: number
  jour: number
  evenements: readonly EvenementAnniversaire[]
}

export interface ResumeAnniversaires {
  parGenre: Readonly<Record<GenreEvenement, number>>
  /** Ce qui n'a ni jour ni mois connus, par genre. Sans ce compte, un lecteur
   *  voit 31 naissances en septembre sans jamais apprendre qu'il en existe 166
   *  de plus dont personne n'a noté le jour. */
  sansJour: Readonly<Record<GenreEvenement, number>>
}

export interface VueAnniversaires {
  jours: readonly JourAnniversaire[]
  resume: ResumeAnniversaires
}

/** `exactOptionalPropertyTypes` interdit de poser `signal: undefined` sur une
 *  propriété déclarée `signal?: AbortSignal`. On construit donc l'objet — même
 *  motif que dans `chronologie.ts`. */
function options(signal?: AbortSignal): OptionsRequete {
  const o: OptionsRequete = {}
  if (signal !== undefined) o.signal = signal
  return o
}

/** `GET /api/carte`. Sans paramètre — voir l'en-tête. */
export function chargerCarte(signal?: AbortSignal): Promise<VueCarte> {
  return requeteApi<VueCarte>('/carte', options(signal))
}

/** `GET /api/anniversaires`. L'**année entière**, en une fois : naviguer de mois
 *  en mois ne redemande donc rien, ce qui est tout l'intérêt des deux flèches. */
export function chargerAnniversaires(signal?: AbortSignal): Promise<VueAnniversaires> {
  return requeteApi<VueAnniversaires>('/anniversaires', options(signal))
}
