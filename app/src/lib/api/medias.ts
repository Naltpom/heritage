/**
 * Les appels d'API du module `Media` — § 7 du contrat.
 *
 * Deux natures de média sur le même écran, et elles ne se confondent pas :
 * le **portrait** (un seul actif par personne) et la **preuve** (acte,
 * document, photo d'archive). C'est la demande n° 3 du commanditaire, et elle
 * en compte bien deux : « possibilité d'ajouter des preuves sur les personnes :
 * photo, et photo de portrait de la personne aussi » (§ 1 du dossier).
 *
 * ────────────────────────────────────────────────────────────────────
 * **Aucune URL de fichier ne se fabrique ici, et ce n'est pas une omission.**
 *
 * Ce module n'exporte volontairement pas de `urlDuFichier(id)`. Les octets ne
 * sortent que par `GET /api/medias/{id}/fichier` et `/vignette` (§ 7.4, § 7.5),
 * et **c'est le serveur qui écrit ces deux URL** dans la charge du média
 * (`url`, `vignetteUrl`). Une URL recomposée côté client marcherait — jusqu'au
 * jour où elle cesserait de marcher, sans que personne ne sache pourquoi :
 *
 * 1. le contrôle d'accès est côté serveur (« jamais de lien direct vers le
 *    système de fichiers, jamais de redirection ») ; une URL devinée
 *    court-circuite l'intention même du couple charge-utile / route servante ;
 * 2. un média qu'on n'a pas le droit de voir rend **`404`** et pas `403`
 *    (§ 7.4) — donc une URL construite sur un identifiant lu ailleurs produit
 *    une image cassée là où l'absence de la clé `url` aurait produit… rien du
 *    tout, ce qui est la bonne réponse ;
 * 3. le jour où le chemin change, le serveur envoie le nouveau, et le front
 *    n'a rien à recompiler.
 *
 * Règle pratique : **si vous n'avez pas d'objet média, vous n'avez pas d'URL.**
 * Chargez le média, puis lisez `url` ou `vignetteUrl`.
 * ────────────────────────────────────────────────────────────────────
 *
 * **Un média téléversé n'est pas un média validé.** Il naît
 * `status: "en_attente"` et suit le même circuit de validation que le reste
 * (§ 7.1) : il n'est visible que de son déposant et des administrateurs jusqu'à
 * ce qu'une demande de nature `ajout_media` soit validée. L'écran doit le dire,
 * sans quoi un contributeur croit son acte publié alors qu'il attend.
 */

import { requeteApi, type OptionsRequete, type ValeurRequete } from './client'
import type { BlocDate } from './types'

/* ------------------------------------------------------------------ */
/* Les types du § 7                                                    */
/* ------------------------------------------------------------------ */

/** § 6.5 du dossier — les deux natures. Valeurs du modèle, non traduites. */
export type NatureMedia = 'portrait' | 'preuve'

/** Ce à quoi un média se rattache (§ 7.1). */
export type NatureSujetMedia = 'personne' | 'famille' | 'citation'

/**
 * `med_media.status`. `refuse` **n'est pas** une suppression : « un refus n'est
 * pas une destruction, un média `refuse` garde son fichier sur disque jusqu'à
 * purge explicite » (§ 7.6).
 */
export type StatutMedia = 'en_attente' | 'actif' | 'refuse'

/**
 * Le doublon est un **avertissement, pas un refus** : le même acte de mariage
 * étaye légitimement les fiches des deux époux et de leurs quatre parents. Le
 * téléversement rend donc `201`, jamais `409` (§ 7.2).
 */
export interface AvertissementMedia {
  code: string
  message: string
  /** Le média déjà présent, quand l'avertissement en désigne un. */
  mediaId?: number
}

/**
 * La charge d'un média (§ 7.2).
 *
 * Deux clés sont **optionnelles parce qu'elles sont filtrées**, et non parce
 * qu'elles seraient parfois vides — c'est la distinction du § 1.3, et le type
 * la porte :
 *
 * - `originalName` : rendu **au déposant et aux administrateurs seulement** —
 *   « un nom de fichier d'appareil photo porte souvent une date et parfois un
 *   nom » ;
 * - `sha256` : aux administrateurs, et au déposant sur **son** média — c'est
 *   une empreinte qui permet de tester la présence d'un fichier qu'on possède
 *   déjà.
 *
 * `subjectId` et `subjectLabel`, eux, sont **requis**. La « huitième porte »
 * (§ 11.3) veut qu'une clé `…Label` désignant une personne soit filtrée comme la
 * personne — mais elle ne mord pas ici : pour une personne vivante hors cercle,
 * la porte n° 5 retire **l'élément entier** de `GET /api/medias`, et le fichier
 * rend `404` (§ 11.4). Il n'existe donc pas de média rendu avec un sujet masqué.
 *
 * Et trois clés n'existent nulle part, pour personne, administrateur compris :
 * `storageName`, `thumbnailName`, `originalSha256`, ni aucun chemin de disque
 * (§ 16). « Un nom devinable rend le stockage énumérable même hors racine web. »
 */
export interface Media {
  id: number
  kind: NatureMedia
  /**
   * **Toujours là, et il ne vaut jamais `"brouillon"`.** Un média joint à une
   * personne pas encore créée porte `subjectKind: "personne"` comme les autres :
   * `med_media.subject_kind` est `NOT NULL`, et le seul brouillon qui existe est
   * un brouillon de personne — `prp_draft_person` n'a d'équivalent ni pour la
   * famille ni pour la citation (§ 7.1).
   *
   * La nuance qui justifie l'encadré du contrat : `subjectKind` décrit un **type
   * de sujet**, pas un **état de résolution**. L'état est porté par la paire
   * `subjectId` / `brouillonRef`, et la validation se contente de poser le
   * premier et d'effacer le second — `subjectKind` ne bouge pas, avant comme
   * après.
   */
  subjectKind: NatureSujetMedia
  /**
   * `null` — **présent, pas absent** — tant que le média est joint à un
   * brouillon : « le lecteur a le droit de le voir, il n'est simplement pas
   * encore attribué » (§ 7.2). C'est la distinction du § 1.3 dans l'autre sens,
   * et la confondre avec un champ interdit ferait disparaître le média de la
   * fiche sans erreur.
   */
  subjectId: number | null
  /** `NOT NULL`. Pour un brouillon, **le serveur le compose** depuis
   *  `prp_draft_person` ; le client ne l'envoie pas, et s'il l'envoyait il
   *  serait ignoré (§ 7.1). */
  subjectLabel: string
  /**
   * La demande et la référence de brouillon, quand le média est joint à une
   * personne pas encore créée.
   *
   * > **Le contrat et le serveur ne les nomment pas pareil.** Le § 7.2 écrit
   * > `demandeId` et `brouillonRef` ; `Media\Service\NormaliseurMedia` émet
   * > `subjectRequestId` et `subjectDraftRef`, et son auteur le signale comme un
   * > ajout assumé « hors contrat écrit ». Les quatre clés sont déclarées, toutes
   * > facultatives, le temps que l'écart soit arbitré — signalé au livrable.
   *
   * Le type ne tranche pas à la place du contrat : il laisse un lecteur découvrir
   * l'absence plutôt que de lui promettre une clé qui n'arrivera pas. **La fiche
   * d'une personne existante n'en reçoit aucune** — ces médias-là appartiennent
   * aux écrans de proposition.
   */
  demandeId?: number
  brouillonRef?: string
  subjectRequestId?: number
  subjectDraftRef?: string
  mimeType: string
  byteSize: number
  width: number | null
  height: number | null
  pageCount: number | null
  /** Le ré-encodage a-t-il eu lieu ? « Une photo de famille non nettoyée publie
   *  l'adresse du domicile » (§ 6.5 du dossier). */
  exifStripped: boolean
  status: StatutMedia
  isActivePortrait: boolean
  caption: string | null
  taken: BlocDate | null
  uploadedByLabel: string | null
  createdAt: string
  /** URL **relative** vers la route authentifiée du § 7.4. Écrite par le
   *  serveur : on la lit, on ne la fabrique pas (voir l'en-tête de ce module). */
  url: string
  /** Idem pour la vignette du § 7.5. */
  vignetteUrl: string
  /** La demande `ajout_media` qui porte ce média, `null` pour un dépôt direct
   *  d'administrateur — qui naît `actif` (§ 7.1). */
  changeRequestId: number | null
  /**
   * **Absente en lecture** — vérifié contre `NormaliseurMedia`, qui ne pose la
   * clé qu'à la création (`if ([] !== $avertissements)`). Un `GET /api/medias`
   * n'en porte donc aucune, même vide.
   *
   * Et ici, contrairement à un champ de fiche, **absent et vide veulent bien dire
   * la même chose** : « aucun avertissement ». La règle du § 1.3 oppose *interdit*
   * et *inconnu* sur une donnée que le lecteur pourrait ne pas avoir le droit de
   * voir ; ce n'en est pas une. Un `?? []` est donc correct au point d'emploi —
   * c'est le seul endroit de cet écran où il l'est, et c'est pour cela que la
   * raison est écrite ici plutôt que laissée à deviner.
   */
  avertissements?: AvertissementMedia[]
  originalName?: string
  sha256?: string
}

/* ------------------------------------------------------------------ */
/* Les appels                                                          */
/* ------------------------------------------------------------------ */

/** `exactOptionalPropertyTypes` interdit de poser `signal: undefined` sur une
 *  propriété déclarée `signal?: AbortSignal`. On construit donc l'objet — même
 *  geste que dans `arbre.ts`. */
function optionsDeRequete(signal?: AbortSignal): OptionsRequete {
  const options: OptionsRequete = {}
  if (signal !== undefined) options.signal = signal
  return options
}

/** Filtres de `GET /api/medias` (§ 7.3). La liste est **bornée par le sujet** :
 *  il n'existe pas de route qui rende tous les médias de l'arbre. */
export interface FiltreMedias {
  subjectKind: NatureSujetMedia
  subjectId: number
  kind?: NatureMedia
  /**
   * Par défaut `actif` **seul**. Un membre qui demande `en_attente` ne reçoit
   * que les siens ; un administrateur reçoit tous ceux en attente — c'est sa
   * file de validation (§ 7.3). Le serveur applique cette règle : le front ne
   * trie rien après coup.
   */
  status?: StatutMedia
}

/**
 * `GET /api/medias` — **tableau nu**, sans enveloppe de pagination : la liste
 * est bornée par nature (§ 1.5).
 */
export function listerMedias(filtre: FiltreMedias, signal?: AbortSignal): Promise<Media[]> {
  const requete: Record<string, ValeurRequete> = {
    subjectKind: filtre.subjectKind,
    subjectId: filtre.subjectId,
  }
  // `undefined` est simplement omis par `construireUrl` ; on n'écrit donc pas
  // `status=undefined` dans la chaîne de requête en passant la clé telle quelle.
  if (filtre.kind !== undefined) requete['kind'] = filtre.kind
  if (filtre.status !== undefined) requete['status'] = filtre.status

  const options = optionsDeRequete(signal)
  options.requete = requete
  return requeteApi<Media[]>('/medias', options)
}

/** `GET /api/medias/{id}` — `404` si le lecteur n'a pas le droit de le voir,
 *  jamais `403` : « un `403` confirmerait qu'une personne masquée a un
 *  portrait » (§ 7.4). */
export function lireMedia(id: number, signal?: AbortSignal): Promise<Media> {
  return requeteApi<Media>(`/medias/${id}`, optionsDeRequete(signal))
}

/** La personne **pas encore créée** à laquelle un média se rattache (§ 6.4).
 *  `brouillonRef` s'écrit **sans dièse** — `p1`, et non `#p1` : le `#` est le
 *  séparateur de fragment d'URL, et c'est pourquoi les deux morceaux voyagent
 *  séparément (§ 6.6). */
export interface BrouillonVise {
  demandeId: number
  brouillonRef: string
}

/**
 * Ce qu'un téléversement porte (§ 7.1).
 *
 * **L'exclusivité est portée par le type, pas par une note.** Le contrat dit que
 * `subjectId` est « exclusif » du couple `demandeId` + `brouillonRef` ; l'union
 * ci-dessous le rend vrai à la compilation — les `?: never` font échouer
 * l'écriture qui fournirait les deux, plutôt que de la laisser partir et
 * découvrir le refus à l'exécution.
 *
 * `subjectKind` est **dans les deux branches**, parce qu'il est toujours
 * obligatoire. Pour un brouillon il vaut `"personne"`, et l'union le contraint à
 * cette seule valeur : « le seul brouillon qui existe est un brouillon de
 * personne », et il n'y a **pas** de quatrième valeur `"brouillon"` (§ 7.1).
 *
 * `subjectLabel` n'est jamais envoyé : le serveur le compose, et un client qui
 * l'enverrait le verrait ignoré.
 *
 * `taken` (le bloc date de la prise de vue) n'est pas collecté par la fiche
 * d'une personne. S'il l'était un jour, c'est un **`BlocDateOp`** qu'il faudrait
 * poster — cinq clés, toujours les cinq — et surtout pas le `BlocDate` reçu
 * d'une fiche : sa sixième clé `texte` ferait diverger la comparaison `JSONB` de
 * la détection de conflit, et la demande partirait caduque sans cause réelle
 * (§ 14.1).
 */
export type EnvoiMedia = {
  fichier: File
  kind: NatureMedia
  /** Légende, 255 caractères (§ 7.1). */
  caption?: string
} & (
  | { subjectKind: NatureSujetMedia; subjectId: number; brouillon?: never }
  | { subjectKind: 'personne'; subjectId?: never; brouillon: BrouillonVise }
)

/**
 * `POST /api/medias` — `multipart/form-data`, **un seul fichier par requête**.
 *
 * On ne pose **aucun** `Content-Type` : le navigateur doit écrire lui-même la
 * frontière multipart, et l'écraser casse le téléversement. Le client d'API le
 * sait déjà pour tout `FormData`, c'est pourquoi on lui passe l'objet tel quel.
 *
 * Les refus arrivent en `413 fichier_trop_volumineux` et
 * `415 type_de_fichier_refuse`. Les deux sont déjà classés `corriger` par
 * `analyserErreur()` : **aucun écran ne réinterprète ces codes**, il affiche le
 * message du serveur.
 */
export function televerserMedia(envoi: EnvoiMedia, signal?: AbortSignal): Promise<Media> {
  const formulaire = new FormData()
  formulaire.append('fichier', envoi.fichier, envoi.fichier.name)
  formulaire.append('kind', envoi.kind)
  // Toujours, dans les deux formes. L'omettre ferait échouer l'insertion sur une
  // colonne `NOT NULL`, et le message ne dirait pas pourquoi.
  formulaire.append('subjectKind', envoi.subjectKind)

  // On teste la présence de chaque forme plutôt que de discriminer l'union : un
  // `String(undefined)` posterait `subjectId=undefined`, et le serveur irait
  // chercher une personne dont l'identifiant n'est pas un nombre. L'union garantit
  // qu'exactement une des deux branches est fournie ; ce code n'en suppose aucune.
  if (envoi.brouillon !== undefined) {
    formulaire.append('demandeId', String(envoi.brouillon.demandeId))
    // Sans dièse : `p1`. Voir `BrouillonVise`.
    formulaire.append('brouillonRef', envoi.brouillon.brouillonRef)
  } else if (envoi.subjectId !== undefined) {
    formulaire.append('subjectId', String(envoi.subjectId))
  }

  if (envoi.caption !== undefined && envoi.caption.length > 0) {
    formulaire.append('caption', envoi.caption)
  }

  const options = optionsDeRequete(signal)
  options.methode = 'POST'
  options.corps = formulaire
  return requeteApi<Media>('/medias', options)
}

/**
 * `DELETE /api/medias/{id}` — **administrateur seulement** (§ 7.6).
 *
 * « Un membre ne supprime pas un média validé : il dépose une demande. » C'est
 * une **action réservée**, donc l'un des rares endroits où le rôle du lecteur a
 * le droit de décider de ce que l'écran propose — à la différence d'un champ,
 * qui s'affiche parce que la charge utile le porte, jamais parce que le lecteur
 * est administrateur.
 *
 * `409 media_reference` sur un média `actif` encore rattaché à une citation
 * référencée : la suppression n'a pas eu lieu, et le message du serveur le dit.
 */
/**
 * `POST /api/medias/portrait-du-compte` — **son propre portrait, direct.**
 *
 * Le seul média qui ne passe pas par le circuit de demande : c'est ton visage.
 * La règle « un seul portrait actif » tient quand même — l'ancien passe à
 * `remplace` et reste visible sur la fiche. `409 compte_non_rattache` sans
 * personne validée : un compte en attente n'a pas de fiche à lui.
 */
export function televerserPortraitDuCompte(fichier: File, signal?: AbortSignal): Promise<Media> {
  const formulaire = new FormData()
  formulaire.append('fichier', fichier, fichier.name)
  return requeteApi<Media>('/medias/portrait-du-compte', {
    methode: 'POST',
    corps: formulaire,
    ...(signal === undefined ? {} : { signal }),
  })
}

/**
 * `POST /api/medias/{id}/pivoter` — un angle **horaire**. Réservé à
 * l'administrateur, ou à l'auteur tant que la pièce attend. La réponse porte des
 * `url` / `vignetteUrl` versionnées : remplacer le média suffit à rafraîchir.
 */
export function pivoterMedia(id: number, angle: 90 | 180 | 270): Promise<Media> {
  return requeteApi<Media>(`/medias/${id}/pivoter`, { methode: 'POST', corps: { angle } })
}

export function supprimerMedia(id: number): Promise<{ supprime: boolean; id: number }> {
  return requeteApi<{ supprime: boolean; id: number }>(`/medias/${id}`, { methode: 'DELETE' })
}
