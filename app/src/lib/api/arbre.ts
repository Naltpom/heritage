/**
 * Les appels d'API du module `Tree` — fiche d'une personne, fiche d'une
 * famille, recherche, et détail d'un nœud « en attente ».
 *
 * `routes.ts` (socle) ne porte que `GET /api/arbre/graphe`, parce que c'est la
 * seule route de l'arbre dont la fondation avait besoin. Les trois autres sont
 * ici, suivant la convention du `README.md` : « une fonction par route, dans
 * `src/lib/api/<module>.ts` ».
 *
 * ────────────────────────────────────────────────────────────────────
 * **Pourquoi les types de fiche sont des unions discriminées sur
 * `visibilite`.** Le § 1.3 du contrat oppose *absent* et `null` : un champ que
 * le lecteur n'a pas le droit de voir n'est **pas envoyé**, un champ qu'il a le
 * droit de voir mais qui n'est pas renseigné est présent à `null`. Une fiche de
 * personne vivante hors du cercle du lecteur porte **sept clés, pas une de
 * plus** (§ 11.1). Déclarer `birth?: BlocDate | null` laisserait écrire
 * `fiche.birth ?? '—'` et afficher un tiret là où la vérité est « tu n'as pas
 * le droit de savoir ». Avec l'union, `fiche.birth` ne compile pas tant qu'on
 * n'a pas discriminé sur `visibilite` : l'écran est **obligé** de traiter la
 * fiche partielle comme un cas à part entière, ce qu'elle est.
 *
 * **Le front ne filtre rien.** Le cercle de visibilité est appliqué côté
 * serveur, dans la sérialisation (§ 11, § 6.2 du dossier). Ces types décrivent
 * ce qui arrive ; ils ne cachent rien qui serait arrivé quand même.
 * ────────────────────────────────────────────────────────────────────
 */

import { requeteApi, type OptionsRequete } from './client'
import type { BlocDate, BlocDateOp, Branche, Calque, PorteeExplorer, Sexe, Visibilite } from './types'

/* ------------------------------------------------------------------ */
/* § 5.3 — la fiche d'une personne                                     */
/* ------------------------------------------------------------------ */

/** Un parent, tel que la fiche le dénormalise. `relation` vaut `naissance`,
 *  `adoption`… — valeur du modèle, jamais traduite. */
export interface ParentDeFiche {
  personId: number
  displayName: string
  relation: string
  familyId: number
  /** Date de naissance compacte (« 1816 », « 5.10.1816 ») — **absente** hors cercle,
   *  `null` quand elle n'est pas connue. */
  naissance?: string | null
  /** Date de décès compacte — mêmes règles que `naissance`. */
  deces?: string | null
}

export interface FrereOuSoeurDeFiche {
  personId: number
  displayName: string
  siblingOrder: number
  /** Date de naissance compacte (« 1816 », « 5.10.1816 ») — **absente** hors cercle,
   *  `null` quand elle n'est pas connue. */
  naissance?: string | null
  /** Date de décès compacte — mêmes règles que `naissance`. */
  deces?: string | null
}

/** Référence minimale d'une personne dans une union (conjoint, enfant). */
export interface ReferencePersonne {
  personId: number
  displayName: string
  /** Date de naissance compacte (« 1816 », « 5.10.1816 ») — **absente** hors cercle,
   *  `null` quand elle n'est pas connue. */
  naissance?: string | null
  /** Date de décès compacte — mêmes règles que `naissance`. */
  deces?: string | null
}

export interface UnionDeFiche {
  familyId: number
  /** `null` quand le conjoint n'est pas connu — une union sans conjoint nommé
   *  reste une union, et la fratrie en dépend (§ 5.4). */
  conjoint: ReferencePersonne | null
  marriage: BlocDate | null
  /**
   * La nature de l'union (ADR 0020) : `true` mariés — daté ou non —, `false`
   * union libre, `null` on ne sait pas. Une date ou un lieu de mariage la font
   * toujours `true`. Voir `components/personne/union.ts`.
   */
  married: boolean | null
  /** Toute rupture : « divorcés » quand `married` est vrai, « séparés » sinon. */
  divorced: boolean
  divorce: BlocDate | null
  /** `version` de la famille — le `baseVersion` d'une `modification_famille`. */
  version: number
  enfants: ReferencePersonne[]
}

export interface FaitDeFiche {
  id: number
  /** `residence`, `profession`… — valeur du modèle. */
  type: string
  label: string | null
  happened: BlocDate | null
  address: string | null
  age: string | null
  cause: string | null
}

export interface SourceDeCitation {
  id: number
  title: string
  author: string | null
  /** Valeur du modèle : `acte_etat_civil`, `arbre_tiers`, `recensement`… */
  sourceKind: string
  /** Le même, en français. Rendu par le serveur pour que l'écran ne tienne pas
   *  sa propre table de traduction (§ 5.3). */
  sourceKindLabel: string
}

export interface MediaDeCitation {
  mediaId: number
  kind: string
  vignetteUrl: string
}

export interface CitationDeFiche {
  id: number
  scope: string
  /** `0` à `3`, ou `null` quand la source ne porte pas de `QUAY`. */
  quay: number | null
  /** Libellé déjà rendu par le serveur : `acte ou registre`, `recopie non
   *  vérifiée`… On l'affiche, on ne le recompose pas (§ 5.3). */
  quayLabel: string | null
  /**
   * **Sur quoi la citation repose réellement** — `acte`, `index` ou `arbre`.
   *
   * Composé par le serveur à partir de **deux** colonnes : la nature du fonds
   * (`source.sourceKind`) et le `QUAY` de cette lecture-ci, en retenant **le plus
   * bas des deux**. Une fiche ne doit jamais paraître mieux tenue qu'elle ne
   * l'est — et dans les deux sens : citer un acte sans l'avoir vérifié reste une
   * recopie, et un `quay` absent n'a jamais été évalué.
   *
   * C'est **cette** clé qu'on affiche, et non `quay` : `quay` dit ce que le
   * contributeur a déclaré, `appui` ce que ça vaut.
   */
  appui: 'acte' | 'index' | 'arbre'
  /** Le libellé du niveau, rendu par le serveur : « tenu par un acte »… */
  appuiLabel: string
  /**
   * La cote ou la page, **débarrassée des adresses d'arbres en ligne**.
   *
   * Le retrait est fait au serveur et la base garde tout — « garde-les en DB
   * uniquement pour l'IA ». Les cotes d'archives que ce champ porte à côté,
   * « 5 E 341/7, vue 1/6 », sont intactes, et les liens de visionneuses aussi.
   */
  page: string | null
  evenContext: string | null
  url: string | null
  dataText: string | null
  source: SourceDeCitation | null
  medias: MediaDeCitation[]
  /**
   * ATOM — quand la citation est **entrée dans l'arbre**, comme `createdAt` d'une
   * note. C'est la date de la ligne, pas celle de l'acte : une citation venue de
   * l'import GEDCOM initial porte la date de cet import. L'onglet Historique range par elle.
   */
  createdAt: string
}

export interface NoteDeFiche {
  id: number
  scope: string
  body: string
  authorLabel: string | null
  createdAt: string
}

export interface MediaDeFiche {
  mediaId: number
  kind: string
  caption: string | null
  /** URL **relative** vers une route authentifiée. Jamais un chemin de disque. */
  url: string
  vignetteUrl: string
  /** ATOM — la date du dépôt, celle de `createdAt` au § 7.2. */
  createdAt: string
  /** ADR 0016 — les URL mènent déjà à la variante floutée. Voir `PortraitDeFiche`. */
  flou?: boolean
}

export interface PortraitDeFiche {
  mediaId: number
  url: string
  vignetteUrl: string
  caption: string | null
  /**
   * ADR 0016 — le lecteur n'a pas de photo de lui : le serveur sert ce portrait
   * de personne vivante **flouté**, et `url` y mène déjà. Le front ne floute
   * jamais en CSS — une image nette floutée à l'écran reste nette à qui
   * l'enregistre ; il dit seulement pourquoi.
   */
  flou?: boolean
}

/**
 * Ce qu'on écrit à la place du nom d'une personne vivante hors du cercle du lecteur —
 * fiche réduite, carte restreinte, fiche de l'arbre. Le même libellé que le serveur
 * pose dans `displayName`/`etiquetteNom` d'un nœud restreint (ADR 0003, révision du
 * 2026-10-01) : le front l'écrit lui-même, il ne relit pas le nom reçu.
 */
export const NOM_D_UNE_PERSONNE_VIVANTE = 'Personne vivante'

/**
 * La fiche d'une personne **vivante hors du cercle du lecteur** : sept clés.
 *
 * Ce n'est pas une erreur et ce n'est pas une panne — le serveur rend `200`,
 * « parce que le nœud est visible dans le graphe : cliquer dessus doit donner
 * quelque chose de cohérent (un nom, et l'impossibilité d'aller plus loin) »
 * (§ 5.1). L'écran doit donc l'afficher proprement, sans case vide.
 *
 * **Révision du 2026-10-01 : elle n'a plus de nom.** « Un nom, et l'impossibilité
 * d'aller plus loin » est devenu « Personne vivante, et l'impossibilité d'aller plus
 * loin » : le serveur pose ce libellé neutre dans `displayName` (et `etiquetteNom` sur
 * le nœud du graphe), et n'envoie plus le vrai nom (ADR 0003, révision datée).
 */
export interface FichePersonneRestreinte {
  visibilite: 'restreinte'
  id: number
  /** Le libellé neutre {@link NOM_D_UNE_PERSONNE_VIVANTE}, jamais le vrai nom. L'écran
   *  ne l'affiche pas : il écrit la constante, pour ne rien dessiner d'autre même face
   *  à un serveur antérieur à la révision. */
  displayName: string
  sex: Sexe
  generation: number
  branch: Branche
  /** Reste envoyé, et vaut `true` : « le masquage est son propre indice »
   *  (§ 16.4). C'est lui qui allume `.fiche.prive`. */
  vivant: boolean
}

export interface FichePersonneComplete {
  visibilite: 'complete'
  id: number
  gedcomXref: string | null
  displayName: string
  givenNames: string | null
  surname: string | null
  marriedName: string | null
  nickname: string | null
  sex: Sexe
  /** Le `DEAT` du GEDCOM — **stocké**. Ne pas le confondre avec `vivant`, qui
   *  est **calculé** (seuil, repli par génération, `force_privacy`). */
  deceased: boolean
  vivant: boolean
  birth: BlocDate | null
  death: BlocDate | null
  /**
   * ADR 0016 — `false` quand la naissance est masquée, même en partie, pour ce
   * lecteur : le champ ne se saisit pas (`naissanceVerrouillee()`). Facultatif
   * pour les charges d'essai écrites avant lui ; absent vaut `true`.
   */
  naissanceModifiable?: boolean
  /** **La ligne de dates de la fiche**, forme compacte (« 1901-1909 – 1987 »).
   *  Ce n'est pas l'étiquette du moteur : celle-là s'appelle `etiquette`, ne vit
   *  que sur un nœud du graphe, et vaut « vivant·e » pour toute personne
   *  vivante. Deux clés distinctes, parce que la même personne porte les deux
   *  chaînes dans la même réponse (§ 14.4). */
  ligneDates: string | null
  branch: Branche
  generation: number
  bestQuay: number | null
  citationCount: number
  forcePrivacy: boolean
  privacyNote: string | null
  /** Sert de `baseVersion` au verrou optimiste d'une demande (§ 6.1). */
  version: number
  /** Calculée à la volée, relativement au lecteur. Jamais stockée. */
  parente: string | null
  portrait: PortraitDeFiche | null
  parents: ParentDeFiche[]
  fratrie: FrereOuSoeurDeFiche[]
  unions: UnionDeFiche[]
  faits: FaitDeFiche[]
  citations: CitationDeFiche[]
  notes: NoteDeFiche[]
  medias: MediaDeFiche[]
  /** Toujours présent, `{}` quand il n'y a rien (§ 13.1). */
  enAttente: Calque
  demandesEnAttente: number[]
}

export type FichePersonne = FichePersonneRestreinte | FichePersonneComplete

/* ------------------------------------------------------------------ */
/* § 5.4 — la fiche d'une famille                                      */
/* ------------------------------------------------------------------ */

export interface EpouxDeFamille {
  personId: number
  displayName: string
  visibilite: Visibilite
}

export interface EnfantDeFamille {
  personId: number
  displayName: string
  siblingOrder: number
  relation: string
}

export interface FicheFamille {
  id: number
  gedcomXref: string | null
  husband: EpouxDeFamille | null
  wife: EpouxDeFamille | null
  /** Les dates d'union restent visibles même si un époux est masqué : elles
   *  appartiennent à la famille, pas à la personne (§ 5.4). */
  marriage: BlocDate | null
  /** La nature de l'union (ADR 0020) — même règle que `UnionDeFiche.married`. */
  married: boolean | null
  divorced: boolean
  divorce: BlocDate | null
  generation: number
  rank: number
  version: number
  enfants: EnfantDeFamille[]
  citations: CitationDeFiche[]
  notes: NoteDeFiche[]
  enAttente: Calque
}

/* ------------------------------------------------------------------ */
/* § 5.5 — la recherche                                                */
/* ------------------------------------------------------------------ */

export interface ResultatDeRecherche {
  personId: number
  displayName: string
  sex: Sexe
  generation: number
  branch: Branche
  vivant: boolean
  ligneDates?: string
  visibilite: Visibilite
}

export interface ReponseDeRecherche {
  elements: ResultatDeRecherche[]
  /** Le plafond `limite` a été atteint — sur le jeu **déjà filtré**. */
  tronque: boolean
}

/* ------------------------------------------------------------------ */
/* § 5.5 bis — les ressemblances dans l'arbre                          */
/* ------------------------------------------------------------------ */

/**
 * **La fiche qu'on compare** — le corps commun des deux routes de ressemblance
 * (§ 5.5 bis, § 6.6 quater) : les clés d'un brouillon de `creation_personne`,
 * **et elles seules**.
 *
 * Une clé de trop rend `422` sur `fiche` plutôt que d'être ignorée : un `birht`
 * ignoré serait une naissance silencieusement non comparée. D'où un type fermé,
 * et un corps recopié clé par clé ({@link corpsDeFicheAComparer}) — jamais l'objet
 * du formulaire posté tel quel, qui porte aussi la note, les liens et les photos.
 *
 * Les blocs date sont des **`BlocDateOp`** : cinq clés, sans `texte`, refusé en
 * entrée (§ 14.1). `deceased` est accepté et sans effet : une case non cochée ne
 * prouve pas qu'une personne vit.
 */
export interface FicheAComparer {
  givenNames: string | null
  surname: string | null
  marriedName: string | null
  nickname: string | null
  sex: Sexe
  deceased: boolean
  birth: BlocDateOp | null
  death: BlocDateOp | null
}

/** Les codes des concordances, **dans cet ordre fixe** — celui des pastilles. */
export type CritereDeRessemblance = 'nom' | 'prenom' | 'sexe' | 'naissance' | 'deces' | 'lieu'

/**
 * Une personne de l'arbre qui ressemble à la saisie (§ 5.5 bis).
 *
 * Une personne hors du cercle du lecteur est **absente**, comme à la recherche :
 * elle n'arrive pas réduite, elle n'arrive pas. Pour un vivant, seule la
 * naissance **réduite** à ce que le lecteur voit a été comparée — sans quoi la
 * route serait un oracle de date de naissance.
 */
export interface RessemblanceDansLArbre {
  personId: number
  displayName: string
  sex: Sexe
  vivant: boolean
  generation: number
  branch: Branche | null
  /** La ligne de dates de la recherche, réciprocité comprise (« — » quand rien
   *  n'est partagé). */
  ligneDates: string
  /** Le `place` de la naissance tel que la fiche l'écrit, ou `null`. */
  lieuNaissance: string | null
  criteres: CritereDeRessemblance[]
  /** Les mêmes concordances en français, **à afficher telles quelles**. */
  raisons: string[]
  /** Le nombre de critères concordants : 2 à 6. */
  score: number
}

export interface ReponseRessemblancesArbre {
  elements: RessemblanceDansLArbre[]
  /** Le plafond `limite` a été atteint — sur le jeu déjà filtré et trié. */
  tronque: boolean
}

/** Cinq clés, recopiées une à une : un bloc reçu d'une fiche porterait `texte`. */
function blocAComparer(bloc: BlocDateOp | null): BlocDateOp | null {
  if (bloc === null) return null
  return { raw: bloc.raw, qualifier: bloc.qualifier, min: bloc.min, max: bloc.max, place: bloc.place }
}

/**
 * Le corps de `fiche`, **clé par clé** : rien de ce que l'objet passé porterait
 * en plus — une note, des liens, un `texte` dans une date — ne part, puisqu'une
 * clé inconnue rend `422`. Commun aux deux routes : `propositions.ts` l'emploie.
 */
export function corpsDeFicheAComparer(fiche: FicheAComparer): FicheAComparer {
  return {
    givenNames: fiche.givenNames,
    surname: fiche.surname,
    marriedName: fiche.marriedName,
    nickname: fiche.nickname,
    sex: fiche.sex,
    deceased: fiche.deceased,
    birth: blocAComparer(fiche.birth),
    death: blocAComparer(fiche.death),
  }
}

/** Le plafond par défaut des deux routes : cinq, comme le serveur. */
export const LIMITE_DES_RESSEMBLANCES = 5

/**
 * `POST /api/arbre/ressemblances` — « cette personne est peut-être déjà dans
 * l'arbre ».
 *
 * **Un `POST` qui n'écrit rien** : en `GET`, le nom et la naissance — d'un
 * vivant, le plus souvent — partiraient dans l'URL, donc dans les journaux et
 * l'historique. Il reste ouvert sous la bascule « voir comme un membre » ; c'est
 * le formulaire qui s'en abstient, parce que rien de ce qu'il en ferait ne
 * pourrait s'écrire.
 *
 * `422 donnees_invalides` sans nom ni nom d'usage de deux lettres, ou sans
 * prénom ni surnom : c'est à l'appelant de ne pas appeler avant
 * (`ficheDeComparaison`). Un compte en attente reçoit `403 regime_en_attente`.
 */
export function chercherRessemblancesArbre(
  fiche: FicheAComparer,
  limite: number = LIMITE_DES_RESSEMBLANCES,
  signal?: AbortSignal,
): Promise<ReponseRessemblancesArbre> {
  const o = options(signal)
  o.methode = 'POST'
  o.corps = { fiche: corpsDeFicheAComparer(fiche), limite }
  return requeteApi<ReponseRessemblancesArbre>('/arbre/ressemblances', o)
}

/* ------------------------------------------------------------------ */
/* § 6.6 — le détail d'un nœud en attente                              */
/* ------------------------------------------------------------------ */

/**
 * Un lien décrit par une demande de création.
 *
 * `otherPersonId` et `otherLabel` sont **optionnels** : le § 11.3 masque toute
 * clé `*Label` désignant une personne vivante hors cercle, et la clé `…Id` qui
 * l'accompagne disparaît avec elle. Absentes, elles ne sont pas `null`.
 */
export interface LienDeBrouillon {
  seq: number
  /** `enfant_de`, `parent_de`, `conjoint_de` — valeurs du modèle. */
  kind: string
  otherPersonId?: number
  otherLabel?: string
  marriage?: BlocDate | null
  divorce?: BlocDate | null
}

export interface MediaDeBrouillon {
  mediaId: number
  kind: string
  caption: string | null
  url: string
  vignetteUrl: string
  status: string
}

export interface FicheBrouillon {
  demandeId: number
  /** Avec le dièse (`#p1`) : c'est la valeur de `prp_draft_person.local_ref`. */
  localRef: string
  /** L'identifiant de nœud complet (`#12/p1`), **opaque**, jamais découpé. */
  noeudId: string
  status: string
  enAttente: true
  authorLabel: string
  givenNames: string | null
  surname: string | null
  marriedName: string | null
  sex: Sexe
  deceased: boolean
  vivant: boolean
  birth: BlocDate | null
  death: BlocDate | null
  ligneDates: string | null
  note: string | null
  liens: LienDeBrouillon[]
  medias: MediaDeBrouillon[]
  /** Après validation, l'identifiant réel : l'écran peut alors renvoyer vers
   *  `/api/arbre/personnes/{id}` (§ 6.6). */
  resolvedPersonId: number | null
}

/* ------------------------------------------------------------------ */
/* Les appels                                                          */
/* ------------------------------------------------------------------ */

/** `exactOptionalPropertyTypes` interdit de poser `signal: undefined` sur une
 *  propriété déclarée `signal?: AbortSignal`. On construit donc l'objet. */
function options(signal?: AbortSignal): OptionsRequete {
  const o: OptionsRequete = {}
  if (signal !== undefined) o.signal = signal
  return o
}

/**
 * `GET /api/arbre/personnes/{id}`.
 *
 * Rend `200` même pour une personne vivante hors cercle — une fiche réduite,
 * pas une `404` (§ 5.1). Discriminer sur `visibilite` avant de lire un champ.
 */
export function lireFichePersonne(id: number, signal?: AbortSignal, depuis: number | null = null): Promise<FichePersonne> {
  // `depuis` : la parenté de la fiche vue depuis cette personne (administrateur, § 5.2).
  return requeteApi<FichePersonne>(`/arbre/personnes/${id}`, depuis !== null ? { ...options(signal), requete: { depuis } } : options(signal))
}

/** `GET /api/arbre/familles/{id}`. */
export function lireFicheFamille(id: number, signal?: AbortSignal): Promise<FicheFamille> {
  return requeteApi<FicheFamille>(`/arbre/familles/${id}`, options(signal))
}

/* ------------------------------------------------------------------ */
/* § 5.4 bis — l'arbre d'une affiche                                   */
/* ------------------------------------------------------------------ */

/**
 * Une personne de l'affiche que le cercle montre. `birth` est entière, **réduite** par la
 * réciprocité (sans `raw`, bornes élargies), ou `masquee` quand l'année n'est pas partagée.
 */
export interface PersonneDAfficheComplete {
  id: number
  visibilite: 'complete'
  givenNames: string
  surname: string
  sex: Sexe
  deceased: boolean
  vivant: boolean
  birth: BlocDate
  death: BlocDate
}

/** Un vivant hors cercle : **cinq clés**, à sa place dans l'arbre, comme son nœud du graphe. */
export interface PersonneDAfficheRestreinte {
  id: number
  visibilite: 'restreinte'
  /** Le masque {@link NOM_D_UNE_PERSONNE_VIVANTE} — jamais le vrai nom. */
  displayName: string
  sex: Sexe
  vivant: boolean
}

export type PersonneDAffiche = PersonneDAfficheComplete | PersonneDAfficheRestreinte

export interface FamilleDAffiche {
  id: number
  /** Identifiants de `personnes` — `null` pour un époux absent ou hors périmètre. */
  husband: number | null
  wife: number | null
  marriage: BlocDate
  divorced: boolean
  divorce: BlocDate | null
  /**
   * Le mariage **attesté** même sans date (vrai), l'union libre (faux), l'inconnu (`null`).
   * `tree_family.married` (ADR 0020) : servi par la route, toujours, `null` compris — et
   * toujours vrai sous une date ou un lieu de mariage. Facultatif ici pour les arbres
   * fictifs de l'essai, qui ne le portent pas tous. Absent ou `null`, l'affiche le déduit
   * de la date, du lieu et du divorce.
   */
  married?: boolean | null
  /** Dans l'ordre de l'affiche : naissance, rang dans la fratrie, identifiant. */
  enfants: { personId: number; relation: string }[]
}

export interface ArbreDAffiche {
  famille: number
  generationsDeDescendance: number
  /**
   * Le nom qui signe les affiches (`tree.signature_affiches`), `null` s'il n'est pas réglé.
   * Facultatif ici pour les arbres fictifs de l'essai.
   */
  signature?: string | null
  personnes: PersonneDAffiche[]
  familles: FamilleDAffiche[]
}

/** `GET /api/arbre/familles/{id}/affiche` — le couple, son ascendance, sa descendance. */
export function lireArbreDAffiche(familleId: number, signal?: AbortSignal): Promise<ArbreDAffiche> {
  return requeteApi<ArbreDAffiche>(`/arbre/familles/${familleId}/affiche`, options(signal))
}

/** Un proche cité dans une ligne du tableau — `displayName` vaut « Personne vivante » hors cercle. */
export interface ProcheDuTableau {
  personId: number
  displayName: string
}

/**
 * Une ligne du tableau des personnes (§ 5.5 ter) — une fiche que le lecteur a le droit de
 * lire en entier. Il n'y a pas de forme réduite : un vivant hors cercle n'a pas de ligne.
 */
export interface LigneDuTableau {
  personId: number
  gedcomXref: string | null
  displayName: string
  surname: string
  givenNames: string
  marriedName: string | null
  nickname: string | null
  sex: Sexe
  vivant: boolean
  generation: number | null
  branch: Branche | null
  /** La phrase de la fiche (« grand-mère »), relative au lecteur ; `null` sans point de vue. */
  parente: string | null
  /** Forme compacte du § 14.4 (« 1901-1909 », « 26.9.2012 ») ; `null` si inconnue. */
  naissance: string | null
  /** `AAAA-MM-JJ`, borne basse de la naissance **telle que le lecteur la voit** : pour trier. */
  naissanceTri: string | null
  lieuNaissance: string | null
  deces: string | null
  decesTri: string | null
  lieuDeces: string | null
  professions: string[]
  parents: ProcheDuTableau[]
  conjoints: ProcheDuTableau[]
  enfants: number
  citationCount: number
  /** Meilleur `QUAY` des citations ; `>= 2` ⇒ appuyé sur un acte. */
  bestQuay: number | null
  createdAt: string
  updatedAt: string
}

export interface TableauDesPersonnes {
  portee: PorteeExplorer
  elements: LigneDuTableau[]
  total: number
}

/**
 * `GET /api/arbre/tableau?portee=` — toutes les fiches lisibles, une ligne chacune, pour
 * Explorer › Tableau. Filtres, tri et export CSV se font ici, sur cette charge : la route
 * n'en prend aucun. `tout` n'est accordé qu'à l'administration (le serveur répond `arbre`
 * à un membre).
 */
export function chargerTableau(portee: PorteeExplorer = 'arbre', signal?: AbortSignal): Promise<TableauDesPersonnes> {
  const o = options(signal)
  o.requete = { portee }
  return requeteApi<TableauDesPersonnes>('/arbre/tableau', o)
}

/**
 * `GET /api/arbre/recherche?q=&limite=`.
 *
 * **La recherche passe par l'API, jamais par un index reconstruit depuis le
 * graphe.** Le serveur exclut déjà les personnes vivantes hors du cercle du
 * lecteur — « elle n'est pas rendue en version réduite : elle est absente »
 * (§ 5.5). Un index local bâti sur les `displayName` des nœuds les ferait
 * réapparaître, et contournerait la règle sans qu'on l'ait voulu.
 *
 * `q` doit faire au moins deux caractères, sinon `422` : c'est à l'appelant de
 * ne pas appeler avant.
 *
 * `vivantsSeulement` pose `?vivants=1` : le serveur écarte alors les défunts.
 * Le tirage au sort d'heritage le demandait (on n'offre pas de cadeau à un aïeul) ;
 * il a été retiré, l'option reste.
 * Les formulaires de proposition et les actes demandés, eux, doivent continuer
 * de désigner des défunts : le filtre n'est jamais un défaut.
 */
export function rechercherPersonnes(
  q: string,
  limite?: number,
  signal?: AbortSignal,
  vivantsSeulement = false,
): Promise<ReponseDeRecherche> {
  const o = options(signal)
  o.requete = {
    q,
    ...(limite === undefined ? {} : { limite }),
    // Absent plutôt que `vivants=0` : la requête d'hier reste la requête d'aujourd'hui.
    ...(vivantsSeulement ? { vivants: 1 } : {}),
  }
  return requeteApi<ReponseDeRecherche>('/arbre/recherche', o)
}

/**
 * `GET /api/demandes/{demandeId}/brouillons/{ref}` — **la référence sans le
 * dièse**.
 *
 * L'URL ne se fabrique **jamais** en découpant l'identifiant de nœud `#12/p1` :
 * le `#` y est le séparateur de fragment d'URL, et c'est précisément pourquoi
 * l'API envoie `demandeId` et `brouillonRef` séparément sur le nœud (§ 5.2,
 * piège n° 2). Cette fonction prend les deux morceaux déjà séparés.
 */
export function lireBrouillonPersonne(
  demandeId: number,
  brouillonRef: string,
  signal?: AbortSignal,
): Promise<FicheBrouillon> {
  return requeteApi<FicheBrouillon>(
    `/demandes/${demandeId}/brouillons/${encodeURIComponent(brouillonRef)}`,
    options(signal),
  )
}
