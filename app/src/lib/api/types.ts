/**
 * Types du contrat d'API — `docs/api-contract.md`.
 *
 * Deux règles du contrat se lisent directement dans ces déclarations, et c'est
 * volontaire : le typeur doit refuser le code qui les enfreint, plutôt que de
 * laisser un écran les découvrir en production.
 *
 * 1. **§ 1.2 — la langue des clés est mécanique.** Une clé qui reflète une
 *    colonne porte le nom de la propriété Doctrine (`surname`, `displayName`,
 *    `birth`, `status`) ; toute autre clé est en français (`erreur`, `elements`,
 *    `vivant`, `enAttente`, `visibilite`). On ne « francise » rien : la clé du
 *    calque « en attente » est littéralement la clé du corps principal, et c'est
 *    ce qui évite une table de correspondance où les champs se perdent.
 *
 * 2. **§ 1.3 — absent contre `null`.** Un champ que le lecteur n'a pas le droit
 *    de voir est *absent* ; un champ qu'il a le droit de voir et qui n'est pas
 *    renseigné est *présent, à `null`*. D'où les unions discriminées ci-dessous
 *    plutôt que des champs optionnels partout : `'birth' in fiche === false`
 *    veut dire « interdit », `fiche.birth === null` veut dire « inconnu ».
 *    `exactOptionalPropertyTypes` (tsconfig) rend cette distinction effective.
 */

/* ------------------------------------------------------------------ */
/* Énumérations du modèle — valeurs jamais traduites, jamais recasées  */
/* ------------------------------------------------------------------ */

/** `UserStatus` / `MemberStatus` (§ 4.5 du modèle). */
export type StatutCompte = 'actif' | 'en_attente' | 'suspendu'

/** `mbr_member.match_kind` — comment le rattachement a été établi. */
export type TypeRattachement = 'pre_lie' | 'recherche_confirmee' | 'manuel_admin' | 'a_rattacher'

export type Sexe = 'masculin' | 'feminin' | 'inconnu'

/**
 * Les quatre quarts d'ascendance du lecteur, le tronc, et les quatre quarts de son
 * conjoint.
 *
 * **L'INVARIANT : aucune personne servie sans branche, hors îlot détaché.** *« Tous doivent
 * forcément être dans un des 13 blocs ; si c'est les conjoints d'une personne de ma
 * famille, ils vont avec leur partenaire »* — le commanditaire, 23/09/2026. Le serveur le
 * tient (`CalculAscendance::parAlliance()`), et le front n'a donc **pas** à prévoir de
 * région de secours : `hors` reste réservée à ce qu'aucun chemin ne relie au lecteur.
 *
 * **`bf`, la belle-famille, a existé du 20 au 23/09/2026 et n'existe plus.** Elle donnait
 * une région à ceux qui entraient par alliance ; ils prennent désormais le bloc de leur
 * partenaire. Une valeur `bf` qui reviendrait d'un stockage ancien tombe dans `hors` par
 * {@link cleDeZone}, sans rien casser.
 *
 * ⚠ **CONSÉQUENCE MESURÉE, à connaître avant de dessiner** : pour le lecteur médian du
 * graphe servi, `tr` porte **431 personnes sur 468**. La concentration n'est pas nouvelle —
 * avant l'arbitrage, les 381 du même lecteur étaient dans `bf` — et le nombre de régions
 * non vides AUGMENTE (médiane 2 → 3, maximum 6 → 9). Mais un dessin qui suppose treize
 * blocs à peu près équilibrés se trompera : c'est quand le conjoint du lecteur a une
 * ascendance connue qu'ils se remplissent.
 *
 * **Révision du 2026-10-01 — l'arbitrage du 23/09 est levé pour les familles par
 * alliance.** L'ascendance du mari d'une tante n'a aucun lien de sang avec le lecteur :
 * rangée dans le bloc de son partenaire, elle gonflait la bande « Voulgre » de toute une
 * généalogie étrangère (captures « voir comme Camille », 581 personnes). Ces nœuds portent
 * désormais `parente: 'alliance'` ; le front ne lit JAMAIS leur `branch` pour les ranger —
 * quelle que soit la valeur que le serveur y met (ancienne branche, `null`, ou une valeur
 * dédiée) — et leur donne la teinte `hors`.
 */
export type Branche =
  | 'pp'
  | 'pm'
  | 'mp'
  | 'mm'
  | 'tr'
  | 'cpp'
  | 'cpm'
  | 'cmp'
  | 'cmm'

/**
 * Les quatre quarts du CONJOINT du lecteur — `pp'`, `pm'`, `mp'`, `mm'` de la
 * spécification à treize blocs, préfixés `c` parce qu'une apostrophe ne passe ni dans une
 * valeur stockée ni dans un nom de classe CSS.
 *
 * **Ce ne sont pas quatre régions de plus au même titre que les autres** : ce sont les
 * quatre mêmes, vues depuis une seconde personne. Le serveur les calcule avec exactement la
 * fonction qui découpe celles du lecteur (`CalculAscendance::quartsDuConjoint()`), et leurs
 * teintes sont celles de leurs homologues assourdies — jamais quatre couleurs inédites, qui
 * feraient lire huit familles de quarts là où il y en a quatre.
 *
 * **Le compte des régions n'est pas celui des blocs.** Le dessin en montre treize ; cette
 * union en porte dix, et il n'en manque aucune : les quatre enveloppes `p`, `m`, `p'`, `m'`
 * sont des RÉUNIONS de deux régions voisines, dérivées à la géométrie. Une valeur ici
 * désigne une partition, jamais une réunion.
 */
export type BrancheDuConjoint = Extract<Branche, 'cpp' | 'cpm' | 'cmp' | 'cmm'>

/** § 5.1 — ce que le cercle de visibilité laisse voir d'une personne. */
export type Visibilite = 'complete' | 'restreinte'

/**
 * § 14.1 — `DateQualifier`. **Dix valeurs, et il faut les dix.**
 *
 * Cette union a été fausse : elle en portait sept, `mois`, `depuis` et `jusqu_a`
 * manquaient. Ce ne sont pas des cas de bord — le § 14.3 les donne en exemple,
 * `JAN 1920` est un `mois` et `FROM 15 JUN 1900` un `depuis` — et une valeur
 * absente de l'union ne produit aucune erreur à l'exécution : elle produit un
 * `switch` qui tombe dans son défaut, sur une fiche, une barre de chronologie et
 * un formulaire de proposition à la fois.
 *
 * **La liste se dérive du tableau du § 14.1, elle ne se recopie pas.** C'est la
 * faute qui a produit la version à sept valeurs, et la même a produit ailleurs
 * un nœud dont la largeur était mesurée sur le mauvais texte.
 *
 * Deux conséquences de forme, portées par `BlocDateOp` ci-dessous :
 * `avant` et `jusqu_a` laissent `min` à `null`, `apres` et `depuis` laissent
 * `max` à `null`. Ce n'est **pas** `min`/`max` qui porte l'incertitude, c'est
 * `qualifier` : `1930` donne déjà `1930-01-01` → `1930-12-31`, et un client qui
 * déduirait « `min ≠ max` donc fourchette » classerait les trois quarts de
 * l'arbre en incertain. **Le test « est-ce une fourchette » est
 * `qualifier === 'entre'`, rien d'autre** (§ 14.3, arbitrage W).
 */
export type QualificatifDate =
  | 'exacte'
  | 'annee'
  | 'mois'
  | 'environ'
  | 'avant'
  | 'apres'
  | 'entre'
  | 'depuis'
  | 'jusqu_a'
  | 'inconnue'

/* ------------------------------------------------------------------ */
/* Enveloppes générales                                                */
/* ------------------------------------------------------------------ */

/** § 1.5 — enveloppe de **toutes** les listes paginées. Une liste bornée par
 *  nature (réglages, ops d'une demande) rend un tableau nu, sans enveloppe. */
export interface ListePaginee<T> {
  elements: T[]
  /** Compté **après** application du cercle : sinon le total dit ce qu'il cache. */
  total: number
  /** Commence à 1, jamais à 0. */
  page: number
  parPage: number
}

/** § 15.1 — l'enveloppe d'erreur, identique pour toute réponse ≥ 400. */
export interface EnveloppeErreur {
  erreur: {
    /** Chaîne stable en `snake_case` : c'est sur elle que le front branche. */
    code: string
    /** Français, une phrase, affichable telle quelle. */
    message: string
    /** `{ nomDeChamp: message }` — sur les 422 seulement. `null` sinon. */
    champs: Record<string, string> | null
    /** Structure propre au code. `null` quand elle est sans objet. */
    details: Record<string, unknown> | null
  }
}

/* ------------------------------------------------------------------ */
/* § 14 — bloc date                                                    */
/* ------------------------------------------------------------------ */

/**
 * Un bloc date **tel qu'il part dans une requête** : les cinq clés, toujours les
 * cinq, même quand trois valent `null`.
 *
 * Une date généalogique est un **intervalle**, jamais un instant : `an_nais` et
 * `an_nais_max` encodent une fourchette (`BET 1901 AND 1909`, `ABT 1904`).
 * « Ne jamais aplatir une fourchette en date ferme : ni en base, ni à l'écran »
 * (§ 4 du dossier de mission).
 *
 * Aucune clé n'est optionnelle, et c'est le § 14.1 qui l'impose : « pour qu'un
 * champ absent soit toujours une erreur et jamais un non-renseigné implicite ».
 */
export interface BlocDateOp {
  /** La chaîne GEDCOM **verbatim**. Jamais reconstruite, jamais réécrite : c'est
   *  la provenance. */
  raw: string | null
  qualifier: QualificatifDate
  /** Borne basse **inclusive**. `null` = non bornée à gauche (`BEF`, `TO`). */
  min: string | null
  /** Borne haute **inclusive**. `null` = non bornée à droite (`AFT`, `FROM`). */
  max: string | null
  /** Le lieu tel qu'écrit (`PLAC`). */
  place: string | null
}

/**
 * Un bloc date **tel qu'il arrive d'une fiche** : les cinq clés, plus `texte`.
 *
 * `texte` est le rendu de `UncertainDate::format()` — « entre 1901 et 1909 »,
 * « vers 1904 » — et il est en **lecture seule**.
 *
 * > **Un bloc reçu d'une fiche ne se reposte pas tel quel : il faut en retirer
 * > `texte`.** C'est la seule manipulation à faire, et le § 14.1 l'écrit
 * > explicitement « pour qu'elle ne s'invente pas ». Le motif est mécanique :
 * > la détection de conflit compare les valeurs d'op en `JSONB` (§ 9.6), et une
 * > sixième clé ferait diverger la comparaison — la demande partirait caduque
 * > sans que personne ne comprenne pourquoi.
 *
 * Les deux types sont distincts pour que le compilateur remarque la confusion.
 * La conversion existe déjà, une seule fois : `sansTexte()` dans
 * `@/components/proposition/dates`. Ne pas en écrire une seconde.
 */
export interface BlocDate extends Omit<BlocDateOp, 'qualifier'> {
  /**
   * `masquee` n'existe **qu'en lecture** (ADR 0016, réciprocité des vivants) :
   * la naissance d'une personne vivante dont le lecteur ne voit qu'une partie —
   * ou rien. `raw`, `min` et `max` valent alors `null`, `place` reste, et
   * `texte` dit ce qui est partagé (« mars 1985 », « 12 mars », ou `''`).
   *
   * Ce n'est pas un qualificatif GEDCOM, et il n'entre donc pas dans
   * `QualificatifDate` : un bloc masqué ne se reposte jamais. Le champ de la
   * fiche est verrouillé (`naissanceModifiable`), et `sansTexte()` le ramène à
   * un bloc qui n'affirme rien.
   */
  qualifier: QualificatifDate | 'masquee'
  texte: string
  /** Sur un bloc `masquee` seulement : les composantes que le lecteur voit. */
  composantes?: ComposantesDate
}

/** ADR 0016 — les composantes d'une date de naissance réduite. Absente = non
 *  partagée. */
export interface ComposantesDate {
  annee?: number
  mois?: number
  jour?: number
}

/**
 * ADR 0016, révision du 7 octobre 2026 — **à qui** une composante de la date de
 * naissance est montrée : tous les vivants de l'arbre, ses proches seulement
 * (ascendants, descendants, frères et sœurs, conjoint, beaux-parents, conjoints
 * des descendants), ou personne. `proches` est le défaut.
 */
export type NiveauDePartage = 'tous' | 'proches' | 'personne'

/**
 * ADR 0016 — ce que le membre partage de sa date de naissance : un niveau par
 * composante, **indépendants** (« le jour et le mois à tous, l'année à mes
 * proches » est un choix courant). Réciproque : on ne voit d'une personne que
 * ce que chacun montre à l'autre.
 */
export interface PartageNaissance {
  annee: NiveauDePartage
  mois: NiveauDePartage
  jour: NiveauDePartage
}

/** Trois cases à cocher — les composantes visées par une demande RGPD de retrait. */
export interface ComposantesCochees {
  annee: boolean
  mois: boolean
  jour: boolean
}

/**
 * Le **verrou** : une composante partagée avec tous le reste au moins trente
 * jours. Partager est immédiat ; revenir à « proches » ou « personne » ne l'est
 * qu'une fois le verrou passé — avant, il faut une demande RGPD. Seules les
 * composantes **à « tous » et encore verrouillées** sont présentes, avec la
 * date (ISO) à partir de laquelle elles se libèrent.
 */
export type VerrouDePartage = Partial<Record<keyof PartageNaissance, string>>

/** Le choix tel que le serveur le rend : avec la date du dernier changement. */
export interface PartageNaissanceEnregistre extends PartageNaissance {
  modifieLe: string | null
  /** Facultatif pour les charges d'essai ; absent vaut « aucun verrou ». */
  verrouJusquAu?: VerrouDePartage
}

/* ------------------------------------------------------------------ */
/* § 2.2 — la charge de session                                        */
/* ------------------------------------------------------------------ */

export interface UtilisateurDeSession {
  id: number
  email: string
  /** Retombe sur le courriel tant qu'il n'y a pas de rattachement validé. */
  displayName: string
  roles: string[]
  status: StatutCompte
  totpEnabledAt: string | null
  lastLoginAt: string | null
}

/**
 * Le membre, **avant** activation. `personId` et `personLabel` sont *absents* :
 * « confirmer une date n'est pas la lire », et le pré-lien n'ouvre aucun accès
 * (§ 2.2 point 2). Un back qui renverrait `personLabel` « puisque c'est lui »
 * enfreindrait la règle ; le type refuse donc de le lire.
 */
export interface MembreEnAttente {
  id: number
  status: 'en_attente'
  matchKind: TypeRattachement
  activatedAt: null
}

export interface MembreRattache {
  id: number
  status: 'actif' | 'suspendu'
  personId: number
  personLabel: string
  matchKind: TypeRattachement
  activatedAt: string
}

export type MembreDeSession = MembreEnAttente | MembreRattache

/**
 * § 12 — **ce n'est pas un cercle réduit, c'est un régime distinct.**
 *
 * L'union discriminée est le point de tout ce fichier : `generations` et
 * `taille` n'existent pas dans le régime `aucun`, donc `cercle.taille === 0`
 * ne compile pas. On ne peut pas écrire par mégarde le test que le contrat
 * interdit — il faut d'abord discriminer sur `regime`.
 */
export type CercleDeSession =
  | { regime: 'aucun' }
  | { regime: 'cercle'; generations: number; taille: number }

/** § 10.4 — bascule « voir comme un membre », portée par la session. */
export interface VueCommeMembre {
  memberId: number
  memberLabel: string
  depuis: string
}

/**
 * ADR 0010 — un administrateur **connecté en tant que** ce compte. Toute la
 * charge est alors celle du compte incarné ; `par` dit qui tient le clavier.
 */
export interface IncarnationDeSession {
  par: { id: number; displayName: string }
}

export interface ChargeDeSession {
  utilisateur: UtilisateurDeSession
  membre: MembreDeSession | null
  cercle: CercleDeSession
  /** `null` sauf pour un administrateur ayant activé la bascule (§ 10.4). */
  voirComme: VueCommeMembre | null
  /** `null` hors incarnation (ADR 0010). Facultatif pour les charges d'essai
   *  écrites avant elle ; le serveur l'envoie toujours. */
  incarnation?: IncarnationDeSession | null
  /** Identifiants des demandes `en_attente` dont le lecteur est l'auteur. */
  demandesEnAttente: number[]
  /**
   * Le portrait actif de la personne du lecteur, ou `null` — sans portrait, ou
   * sans personne validée. `deposeParMoi` distingue « ajoutez votre photo » de
   * « quelqu'un a déposé cette photo de vous : la garder ou la remplacer ? ».
   */
  portrait: PortraitDeSession | null
  /**
   * ADR 0016 — le choix de partage de la date de naissance. `null` quand le
   * compte n'a pas de ligne membre (le compte d'amorçage). Facultatif pour les
   * charges d'essai écrites avant lui ; le serveur l'envoie toujours.
   */
  partageNaissance?: PartageNaissanceEnregistre | null
  /** ADR 0016 — la réciprocité telle qu'elle s'applique au lecteur effectif. */
  reciprocite?: ReciprociteDeSession
}

export interface ReciprociteDeSession {
  /** L'administrateur a levé pour ce compte la restriction sur les **dates**
   *  (jamais sur les photos). */
  restrictionIgnoree: boolean
  /** Le lecteur a une photo de lui, validée ou en attente. Sans elle, les
   *  portraits des personnes vivantes arrivent floutés par le serveur. */
  photo: boolean
}

export interface PortraitDeSession {
  mediaId: number
  url: string
  vignetteUrl: string
  deposeParMoi: boolean
}

/* ------------------------------------------------------------------ */
/* § 4 — inscription                                                   */
/* ------------------------------------------------------------------ */

/** § 4.1 — la réponse ne dit **jamais** qui est la personne pré-liée. */
export interface VerificationJeton {
  /** `confirmation` : une personne est pré-liée. `recherche` : rien n'est pré-lié. */
  mode: 'confirmation' | 'recherche'
  expiresAt: string
  candidatsMax: number
  champsDemandes: string[]
}

/** § 4.2 — affichage minimal : initiale, nom, année. Jamais un `personId`. */
export interface CandidatRattachement {
  /** Référence opaque et aléatoire, propre à la tentative. */
  ref: string
  libelle: string
}

export interface ResultatRattachement {
  candidats: CandidatRattachement[]
}

/** § 4.4 — l'écran d'attente. Aucun `personId`, aucun `personLabel`. */
export interface EtatInscription {
  status: StatutCompte
  depuis: string
  matchKind: TypeRattachement
  regime: 'aucun' | 'cercle'
  /**
   * Le compte a-t-il déposé sa photo, qui attend le rattachement pour devenir le
   * portrait de sa fiche ? Présente seulement tant que `status` vaut
   * `en_attente` : absente, elle ne dit rien — elle ne vaut pas `false`.
   */
  portraitEnAttente?: boolean
}

/* ------------------------------------------------------------------ */
/* § 6.2 bis — la fin de recherche (ADR 0023)                          */
/* ------------------------------------------------------------------ */

/**
 * Le côté d'une fin de recherche : le **père** ou la **mère** de la personne.
 *
 * « Les deux » n'est **pas** une valeur : c'est un raccourci de saisie, qui dépose
 * deux ops dans la même demande (ADR 0023 § 2). L'arbre a besoin de l'état de
 * chaque côté, et un parent retrouvé d'un côté ne rouvre que ce côté-là.
 */
export type CoteDeRecherche = 'pere' | 'mere'

/**
 * Pourquoi la recherche s'arrête — `Core\Enum\MotifDeFinDeRecherche`, valeurs du
 * modèle, jamais traduites (§ 1.2). Les libellés français sont dans
 * `@/lib/finDeRecherche` (`MOTIFS_DE_FIN_DE_RECHERCHE`), recopiés du serveur.
 *
 * - `registres_inexistants` porte son année (`beforeYear`), et elle seule ;
 * - `pere_inconnu` ne vaut que du côté du père ;
 * - `autre` exige un texte (`body`).
 */
export type MotifDeFinDeRecherche =
  | 'registres_inexistants'
  | 'registres_detruits'
  | 'lacune'
  | 'pere_inconnu'
  | 'enfant_trouve'
  | 'origine_inconnue'
  | 'homonymie'
  | 'autre'

/**
 * Une fin de recherche **telle qu'elle voyage dans un op** (`valueKind:
 * "fin_de_recherche"`, § 6.2 bis) : trois clés, **toujours les trois**, comme les
 * cinq d'un bloc date. Une clé absente ou une clé en plus rend `422`.
 *
 * Les noms sont ceux des propriétés Doctrine de `Tree\ResearchClosure` (§ 1.2) :
 * - `reason` : le motif ;
 * - `beforeYear` : l'année avant laquelle les registres n'existent pas — entier
 *   pour `registres_inexistants`, `null` pour tout autre motif ;
 * - `body` : le texte libre, `null` quand il n'y en a pas (une chaîne vide est
 *   ramenée à `null` par le serveur).
 */
export interface ValeurFinDeRecherche {
  reason: MotifDeFinDeRecherche
  beforeYear: number | null
  body: string | null
}

/**
 * Une fin de recherche **telle qu'une fiche complète la sert** : la valeur, plus
 * son auteur et sa date — en lecture seule, jamais dans un op.
 *
 * - `authorLabel` : le **déposant** de la demande qui l'a posée ou modifiée en
 *   dernier (comme l'auteur d'une note, § 6.4 ter), jamais l'administrateur qui
 *   l'a validée. `null` pour une ligne écrite hors du circuit des demandes.
 * - `closedAt` : ATOM — la date du **dépôt** de cette demande (`submittedAt`),
 *   pas celle de sa validation (ADR 0023 § 5).
 */
export interface FinDeRecherche extends ValeurFinDeRecherche {
  authorLabel: string | null
  closedAt: string
}

/**
 * Ce que le nœud du graphe (§ 5.2) et la personne d'une affiche (§ 5.4 bis)
 * portent d'une fin de recherche : le motif et son année, de quoi dessiner le
 * mur et dire le motif au survol. Le texte et l'auteur se lisent sur la fiche.
 */
export type ResumeFinDeRecherche = Pick<ValeurFinDeRecherche, 'reason' | 'beforeYear'>

/* ------------------------------------------------------------------ */
/* § 5.2 — le graphe                                                   */
/* ------------------------------------------------------------------ */

/**
 * Identifiant de nœud : chaîne **préfixée**, jamais un entier.
 * `"p17"` personne, `"f28"` famille, `"#12/p1"` nœud provisoire de la demande 12.
 * Un identifiant qui commence par `#` n'existe dans aucune table, ne survit pas
 * à la validation et n'est accepté par aucune route.
 *
 * **Un identifiant de nœud ne se découpe jamais.** Il est *opaque* : il sert à
 * relier une arête à un nœud (`de`, `vers`) et à retrouver un élément du DOM,
 * rien d'autre. Pour agir sur ce que le nœud désigne, on lit les champs que
 * l'API envoie **déjà séparés** — `personId`, `familyId`, `demandeId`,
 * `brouillonRef` — et jamais un `split('/')` ou un `slice(1)` sur la chaîne.
 *
 * Deux raisons, et la seconde est celle qui coûte :
 *
 * 1. le `#` est le séparateur de fragment d'URL : `#12/p1` ne traverse pas un
 *    chemin, et c'est précisément pourquoi les deux morceaux sont envoyés à
 *    part ;
 * 2. la forme `#<demandeId>/<localRef>` existe parce que `local_ref` n'est
 *    unique **que dans sa demande** — « `#p1` de la demande 12 et `#p1` de la
 *    demande 13 n'ont aucun rapport ». Un découpage maison qui se tromperait de
 *    moitié rapprocherait deux nœuds sans rapport, sans rien casser de visible.
 */
export type IdentifiantNoeud = string

interface NoeudCommun {
  id: IdentifiantNoeud
  x: number
  y: number
  /** Mesurés par le moteur, **identiques pour tous les lecteurs**, déjà entiers.
   *  Le client ne les recalcule jamais (§ 5.2, ADR 0005) ; seule la vue `/histoire`
   *  peut leur substituer, en format Portrait, une boîte CONSTANTE
   *  (`auFormat`, `histoire/cartes.ts`), qui ne lit aucune donnée. */
  w: number
  h: number
  /** Présent et à `true` sur un nœud issu du calque ; absent sinon (§ 13.4). */
  enAttente?: true
  demandeId?: number
}

export interface NoeudPersonne extends NoeudCommun {
  type: 'personne'
  /** `null` sur un nœud provisoire : la personne n'a pas encore d'identifiant. */
  personId: number | null
  /** `local_ref` **sans dièse**, envoyé à part de `id` pour qu'on n'ait jamais
   *  à découper `#12/p1`. C'est ce couple (`demandeId`, `brouillonRef`) que
   *  prend la route de détail d'un nœud en attente (§ 6.6). */
  brouillonRef?: string
  /**
   * La vignette du portrait actif, pour l'emplacement photo de la fiche.
   * **Absente** — et non `null` — quand la personne n'en a pas, ou quand sa
   * fiche est restreinte pour ce lecteur : « cette personne masquée a une
   * photo » est déjà une information (§ 16.2). Le canevas dessine alors la
   * silhouette ; l'emplacement, lui, est le même pour tout le monde.
   */
  portrait?: string
  /**
   * Le nom **complet** — tous les prénoms : « Ambre Célestin Jade Mica Romarin ».
   * Il sert à **lire** : survol, panneau latéral, recherche. Le canevas ne le
   * dessine **jamais** — voir `etiquetteNom`.
   */
  displayName: string
  /**
   * **Le nom que le moteur a mesuré, et le seul que le canevas doive dessiner.**
   *
   * `(nickname ?? givenNames)` réduit à son **premier mot**, puis `surname` :
   * « Ambre Romarin », 13 signes, là où `displayName` en fait 32.
   *
   * Pourquoi l'API l'envoie au lieu de laisser le client la dériver : elle n'est
   * **pas dérivable**. `displayName` colle `nickname ?? givenNames` puis
   * `surname`, et un nom de famille peut contenir des espaces — « de la Tour »
   * ne se découpe par aucune règle. Mesuré sur les 274 fiches réelles, dessiner
   * `displayName` fait **déborder 220 boîtes**, jusqu'à 169 unités de trop sur
   * une boîte de 149 : trois fiches voisines se chevauchent.
   *
   * **Présente aussi sur un nœud restreint** — et, depuis la révision du
   * 2026-10-01 (ADR 0003), elle y vaut le libellé neutre « Personne vivante »,
   * comme `displayName` : le vrai nom d'une personne vivante hors cercle n'est plus
   * envoyé. Le canevas n'en dépend pas : il écrit lui-même ce libellé sur tout nœud
   * restreint (`nomEcrit()`, `histoire/lignees.ts`), même face à un serveur antérieur.
   *
   * Trois clés de nom, trois usages : `displayName` pour lire, `etiquetteNom`
   * pour dessiner, `surname`/`givenNames` pour éditer.
   *
   * ⚠ **Obligatoire au type, absente de l'API déployée au 15 septembre 2026 au
   * soir.** Le sérialiseur du dépôt la pose (`NormaliseurDeGraphe.php:274`,
   * modifié à 22 h 46) mais l'image `heritage/api:dev` qui répond a été
   * construite plus tôt : relevé sur la charte réelle, `etiquetteNom` sur
   * **0 nœud sur 274**, `ligneDates` sur 274. Ce n'est pas un cache compilé —
   * c'est l'ancienne source, cuite dans l'image.
   *
   * Elle reste déclarée **obligatoire** à dessein : l'optionnel autoriserait un
   * repli permanent sur `displayName`, c'est-à-dire 220 boîtes qui débordent sur
   * 274. Mais **le type ne rend pas la clé présente à l'exécution** : ne pas
   * écrire `noeud.etiquetteNom.length` sans garde tant que la condition
   * ci-dessous n'est pas remplie. `nomDeFiche()` en garde une.
   *
   * **Condition de retrait de cette note**, falsifiable : reconstruire l'image
   * (`docker compose build api`), puis relever les clés d'un nœud de
   * `GET /api/arbre/graphe`. `etiquetteNom` doit y être sur 274 nœuds sur 274 et
   * `ligneDates` sur zéro. Tant que ce relevé n'a pas été refait, la note reste
   * — une cale sans date devient une convention.
   */
  etiquetteNom: string
  sex: Sexe
  generation: number
  branch: Branche
  vivant: boolean
  visibilite: Visibilite
  /**
   * **La ligne de dates que le moteur a mesurée — des DATES, pas un nom.**
   *
   * Forme compacte (`"1901-1909 – 1987"`, `"n. 26.9.2012"`, `"† 1972"`, `"—"`
   * quand rien n'est connu) — **et la constante `"vivant·e"` pour toute personne
   * vivante**.
   *
   * Cette clé a changé de nom deux fois pendant le chantier : `ligneDates`, puis
   * `etiquette`, puis `etiquetteDates`. Si vous lisez encore un des deux
   * premiers quelque part, c'est un résidu — le contrat (§ 5.2, § 14.4) et le
   * sérialiseur (`NormaliseurDeGraphe`) disent tous deux `etiquetteDates`.
   *
   * Ne pas la confondre avec `ligneDates`, qui existe toujours — **ailleurs** :
   *
   * | Forme | Clé | Où |
   * |---|---|---|
   * | prose — « entre 1901 et 1909 » | `texte`, **dans** le bloc date | fiche, calque |
   * | compacte, filtrée en aval | `ligneDates` | fiche, recherche, brouillon, barre de chronologie |
   * | compacte, **mesurée** | **`etiquetteDates`** | **nœud du graphe, et nulle part ailleurs** |
   *
   * Le court-circuit sur `vivant` est la raison d'être de la clé séparée :
   * `etiquetteDates` est l'une des deux chaînes sur lesquelles `w` est mesurée, à
   * l'**étage 1 du cache de placement, partagé et sans filtre de
   * confidentialité**. Si elle portait les vraies dates d'une personne vivante,
   * la largeur de sa fiche encoderait leur longueur pour **tous** les lecteurs,
   * y compris hors cercle. `ligneDates`, elle, est filtrée en aval et ne
   * court-circuite jamais : la même personne porte donc deux chaînes
   * différentes dans la même réponse, et c'est correct.
   *
   * Absente — pas `null` — sur un nœud `restreinte` (§ 11.2). Le canevas ne
   * dessine alors pas de ligne sous le nom et **ne la reconstitue jamais** : la
   * boîte reste dimensionnée par le moteur.
   */
  etiquetteDates?: string
  /**
   * **Ce que cette personne est pour le lecteur** — calculé par le serveur, pour
   * le point de vue servi (révision du 2026-10-01, « familles par alliance ») :
   *
   *  - `famille` : ses ancêtres, les descendants de ses ancêtres (lui et sa
   *    descendance compris), et les conjoints de toutes ces personnes — le
   *    conjoint LUI-MÊME, pas sa famille ;
   *  - `conjoint` : la famille du conjoint du lecteur (« Famille du conjoint ») ;
   *  - `alliance` : tout le reste qui est relié — l'ascendance et la parenté d'un
   *    conjoint de collatéral (vu de la nièce, les Provost du mari de sa tante).
   *
   * Ces deux clés se DÉDUISENT des liens, que tout lecteur reçoit déjà : elles
   * n'apprennent rien de plus. Absentes d'un serveur antérieur : le front les
   * recalcule alors sur les liens (`classerParente()`, `histoire/alliances.ts`).
   */
  parente?: Parente
  /**
   * Présente sur un nœud `alliance` seulement : le conjoint « famille » par
   * lequel ce groupe est rattaché (le mari de la tante). Clé de bloc : chaque
   * famille par alliance est mise en page à part, comme sa propre généalogie.
   */
  pont?: IdentifiantNoeud
  /**
   * La fin de recherche du côté du père (ADR 0023) : le motif et son année.
   *
   * **Absente** — jamais `null` — quand il n'y en a pas, et **toujours absente
   * sur un nœud `restreinte`** : elle suit la règle des notes, servie avec la
   * fiche complète et jamais avec la fiche réduite (§ 11.2). L'arbre la tient
   * pour « recherche close » seulement si aucun parent n'est lié de ce côté
   * (`etatDuSommet()`, `@/lib/finDeRecherche`) : un parent connu l'emporte.
   */
  finDeRecherchePere?: ResumeFinDeRecherche
  /** Le pendant, du côté de la mère. Mêmes règles. */
  finDeRechercheMere?: ResumeFinDeRecherche
}

/** Voir `NoeudPersonne.parente`. */
export type Parente = 'famille' | 'conjoint' | 'alliance'

export interface NoeudFamille extends NoeudCommun {
  type: 'famille'
  familyId: number | null
  /** Toute rupture, divorce ou séparation — `married` dit laquelle (ADR 0020). */
  divorced: boolean
  /**
   * La nature de l'union (ADR 0020) : `true` mariés, `false` union libre, `null`
   * on ne sait pas — `null` aussi sur un losange provisoire. Le serveur l'émet
   * toujours ; facultative ici parce que les nœuds bâtis à la main (tests, banc
   * d'essai de `histoire/`) n'ont rien à en dire. Elle ne change **aucun** tracé.
   */
  married?: boolean | null
}

export type NoeudGraphe = NoeudPersonne | NoeudFamille

/**
 * Aucune arête ne porte de marqueur « longue » : `.ar.long` est supprimée
 * (§ 7.3 du dossier, ADR 0007). L'API n'émet ni `long`, ni `distance`, ni
 * `portee` — il n'y a donc rien ici pour réintroduire les pointillés.
 */
export interface AreteGraphe {
  de: IdentifiantNoeud
  vers: IdentifiantNoeud
  genre: 'union' | 'filiation'
  /** N'existe que sur une arête d'union ; allume `.ar.divorce`, conservée. */
  divorce?: boolean
  enAttente?: true
}

/** Alimente directement le `viewBox` initial. **Jamais recalculée côté client.** */
export interface BoiteGraphe {
  x: number
  y: number
  w: number
  h: number
}

/**
 * Une fiche proposée que le graphe **n'a pas su placer**.
 *
 * Elle est omise plutôt que posée faute de mieux : sans ancre, elle atterrirait
 * à `x = 0`, c'est-à-dire sur l'ego. Ce qui compte, c'est qu'elle soit **dite**.
 * `demandeId` et `brouillonRef` sont là pour que l'écran puisse l'ouvrir : la
 * fiche reste consultable même quand le canevas ne sait pas la dessiner.
 *
 * **Le cas qui dominait cette liste n'existe plus.** Une fiche rattachée à une
 * autre fiche de la même demande — un enfant dont le parent est lui-même proposé
 * — tombait, parce que le serveur ne cherchait les ancres qu'une fois et
 * seulement dans l'arbre validé. Il résout désormais par vagues : une branche
 * proposée en bloc se place entièrement. Ce qui reste ici est ce qu'aucune vague
 * ne rattrape — une ancre disparue, ou un enchaînement sans issue.
 */
export interface NoeudNonPlace {
  noeudId: IdentifiantNoeud
  demandeId: number
  /** La référence **sans dièse**, pour l'URL de détail. Jamais découpée ici. */
  brouillonRef: string
  displayName: string
  /** `enchainement`, `ancre_absente`, `sans_couple`, `sans_lien`… */
  raison: string
}

/**
 * Le calque du graphe.
 *
 * > **`actif` ne dit pas « complet ».** Il dit qu'il y a quelque chose en
 * > attente, et rien de plus.
 *
 * Les trois clés suivantes disent ce que `actif` taisait. Mesuré sur un cas
 * réel : cinq demandes portant vingt-deux personnes ont rendu `actif: true`,
 * `demandes: [1,2,3,4,5]` et **cinq nœuds sur vingt-deux**. Rien dans la charge
 * ne signalait l'écart, et le lecteur croyait voir la branche entière.
 *
 * `proposes === places + nonPlaces.length`, toujours : le serveur mesure les
 * deux sur la même sortie. Les trois clés sont **toujours présentes**, à zéro
 * quand il n'y a rien — un compteur absent se lit `undefined`, et
 * `undefined > 0` est faux, ce qui éteindrait l'avertissement en silence.
 */
export interface CalqueDuGraphe {
  actif: boolean
  demandes: number[]
  /** Nombre de fiches proposées que le serveur a reçues pour ce lecteur. */
  proposes: number
  /** Combien d'entre elles sont réellement dans `noeuds`. */
  places: number
  nonPlaces: NoeudNonPlace[]
}

export interface Graphe {
  /** Empreinte de l'arbre, 64 hexadécimaux. Sert au diagnostic ; ce n'est
   *  **pas** l'`ETag`, qui incorpore aussi les demandes du lecteur (§ 5.2). */
  empreinte: string
  ego: IdentifiantNoeud
  boite: BoiteGraphe
  calque: CalqueDuGraphe
  noeuds: NoeudGraphe[]
  aretes: AreteGraphe[]
}

/* ------------------------------------------------------------------ */
/* § 13 — le calque « en attente »                                     */
/* ------------------------------------------------------------------ */

/**
 * Une entrée du calque, pour un champ.
 *
 * `T` est le type de la valeur du champ dans le corps principal : la clé du
 * calque **est** la clé du corps (§ 13.2), il n'y a aucune traduction à faire.
 *
 * `actuel` vaut l'`old_value` de l'op, c'est-à-dire la valeur **au moment de la
 * soumission**. Si la fiche a changé depuis, `actuel` et `fiche[champ]`
 * diffèrent — et cette différence annonce une caducité à venir (§ 6.8). Le front
 * peut l'afficher ; il ne doit pas supposer qu'ils sont égaux.
 */
export interface EntreeCalque<T = unknown> {
  propose: T
  actuel: T
  demandeId: number
  auteur: string
  depuis: string
  /** L'entrée porte la demande la plus ancienne ; les autres sont listées ici. */
  autresDemandes: number[]
}

/**
 * Toujours présente sur une fiche, valant `{}` quand il n'y a rien — « un front
 * qui doit tester `"enAttente" in fiche` écrira un jour le second test sans le
 * premier » (§ 13.1). Sauf sur une personne vivante hors cercle, où la clé fait
 * partie de ce qui n'est pas envoyé (§ 11.1) : d'où l'optionnalité ici.
 */
export type Calque = Record<string, EntreeCalque>

/* ------------------------------------------------------------------ */
/* § 8 — notifications                                                 */
/* ------------------------------------------------------------------ */

/**
 * `title` et `body` sont **déjà rendus** en base et sortent tels quels : la
 * notification ne se recompose pas à la lecture, « sinon un changement de
 * libellé réécrit l'histoire ». Aucune interpolation côté front.
 *
 * `linkRoute` est un **nom de route du front**, pas une URL : une refonte du
 * routage ne casse pas les notifications d'hier. Une route inconnue (notification
 * ancienne) n'est pas un lien, **et pas une erreur** — voir `lienDeNotification()`
 * dans `@/lib/notifications`.
 */
export interface Notification {
  id: number
  kind: string
  title: string
  body: string
  linkRoute: string | null
  linkParams: Record<string, string | number> | null
  readAt: string | null
  createdAt: string
}

/* ------------------------------------------------------------------ */
/* § 6.8 — les issues d'une validation                                 */
/* ------------------------------------------------------------------ */

/**
 * `details` des deux 409 de la validation. Les deux codes donnent des **conseils
 * opposés** ; `rejouable` porte la même information sous forme booléenne pour
 * que le front n'ait pas à connaître la liste des codes (§ 15.4).
 */
export interface DetailsConflitDemande {
  demandeId: number
  statut: string
  rejouable: boolean
  champs?: unknown[]
  cible?: { kind: string; id: number; label: string }
}

/**
 * Ce que couvre un onglet d'Explorer (Chronologie, Carte, Calendrier, Stats) —
 * décision du 05/10/2026. `arbre`, le défaut : l'arbre du lecteur, la famille
 * reliée à sa fiche, sans les personnes sans lien connu. `tout` : toute la base,
 * accordé à l'administration seule — un membre qui le demande reçoit `arbre`.
 */
export type PorteeExplorer = 'arbre' | 'tout'
