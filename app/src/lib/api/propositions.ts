/**
 * Les appels d'API des propositions — § 6 du contrat.
 *
 * **Un seul mécanisme** : modifier un champ, ajouter une personne, ajouter un
 * lien, joindre un média passent par la même `ChangeRequest`, la même file, le
 * même message obligatoire au refus (§ 6.4 du dossier, ADR 0004). D'où une seule
 * forme de demande, un seul écran de détail, et un `kind` qui discrimine.
 *
 * Deux routes lisent la même demande, et ce n'est pas une redondance :
 *
 * - `GET /api/demandes/{id}` — la vue de **l'auteur** (§ 6.5) ;
 * - `GET /api/administration/demandes/{id}` — la même, **plus ce qu'il faut pour
 *   décider** (§ 6.7) : `valeurCourante` et `aBouge` par op, le bloc `cible`, la
 *   `resolutionPrevue`, les demandes concurrentes et les doublons probables.
 *
 * L'écran de détail choisit la route selon le lecteur, puis **n'affiche que ce
 * que la charge porte**. C'est la nuance du § 10.4, et elle est facile à rater :
 * un administrateur voit plus de choses parce que le serveur lui en envoie plus,
 * jamais parce que l'écran teste son rôle avant de dessiner. Un
 * `v-if="administrateur"` qui afficherait un champ absent de la charge utile
 * serait la version front du `if ($estAdmin) { return $tout; }` qu'il a fallu
 * retirer du serveur.
 */

import { corpsDeFicheAComparer, LIMITE_DES_RESSEMBLANCES, type CritereDeRessemblance, type FicheAComparer } from './arbre'
import { requeteApi, type OptionsRequete } from './client'
import type { BlocDate, BlocDateOp, ListePaginee, Sexe } from './types'

/* ------------------------------------------------------------------ */
/* Les types du § 6                                                    */
/* ------------------------------------------------------------------ */

/** § 5.7 — les dix natures de `RequestKind`. Valeurs du modèle, non traduites. */
export type NatureDemande =
  | 'modification_personne'
  | 'modification_famille'
  | 'modification_citation'
  | 'creation_personne'
  | 'ajout_lien'
  | 'ajout_media'
  /**
   * La **création** d'une citation (§ 6.4 bis du contrat). `modification_citation`
   * ne savait que corriger celles qui existaient déjà.
   *
   * L'API l'accepte ; **aucun écran ne la dépose encore**. Elle est nommée ici pour
   * que « mes demandes » et la file d'administration sachent l'afficher — une nature
   * que le back renvoie et que le type ignore se lit en `snake_case` sous les yeux
   * d'un contributeur, ou pas du tout.
   */
  | 'ajout_citation'
  /**
   * La création d'une **note** sur une fiche existante (§ 6.4 ter du contrat).
   *
   * On savait déjà donner une note à une personne qu'on crée — le formulaire de
   * création porte son champ `note` — mais pas à une personne qui existe.
   *
   * L'API l'accepte ; **aucun écran ne la dépose encore**.
   */
  | 'ajout_note'
  /**
   * La **suppression** d'une fiche existante (§ 6.4 quater du contrat).
   *
   * La neuvième, et la première qui retire au lieu d'ajouter. Un doublon dans un
   * arbre généalogique se réparait jusqu'ici à la main en base — le seul chemin
   * d'écriture qui contournait le mécanisme des demandes.
   *
   * Trois différences avec les huit autres, qui se voient toutes à l'écran :
   * le **motif est obligatoire** au dépôt, l'écran annonce **ce que la
   * suppression emporte** avant qu'on écrive, et une fiche qui porte une
   * descendance ou une preuve n'est **pas supprimable** — c'est l'autre fiche du
   * doublon qu'il faut viser.
   */
  | 'suppression_personne'
  /**
   * Le **retrait d'un lien de parenté** (§ 6.4 quinquies du contrat) — le pendant
   * de `ajout_lien`. Un op par lien, sur la famille ; c'est le serveur qui mesure
   * ce que le retrait fera (union conservée ou supprimée), jamais le front.
   * Déposée par le mode édition de la fiche (`components/personne/edition.ts`).
   */
  | 'retrait_lien'

/**
 * `caduque` n'est **pas** `refusee` : personne n'a refusé, le monde a bougé
 * (ADR 0004). Deux statuts, deux notifications, deux écrans, deux actions.
 */
export type StatutDemande =
  | 'brouillon'
  | 'en_attente'
  | 'validee'
  | 'refusee'
  | 'caduque'
  | 'annulee'

export type NatureSujet = 'personne' | 'famille' | 'citation' | 'media' | 'lien' | 'source'

/**
 * Une valeur d'op : un scalaire, ou un bloc date (§ 14). Jamais recomposée.
 *
 * **`ValeurDate` et non `BlocDate`** : une valeur d'op porte **cinq** clés, sans
 * `texte` (§ 14.1). La distinction n'est pas cosmétique — la détection de
 * conflit compare ces valeurs en `JSONB` (§ 9.6), et une sixième clé ferait
 * diverger la comparaison, rendant caduque une demande que rien ne contredit.
 *
 * Le calque `enAttente`, lui, porte le bloc **entier avec `texte`** (§ 13.1) :
 * il est rendu pour l'affichage, pas pour être reposté.
 */
export type ValeurOp = string | number | boolean | null | ValeurDate | unknown[]

/**
 * Un op de modification (§ 6.2), vu par l'administration (§ 6.7).
 *
 * `valeurCourante` et `aBouge` ne sont présents que sur la route
 * d'administration : ils sont calculés à la lecture, jamais stockés. Leur
 * **absence** est donc une information — « cette charge ne porte pas de quoi
 * comparer » — et non une valeur à remplacer par un défaut.
 */
export interface OpDemande {
  seq: number
  opKind: string
  subjectKind: NatureSujet
  subjectId: number | null
  /** Nom de propriété Doctrine (§ 1.2). Pour un bloc date, le nom du **bloc** :
   *  `birth`, jamais `birthMin` — un bloc date est un seul op, jamais cinq. */
  field: string
  valueKind: string
  /**
   * **Absente** — pas `null` — quand la fiche visée est une personne vivante hors
   * du cercle de l'auteur (demande déposée par l'assistant, qui peut viser tout
   * l'arbre) : la valeur d'avant ne lui est pas communiquée. `null` veut dire
   * « inconnue », l'absence veut dire « masquée » (§ 1.3).
   */
  oldValue?: ValeurOp
  newValue: ValeurOp
  /** Absente dans le même cas que `oldValue`. */
  baseVersion?: number | null
  /** § 6.7 — administration seulement. Absent ailleurs. */
  valeurCourante?: ValeurOp
  /** § 6.7 — administration seulement. La valeur a-t-elle bougé depuis le dépôt ? */
  aBouge?: boolean
  /** § 6.7 — libellé prêt à afficher, fabriqué par le serveur. On l'affiche. */
  libelleDiff?: string
}

/** § 6.5 — une personne proposée, qui n'a pas encore d'identifiant. */
export interface BrouillonDemande {
  localRef: string
  /** L'identifiant de ce brouillon **dans le graphe** (§ 5.2). Lu, jamais
   *  fabriqué par concaténation. */
  noeudId: string
  givenNames: string | null
  surname: string | null
  marriedName?: string | null
  nickname?: string | null
  sex: Sexe
  deceased: boolean
  birth?: BlocDate | null
  death?: BlocDate | null
  note?: string | null
  resolvedPersonId: number | null
}

/** § 6.3 — un lien proposé. Les dates d'union ne valent que sur `conjoint_de`. */
export interface LienDemande {
  seq: number
  /**
   * `NatureLien` et non `string` : l'énumération `LinkKind` en compte **trois,
   * ni plus ni moins** (§ 9.3 du modèle). Typée `string`, cette clé a laissé
   * passer une quatrième nature inventée — `frere_soeur_de` — dans une table de
   * libellés, et rien ne l'a signalée. Un `Record<NatureLien, string>` rend
   * désormais la table vérifiable : une clé en trop ne compile pas, une clé
   * manquante non plus.
   */
  kind: NatureLien
  subjectDraftRef?: string | null
  subjectPersonId?: number | null
  /**
   * Le sujet, quand c'est une fiche proposée par une **autre demande encore en
   * attente** — la troisième forme d'extrémité (§ 6.3).
   *
   * C'est l'identifiant de la **ligne** `prp_draft_person`. `subjectDraftRef`
   * ne vaut, lui, que dans sa propre demande : `#p1` de la demande 12 et `#p1`
   * de la demande 13 n'ont aucun rapport, et c'est précisément pourquoi une
   * seconde demande ne pouvait pas désigner la fiche de la première.
   */
  subjectDraftPersonId?: number | null
  /**
   * Le sujet du lien, nommé. Pour une fiche de la même demande, le serveur rend
   * son nom suivi de « (proposée) » — il rendait sa **référence**, « Personne
   * proposée #p1 », et c'est ce qui a rendu illisible la parenté d'une branche
   * de vingt-deux personnes : pour savoir de qui un enfant était le fils, il
   * fallait relever `#p1` dans le lien et le retrouver dans les brouillons.
   */
  subjectLabel?: string | null
  otherPersonId?: number | null
  /** Renseigné quand l'autre extrémité est une fiche de la même demande. */
  otherDraftRef?: string | null
  /** Renseigné quand l'autre extrémité est une fiche proposée **ailleurs**. */
  otherDraftPersonId?: number | null
  otherLabel?: string | null
  other2PersonId?: number | null
  /**
   * Le **second parent** d'une filiation, quand c'est une fiche de la même
   * demande. Sans cette clé, les deux cas rendaient la même forme — un
   * `other2Label` seul, dont rien ne disait s'il désignait quelqu'un qui existe.
   */
  other2DraftRef?: string | null
  /** Le second parent, quand c'est une fiche proposée **ailleurs**. */
  other2DraftPersonId?: number | null
  other2Label?: string | null
  familyId?: number | null
  /**
   * Le couple désigné, **nommé**. `familyId` seul affichait « rattaché à la
   * famille 2 », un identifiant de ligne, alors que la base porte le nom des
   * deux époux.
   */
  familyLabel?: string | null
  marriage?: BlocDate | null
  divorced?: boolean | null
  divorce?: BlocDate | null
  siblingOrder?: number | null
  note?: string | null
  resolvedFamilyId?: number | null
  /**
   * **Où cette filiation atterrira**, calculé par le serveur sur l'arbre
   * courant — vue d'administration seulement, et absent quand rien ne se
   * prévoit (un parent encore à créer).
   *
   * Une filiation à un parent ne va plus forcément là où le lien se lit : elle
   * peut compléter la famille que l'enfant a déjà, ou rejoindre l'union unique
   * de son parent. « Enfant de Jean-Marie » qui atterrit chez « Jean-Marie ×
   * Hélène » doit se lire avant le clic — c'est l'absence de ce bloc qui a
   * laissé trois familles de parents à une même personne sans que personne le
   * voie venir.
   */
  filiationPrevue?: FiliationPrevue
}

/** Voir `LienDemande.filiationPrevue`. Les phrases viennent du serveur, telles quelles. */
export interface FiliationPrevue {
  motif:
    | 'deja_rattache'
    | 'couple'
    | 'famille_completee'
    | 'couple_existant'
    | 'couple_forme'
    | 'union_unique'
    | 'parent_seul'
  familleId: number | null
  libelle: string
  /** « Sera rattaché·e au couple « Jean-Marie × Hélène », seule union connue de Jean-Marie. » */
  phrase: string
  /** Vrai quand la destination ne se devine pas du lien, ou qu'une famille est quittée. */
  aSignaler: boolean
  /** Les familles à un seul parent que l'enfant quittera ; `supprimee` si elle part, vide. */
  quittees: { familleId: number; libelle: string; supprimee: boolean }[]
  /** Non nul : la validation sera refusée (deux familles de parents partageant un parent). */
  incompatibilite: string | null
}

/** § 6.5 — ce que la validation a réellement produit. */
export interface RevisionDemande {
  id: number
  subjectKind: NatureSujet
  subjectId: number
  subjectLabel?: string | null
  operation: string
  versionBefore?: number | null
  versionAfter?: number | null
  appliedAt?: string
}

/**
 * § 6.3 ter, § 6.5, § 6.7 — **ce qu'un complément complète** (ADR 0017).
 *
 * Deux formes sous un seul type, et c'est le serveur qui choisit :
 *
 * - la vue de **l'auteur** ne porte que `draftPersonId` — l'identifiant de la
 *   fiche qu'il a lui-même postée, rien de plus : il ne voit pas la proposition
 *   qu'il complète (le plus souvent celle d'un vivant, proposée par un autre) ;
 * - la vue **d'administration** la dit en clair : le numéro de la demande
 *   d'origine, sa référence, son nom et son statut.
 *
 * Les quatre clés de la seconde forme sont donc **facultatives au typage** : un
 * écran qui veut le numéro doit tester qu'il est là, et ne peut pas le
 * reconstruire. C'est la règle de ce fichier — on affiche ce que la charge porte.
 */
export interface ComplementDe {
  draftPersonId: number
  /** Vue d'administration : la demande d'origine. */
  demandeId?: number
  localRef?: string
  displayName?: string
  /** Le statut de la demande **d'origine**, pas celui du complément. */
  status?: StatutDemande
}

/** § 6.5 — la demande telle que son auteur la voit. */
export interface Demande {
  id: number
  kind: NatureDemande
  status: StatutDemande
  authorUserId?: number
  authorLabel: string
  targetKind: NatureSujet | null
  targetId: number | null
  targetLabel: string | null
  message: string | null
  decisionMessage: string | null
  decidedByLabel?: string | null
  decidedAt?: string | null
  /** § 6.8 — l'écriture n'a pas été réexaminée par un second humain. */
  autoValidated: boolean
  duplicateOfId: number | null
  /**
   * § 6.3 ter — `null`, ou la fiche que cette demande **complète** (ADR 0017).
   * Facultatif au typage : une ligne de liste ne le porte pas, elle porte
   * `complementDeDemandeId`.
   */
  complementDe?: ComplementDe | null
  submittedAt: string | null
  createdAt: string
  ops: OpDemande[]
  brouillons: BrouillonDemande[]
  liens: LienDemande[]
  revisions: RevisionDemande[]
  /**
   * § 6.5 — un sha256 de ce que la demande est **maintenant** : statut,
   * `updatedAt`, chaque champ de chaque fiche, chaque lien. Calculée, jamais
   * stockée. Elle se **renvoie** à la complétion, à la fusion et à la validation :
   * si la demande a changé entre l'affichage et le clic, la route rend
   * `409 demande_modifiee_entre_temps` sans rien écrire, plutôt que d'écraser ou
   * de valider ce que personne n'a relu.
   *
   * Facultative au typage : une ligne de liste ne la porte pas — et l'écran qui
   * écrit doit tester qu'il la tient, jamais en fabriquer une.
   */
  empreinte?: string
  /** Sur une ligne de liste (§ 6.7, 2026-10-04) : le nom des personnes que la
   *  demande ajoute à l'arbre, « Prénoms Nom ». Absent du détail. */
  nouvellesPersonnes?: string[]
  /**
   * Sur une ligne de liste (§ 6.5, 10/10/2026) : le **numéro** de la demande que
   * celle-ci complète, ou `null`. Un numéro, jamais un nom. Absent du détail, qui
   * porte `complementDe`.
   */
  complementDeDemandeId?: number | null
}

/**
 * § 6.7 — ce que la validation fera au clic, calculé en lecture seule.
 *
 * Sans ce champ, l'administrateur découvre la caducité **en cliquant**, ce qui
 * la rend indistinguable d'une panne.
 */
export type ResolutionPrevue =
  | 'application'
  | 'rejeu'
  | 'caducite'
  /**
   * La ligne visée n'existe plus. **Elle manquait à ce type** alors que le
   * serveur la rend depuis toujours : `actionValidation()` discrimine sur cette
   * union, et une valeur absente n'y tombait dans aucun cas — la fonction
   * rendait `undefined`, et l'écran de décision plantait sur la seule demande
   * qu'il fallait surtout ne pas valider.
   */
  | 'cible_disparue'
  /**
   * Cette demande s'appuie sur une personne proposée par **une autre demande
   * encore en attente** : il y a un ordre à respecter, pas une demande à
   * condamner. Elle reste dans la file et se validera après l'autre.
   */
  | 'dependance_en_attente'
  /**
   * La demande sur laquelle celle-ci s'appuyait a été refusée, retirée ou rendue
   * caduque : la personne désignée n'existera jamais. C'est une caducité.
   */
  | 'dependance_morte'
  /**
   * Des **compléments** attendent sur cette proposition (§ 6.3 ter, ADR 0017).
   * La valider maintenant créerait la personne sans eux, et chacun deviendrait
   * une seconde création de la même personne. Un ordre, pas une mort : elle se
   * validera telle quelle une fois les compléments fusionnés ou refusés.
   */
  | 'complements_en_attente'
  /**
   * Cette demande **est** un complément, et son origine vit encore : en attente
   * — le geste est la fusion —, ou validée — la valider doublerait la personne,
   * il reste le refus.
   */
  | 'complement_a_fusionner'

/**
 * § 6.7 — une fiche proposée par une autre demande que celle-ci désigne, et
 * l'état de cette autre demande.
 *
 * Quatre états, et ils ne se déduisent pas l'un de l'autre : `resolue` (l'autre
 * demande est validée, la personne existe), `en_attente` (on attend),
 * `morte` (refusée, retirée ou caduque), `disparue` (la fiche n'est plus là).
 * Les deux derniers condamnent la demande ; le deuxième la fait attendre ; le
 * premier n'est qu'un contexte utile à qui décide.
 */
export interface DependanceDeDemande {
  draftPersonId: number
  /** `null` quand la fiche a disparu avec sa demande. */
  demandeId: number | null
  /** Le nom proposé. Vide quand la fiche n'est plus là pour être relue. */
  libelle: string
  etat: 'resolue' | 'en_attente' | 'morte' | 'disparue'
}

export interface CibleDemande {
  version: number
  versionALaSoumission: number
  aChangeDepuisSoumission: boolean
}

/** § 6.7 — une autre demande `en_attente` de même `dedup_key` (§ 9.5). Aucun
 *  `personId` : la personne proposée n'existe pas encore. */
export interface DoublonProbable {
  id: number
  authorLabel: string
  libelle: string
  dedupKey: string
}

/** § 6.7 — une autre demande en attente sur la même cible. */
export interface DemandeConcurrente {
  id: number
  authorLabel: string
  kind: NatureDemande
  submittedAt: string
  champs: string[]
}

/**
 * § 6.7 — la demande vue par l'administration : le § 6.5 plus les trois blocs
 * sans lesquels l'écran de décision ne peut pas exister.
 *
 * Tous les ajouts sont **facultatifs au typage**, et c'est délibéré : la vue de
 * détail reçoit l'une ou l'autre charge et ne doit pas supposer la seconde. Le
 * typeur force alors à tester la présence plutôt que le rôle du lecteur.
 */
export interface DemandeAdministration extends Demande {
  cible?: CibleDemande
  resolutionPrevue?: ResolutionPrevue
  /** **Toujours un tableau** sur la route d'administration, vide quand la
   *  demande ne s'appuie sur personne (§ 6.7). Facultatif au typage comme les
   *  trois autres blocs : la vue de l'auteur ne le porte pas. */
  dependances?: DependanceDeDemande[]
  demandesConcurrentes?: DemandeConcurrente[]
  doublonsProbables?: DoublonProbable[]
  /**
   * § 6.7 (10/10/2026) — les **compléments** de cette proposition, hors
   * brouillons, dans l'ordre de soumission, tranchés compris : un complément
   * fusionné reste dans l'historique de l'origine, avec son statut. Toujours un
   * tableau sur la route d'administration ; absent de la vue de l'auteur.
   */
  complements?: ComplementDeDemande[]
}

/* ------------------------------------------------------------------ */
/* La fusion d'un complément — § 6.7 bis                               */
/* ------------------------------------------------------------------ */

/** Les champs d'une fiche proposée, dans l'ordre du serveur (`CHAMPS_DE_FICHE`). */
export type ChampDeFiche =
  | 'givenNames'
  | 'surname'
  | 'marriedName'
  | 'nickname'
  | 'sex'
  | 'deceased'
  | 'birth'
  | 'death'
  | 'note'

/**
 * Une valeur **affichable** du rapport : un texte, un booléen, le sexe en
 * valeur du modèle, ou un bloc date **avec `texte`** — six clés, c'est une
 * sortie, jamais reposté.
 */
export type ValeurDeFusion = string | boolean | null | BlocDate

/** Un champ vide de l'origine que le complément remplit. */
export interface ChampRempli {
  champ: ChampDeFiche
  valeur: ValeurDeFusion
}

/** Laquelle des deux valeurs l'emporte. L'origine, sauf choix contraire. */
export type CoteRetenu = 'origine' | 'complement'

/**
 * Un champ où l'origine et le complément divergent. `deceased` et `note` n'en
 * sont jamais : un décès ne se contredit pas, deux notes s'ajoutent.
 */
export interface ConflitDeFusion {
  champ: ChampDeFiche
  origine: ValeurDeFusion
  complement: ValeurDeFusion
  retenu: CoteRetenu
}

/** Pourquoi un lien du complément ne passe pas dans l'origine. */
export type RaisonLienIgnore = 'deja_present' | 'fiche_proposee_ailleurs' | 'meme_personne'

/**
 * § 6.7 bis — ce que la fusion fait, ou ferait : le même rapport, calculé à blanc
 * pour l'aperçu et rendu par la fusion elle-même.
 *
 * Les liens ne sont désignés que par leur **rang** (`seqComplement` dans le
 * complément, `seq` dans l'origine) : c'est la demande du complément qui les
 * nomme, et l'écran la lit pour les mettre en mots.
 */
export interface RapportDeFusion {
  remplis: ChampRempli[]
  conflits: ConflitDeFusion[]
  liensAjoutes: { seqComplement: number; seq: number }[]
  liensIgnores: { seqComplement: number; raison: RaisonLienIgnore }[]
  mediasDeplaces: number
  /** Les demandes non tranchées qui citaient la fiche du complément. */
  demandesRepointees: number[]
}

/**
 * § 6.7 — un complément tel que la vue de son origine le montre.
 *
 * `apercu` est `null` quand la fusion n'est plus possible — complément ou
 * origine tranchés. `empreinte` est celle **du complément**, à renvoyer en
 * `empreinteComplement`.
 */
export interface ComplementDeDemande {
  id: number
  authorLabel: string
  submittedAt: string | null
  status: StatutDemande
  /** La fiche du complément. */
  draftPersonId: number | null
  /** La fiche de l'origine qu'il complète — une origine peut en porter plusieurs. */
  origineDraftPersonId: number | null
  displayName: string | null
  ligneDates: string | null
  apercu: RapportDeFusion | null
  empreinte: string
}

/** `GET …/fusionner/{complementId}` — l'aperçu, sans rien écrire. */
export interface ApercuDeFusion {
  origine: { id: number; empreinte: string }
  complement: { id: number; empreinte: string }
  rapport: RapportDeFusion
}

/**
 * Le corps de la fusion. Les trois clés sont facultatives — et posées clé par
 * clé (`exactOptionalPropertyTypes`).
 *
 * `retenir` ne nomme **que des champs en conflit** : un autre rend `422
 * retenir.<champ>`, plutôt que de laisser croire qu'on a tranché quelque chose
 * que la fusion ne ferait pas.
 */
export interface CorpsFusion {
  retenir?: Partial<Record<ChampDeFiche, CoteRetenu>>
  empreinte?: string
  empreinteComplement?: string
}

/** `POST …/fusionner/{complementId}` — l'origine relue, la ligne du complément clos, le rapport. */
export interface ResultatFusion {
  origine: DemandeAdministration
  complement: Demande
  rapport: RapportDeFusion
}

/** § 6.8 — le détail d'un rejeu automatique. */
export interface DetailRejeu {
  motif: string
  versionALaSoumission: number
  versionAppliquee: number
  champsVerifies: string[]
}

/**
 * § 6.8 — les deux issues **en succès** de la validation.
 *
 * `rejouee` est un succès, et le front **doit le dire** : le verrou s'était
 * déclenché, toutes les anciennes valeurs correspondaient encore, la demande a
 * été rejouée sur la version courante. C'est tracé au journal précisément parce
 * qu'« une écriture qu'aucun humain n'a réexaminée doit se voir » (§ 2.7).
 * Afficher « validée » tout court effacerait la distinction.
 */
export interface ResultatValidation {
  resultat: 'validee' | 'rejouee'
  demande: Demande
  revisions: RevisionDemande[]
  rejeu?: DetailRejeu
  /** Nouvelle empreinte de l'arbre : l'`ETag` du graphe est périmé. */
  empreinte?: string
}

/* ------------------------------------------------------------------ */
/* Les routes                                                          */
/* ------------------------------------------------------------------ */

/**
 * Une demande telle qu'elle sort d'une **liste** — et non d'un détail.
 *
 * Douze clés, mesurées sur l'API : le résumé ne porte ni les ops, ni les
 * brouillons, ni les liens, ni les révisions. **Il ne porte pas non plus
 * `decisionMessage`**, et c'est le piège de cet écran : la liste sait dire
 * « refusée », elle ne sait pas dire pourquoi. Le motif vit sur
 * `GET /api/demandes/{id}` — d'où la seconde lecture que fait `MesDemandesView`
 * sur les seules demandes refusées, et rien qu'elles.
 *
 * Un type distinct de `Demande` plutôt qu'un `Partial<Demande>` : `Partial`
 * rendrait facultatives les douze clés qui sont bel et bien là, et l'écran
 * testerait la présence de `status`. Ce qui manque manque ; ce qui est là est
 * garanti.
 */
export interface ResumeDemande {
  id: number
  kind: NatureDemande
  status: StatutDemande
  authorUserId: number
  authorLabel: string
  targetKind: NatureSujet | null
  targetId: number | null
  targetLabel: string | null
  /** Le mot de **l'auteur** au dépôt. Jamais celui de l'administrateur : ce
   *  dernier est `decisionMessage`, et il n'est pas dans cette charge. */
  message: string | null
  autoValidated: boolean
  submittedAt: string | null
  createdAt: string
  /**
   * La demande que celle-ci **complète** (§ 6.3 ter, ADR 0017), ou `null` : son
   * numéro, jamais un nom — l'auteur d'un complément ne voit pas la proposition
   * qu'il complète, mais son numéro ne lui apprend rien d'elle, et c'est celui
   * que la fusion lui redonnera (`duplicateOfId`).
   */
  complementDeDemandeId: number | null
}

/**
 * `GET /api/demandes` — **les demandes du lecteur, et rien d'autre**.
 *
 * Le serveur filtre sur l'auteur ; il n'y a aucun paramètre pour demander celles
 * de quelqu'un d'autre, et c'est pour cela que cette route n'en prend pas. Sous
 * la bascule « voir comme un membre », c'est l'identité **empruntée** qui filtre
 * — un administrateur y voit les demandes du membre emprunté, pas les siennes.
 *
 * Les demandes `refusee` et `caduque` **restent dans la liste** : c'est tout
 * l'objet de l'écran. « Une caducité sans ce bouton est une contribution
 * perdue » vaut aussi pour un refus dont l'auteur n'a jamais lu le motif.
 *
 * Tri : `createdAt` **décroissant** — l'inverse de la file d'administration, et
 * pour la raison inverse : une file se prend par le début, sa propre liste se
 * lit par ce qu'on vient de déposer.
 *
 * Un `status` inconnu est **silencieusement ignoré** par le serveur (mesuré :
 * `200`, aucun filtre) plutôt que refusé. On ne lui en envoie donc jamais
 * d'autre que ceux du modèle.
 */
export function listerDemandes(
  options: {
    status?: StatutDemande | undefined
    page?: number | undefined
    parPage?: number | undefined
  } = {},
): Promise<ListePaginee<ResumeDemande>> {
  return requeteApi<ListePaginee<ResumeDemande>>('/demandes', {
    requete: {
      status: options.status,
      page: options.page,
      parPage: options.parPage,
    },
  })
}

/** `GET /api/demandes/{id}` — la vue de l'auteur (§ 6.5). */
export function lireDemande(id: number): Promise<Demande> {
  return requeteApi<Demande>(`/demandes/${id}`)
}

/**
 * `GET /api/administration/demandes` — la file (§ 6.7).
 *
 * Triée par `submittedAt` **croissant** côté serveur : la plus ancienne en
 * premier, parce qu'une file se prend par le début. `tri` la range par numéro.
 * Paginée : 100 par page au plus.
 */
/** Un auteur qui a réellement déposé quelque chose, pour peupler le filtre. */
export interface AuteurDeLaFile {
  userId: number
  libelle: string
}

export function listerDemandesAdministration(options: {
  status?: StatutDemande | undefined
  /** Une nature, ou plusieurs séparées par des virgules (les onglets de la file). */
  kind?: NatureDemande | string | undefined
  targetId?: number | undefined
  auteur?: number | undefined
  /** Un numéro de demande (`42`, `#42`, `n° 42`), ou un texte : fiche, auteur, message, personnes proposées. */
  q?: string | undefined
  /** Par numéro, croissant (`anciennes`) ou décroissant (`recentes`) — sur toute la file, pas sur la page. */
  tri?: 'anciennes' | 'recentes' | undefined
  page?: number | undefined
  parPage?: number | undefined
}): Promise<ListePaginee<Demande>> {
  return requeteApi<ListePaginee<Demande>>('/administration/demandes', {
    requete: {
      status: options.status,
      kind: options.kind,
      targetId: options.targetId,
      auteur: options.auteur,
      q: options.q === '' ? undefined : options.q,
      tri: options.tri,
      page: options.page,
      parPage: options.parPage,
    },
  })
}

/**
 * `GET /api/administration/demandes/auteurs` — qui a déposé quelque chose.
 *
 * Une route à part, et non une clé de plus sur la file : le § 1.5 fixe
 * l'enveloppe d'une liste paginée à quatre clés, et un test la tient « sans une
 * clé de plus ».
 */
export function listerAuteursDesDemandes(): Promise<{ elements: readonly AuteurDeLaFile[] }> {
  return requeteApi<{ elements: readonly AuteurDeLaFile[] }>('/administration/demandes/auteurs')
}

/** `GET /api/administration/demandes/{id}` — le § 6.5 plus de quoi décider. */
export function lireDemandeAdministration(id: number): Promise<DemandeAdministration> {
  return requeteApi<DemandeAdministration>(`/administration/demandes/${id}`)
}

/**
 * `POST /api/administration/demandes/{id}/valider` — corps vide, ou
 * `{empreinte}` (10/10/2026).
 *
 * Six issues (§ 6.8), dont **deux `409` aux conseils opposés** :
 * `verrou_concurrent` (rien n'a été écrit, réessayer) et `demande_caduque` (la
 * demande est morte, ne pas réessayer). Elles ne sont **pas** retriées ici : le
 * socle les distingue dans `erreurs.ts`, et un écran qui les retraiterait
 * finirait par en oublier une.
 *
 * **`empreinte`** est celle de la demande **telle qu'on l'a lue** (§ 6.5) : une
 * demande en attente n'est plus figée — son auteur peut la compléter pendant
 * qu'on la relit (ADR 0017). Périmée, la route rend `409
 * demande_modifiee_entre_temps` sans rien écrire, et l'écran relit au lieu de
 * valider ce que personne n'a vu. Sans empreinte, elle valide comme avant.
 */
export function validerDemande(id: number, empreinte?: string): Promise<ResultatValidation> {
  const options: OptionsRequete = { methode: 'POST' }
  if (empreinte !== undefined) options.corps = { empreinte }
  return requeteApi<ResultatValidation>(`/administration/demandes/${id}/valider`, options)
}

/**
 * `GET /api/administration/demandes/{id}/fusionner/{complementId}` — **ce que la
 * fusion ferait, sans rien écrire** (§ 6.7 bis).
 *
 * Le même code que la fusion, à blanc : l'administrateur lit les champs remplis,
 * les conflits à trancher et les liens repris **avant** de cliquer. La vue de
 * l'origine porte déjà cet aperçu (`complements[].apercu`) ; cette route sert
 * l'écran **du complément**, qui ne l'a pas.
 */
export function apercuFusion(id: number, complementId: number): Promise<ApercuDeFusion> {
  return requeteApi<ApercuDeFusion>(`/administration/demandes/${id}/fusionner/${complementId}`)
}

/**
 * `POST /api/administration/demandes/{id}/fusionner/{complementId}` — **la
 * fusion**, en une transaction (§ 6.7 bis).
 *
 * L'origine l'emporte, le complément remplit ce qui est vide ; un conflit se
 * tranche pour l'origine sauf `retenir[champ] = 'complement'`. Les liens passent
 * (sauf les doublons), les pièces suivent, et le complément est clos — « Fusionné
 * dans la demande n° O. », jamais un refus sec.
 *
 * Les deux empreintes protègent ce qu'on a lu : l'auteur de l'origine, ou celui
 * du complément, a pu compléter sa demande entre l'aperçu et le clic.
 */
export function fusionnerComplement(id: number, complementId: number, corps: CorpsFusion = {}): Promise<ResultatFusion> {
  return requeteApi<ResultatFusion>(`/administration/demandes/${id}/fusionner/${complementId}`, {
    methode: 'POST',
    corps,
  })
}

/**
 * `POST /api/administration/demandes/{id}/refuser` — **message obligatoire**.
 *
 * Ce n'est pas une validation de formulaire : la contrainte
 * `ck_prp_request_motif_refus` refuse la ligne en base (§ 5.1). Le front ne doit
 * pas être le seul à la connaître, et l'API ne compte pas sur le front. Le
 * message part dans la notification de l'auteur, qui garde la demande refusée
 * consultable, avec son motif.
 *
 * `doublonDeId` transforme un refus sec en rapprochement : l'auteur lit « votre
 * proposition rejoint la demande n° 11 » (§ 6.4 du dossier).
 */
export function refuserDemande(
  id: number,
  decision: { message: string; doublonDeId?: number | undefined },
): Promise<Demande> {
  return requeteApi<Demande>(`/administration/demandes/${id}/refuser`, {
    methode: 'POST',
    corps: {
      message: decision.message,
      doublonDeId: decision.doublonDeId,
    },
  })
}

/**
 * `GET /api/demandes/{id}/refaire` — **lecture seule, aucune écriture**.
 *
 * Rend un corps prêt à poster, rafraîchi sur les valeurs courantes. C'est ce que
 * recouvre « refaire cette demande » : « une caducité sans ce bouton est une
 * contribution perdue, et un membre dont la première contribution se perd n'en
 * dépose pas de seconde » (§ 9.6).
 */
export function lireRepriseDemande(id: number): Promise<Record<string, unknown>> {
  return requeteApi<Record<string, unknown>>(`/demandes/${id}/refaire`)
}

/* ================================================================== */
/* Le dépôt d'une demande — § 6.1 à 6.4                                */
/* ================================================================== */

/*
 * Ce qui précède **lit** des demandes ; ce qui suit en **dépose**. Les deux
 * vivent dans le même fichier parce que le contrat n'a qu'un mécanisme : « une
 * seule route, un discriminant `kind` qui sélectionne la forme du reste. Deux
 * routes seraient deux écrans, deux formats de diff, deux chemins de
 * notification, et deux endroits où oublier le message obligatoire au refus »
 * (§ 6.1, ADR 0004). Séparer le dépôt de la lecture rouvrirait la même porte un
 * cran plus bas.
 */

/**
 * Un bloc date **tel qu'il part dans une requête** : cinq clés, toujours les
 * cinq, `texte` jamais.
 *
 * **C'est `BlocDateOp` de `types.ts`, sous le nom qu'emploient les demandes.**
 * Un alias, pas une seconde déclaration : les deux avaient été écrites à la
 * main, à quelques heures d'intervalle, avec la même liste de clés — et deux
 * listes que rien n'oblige à coïncider finissent par diverger. La forme est
 * figée « au caractère près » par le § 14.1 ; il ne doit donc en exister
 * qu'**une** définition, et le § 14 en est le propriétaire.
 *
 * Ce qui reste vrai et vaut d'être relu ici : `BlocDate` — la forme **reçue** —
 * porte une sixième clé, `texte`. « Un back qui accepte `texte` en entrée
 * accepte une sixième clé que la comparaison `JSONB` de la détection de conflit
 * fera diverger » : un bloc lu sur une fiche passe donc par `sansTexte()` avant
 * d'être reposté.
 */
export type ValeurDate = BlocDateOp

/** Ce qu'un op de champ peut transporter. Un bloc date en fait partie : il est
 *  **un seul op**, jamais cinq (§ 6.2). */
export type ValeurProposee = string | number | boolean | null | ValeurDate

/** § 5.7 du modèle — `LinkKind`, « les trois natures du § 6.4 du dossier, ni
 *  plus ni moins ». Elles se lisent **du sujet vers l'autre** : `parent_de`
 *  désigne les *enfants* du sujet, `enfant_de` ses *parents*. */
export type NatureLien = 'enfant_de' | 'parent_de' | 'conjoint_de'

/**
 * Un op proposé (§ 6.2).
 *
 * `baseVersion` et `oldValue` ne font pas double emploi, et les confondre coûte
 * cher :
 *
 * - **`baseVersion`** est le `version` que la fiche portait **quand l'auteur l'a
 *   ouverte**. C'est le verrou optimiste inter-requêtes : « sans lui, une
 *   demande déposée lundi et validée vendredi écrase le travail de mardi sans
 *   que rien ne le remarque ».
 * - **`oldValue`** est la valeur courante au moment du dépôt. C'est **elle**, et
 *   non `baseVersion`, qui tranche plus tard entre rejeu automatique et
 *   caducité (§ 9.6).
 */
export interface OpProposee {
  seq: number
  opKind: 'champ'
  subjectKind: NatureSujet
  subjectId: number
  /** Nom de propriété Doctrine, borné par la liste blanche du § 6.2. Pour un
   *  bloc date, le nom du **bloc** : `birth`, jamais `birthMin`. */
  field: string
  valueKind: string
  oldValue: ValeurProposee
  newValue: ValeurProposee
  baseVersion: number
}

/** § 6.3 — la personne proposée. Elle n'a pas d'identifiant : elle vit sous une
 *  référence provisoire `#p<n>`, unique dans la demande. */
export interface BrouillonPropose {
  localRef: string
  givenNames: string | null
  surname: string | null
  marriedName: string | null
  nickname: string | null
  sex: Sexe
  deceased: boolean
  birth: ValeurDate | null
  death: ValeurDate | null
  note: string | null
}

/**
 * § 6.3 — un lien proposé.
 *
 * Les exclusions sont des `CHECK` en base, pas des conventions : `familyId` et
 * `other2PersonId` ne valent que sur `enfant_de` ; `marriage`, `divorce` et
 * `divorced` ne valent que sur `conjoint_de` ; et chaque extrémité est **soit**
 * un identifiant réel, **soit** une référence de brouillon, jamais les deux.
 * Un corps qui les enfreint rend `422` — le constructeur de
 * `components/proposition/creation.ts` les fait respecter à la source.
 */
export interface LienPropose {
  seq: number
  kind: NatureLien
  subjectDraftRef?: string
  subjectPersonId?: number
  /**
   * Le sujet est une fiche proposée par **une autre demande en attente**.
   *
   * C'est ce qui permet de proposer un père un jour et son fils le lendemain :
   * la fiche du père n'a pas encore d'identifiant de personne, mais sa **ligne**
   * en a un, et c'est celui-là. Le serveur vérifie au dépôt que le lecteur a le
   * droit de voir cette fiche et que sa demande est encore en attente — et si
   * elle vient d'être validée, il résout la référence en personne réelle plutôt
   * que de refuser.
   */
  subjectDraftPersonId?: number
  otherPersonId?: number
  /** L'autre extrémité est une fiche proposée par une autre demande en attente. */
  otherDraftPersonId?: number
  /** Le **second parent**, sur `enfant_de` seulement. */
  other2PersonId?: number
  /** Le second parent, quand c'est une fiche proposée ailleurs. */
  other2DraftPersonId?: number
  /** Un couple existant, sur `enfant_de` seulement. Exclusif avec `other*`. */
  familyId?: number
  marriage?: ValeurDate | null
  divorced?: boolean
  divorce?: ValeurDate | null
  /** Rang dans la fratrie quand le demandeur le connaît. Absent = à la fin. */
  siblingOrder?: number
  note?: string
}

/**
 * § 6.10 — d'où vient une demande refaite.
 *
 * **Sa seule fonction est de déclencher le transfert des médias** : un média
 * joint à une personne proposée porte `subject_request_id`, et une reprise crée
 * une demande neuve avec un nouvel identifiant. Sans cette clé, les pièces
 * jointes de la demande d'origine resteraient accrochées à une demande morte.
 * « C'est pour cela qu'il faut le renvoyer. »
 */
export interface OrigineReprise {
  demandeId: number
  statut: StatutDemande
}

interface CorpsCommun {
  /** Le mot du demandeur. Facultatif. */
  message?: string
  /** `true` ⇒ la demande naît `en_attente` et l'administrateur est notifié.
   *  `false` ⇒ `brouillon`, visible de son seul auteur, absent de la file. */
  soumettre: boolean
  origine?: OrigineReprise
}

/** § 6.2 — modifier une fiche existante. */
export interface CorpsModificationPersonne extends CorpsCommun {
  kind: 'modification_personne'
  targetKind: 'personne'
  targetId: number
  ops: OpProposee[]
}

/** § 6.2 — modifier une union existante : date et lieu du mariage, divorce. */
export interface CorpsModificationFamille extends CorpsCommun {
  kind: 'modification_famille'
  targetKind: 'famille'
  targetId: number
  ops: OpProposee[]
}

/** § 6.3 — proposer une personne et ses liens. La demande ne décrit **aucune
 *  famille** : « le service en déduit les familles à créer au moment de la
 *  validation ». Un `familleRef` serait refusé en `422`. */
export interface CorpsCreationPersonne extends CorpsCommun {
  kind: 'creation_personne'
  brouillons: BrouillonPropose[]
  liens: LienPropose[]
  /**
   * § 6.3 ter — la fiche proposée **par un autre membre** que cette création
   * complète : le `draftPersonId` de la ressemblance, y compris sous sa forme
   * discrète. L'administrateur fusionnera le complément dans l'origine.
   *
   * Un seul brouillon, au moins un lien à la soumission — le complément doit
   * rester validable seul, l'origine pouvant mourir avant la fusion. Sa propre
   * proposition rend `422` (elle se complète sur place) ; une origine tranchée,
   * `409 proposition_plus_en_attente`.
   */
  complementDe?: number
}

/**
 * § 6.6 ter — la fiche d'une proposition **telle qu'elle doit être** après la
 * complétion. Les champs d'un brouillon du § 6.3, blocs date à cinq clés.
 *
 * **Elle remplace tous les champs** : une clé absente vaut `null`, comme au
 * dépôt. La complétion ne rapièce pas — un client qui veut garder une valeur la
 * renvoie. `localRef` est toléré s'il désigne la fiche de l'adresse.
 */
export interface FicheACompleter {
  localRef?: string
  givenNames: string | null
  surname: string | null
  marriedName: string | null
  nickname: string | null
  sex: Sexe
  deceased: boolean
  birth: ValeurDate | null
  death: ValeurDate | null
  note: string | null
}

/** § 6.6 ter — un lien à **ajouter** : la forme du § 6.3, **sans `seq`** — le
 *  serveur l'attribue à la suite du plus grand, et un `seq` envoyé rend `422`. */
export type LienAAjouter = Omit<LienPropose, 'seq'>

/**
 * § 6.6 ter — le corps de `PUT /api/demandes/{id}/brouillons/{ref}`.
 *
 * `liens` **ne fait qu'ajouter** : retirer un lien, c'est une autre proposition.
 * `empreinte` (facultative) protège ce que le lecteur a vu.
 */
export interface CorpsMiseAJourBrouillon {
  fiche: FicheACompleter
  liens?: LienAAjouter[]
  empreinte?: string
}

/**
 * `PUT /api/demandes/{id}/brouillons/{ref}` — **compléter sur place** une fiche
 * proposée (§ 6.6 ter, ADR 0017).
 *
 * `ref` est la référence **sans le dièse** (`p1`), comme la lecture du nœud
 * (§ 6.6) : `#` est le séparateur de fragment d'une URL. On la reçoit telle que le
 * serveur l'a rendue — `brouillonRef` d'un nœud, ou `localRef` d'une demande, dont
 * on retire le seul dièse de tête.
 *
 * Qui peut : l'auteur (brouillon ou en attente), l'administrateur (en attente
 * seulement — un brouillon est le travail en cours de son auteur). La fiche est
 * réécrite **en place**, jamais recréée : d'autres demandes la citent par sa
 * ligne. La réponse est la demande entière vue par son auteur, avec sa **nouvelle
 * empreinte** — de quoi enchaîner sans relire.
 *
 * Erreurs : `403`, `404`, `409 demande_deja_decidee`, `409
 * demande_modifiee_entre_temps` (relire), `409 vue_comme_membre_active`, `422`.
 */
export function mettreAJourBrouillon(demandeId: number, ref: string, corps: CorpsMiseAJourBrouillon): Promise<Demande> {
  const sansDiese = ref.startsWith('#') ? ref.slice(1) : ref
  return requeteApi<Demande>(`/demandes/${demandeId}/brouillons/${encodeURIComponent(sansDiese)}`, {
    methode: 'PUT',
    corps,
  })
}

/**
 * § 6.4 bis — **rattacher des personnes qui existent déjà**.
 *
 * C'est la nature `ajout_lien` : « ils étaient frère et sœur », « elle s'est
 * remariée avec lui ». Elle ne crée **aucune fiche** — `brouillons` y est refusé
 * en `422` — et ne porte que des liens.
 *
 * `targetId` n'est pas décoratif. Sans lui, le serveur prend pour cible le sujet
 * du premier lien, ce qui se trompe précisément sur le geste le plus courant de
 * cet écran : « ajouter un frère à Untel » s'écrit « ce frère est enfant du
 * couple de ses parents », dont le sujet est **le frère**. La demande
 * apparaîtrait alors dans la file sur la fiche du frère, alors que le
 * contributeur travaillait sur celle d'Untel. La cible annoncée doit être une
 * des personnes que les liens désignent, sinon `422` : c'est ce qui l'empêche
 * d'être un champ libre.
 */
export interface CorpsAjoutLien extends CorpsCommun {
  kind: 'ajout_lien'
  targetKind: 'personne'
  targetId: number
  liens: LienPropose[]
}

/**
 * § 6.4 quater — supprimer une fiche.
 *
 * **`message` est obligatoire ici**, et c'est la seule nature où il l'est. Il ne
 * peut pas être demandé plus tard : `POST /api/demandes/{id}/valider` ne lit
 * aucun corps de requête, une validation ne laisse donc aucune trace écrite de
 * sa raison — seul un refus en porte une. Le motif vit sur la demande, déposé à
 * la création, ou il n'existe nulle part.
 *
 * Le corps ne porte **aucun `ops`** : la demande dit ce qu'elle vise, et c'est le
 * serveur qui mesure ce que la suppression emporte. Un inventaire fabriqué par le
 * front serait une seconde source de vérité pour une opération qui détruit.
 */
export interface CorpsSuppressionPersonne extends CorpsCommun {
  kind: 'suppression_personne'
  targetKind: 'personne'
  targetId: number
  /** Obligatoire, contrairement au `message` de `CorpsCommun`. Le type le dit. */
  message: string
}

/**
 * Un lien à défaire (§ 6.4 quinquies).
 *
 * `enfant_de` : `personId` est l'enfant, `familyId` sa famille de parents, et
 * `parentId` — facultatif — **le** parent qu'il perd ; sans lui, il perd les deux.
 * `conjoint_de` : `personId` est l'époux qui **quitte** l'union `familyId`.
 * `parent_de` n'existe pas ici : le serveur le refuse, on le lit depuis l'enfant.
 */
export type RetraitPropose =
  | { kind: 'enfant_de'; personId: number; familyId: number; parentId?: number }
  | { kind: 'conjoint_de'; personId: number; familyId: number }

/**
 * § 6.4 quinquies — **défaire des liens de parenté**, plusieurs en une demande.
 *
 * Le corps ne porte aucun `ops` : la demande dit ce qu'elle vise, le serveur
 * mesure ce que chaque retrait fera et l'écrit dans l'op qu'il fabrique. Un lien
 * qui n'existe pas, le seul époux d'une union qui porte des enfants, un couple
 * restant qui en doublerait un autre rendent `422` sur `retraits[i]`.
 */
export interface CorpsRetraitLien extends CorpsCommun {
  kind: 'retrait_lien'
  targetKind: 'personne'
  targetId: number
  retraits: RetraitPropose[]
}

/**
 * § 6.4 — joindre un document (portrait, preuve) à une fiche. Le média est
 * **d'abord téléversé** (`POST /api/medias`, il naît `en_attente`), puis cette
 * demande le désigne ; sans elle, il reste orphelin, invisible de la file des
 * demandes. `sha256` est celui que la réponse du téléversement a rendu.
 */
export interface CorpsAjoutMedia extends CorpsCommun {
  kind: 'ajout_media'
  targetKind: 'personne'
  targetId: number
  ops: {
    seq: number
    opKind: 'ajout_media'
    subjectKind: 'personne'
    subjectId: number
    field: null
    valueKind: 'media'
    oldValue: null
    newValue: { mediaId: number; kind: string; caption: string | null; sha256: string }
  }[]
}

export type CorpsDemande =
  | CorpsAjoutMedia
  | CorpsModificationPersonne
  | CorpsModificationFamille
  | CorpsCreationPersonne
  | CorpsAjoutLien
  | CorpsSuppressionPersonne
  | CorpsRetraitLien

/**
 * `POST /api/demandes` — **réservé aux membres**.
 *
 * Un compte `en_attente` reçoit `403 regime_en_attente` : « le défaut fermé vaut
 * aussi en écriture » (§ 6.2 du dossier). Un administrateur sous la bascule
 * « voir comme un membre » reçoit `409 vue_comme_membre_active` — le front
 * éteint le bouton par honnêteté, l'API ne compte pas dessus.
 *
 * Une demande déposée par un administrateur **n'est pas auto-validée** : elle
 * entre dans la file comme les autres (§ 6.1).
 */
export function creerDemande(corps: CorpsDemande): Promise<Demande> {
  return requeteApi<Demande>('/demandes', { methode: 'POST', corps })
}

/**
 * La demande `ajout_media` d'un média qu'on vient de téléverser sur une fiche, soumise
 * d'emblée : c'est elle qui l'amène dans la file des demandes de l'administration.
 * Rien à faire pour un média qui n'attend pas (dépôt direct d'un administrateur, déjà
 * actif) ou qui est déjà porté par une demande.
 */
export async function proposerLeMedia(
  media: { id: number; kind: string; status: string; caption: string | null; sha256?: string; changeRequestId: number | null },
  personId: number,
): Promise<Demande | null> {
  if (media.status !== 'en_attente' || media.changeRequestId !== null || media.sha256 === undefined) return null
  return creerDemande({
    kind: 'ajout_media',
    soumettre: true,
    targetKind: 'personne',
    targetId: personId,
    ops: [
      {
        seq: 1,
        opKind: 'ajout_media',
        subjectKind: 'personne',
        subjectId: personId,
        field: null,
        valueKind: 'media',
        oldValue: null,
        newValue: { mediaId: media.id, kind: media.kind, caption: media.caption, sha256: media.sha256 },
      },
    ],
  })
}

/* ------------------------------------------------------------------ */
/* Chercher parmi les personnes proposées — § 6.6 bis                  */
/* ------------------------------------------------------------------ */

/**
 * Une personne **proposée et non encore validée**, telle que la recherche la
 * rend.
 *
 * Elle n'a **pas de `personId`**, et c'est tout le problème qu'elle résout :
 * `GET /api/arbre/recherche` ne cherche que dans l'arbre validé, si bien qu'une
 * fiche déposée la veille n'était offerte à personne — pas même à son auteur.
 * Ce qu'on reposte pour la désigner est `draftPersonId`, l'identifiant de sa
 * **ligne** ; `localRef` ne vaut que dans sa demande.
 */
export interface BrouillonTrouve {
  draftPersonId: number
  demandeId: number
  localRef: string
  /** L'identifiant de ce nœud **dans le graphe**. Lu, jamais fabriqué. */
  noeudId: string
  displayName: string
  sex: Sexe
  deceased: boolean
  /** « vers 1889 – 1972 », composée par le serveur. `null` si rien n'est connu. */
  ligneDates: string | null
  /** Qui l'a proposée. Un administrateur en voit d'autres que les siennes. */
  authorLabel: string
  /** `true` pour une demande envoyée, `false` pour un brouillon du lecteur pas
   *  encore envoyé. Lu dans la charge, pas déduit de l'URL. */
  enAttente: boolean
}

/**
 * `GET /api/demandes/brouillons/recherche` — **les propositions du lecteur**, et
 * celles de tout le monde pour un administrateur (§ 13.3).
 *
 * Pas de `total`, comme au § 5.5 : « une recherche qui se pagine est une
 * énumération déguisée », et un compte avant filtrage dirait combien de
 * propositions on ne montre pas. `tronque` se dit en mots.
 *
 * `q` doit faire au moins deux caractères, sinon `422` : c'est à l'appelant de
 * ne pas appeler avant.
 */
export function rechercherBrouillons(
  q: string,
  limite = 10,
  signal?: AbortSignal,
): Promise<{ elements: BrouillonTrouve[]; tronque: boolean }> {
  // `exactOptionalPropertyTypes` interdit une clé facultative posée à
  // `undefined` : on la pose seulement si elle existe.
  const o: OptionsRequete = { requete: { q, limite } }
  if (signal !== undefined) o.signal = signal

  return requeteApi<{ elements: BrouillonTrouve[]; tronque: boolean }>(
    '/demandes/brouillons/recherche',
    o,
  )
}

/**
 * `GET /api/demandes/brouillons/{draftPersonId}` — **nommer** une fiche proposée
 * dont on tient l'identifiant de ligne.
 *
 * Un corps de lien ne transporte que `otherDraftPersonId` : c'est la seule
 * désignation qui vaille hors de sa demande. Sans cette lecture, une reprise de
 * demande afficherait « personne n° 7 » — un identifiant sous les yeux du
 * lecteur, ce que le dépôt s'interdit partout ailleurs.
 *
 * `404` sur la fiche d'un autre membre, jamais `403` : un `403` apprendrait
 * qu'elle existe.
 */
export function lireFicheProposee(
  draftPersonId: number,
  signal?: AbortSignal,
): Promise<BrouillonTrouve> {
  const o: OptionsRequete = {}
  if (signal !== undefined) o.signal = signal

  return requeteApi<BrouillonTrouve>(`/demandes/brouillons/${draftPersonId}`, o)
}

/* ------------------------------------------------------------------ */
/* Les propositions qui ressemblent à une saisie — § 6.6 quater        */
/* ------------------------------------------------------------------ */

/**
 * Ce que le lecteur pourra faire d'une proposition semblable, **calculé par le
 * serveur** :
 *  - `completer` — la compléter sur place : il en est l'auteur, ou il est
 *    administrateur et elle est en attente ;
 *  - `complement` — déposer un complément rattaché, que l'administrateur
 *    fusionnera. Toujours le cas d'une forme discrète.
 *
 * La carte en fait un bouton (10/10/2026) : « Compléter cette proposition » fait
 * passer le formulaire en mode complétion (`PUT …/brouillons/{ref}`), « Ajouter
 * mes informations à cette proposition » dépose une création avec `complementDe`.
 * Le front n'en déduit rien d'autre : c'est le serveur qui sait qui peut quoi.
 */
export type ActionSurProposition = 'completer' | 'complement'

/**
 * Une proposition semblable **en clair** : celle du lecteur, celle de n'importe
 * qui pour un administrateur, ou celle d'un défunt — « un défunt est un fait
 * historique ». La ligne de recherche du § 6.6 bis, plus de quoi dire pourquoi
 * elle ressemble.
 */
export interface RessemblancePropositionComplete {
  forme: 'complete'
  draftPersonId: number
  demandeId: number
  localRef: string
  /**
   * Le nœud **dans le graphe du lecteur**, ou `null` : un brouillon non envoyé,
   * ou la proposition d'un défunt faite par un autre membre, n'y sont pas. Ce
   * n'est pas le `noeudId` toujours composé de la recherche — et la projection
   * peut encore écarter un nœud qu'elle ne sait pas placer : l'arbre vérifie
   * qu'il est dessiné avant d'y aller.
   */
  noeudId: string | null
  displayName: string
  sex: Sexe
  deceased: boolean
  ligneDates: string | null
  authorLabel: string
  enAttente: boolean
  lieuNaissance: string | null
  statut: 'brouillon' | 'en_attente'
  action: ActionSurProposition
  criteres: CritereDeRessemblance[]
  raisons: string[]
  score: number
}

/**
 * Une proposition semblable **discrète** : celle d'un vivant, faite par un autre
 * membre. Cinq clés, et c'est tout ce qui existe — ni nom, ni date, ni lieu, ni
 * auteur, ni numéro de demande, ni nœud. Seuls le nom, les prénoms et le sexe
 * ont été comparés, et les raisons ne recopient aucune valeur de la fiche.
 *
 * **Le front ne reconstruit jamais ce qui est caché** : la carte dit « une
 * proposition semblable attend déjà la validation », et rien d'autre.
 * `draftPersonId` n'est là que pour qu'un complément puisse la citer.
 */
export interface RessemblancePropositionDiscrete {
  forme: 'discrete'
  draftPersonId: number
  action: ActionSurProposition
  criteres: CritereDeRessemblance[]
  raisons: string[]
}

/** L'une ou l'autre forme, **choisie par le serveur**, fiche par fiche. */
export type RessemblanceProposition = RessemblancePropositionComplete | RessemblancePropositionDiscrete

export interface ReponseRessemblancesPropositions {
  elements: RessemblanceProposition[]
  tronque: boolean
}

/**
 * `POST /api/demandes/brouillons/ressemblances` — « une proposition semblable
 * attend déjà ».
 *
 * Le pendant du § 5.5 bis pour les fiches **proposées** : les demandes
 * `creation_personne` en attente, tous auteurs confondus, et les brouillons du
 * lecteur seul. Même corps, mêmes `422` — plus `sauf`, la demande à ne pas
 * parcourir (celle qu'on serait en train de compléter). Un compte en attente
 * reçoit `{ elements: [], tronque: false }`.
 */
export function chercherRessemblancesBrouillons(
  fiche: FicheAComparer,
  reglages: { limite?: number; sauf?: number | null } = {},
  signal?: AbortSignal,
): Promise<ReponseRessemblancesPropositions> {
  const o: OptionsRequete = {
    methode: 'POST',
    corps: {
      fiche: corpsDeFicheAComparer(fiche),
      limite: reglages.limite ?? LIMITE_DES_RESSEMBLANCES,
      sauf: reglages.sauf ?? null,
    },
  }
  if (signal !== undefined) o.signal = signal

  return requeteApi<ReponseRessemblancesPropositions>('/demandes/brouillons/ressemblances', o)
}

/**
 * § 6.4 quater — ce qu'une suppression emporterait, pour une union donnée.
 *
 * `supprimee` dit que l'union **part avec la fiche** : c'est le cas quand la
 * personne en est le seul époux, la base interdisant une famille sans personne.
 */
export interface UnionEmportee {
  familleId: number
  libelle: string
  enfants: number
  supprimee: boolean
}

/** Un enfant qui serait détaché de l'arbre — **il bloque la suppression**. */
export interface DescendantDetache {
  personneId: number
  displayName: string
}

/** § 6.4 quater — l'inventaire, tel que le serveur le mesure. */
export interface InventaireDeSuppression {
  personneId: number
  displayName: string
  notes: number
  citations: number
  faits: number
  /** `tree_child` où la personne est **l'enfant** : sa propre parenté. */
  filiations: number
  unions: UnionEmportee[]
  /** Les enfants **directs** qui seraient détachés, **nommés**. */
  descendants: DescendantDetache[]
  /**
   * La branche entière sous cette fiche, **toutes générations confondues**.
   *
   * C'est le chiffre qui arrête la main : « parent de Cobalt Sureau » est exact
   * et ne pèse rien, « 23 fiches descendent d'elle » pèse. Les deux coexistent
   * parce qu'ils ne disent pas la même chose — le total dit l'ampleur, les noms
   * disent **laquelle des deux fiches du doublon** on tient.
   */
  descendanceTotale: number
  medias: number
  /**
   * Le rattachement `mbr_member` dont cette fiche est la **racine**, s'il existe.
   *
   * Nommé par son numéro et jamais par l'adresse du compte : cette charge part
   * sur une route ouverte à tout membre, et le libellé d'un compte est la
   * huitième porte de sortie de l'ADR 0003.
   */
  membreId: number | null
  /** Invitations en attente désignant cette fiche — le même défaut, en différé. */
  invitationsActives: number
  /**
   * Ce que les autres modules accrochent à la fiche — démarches d'acte,
   * événements à venir, autorisation de naissance, questions.
   *
   * `bloquante` vient du serveur et ne se redérive pas ici : c'est lui qui sait
   * quelle nature bloque et laquelle part avec la fiche. Le front ne fait que
   * la mettre en français (`components/proposition/suppression.ts`).
   */
  dependances: DependanceDeFiche[]
}

/** § 6.4 quater — une ligne de `dependances` : une nature, un compte, son sort. */
export interface DependanceDeFiche {
  nature: string
  nombre: number
  bloquante: boolean
}

/**
 * § 6.4 quater — la réponse de `GET /api/demandes/suppression/personne/{id}`.
 *
 * **`bloquee` et `raisons` viennent du serveur, ils ne se déduisent pas de
 * l'inventaire côté front.** La règle qui interdit une suppression appartient au
 * back — elle connaît les six clés étrangères de `tree_person` et la contrainte
 * qui décide du sort d'une union —, et un front qui la redériverait finirait par
 * diverger dans le sens permissif, c'est-à-dire par proposer un bouton qui
 * efface.
 */
export interface ApercuDeSuppression {
  inventaire: InventaireDeSuppression
  /** La phrase prête à afficher : « emporte 3 notes, 2 citations ». */
  resume: string
  bloquee: boolean
  raisons: string[]
}

/**
 * `GET /api/demandes/suppression/personne/{id}` — **lecture seule**.
 *
 * Appelée à l'ouverture du formulaire de suppression, avant que l'auteur écrive
 * quoi que ce soit : découvrir après avoir rédigé son motif que ce n'était pas
 * cette fiche-là qu'il fallait viser est exactement ce que cet appel évite.
 *
 * `404` sur une fiche qui n'existe plus, ou que le lecteur n'a pas le droit de
 * voir — c'est le même filtre que partout, et il est appliqué en amont.
 */
export function lireApercuDeSuppression(personneId: number): Promise<ApercuDeSuppression> {
  return requeteApi<ApercuDeSuppression>(`/demandes/suppression/personne/${personneId}`)
}

/**
 * `POST /api/demandes/{id}/soumettre` — un brouillon entre dans la file.
 *
 * C'est **ici** que « au moins un lien » est contrôlé, et non à la création :
 * on peut enregistrer un brouillon incomplet, on ne peut pas le soumettre. Sans
 * lien ⇒ `422 demande_sans_lien` (§ 6.3). Un complément dont l'origine a été
 * tranchée entre-temps ⇒ `409 proposition_plus_en_attente` (§ 6.3 ter).
 */
export function soumettreDemande(id: number): Promise<Demande> {
  return requeteApi<Demande>(`/demandes/${id}/soumettre`, { methode: 'POST' })
}

/** `POST /api/demandes/{id}/retirer` — l'auteur reprend sa demande tant que
 *  personne n'a décidé. `409 demande_deja_decidee` sinon. */
export function retirerDemande(id: number): Promise<Demande> {
  return requeteApi<Demande>(`/demandes/${id}/retirer`, { methode: 'POST' })
}

/* ------------------------------------------------------------------ */
/* L'historique d'une fiche — § 6.11                                   */
/* ------------------------------------------------------------------ */

/** § 5.7 — ce qu'une révision a fait à l'arbre. Valeurs du modèle, non traduites. */
export type OperationDeRevision =
  | 'creation'
  | 'modification'
  | 'suppression'
  | 'lien_ajoute'
  | 'lien_retire'

/**
 * Une révision **effectivement appliquée** (§ 6.11) — le sérialiseur
 * `SerialiseurDeDemande::revision()`, relu dans son code.
 *
 * `changes` est rendu **formaté** côté serveur, champ par champ :
 * `{ "birth": { "avant": "1987", "apres": "entre 1901 et 1909" } }`. Pour une
 * création, `avant` vaut `null` partout ; sur une fiche masquée, `avant` est
 * **retiré** — d'où les deux clés facultatives. `requestId` à `null` signale une
 * demande effacée hors de l'application, jamais un raccourci d'écriture (§ 5.5).
 */
export interface RevisionDeFiche {
  id: number
  /** Une valeur inconnue s'affiche telle quelle plutôt que de disparaître. */
  operation: OperationDeRevision | (string & {})
  subjectKind: NatureSujet
  subjectId: number
  subjectLabel: string | null
  changes: Record<string, unknown>
  versionBefore: number | null
  versionAfter: number | null
  actorLabel: string | null
  requestId: number | null
  /** ATOM. */
  appliedAt: string
}

/**
 * `GET /api/demandes/historique/{kind}/{id}` — une page de l'historique d'une
 * fiche, du plus récent au plus ancien (§ 6.11).
 *
 * **Réservée aux membres** : un compte en attente reçoit `403 regime_en_attente`,
 * et une personne vivante hors cercle rend `404`, comme une fiche inconnue.
 * `parPage` est plafonné à 100 côté serveur.
 */
export function listerHistoriqueDeFiche(
  subjectKind: NatureSujet,
  subjectId: number,
  options: { page?: number | undefined; parPage?: number | undefined } = {},
  requete: OptionsRequete = {},
): Promise<ListePaginee<RevisionDeFiche>> {
  return requeteApi<ListePaginee<RevisionDeFiche>>(
    `/demandes/historique/${subjectKind}/${subjectId}`,
    { ...requete, requete: { page: options.page, parPage: options.parPage } },
  )
}

/** Au-delà, l'historique est rendu tronqué — et l'écran le dit. */
const PAGES_D_HISTORIQUE_AU_PLUS = 10

/**
 * L'historique **entier** d'une personne : les pages de 100 l'une après
 * l'autre, jusqu'au `total` annoncé.
 *
 * Pourquoi tout charger plutôt que paginer à l'écran : l'onglet Historique
 * **mêle** ces révisions aux sources, notes et documents de la fiche, rangés par
 * date. Avec une seule page, une source vieille de deux ans s'afficherait entre
 * deux révisions récentes, sans les révisions d'entre-deux qu'on n'a pas encore
 * chargées — une frise qui ment par omission. Une fiche en porte quelques
 * dizaines : une ou deux requêtes. Le plafond (`PAGES_D_HISTORIQUE_AU_PLUS`,
 * soit 1 000 révisions) ne sert qu'à ne jamais boucler sans fin ; `complet`
 * dit s'il a été atteint.
 */
export async function lireHistoriqueDePersonne(
  personId: number,
  requete: OptionsRequete = {},
): Promise<{ elements: RevisionDeFiche[]; total: number; complet: boolean }> {
  const elements: RevisionDeFiche[] = []
  let total = 0
  for (let page = 1; page <= PAGES_D_HISTORIQUE_AU_PLUS; page++) {
    const reponse = await listerHistoriqueDeFiche('personne', personId, { page, parPage: 100 }, requete)
    total = reponse.total
    elements.push(...reponse.elements)
    if (reponse.elements.length === 0 || elements.length >= total) break
  }
  return { elements, total, complet: elements.length >= total }
}
