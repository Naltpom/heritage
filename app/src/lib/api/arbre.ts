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
import type { BlocDate, Branche, Calque, Sexe, Visibilite } from './types'

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
   * note. C'est la date de la ligne, pas celle de l'acte : une citation importée
   * du GEDCOM porte la date de l'import. L'onglet Historique range par elle.
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
export function lireFichePersonne(id: number, signal?: AbortSignal): Promise<FichePersonne> {
  return requeteApi<FichePersonne>(`/arbre/personnes/${id}`, options(signal))
}

/** `GET /api/arbre/familles/{id}`. */
export function lireFicheFamille(id: number, signal?: AbortSignal): Promise<FicheFamille> {
  return requeteApi<FicheFamille>(`/arbre/familles/${id}`, options(signal))
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
 * Seuls les tirages au sort le demandent — on n'offre pas de cadeau à un aïeul.
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
