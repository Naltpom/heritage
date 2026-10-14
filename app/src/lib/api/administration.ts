/**
 * Les appels d'API de l'administration — § 3 et § 10 du contrat.
 *
 * Invitations, utilisateurs, et la bascule « voir comme un membre ».
 *
 * **Le piège de ce module tient en une phrase : il n'existe aucune route
 * « renvoyer l'invitation ».** Le serveur ne stocke que `hash(jeton)`, et le
 * lien en clair n'apparaît **que** dans la réponse de création, une seule fois
 * (§ 3.2). Un administrateur qui a perdu le lien **révoque et recrée** — c'est
 * écrit ainsi dans le contrat. La route paraît manquante alors qu'elle est
 * impossible, et c'est le bouton le plus probable qu'on ajouterait par erreur.
 *
 * D'où la forme des deux types ci-dessous : `InvitationCreee` porte `lien`,
 * `Invitation` ne le porte pas — pas à `null`, **absent** (§ 1.3). Le typeur
 * refuse donc de lire `lien` sur une ligne de la liste, ce qui est exactement la
 * garantie qu'on veut.
 */

import { requeteApi } from './client'
import type {
  ChargeDeSession,
  ListePaginee,
  PartageNaissance,
  PartageNaissanceEnregistre,
  StatutCompte,
  TypeRattachement,
} from './types'
import type { CorpsCreationPersonne } from './propositions'
import type { ChangementPropose, PersonneCitee } from './assistant'

/* ------------------------------------------------------------------ */
/* § 3 — invitations                                                   */
/* ------------------------------------------------------------------ */

export type StatutInvitation = 'active' | 'utilisee' | 'expiree' | 'revoquee'

/** § 3.3 — une ligne de la liste. **Sans `lien`** : il n'existe plus. */
export interface Invitation {
  id: number
  /** Les 8 premiers caractères du jeton, pour distinguer deux lignes à l'œil.
   *  8 sur 43 ne reconstruisent rien. */
  tokenPrefix: string
  personId: number | null
  personLabel: string | null
  email: string | null
  status: StatutInvitation
  /** `null` : le lien n'expire jamais. */
  expiresAt: string | null
  /** `null` : nombre d'inscriptions illimité. `utilisee` = plafond atteint. */
  maxUses: number | null
  useCount: number
  /** Les comptes nés de ce lien, du plus ancien au plus récent. */
  inscrits: InscritParInvitation[]
  usedAt: string | null
  usedByEmail: string | null
  revokedAt: string | null
  revokedByEmail: string | null
  createdByEmail: string
  createdAt: string
  note: string | null
  /** Tentatives de rattachement de l'heure écoulée : c'est ce qui permet de voir
   *  qu'un lien est en train d'être sondé. Les critères soumis, eux, ne sont
   *  jamais rendus — `query_hash` est une empreinte, pas un texte (§ 16). */
  tentatives: number
}

/** Un compte créé par un lien. `personId`/`personLabel` : ensemble ou absents
 *  (porte de sortie n° 8, filtrée comme la personne elle-même). */
export interface InscritParInvitation {
  userId: number
  email: string
  memberStatus: string
  createdAt: string
  personId?: number
  personLabel?: string
  /** `a_rattacher` : inscrit sans s'être trouvé dans l'arbre, fiche à
   *  identifier dans l'onglet « À rattacher ». */
  matchKind?: TypeRattachement
}

export interface AvertissementApi {
  code: string
  message: string
}

/**
 * § 3.2 — **le seul moment où le jeton existe en clair.**
 *
 * `lien` est une URL **absolue**, bâtie sur le `Host` de la requête — c'est
 * pour cela que le proxy Vite pose `changeOrigin: false`. Le front l'affiche
 * **tel quel** et ne la reconstruit jamais : le jeton est dans le **chemin**
 * (`/inscription/<jeton>`), et recomposer l'URL à partir du `tokenPrefix` ne
 * produirait qu'un lien mort.
 */
export interface InvitationCreee extends Invitation {
  lien: string
  courrielEnvoye: boolean
  avertissements: AvertissementApi[]
}

/** `POST /api/administration/invitations` (§ 3.1). */
export function creerInvitation(entree: {
  /** La description du lien — obligatoire. */
  note: string
  /** `null` : illimité. */
  maxUses: number | null
  /** `null` : n'expire jamais. */
  expiresInDays: number | null
}): Promise<InvitationCreee> {
  return requeteApi<InvitationCreee>('/administration/invitations', {
    methode: 'POST',
    corps: { note: entree.note, maxUses: entree.maxUses, expiresInDays: entree.expiresInDays },
  })
}

/** `GET /api/administration/invitations` — liste paginée, `createdAt DESC`. */
export function listerInvitations(options: {
  status?: StatutInvitation | undefined
  /** `false` : les révoquées sont écartées côté serveur. */
  revoquees?: boolean | undefined
  page?: number | undefined
  parPage?: number | undefined
}): Promise<ListePaginee<Invitation>> {
  return requeteApi<ListePaginee<Invitation>>('/administration/invitations', {
    requete: {
      status: options.status,
      revoquees: options.revoquees === undefined ? undefined : options.revoquees ? 1 : 0,
      page: options.page,
      parPage: options.parPage,
    },
  })
}

/**
 * `POST /api/administration/invitations/{id}/revoquer`.
 *
 * Révoquer arrête les inscriptions à venir ; les comptes déjà créés restent.
 * Sur un lien **complet** : `409 invitation_deja_consommee`.
 */
export function revoquerInvitation(id: number): Promise<Invitation> {
  return requeteApi<Invitation>(`/administration/invitations/${id}/revoquer`, {
    methode: 'POST',
  })
}

/* ------------------------------------------------------------------ */
/* § 10.1 — utilisateurs et membres                                    */
/* ------------------------------------------------------------------ */

export interface MembreAdministration {
  id: number
  status: StatutCompte
  /** **Absents** quand le cercle ne les laisse pas voir, et toujours absents
   *  pour un compte `a_rattacher` (il n'a pas de fiche). `null` reste admis :
   *  les deux se lisent `!= null`. */
  personId?: number | null
  personLabel?: string | null
  matchKind: TypeRattachement
  invitationId: number | null
  linkedAt: string | null
  activatedAt: string | null
  /** ADR 0016 — ce que ce membre partage de sa date de naissance. Absent d'un
   *  serveur antérieur. */
  partageNaissance?: PartageNaissanceEnregistre
}

export interface UtilisateurAdministration {
  id: number
  email: string
  /** Facultatif, donné à l'inscription ; `null` sinon. Absent d'un serveur antérieur. */
  telephone?: string | null
  displayName: string
  roles: string[]
  status: StatutCompte
  totpEnabledAt: string | null
  lastLoginAt: string | null
  createdAt: string
  /** `null` pour un compte sans rattachement (le compte d'amorçage). */
  membre: MembreAdministration | null
  /**
   * ADR 0016 — ce compte voit les dates de naissance des vivants **sans** la
   * réciprocité : les siennes ne conditionnent plus celles des autres. Les
   * photos restent floutées tant qu'il n'a pas la sienne. Absent d'un serveur
   * antérieur, et alors faux.
   */
  ignoreRestrictionNaissance?: boolean
  /** Les quotas comptés par compte, dans l'ordre du serveur (§ 10.1, « Quotas d'usage »). */
  quotas: QuotaDeCompte[]
}

/**
 * Un quota d'usage tel que l'administration le lit : consommé sur la fenêtre
 * glissante (lu sans être décompté) et limite **effective** — la personnelle
 * si `personnalisee`, sinon le défaut de configuration.
 *
 * Le front ne connaît aucune clé d'avance : il affiche ce que le serveur rend,
 * dans son ordre. Un quota de plus côté serveur apparaît sans une ligne ici.
 */
export interface QuotaGlobal {
  cle: string
  libelle: string
  /** « par 24 h », « par heure » — déjà lisible. */
  fenetre: string
  consomme: number
  limite: number
  limiteParDefaut: number
  personnalisee: boolean
}

export interface QuotaDeCompte extends QuotaGlobal {
  /** L'administrateur n'est pas compté sur ce quota (téléversements). */
  exempte: boolean
}

/** `null` rend le défaut ; un entier de 0 à 10 000 devient la limite ; 0 ferme l'usage. */
export type LimitesDeQuotas = Record<string, number | null>

/** `PATCH /api/administration/utilisateurs/{id}/quotas` — rend la ligne du compte à jour. */
export function modifierQuotasUtilisateur(
  id: number,
  limites: LimitesDeQuotas,
): Promise<UtilisateurAdministration> {
  return requeteApi<UtilisateurAdministration>(`/administration/utilisateurs/${id}/quotas`, {
    methode: 'PATCH',
    corps: { limites },
  })
}

/** `GET /api/administration/quotas` — les quotas à clé globale (rattachement assisté). */
export function listerQuotasGlobaux(): Promise<{ quotas: QuotaGlobal[] }> {
  return requeteApi<{ quotas: QuotaGlobal[] }>('/administration/quotas')
}

/** `PATCH /api/administration/quotas`. */
export function modifierQuotasGlobaux(limites: LimitesDeQuotas): Promise<{ quotas: QuotaGlobal[] }> {
  return requeteApi<{ quotas: QuotaGlobal[] }>('/administration/quotas', {
    methode: 'PATCH',
    corps: { limites },
  })
}

/**
 * `GET /api/administration/utilisateurs` (§ 10.1) — **filtrée et paginée côté
 * serveur** (2026-10-14) : `q` cherche dans le nom affiché, le courriel, le nom
 * et le numéro de la fiche rattachée ; `role: 'admin'` ne garde que les
 * administrateurs.
 */
export function listerUtilisateurs(options: {
  status?: StatutCompte | undefined
  role?: 'admin' | undefined
  q?: string | undefined
  page?: number | undefined
  parPage?: number | undefined
}): Promise<ListePaginee<UtilisateurAdministration>> {
  return requeteApi<ListePaginee<UtilisateurAdministration>>('/administration/utilisateurs', {
    requete: {
      status: options.status,
      role: options.role,
      q: options.q === '' ? undefined : options.q,
      page: options.page,
      parPage: options.parPage,
    },
  })
}

/** Les compteurs des puces de l'écran Utilisateurs, indépendants de la page et de la recherche. */
export interface CompteursUtilisateurs {
  tous: number
  en_attente: number
  actif: number
  suspendu: number
  admins: number
}

/** `GET /api/administration/utilisateurs/compteurs`. */
export function compterUtilisateurs(): Promise<CompteursUtilisateurs> {
  return requeteApi<CompteursUtilisateurs>('/administration/utilisateurs/compteurs')
}

/**
 * `PATCH /api/administration/utilisateurs/{id}` (§ 10.1).
 *
 * `status` accepte `actif` et `suspendu`, **jamais `en_attente`** : on ne remet
 * pas un compte en attente, on le suspend. Et retirer `ROLE_ADMIN` au dernier
 * administrateur rend `409 dernier_administrateur` — se verrouiller dehors est
 * un incident sans porte de secours autre que la console.
 */
export function modifierUtilisateur(
  id: number,
  champs: {
    roles?: string[] | undefined
    status?: 'actif' | 'suspendu' | undefined
    displayName?: string | undefined
    /** ADR 0016 — lève la réciprocité sur les dates (jamais sur les photos). */
    ignoreRestrictionNaissance?: boolean | undefined
  },
): Promise<UtilisateurAdministration> {
  return requeteApi<UtilisateurAdministration>(`/administration/utilisateurs/${id}`, {
    methode: 'PATCH',
    corps: champs,
  })
}

/**
 * Une ligne de `GET /api/administration/membres` — la file des comptes
 * `en_attente` : la charge membre, plus la photo déposée à l'inscription.
 *
 * `portraitEnAttente` est la raison d'être de cette lecture côté front : à la
 * validation, cette photo **devient le portrait de la fiche** (ADR 0013). Un
 * compte qui s'est trouvé (`recherche_confirmee`) se valide depuis l'écran
 * Utilisateurs, dont la liste — servie par `Core` — ne la porte pas ; sans
 * elle, l'administrateur publierait une photo qu'il n'a jamais vue.
 */
export interface MembreDeLaFile extends MembreAdministration {
  portraitEnAttente: PhotoARattacher | null
}

/** `GET /api/administration/membres` — les comptes en attente, du plus ancien au plus récent. */
export function listerMembresEnAttente(): Promise<ListePaginee<MembreDeLaFile>> {
  return requeteApi<ListePaginee<MembreDeLaFile>>('/administration/membres')
}

/**
 * `POST /api/administration/membres/{id}/activer` (§ 10.1).
 *
 * **C'est le moment exact où le régime change** : `mbr_member.status` et
 * `core_user.status` passent à `actif`, et le compte **obtient un cercle** — il
 * n'en avait pas, ce n'était pas un cercle réduit mais un régime distinct
 * (§ 12). `personId` corrige au passage un rattachement erroné.
 */
export function activerMembre(
  id: number,
  options: { personId?: number | undefined } = {},
): Promise<MembreAdministration> {
  return requeteApi<MembreAdministration>(`/administration/membres/${id}/activer`, {
    methode: 'POST',
    corps: { personId: options.personId ?? null },
  })
}

/* ------------------------------------------------------------------ */
/* Les inscriptions à rattacher (ADR 0013)                             */
/* ------------------------------------------------------------------ */

/*
 * Un compte `a_rattacher` est né d'un « Je ne me trouve pas » : la personne a
 * décrit sa famille en texte libre au lieu de désigner une fiche. Son compte
 * est `en_attente`, **sans fiche**, donc sans cercle — et c'est à
 * l'administrateur de dire qui elle est.
 *
 * **La description parle de vivants.** Elle n'est rendue qu'ici, sous
 * `/api/administration` ; le front ne l'écrit dans aucun stockage, et le
 * serveur la purge au rattachement comme au refus. Sous la bascule « voir
 * comme », la liste rend `409 vue_comme_membre_active` : une description ne se
 * filtre pas par un cercle.
 *
 * Les types sont **préfixés** (`…ARattacher`, `…DeRattachement`) : ce module
 * est ré-exporté par `export *`, et un `Declaration` ou un `Suggestion` nu
 * entrerait en collision avec ceux de l'inscription ou de l'assistant.
 */

/** Ce que la personne a saisi en s'inscrivant — nom, prénoms, date **exacte**. */
export interface DeclarationARattacher {
  surname: string
  givenNames: string
  /** `YYYY-MM-DD`. */
  birthDate: string
  /**
   * Texte libre : « Mon père est… ». 60 à 4 000 caractères — ou `null`, ou
   * court, quand la personne a laissé un message vocal à la place.
   */
  description: string | null
}

/**
 * Le message vocal de la déclaration (2 octobre 2026). Décrit ici, jamais
 * transporté : le son sort par `url`, une route `ROLE_ADMIN` qui sert les
 * requêtes partielles — un `<audio controls>` s'y branche tel quel.
 */
export interface MessageVocalARattacher {
  /** Écrite par le serveur, jamais reconstruite ici. */
  url: string
  /** `audio/webm`, `audio/mp4` ou `audio/ogg` — lu sur les octets. */
  type: string
  /** En octets. */
  taille: number
  /** En secondes, mesurée par le chrono du navigateur ; `null` si elle n'a pas été donnée. */
  duree: number | null
  /** Sa transcription automatique (Gemini, 2 octobre 2026 au soir). */
  transcription: TranscriptionARattacher
}

/**
 * L'état de la transcription automatique d'un message vocal.
 *
 *  - `a_faire` — jamais tentée : « Transcrire le message vocal » la lance —
 *    rien ne part tout seul depuis le 3 octobre 2026 ;
 *  - `faite`   — `texte` est là, verbatim, à vérifier à l'écoute — et à
 *    corriger : `corrigeeLe` dit qu'un administrateur l'a relu et enregistré ;
 *  - `echec`   — la dernière tentative a échoué, `erreur` dit pourquoi
 *    (« Réessayer »). L'audio, lui, reste écoutable.
 *
 * **Le texte parle de vivants**, comme la description : il n'est écrit dans
 * aucun stockage du navigateur.
 */
export interface TranscriptionARattacher {
  statut: 'a_faire' | 'faite' | 'echec'
  texte: string | null
  erreur: string | null
  /** ISO 8601 de la dernière tentative, ou `null` avant la première. */
  le: string | null
  /**
   * ISO 8601 de la dernière **correction à la main** (3 octobre 2026), ou
   * `null` : le texte est celui du modèle. Absent d'un serveur antérieur.
   */
  corrigeeLe?: string | null
}

/** La photo déposée à l'inscription, visible des seuls administrateurs (et du
 *  titulaire). URL écrites par le serveur, jamais reconstruites ici. */
export interface PhotoARattacher {
  mediaId: number
  url: string
  vignetteUrl: string
}

/** Une ligne de `GET /api/administration/rattachements`. */
export interface CompteARattacher {
  memberId: number
  userId: number
  email: string
  inscritLe: string
  invitationId: number | null
  /** La personne pré-liée par le lien utilisé, s'il en désignait une — un
   *  indice, pas une preuve : la personne ne s'y est justement pas reconnue. */
  invitationPersonId: number | null
  invitationPersonLabel: string | null
  declaration: DeclarationARattacher
  messageVocal: MessageVocalARattacher | null
  photo: PhotoARattacher | null
  /** ISO 8601, ou `null` si aucune analyse n'est encore en cache. */
  suggestionCalculeeLe: string | null
}

export type ConfianceDeRattachement = 'haute' | 'moyenne' | 'faible'

/** Une fiche que l'analyse désigne ou cite. `ligneDates` vaut `''` sans date. */
export interface FicheSuggeree {
  personId: number
  displayName: string
  ligneDates: string
  justification: string
}

/** Ce que l'analyse propose de créer quand la personne n'est pas dans l'arbre. */
export interface CreationSuggeree {
  titre: string
  justification: string
  confiance: ConfianceDeRattachement
  /** `#p1` est le titulaire du compte ; `#p2`… des parents absents de l'arbre. */
  corps: CorpsCreationPersonne
  changements: ChangementPropose[]
  personnes: PersonneCitee[]
  /** Le résultat de la préparation côté serveur : `false` = il manque un
   *  lien vers l'arbre, et l'administrateur doit choisir un parent. */
  recevable: boolean
  motifIrrecevable: string | null
}

/**
 * La suggestion calculée par le module Assistant (Gemini) — § 4 du contrat du
 * chantier. **Aucun rattachement n'en découle automatiquement** : elle éclaire
 * une décision que l'administrateur prend.
 *
 * `displayName`, `ligneDates` et `dejaRattachee` sont recalculés à chaque
 * lecture, même en cache : une fiche réclamée entre-temps éteint « Rattacher ».
 */
export interface SuggestionDeRattachement {
  memberId: number
  calculeeLe: string
  modele: string
  verdict: 'fiche_existante' | 'absente' | 'indecise'
  resume: string
  avertissements: string[]
  /** Non nul ⇔ `verdict === 'fiche_existante'`. */
  fiche:
    | null
    | (FicheSuggeree & {
        confiance: ConfianceDeRattachement
        /** Réclamée par un autre compte : « Rattacher » est grisé. */
        dejaRattachee: boolean
      })
  /** 0 à 3 autres fiches plausibles. */
  alternatives: FicheSuggeree[]
  /** Non nul si `absente`, facultatif si `indecise`. */
  creation: CreationSuggeree | null
  /** L'analyse a-t-elle lu la transcription du message vocal ? */
  transcriptionPriseEnCompte: boolean
  /**
   * La transcription a changé depuis ce calcul (arrivée, retranscrite ou
   * corrigée à la main) : la recherche n'a pas lu ce que l'écran montre.
   * « Relancez la recherche ». Absent d'un serveur antérieur.
   */
  perimee?: boolean
}

/** `201` de « Créer la fiche et rattacher ». */
export interface ResultatCreationEtRattachement {
  membre: MembreAdministration
  personId: number
  demandeId: number
}

/** `GET /api/administration/rattachements` — du plus ancien au plus récent. */
export function listerRattachements(): Promise<ListePaginee<CompteARattacher>> {
  return requeteApi<ListePaginee<CompteARattacher>>('/administration/rattachements')
}

/**
 * `GET …/rattachements/{memberId}/suggestion` — la suggestion **déjà
 * calculée**, ou `null` (`204`) s'il n'y en a pas. **Lecture seule** depuis le
 * 3 octobre 2026 : ni modèle, ni quota, ni clé exigée — c'est
 * {@link relancerSuggestionDeRattachement}, derrière un clic, qui calcule.
 *
 * `perimee` dit que la transcription a changé depuis le calcul.
 */
export async function lireSuggestionDeRattachement(
  memberId: number,
  signal?: AbortSignal,
): Promise<SuggestionDeRattachement | null> {
  return (
    (await requeteApi<SuggestionDeRattachement | null>(
      `/administration/rattachements/${memberId}/suggestion`,
      signal === undefined ? {} : { signal },
    )) ?? null
  )
}

/**
 * `POST …/rattachements/{memberId}/transcription` — transcrit le message vocal
 * (ou recommence : « Réessayer », « Retranscrire »), 10 à 60 s. Consomme le
 * quota global des rattachements assistés. Une correction à la main est
 * **remplacée** par le texte du modèle (`corrigeeLe` repasse à `null`).
 *
 * Rend **`200` même en échec** (`statut: 'echec'`, avec le motif) : l'état est
 * la réponse. `503` sans clé, `429` quota, `404` compte décidé ou sans vocal.
 */
export function transcrireMessageVocal(
  memberId: number,
  signal?: AbortSignal,
): Promise<TranscriptionARattacher & { memberId: number }> {
  return requeteApi<TranscriptionARattacher & { memberId: number }>(
    `/administration/rattachements/${memberId}/transcription`,
    signal === undefined ? { methode: 'POST' } : { methode: 'POST', signal },
  )
}

/**
 * `PATCH …/rattachements/{memberId}/transcription` — enregistre la
 * transcription **corrigée à la main** (ou écrite, quand le modèle n'en a pas
 * rendu). Ni modèle, ni quota. `422` texte vide ou de plus de
 * {@link TRANSCRIPTION_MAX} caractères ; `404` compte décidé ou sans vocal. Une
 * suggestion déjà calculée devient `perimee`.
 */
export function corrigerTranscription(
  memberId: number,
  texte: string,
): Promise<TranscriptionARattacher & { memberId: number }> {
  return requeteApi<TranscriptionARattacher & { memberId: number }>(
    `/administration/rattachements/${memberId}/transcription`,
    { methode: 'PATCH', corps: { texte } },
  )
}

/** La borne du serveur (`TranscriptionDesMessagesVocaux::TEXTE_MAX`). */
export const TRANSCRIPTION_MAX = 16000

/**
 * `POST` sur la route de la suggestion : « Lancer la recherche » ou « Relancer
 * la recherche ». 20 à 60 s ; consomme le quota global. `503` sans clé, `502`
 * modèle en panne, `429` quota atteint (`Retry-After`).
 */
export function relancerSuggestionDeRattachement(
  memberId: number,
  signal?: AbortSignal,
): Promise<SuggestionDeRattachement> {
  return requeteApi<SuggestionDeRattachement>(
    `/administration/rattachements/${memberId}/suggestion`,
    signal === undefined ? { methode: 'POST' } : { methode: 'POST', signal },
  )
}

/**
 * `POST …/rattachements/{memberId}/creer-et-rattacher` — dépose la demande de
 * création, la valide, rattache et active le compte, **en une transaction** :
 * une erreur n'écrit rien.
 *
 * Le corps de la demande est construit **côté serveur** (suggestion, sinon
 * déclaration) : le front ne transmet que les parents choisis, 0 à 2.
 * `422 demande_sans_lien` : ni la suggestion ni `parents` ne rattachent la
 * fiche à l'arbre.
 */
export function creerFicheEtRattacher(
  memberId: number,
  options: { parents?: number[] | undefined; message?: string | undefined } = {},
): Promise<ResultatCreationEtRattachement> {
  const corps: { parents: number[]; message?: string } = { parents: options.parents ?? [] }
  const message = options.message?.trim() ?? ''
  if (message !== '') corps.message = message
  return requeteApi<ResultatCreationEtRattachement>(
    `/administration/rattachements/${memberId}/creer-et-rattacher`,
    { methode: 'POST', corps },
  )
}

/**
 * `POST /api/administration/membres/{id}/refuser` — refuser une inscription
 * en attente. Le compte est **suspendu** (il ne se connecte plus) et sa
 * déclaration, la suggestion et la photo en attente sont purgées. Le courriel
 * reste réservé. `409 compte_deja_decide` si le compte n'est plus en attente.
 */
export function refuserInscription(memberId: number): Promise<MembreAdministration> {
  return requeteApi<MembreAdministration>(`/administration/membres/${memberId}/refuser`, {
    methode: 'POST',
  })
}

/* ------------------------------------------------------------------ */
/* § 10.4 — la bascule « voir comme un membre »                        */
/* ------------------------------------------------------------------ */

/**
 * `POST /api/administration/voir-comme`.
 *
 * Elle **remplace un cercle par un autre**, elle n'en supprime jamais un : le
 * normaliseur n'a pas de mode « sans filtre », et l'administrateur passe par un
 * cercle complet, jamais par un `if ($estAdmin) { return $tout; }` (ADR 0003).
 *
 * Elle est portée par la **session**, pas par un en-tête par requête : une
 * bascule qu'on oublierait de rejouer sur un appel ne vérifierait plus rien.
 *
 * Deux conséquences que l'appelant doit traiter, et que cette fonction ne peut
 * pas traiter à sa place :
 *
 * 1. **recharger la session** — c'est elle qui porte `voirComme` et le cercle ;
 * 2. **oublier l'`ETag` du graphe** — il incorpore le membre emprunté (§ 5.2).
 *    Sans cela, l'administrateur revalide, reçoit `304`, et regarde **son
 *    propre graphe non filtré** en croyant tester celui de quelqu'un d'autre.
 *
 * `rafraichirSession()` fait les deux d'un coup : c'est par lui qu'il faut
 * passer, et jamais en posant `session.value` à la main.
 */
export function activerVueComme(memberId: number): Promise<ChargeDeSession> {
  return requeteApi<ChargeDeSession>('/administration/voir-comme', {
    methode: 'POST',
    corps: { memberId },
  })
}

/**
 * `POST /api/administration/incarnation` — se connecter **en tant que** ce
 * compte (ADR 0010). Rend la charge de session du compte incarné ; l'appelant
 * passe ensuite par `rafraichirSession()`, pour la même raison que la bascule.
 */
export function incarnerUtilisateur(userId: number): Promise<ChargeDeSession> {
  return requeteApi<ChargeDeSession>('/administration/incarnation', { methode: 'POST', corps: { userId } })
}

/**
 * `DELETE /api/session/incarnation` — revenir à son propre compte. Hors de
 * `/administration` : pendant l'incarnation, le compte connecté n'est pas
 * administrateur.
 */
export function quitterIncarnation(): Promise<ChargeDeSession> {
  return requeteApi<ChargeDeSession>('/session/incarnation', { methode: 'DELETE' })
}

/* ------------------------------------------------------------------ */
/* § 10.7 — les sources (ADR 0012)                                     */
/* ------------------------------------------------------------------ */

/** Les valeurs de `SourceKind` côté serveur — `Tree\Enum\SourceKind`. */
export type GenreDeSource =
  | 'acte_etat_civil'
  | 'recensement'
  | 'registre_officiel'
  | 'arbre_tiers'
  | 'publication'
  | 'temoignage'
  | 'autre'

/** Les libellés, dans l'ordre de l'énumération PHP (`SourceKind::label()`). */
export const GENRES_DE_SOURCE: readonly { valeur: GenreDeSource; libelle: string }[] = [
  { valeur: 'acte_etat_civil', libelle: 'Acte d’état civil' },
  { valeur: 'recensement', libelle: 'Recensement' },
  { valeur: 'registre_officiel', libelle: 'Registre officiel' },
  { valeur: 'arbre_tiers', libelle: 'Arbre d’un autre utilisateur' },
  { valeur: 'publication', libelle: 'Publication' },
  { valeur: 'temoignage', libelle: 'Témoignage familial' },
  { valeur: 'autre', libelle: 'Autre' },
]

/**
 * Une source — la **notice** d'un fonds, pas son usage : la page et le `QUAY`
 * sont portés par la citation. `gedcomXref` n'est jamais envoyé : il appartient
 * au ré-import GEDCOM, et une source créée à l'écran le garde à `null`.
 */
export interface SourceAdministration {
  id: number
  gedcomXref: string | null
  title: string
  author: string | null
  publication: string | null
  text: string | null
  note: string | null
  mediaType: string | null
  sourceKind: GenreDeSource
  sourceKindLabel: string
  /** Combien de citations de l'arbre reposent sur cette source. */
  citationCount: number
  createdAt: string
  updatedAt: string
}

/** Le corps de `POST` et de `PUT`. `PUT` **remplace** : un champ omis est vidé. */
export interface NoticeDeSource {
  title: string
  sourceKind: GenreDeSource
  author: string | null
  publication: string | null
  note: string | null
}

/** `GET /api/administration/sources` — triée par titre, `q` sans accents ni casse. */
export function listerSources(options: {
  q?: string | undefined
  page?: number | undefined
  parPage?: number | undefined
}): Promise<ListePaginee<SourceAdministration>> {
  return requeteApi<ListePaginee<SourceAdministration>>('/administration/sources', {
    requete: { q: options.q, page: options.page, parPage: options.parPage },
  })
}

/** `POST /api/administration/sources` — `201`, ou `422` avec `champs`. */
export function creerSource(notice: NoticeDeSource): Promise<SourceAdministration> {
  return requeteApi<SourceAdministration>('/administration/sources', { methode: 'POST', corps: notice })
}

/**
 * `PUT /api/administration/sources/{id}`. Le `text` et le `mediaType` d'une
 * source importée ne sont pas éditables à l'écran : on les renvoie tels quels,
 * sans quoi le remplacement les viderait.
 */
export function modifierSource(
  source: SourceAdministration,
  notice: NoticeDeSource,
): Promise<SourceAdministration> {
  return requeteApi<SourceAdministration>(`/administration/sources/${source.id}`, {
    methode: 'PUT',
    corps: { ...notice, text: source.text, mediaType: source.mediaType },
  })
}

/** `DELETE /api/administration/voir-comme` — retour à la session normale. */
export function quitterVueComme(): Promise<ChargeDeSession> {
  return requeteApi<ChargeDeSession>('/administration/voir-comme', { methode: 'DELETE' })
}

/**
 * `GET /api/session/quotas` — **ses propres** quotas, limite effective comprise.
 * Lire ne décompte rien. Sert aux écrans de l'assistant, qui annoncent ce qui reste
 * avant le premier appel au lieu d'un chiffre écrit en dur.
 */
export function lireMesQuotas(): Promise<{ quotas: QuotaDeCompte[] }> {
  return requeteApi<{ quotas: QuotaDeCompte[] }>('/session/quotas')
}

/** Ce qui reste d'un quota, ou `null` s'il est inconnu (lecture en échec, clé absente). */
export function resteDuQuota(quotas: readonly QuotaDeCompte[], cle: string): number | null {
  const quota = quotas.find((q) => q.cle === cle)
  if (quota === undefined || quota.exempte) return null
  return Math.max(0, quota.limite - quota.consomme)
}

/* ------------------------------------------------------------------ */
/* ADR 0016 — l'autorisation signée d'une personne vivante sans compte */
/* ------------------------------------------------------------------ */

/*
 * Une personne vivante **sans compte** ne peut pas choisir elle-même ce qu'elle
 * partage de sa date de naissance. Sans rien, le défaut tient : ses proches.
 * Un administrateur peut déposer son **autorisation signée** (PDF ou photo du
 * papier) et poser ce qu'elle accepte, composante par composante : tous,
 * proches, personne.
 *
 * Dès qu'un compte est rattaché à la fiche, c'est **son** choix qui compte :
 * l'autorisation est archivée (`compte_rattache`) et un nouveau dépôt rend
 * `409 compte_rattache`. Une personne décédée n'est pas concernée (`422`).
 */

/** Pourquoi une autorisation n'est plus active. */
export type RaisonArchivageAutorisation = 'remplacee' | 'compte_rattache' | 'retiree'

export interface AutorisationNaissance extends PartageNaissance {
  id: number
  note: string | null
  mime: string
  taille: number
  sha256: string
  accordeeLe: string
  accordeeParUserId: number | null
  archiveeLe: string | null
  raisonArchivage: RaisonArchivageAutorisation | null
  /** URL **relative** du document, écrite par le serveur — route d'administration. */
  documentUrl: string
}

export interface EtatAutorisationNaissance {
  /** Un compte est rattaché : seul son propre choix compte. */
  compteRattache: boolean
  /** L'autorisation active, ou `null`. */
  autorisation: AutorisationNaissance | null
  /** Les autorisations archivées, de la plus récente à la plus ancienne. */
  historique: AutorisationNaissance[]
}

/** `GET /api/administration/personnes/{id}/autorisation-naissance`. */
export function lireAutorisationNaissance(personId: number): Promise<EtatAutorisationNaissance> {
  return requeteApi<EtatAutorisationNaissance>(`/administration/personnes/${personId}/autorisation-naissance`)
}

/**
 * `POST /api/administration/personnes/{id}/autorisation-naissance` —
 * `multipart/form-data`. Le document (PDF, JPEG, PNG ou WebP, 10 Mo au plus)
 * et les trois niveaux (`tous`, `proches`, `personne`). Une autorisation active est
 * remplacée (archivée en `remplacee`). Aucun `Content-Type` posé : voir
 * `televerserMedia()`.
 */
export function deposerAutorisationNaissance(
  personId: number,
  envoi: PartageNaissance & { document: File; note?: string | undefined },
): Promise<EtatAutorisationNaissance> {
  const formulaire = new FormData()
  formulaire.append('document', envoi.document, envoi.document.name)
  formulaire.append('annee', envoi.annee)
  formulaire.append('mois', envoi.mois)
  formulaire.append('jour', envoi.jour)
  const note = envoi.note?.trim() ?? ''
  if (note.length > 0) formulaire.append('note', note)
  return requeteApi<EtatAutorisationNaissance>(`/administration/personnes/${personId}/autorisation-naissance`, {
    methode: 'POST',
    corps: formulaire,
  })
}

/** `DELETE` — l'autorisation active est archivée (`retiree`), jamais effacée. */
export function retirerAutorisationNaissance(personId: number): Promise<EtatAutorisationNaissance> {
  return requeteApi<EtatAutorisationNaissance>(`/administration/personnes/${personId}/autorisation-naissance`, {
    methode: 'DELETE',
  })
}
