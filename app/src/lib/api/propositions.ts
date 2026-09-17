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

import { requeteApi, type OptionsRequete } from './client'
import type { BlocDate, BlocDateOp, ListePaginee, Sexe } from './types'

/* ------------------------------------------------------------------ */
/* Les types du § 6                                                    */
/* ------------------------------------------------------------------ */

/** § 5.7 — les huit natures de `RequestKind`. Valeurs du modèle, non traduites. */
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
  oldValue: ValeurOp
  newValue: ValeurOp
  baseVersion: number | null
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
  submittedAt: string | null
  createdAt: string
  ops: OpDemande[]
  brouillons: BrouillonDemande[]
  liens: LienDemande[]
  revisions: RevisionDemande[]
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
 * premier, parce qu'une file se prend par le début.
 */
/** Un auteur qui a réellement déposé quelque chose, pour peupler le filtre. */
export interface AuteurDeLaFile {
  userId: number
  libelle: string
}

export function listerDemandesAdministration(options: {
  status?: StatutDemande | undefined
  kind?: NatureDemande | undefined
  targetId?: number | undefined
  auteur?: number | undefined
  page?: number | undefined
  parPage?: number | undefined
}): Promise<ListePaginee<Demande>> {
  return requeteApi<ListePaginee<Demande>>('/administration/demandes', {
    requete: {
      status: options.status,
      kind: options.kind,
      targetId: options.targetId,
      auteur: options.auteur,
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
 * `POST /api/administration/demandes/{id}/valider` — corps vide.
 *
 * Six issues (§ 6.8), dont **deux `409` aux conseils opposés** :
 * `verrou_concurrent` (rien n'a été écrit, réessayer) et `demande_caduque` (la
 * demande est morte, ne pas réessayer). Elles ne sont **pas** retriées ici : le
 * socle les distingue dans `erreurs.ts`, et un écran qui les retraiterait
 * finirait par en oublier une.
 */
export function validerDemande(id: number): Promise<ResultatValidation> {
  return requeteApi<ResultatValidation>(`/administration/demandes/${id}/valider`, {
    methode: 'POST',
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

/** § 6.3 — proposer une personne et ses liens. La demande ne décrit **aucune
 *  famille** : « le service en déduit les familles à créer au moment de la
 *  validation ». Un `familleRef` serait refusé en `422`. */
export interface CorpsCreationPersonne extends CorpsCommun {
  kind: 'creation_personne'
  brouillons: BrouillonPropose[]
  liens: LienPropose[]
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

export type CorpsDemande =
  | CorpsModificationPersonne
  | CorpsCreationPersonne
  | CorpsAjoutLien
  | CorpsSuppressionPersonne

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
  /** Toujours `true` sur cette route. Lu quand même, pas déduit de l'URL. */
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
 * lien ⇒ `422 demande_sans_lien` (§ 6.3).
 */
export function soumettreDemande(id: number): Promise<Demande> {
  return requeteApi<Demande>(`/demandes/${id}/soumettre`, { methode: 'POST' })
}

/** `POST /api/demandes/{id}/retirer` — l'auteur reprend sa demande tant que
 *  personne n'a décidé. `409 demande_deja_decidee` sinon. */
export function retirerDemande(id: number): Promise<Demande> {
  return requeteApi<Demande>(`/demandes/${id}/retirer`, { methode: 'POST' })
}
