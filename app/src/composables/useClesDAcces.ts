/**
 * Les clés d'accès (passkeys) — l'enchaînement des cérémonies, la mémoire de
 * l'appareil, et l'invitation qui s'ouvre à l'arrivée dans l'application.
 *
 * Trois couches, trois fichiers :
 *
 * - `lib/api/clesDAcces.ts` parle au serveur ;
 * - `lib/cleDAcces.ts` parle au navigateur (base64url ↔ `ArrayBuffer`, et ce
 *   que veulent dire ses erreurs) ;
 * - ici, on enchaîne : options du serveur → navigateur → réponse au serveur.
 *
 * ── Le geste de l'utilisateur ───────────────────────────────────────────────
 *
 * Safari n'ouvre la fenêtre Face ID que si `credentials.create()` / `get()`
 * part **dans** le geste (le toucher du bouton). Un `await fetch(options)`
 * entre le toucher et l'appel suffit, sur un iPhone, à faire refuser la
 * cérémonie (`NotAllowedError`, comme si on avait annulé). Les options sont
 * donc **préparées d'avance** — juste avant d'ouvrir l'invitation, au montage de la
 * connexion — et l'appel au navigateur part sans attente. Le serveur garde le
 * défi cinq minutes : on le renouvelle à quatre.
 *
 * ── La mémoire de l'appareil ────────────────────────────────────────────────
 *
 * Le serveur sait quelles clés a un compte ; il ne sait pas lesquelles vivent
 * **sur ce téléphone-ci**. On le retient localement (`localStorage`, protégé :
 * navigation privée de Safari = rien de retenu, et c'est sans gravité — au
 * pire l'invitation revient, et le navigateur répond « déjà présente » grâce à
 * `excludeCredentials`). Ce n'est pas un secret : des identifiants de compte et
 * de clé, que le serveur rend de toute façon à qui est connecté.
 */
import { getCurrentScope, onScopeDispose, readonly, shallowRef, watch, type Ref } from 'vue'
import {
  enregistrerCleDAcces,
  lireOptionsConnexionCleDAcces,
  lireOptionsEnregistrementCleDAcces,
  listerClesDAcces,
  type ChargeDeSession,
  type CleDAcces,
} from '@/lib/api'
// Depuis `erreurs` et non l'index : les tests simulent `@/lib/api` en entier.
import { ErreurApi } from '@/lib/api/erreurs'
import { connecterParCleDAcces, useSession } from '@/composables/useSession'
import {
  environnementCleDAcces,
  issueDeLErreur,
  libelleConnexionCleDAcces,
  messageDeLIssue,
  nomParDefautDeLAppareil,
  optionsDeCreation,
  optionsDeRequete,
  reponseEnJson,
  systemeDeLAgent,
  type IdentifiantBrut,
  type SystemeAppareil,
} from '@/lib/cleDAcces'
import { libererApresLaCleDAcces, marquerOuverte, reserverPourLaCleDAcces } from './fenetresAutomatiques'

/** Le serveur garde un défi cinq minutes ; on n'en sert pas un de plus de quatre. */
export const DUREE_OPTIONS_MS = 4 * 60 * 1000

/* ------------------------------------------------------------------ */
/* Mémoire locale                                                      */
/* ------------------------------------------------------------------ */

/** Clé `localStorage` : `{ "<id du compte>": [ids des clés créées ici] }`.
 *  Une liste **vide** veut dire « une clé de ce compte sert ici, on ne sait pas
 *  laquelle » — après une connexion par clé, ou un « déjà présente ». */
const CLE_APPAREIL = 'heritage.cles-d-acces.appareil'

/** Clé `localStorage` : `{ "<id du compte>": date du « Plus tard » (ms) }`. */
const CLE_PLUS_TARD = 'heritage.cles-d-acces.invitation.plus-tard'

/** Silence après « Plus tard ». Plus long que pour les notifications (sept
 *  jours) : se connecter par mot de passe n'est pas un manque, c'est un confort
 *  de moins. */
export const SILENCE_INVITATION_CLE_MS = 30 * 24 * 60 * 60 * 1000

function lireObjet(cle: string): Record<string, unknown> {
  try {
    const brut = globalThis.localStorage?.getItem(cle)
    if (brut == null) return {}
    const valeur: unknown = JSON.parse(brut)
    return valeur !== null && typeof valeur === 'object' && !Array.isArray(valeur)
      ? (valeur as Record<string, unknown>)
      : {}
  } catch {
    return {}
  }
}

function ecrireObjet(cle: string, valeur: Record<string, unknown>): void {
  try {
    globalThis.localStorage?.setItem(cle, JSON.stringify(valeur))
  } catch {
    // Stockage bloqué : rien de retenu. Voir l'en-tête.
  }
}

/** Les clés de ce compte créées ici, ou `null` si aucune n'y est connue. */
export function clesDeCetAppareil(utilisateurId: number): number[] | null {
  const liste = lireObjet(CLE_APPAREIL)[String(utilisateurId)]
  return Array.isArray(liste) ? liste.filter((id): id is number => typeof id === 'number') : null
}

export function cetAppareilAUneCle(utilisateurId: number): boolean {
  return clesDeCetAppareil(utilisateurId) !== null
}

/** `cleId` à `null` : une clé sert ici, sans qu'on sache laquelle. */
export function retenirCleSurCetAppareil(utilisateurId: number, cleId: number | null): void {
  const memoire = lireObjet(CLE_APPAREIL)
  const actuelles = clesDeCetAppareil(utilisateurId) ?? []
  memoire[String(utilisateurId)] =
    cleId === null || actuelles.includes(cleId) ? actuelles : [...actuelles, cleId]
  ecrireObjet(CLE_APPAREIL, memoire)
}

/** Après un changement de mot de passe : le serveur a retiré **toutes** les
 *  clés du compte. Celle d'ici ne sert plus ; l'invitation pourra revenir. */
export function oublierToutesLesClesDeCetAppareil(utilisateurId: number): void {
  const memoire = lireObjet(CLE_APPAREIL)
  if (!(String(utilisateurId) in memoire)) return
  delete memoire[String(utilisateurId)]
  ecrireObjet(CLE_APPAREIL, memoire)
}

/** Après une suppression. Si c'était la dernière clé **connue** d'ici, l'appareil
 *  redevient « sans clé » et l'invitation pourra revenir. */
export function oublierCleDeCetAppareil(utilisateurId: number, cleId: number): void {
  const actuelles = clesDeCetAppareil(utilisateurId)
  if (actuelles === null || !actuelles.includes(cleId)) return
  const memoire = lireObjet(CLE_APPAREIL)
  const restantes = actuelles.filter((id) => id !== cleId)
  if (restantes.length === 0) delete memoire[String(utilisateurId)]
  else memoire[String(utilisateurId)] = restantes
  ecrireObjet(CLE_APPAREIL, memoire)
}

export function lirePlusTardCleDAcces(utilisateurId: number): number | null {
  const date = lireObjet(CLE_PLUS_TARD)[String(utilisateurId)]
  return typeof date === 'number' && Number.isFinite(date) ? date : null
}

export function ecrirePlusTardCleDAcces(utilisateurId: number, date: number): void {
  const memoire = lireObjet(CLE_PLUS_TARD)
  memoire[String(utilisateurId)] = date
  ecrireObjet(CLE_PLUS_TARD, memoire)
}

/* ------------------------------------------------------------------ */
/* Ajouter une clé sur cet appareil                                    */
/* ------------------------------------------------------------------ */

let optionsDAjoutPreparees: { options: PublicKeyCredentialCreationOptionsJSON; le: number } | null = null

/**
 * Le serveur demande une **preuve d'identité récente** avant de délivrer un
 * défi d'enregistrement (§ 2.4) : connexion de moins de dix minutes, ou le
 * mot de passe actuel. Ce refus-là (`422` sur `champs.motDePasseActuel`) n'est
 * pas une panne : l'écran demande le mot de passe, puis réessaie.
 */
export function preuveDIdentiteRequise(cause: unknown): boolean {
  return (
    cause instanceof ErreurApi &&
    cause.statut === 422 &&
    cause.champs !== null &&
    typeof cause.champs.motDePasseActuel === 'string'
  )
}

/** Demande les options d'enregistrement **avant** le toucher du bouton (voir
 *  l'en-tête). Ne lève pas : rend l'erreur (ou `null`). Un refus (incarnation,
 *  session tombée) sera redit par `ajouterCleDAccesSurCetAppareil()`, qui
 *  redemandera ; une preuve requise (`preuveDIdentiteRequise`) permet à l'écran
 *  de demander le mot de passe **avant** le geste. */
export async function preparerAjoutCleDAcces(horloge: () => number = Date.now): Promise<unknown> {
  try {
    const { options } = await lireOptionsEnregistrementCleDAcces()
    optionsDAjoutPreparees = { options, le: horloge() }
    return null
  } catch (cause) {
    optionsDAjoutPreparees = null
    return cause
  }
}

/** Le mot de passe actuel, donné pour obtenir un défi : les options sont
 *  préparées pour le geste suivant (« Ajouter… »), et le serveur tient la
 *  preuve pour fraîche dix minutes. Lève l'`ErreurApi` (mot de passe faux :
 *  `422` sur `champs.motDePasseActuel`). */
export async function confirmerMotDePassePourAjout(
  motDePasseActuel: string,
  horloge: () => number = Date.now,
): Promise<void> {
  optionsDAjoutPreparees = null
  const { options } = await lireOptionsEnregistrementCleDAcces(motDePasseActuel)
  optionsDAjoutPreparees = { options, le: horloge() }
}

/** Les options préparées si elles sont fraîches — **une seule fois** : le défi
 *  est à usage unique. Sinon, une demande au serveur. */
async function optionsDAjout(horloge: () => number): Promise<PublicKeyCredentialCreationOptionsJSON> {
  const preparees = optionsDAjoutPreparees
  optionsDAjoutPreparees = null
  if (preparees !== null && horloge() - preparees.le < DUREE_OPTIONS_MS) return preparees.options
  return (await lireOptionsEnregistrementCleDAcces()).options
}

/**
 * La cérémonie d'enregistrement, de bout en bout. Lève l'erreur du navigateur
 * (`DOMException` : annulée, déjà présente…) ou celle du serveur (`ErreurApi`)
 * telle quelle : l'appelant la passe à `issueDeLErreur()` puis, à défaut, à
 * `MessageErreur`.
 */
export async function ajouterCleDAccesSurCetAppareil(
  nom: string | null = null,
  horloge: () => number = Date.now,
): Promise<CleDAcces> {
  const env = environnementCleDAcces()
  const utilisateurId = useSession().session.value?.utilisateur.id ?? null
  const options = await optionsDAjout(horloge)

  let identifiant: Credential | null
  try {
    identifiant = await env.creer({ publicKey: optionsDeCreation(options) })
  } catch (cause) {
    // `excludeCredentials` a joué : ce compte a déjà une clé sur cet
    // authentificateur. On le retient, pour ne plus le proposer.
    if (issueDeLErreur(cause) === 'deja-presente' && utilisateurId !== null) {
      retenirCleSurCetAppareil(utilisateurId, null)
    }
    throw cause
  }
  if (identifiant === null) throw new DOMException('Aucune clé créée.', 'NotAllowedError')

  const cle = await enregistrerCleDAcces(
    reponseEnJson(identifiant as unknown as IdentifiantBrut),
    nom ?? nomParDefautDeLAppareil(env.agent),
  )
  if (utilisateurId !== null) retenirCleSurCetAppareil(utilisateurId, cle.id)
  return cle
}

/* ------------------------------------------------------------------ */
/* Se connecter                                                        */
/* ------------------------------------------------------------------ */

export interface PilotageConnexionCleDAcces {
  /** Le navigateur sait faire du WebAuthn : le bouton peut s'afficher. */
  possible: boolean
  /** « Face ID / empreinte », « Windows Hello », ou le libellé neutre. */
  libelle: Readonly<Ref<string>>
  /** L'autoremplissage est armé : le champ courriel porte `webauthn`. */
  conditionnel: Readonly<Ref<boolean>>
  enCours: Readonly<Ref<boolean>>
  /** Ce que le **navigateur** a répondu, en clair (annulé, impossible…).
   *  Les erreurs du serveur passent par `surErreur`. */
  message: Readonly<Ref<string | null>>
  /** À appeler au montage de l'écran. */
  demarrer: () => Promise<void>
  /** Le bouton. */
  seConnecter: () => Promise<void>
  effacerMessage: () => void
  arreter: () => void
}

export interface RappelsConnexionCleDAcces {
  /** Session ouverte : la même suite que par mot de passe. */
  apres: (charge: ChargeDeSession) => void | Promise<void>
  /** Une erreur du **serveur** (`401`, `429`…), à montrer comme celles du mot
   *  de passe — même format, même message, même plafond. */
  surErreur: (cause: unknown) => void
  /** Une cérémonie commence : l'écran efface ses erreurs précédentes. */
  surDebut?: () => void
}

/**
 * Le pilote de la connexion par clé d'accès, pour `ConnexionView`.
 *
 * Deux chemins, un seul défi :
 *
 * - **l'autoremplissage** (`mediation: 'conditional'`) : armé au montage, il
 *   attend en silence que la personne touche le champ courriel et choisisse sa
 *   clé dans la liste du navigateur. Il est renouvelé avant l'expiration du
 *   défi, et réarmé après chaque échec ;
 * - **le bouton** : il interrompt l'autoremplissage (un seul `get()` à la fois)
 *   et réutilise **ses** options — le défi n'a pas servi, il vaut toujours, et
 *   l'appel part sans `fetch` dans le geste.
 */
export function piloterConnexionCleDAcces(
  rappels: RappelsConnexionCleDAcces,
  horloge: () => number = Date.now,
): PilotageConnexionCleDAcces {
  const env = environnementCleDAcces()
  const libelle = shallowRef(libelleConnexionCleDAcces(false, systemeDeLAgent(env.agent)))
  const conditionnel = shallowRef(false)
  const enCours = shallowRef(false)
  const message = shallowRef<string | null>(null)

  let options: { json: PublicKeyCredentialRequestOptionsJSON; le: number } | null = null
  let controleur: AbortController | null = null
  let renouvellement: ReturnType<typeof setTimeout> | null = null
  let arrete = false
  let connecte = false

  async function optionsFraiches(): Promise<PublicKeyCredentialRequestOptionsJSON> {
    if (options !== null && horloge() - options.le < DUREE_OPTIONS_MS) return options.json
    const { options: json } = await lireOptionsConnexionCleDAcces()
    options = { json, le: horloge() }
    return json
  }

  function interrompreAutoremplissage(): void {
    if (renouvellement !== null) clearTimeout(renouvellement)
    renouvellement = null
    controleur?.abort()
    controleur = null
  }

  function armerAutoremplissage(): void {
    if (arrete || connecte || !conditionnel.value) return
    void ceremonie(true)
  }

  async function ceremonie(autoremplissage: boolean): Promise<void> {
    interrompreAutoremplissage()
    const local = autoremplissage ? new AbortController() : null
    controleur = local
    // Réarmer l'autoremplissage à la fin ? Seulement après un geste de la
    // personne (le bouton, ou une clé choisie que le serveur a refusée) : un
    // navigateur qui refuserait d'emblée ferait sinon tourner la boucle.
    let rearmer = !autoremplissage
    let etape: 'options' | 'navigateur' | 'serveur' = 'options'

    if (!autoremplissage) {
      enCours.value = true
      message.value = null
      rappels.surDebut?.()
    }
    try {
      // En temps normal, déjà là : préparées au montage ou par l'autoremplissage.
      const json = await optionsFraiches()
      if (local?.signal.aborted === true || arrete) return

      if (autoremplissage) {
        // Le défi vieillit pendant que le champ attend : on repart avant qu'il
        // expire, avec un défi neuf.
        renouvellement = setTimeout(() => {
          options = null
          armerAutoremplissage()
        }, DUREE_OPTIONS_MS)
      }

      etape = 'navigateur'
      const identifiant = await env.obtenir({
        publicKey: optionsDeRequete(json),
        ...(local !== null ? { mediation: 'conditional' as CredentialMediationRequirement, signal: local.signal } : {}),
      })
      if (identifiant === null) throw new DOMException('Aucune clé choisie.', 'NotAllowedError')

      // La réponse part : le défi est consommé, réussite ou non.
      etape = 'serveur'
      options = null
      if (renouvellement !== null) clearTimeout(renouvellement)
      renouvellement = null
      rearmer = true
      if (autoremplissage) {
        enCours.value = true
        message.value = null
        rappels.surDebut?.()
      }
      const brut = identifiant as unknown as IdentifiantBrut
      const charge = await connecterParCleDAcces(reponseEnJson(brut))
      connecte = true
      // Une clé de cet appareil-ci (pas un téléphone posé à côté d'un PC) :
      // inutile de proposer d'en créer une ici plus tard.
      if (brut.authenticatorAttachment === 'platform') retenirCleSurCetAppareil(charge.utilisateur.id, null)
      await rappels.apres(charge)
    } catch (cause) {
      if (connecte) return
      const issue = issueDeLErreur(cause)
      // Interrompue exprès (le bouton, le renouvellement, le départ de l'écran) :
      // celui qui a interrompu s'occupe de la suite.
      if (issue === 'interrompue' && etape !== 'serveur') {
        rearmer = false
        return
      }
      // L'autoremplissage échoue en silence tant que la personne n'a rien
      // choisi : rien de visible n'a été demandé, le formulaire est là.
      if (autoremplissage && etape !== 'serveur') return
      if (issue === null) rappels.surErreur(cause)
      else message.value = messageDeLIssue(issue, 'connexion')
    } finally {
      enCours.value = false
      if (controleur === local) controleur = null
      if (rearmer && !connecte && !arrete) armerAutoremplissage()
    }
  }

  async function demarrer(): Promise<void> {
    if (!env.webauthn) return
    const [plateforme, autoremplissage] = await Promise.all([env.plateforme(), env.conditionnel()])
    if (arrete) return
    libelle.value = libelleConnexionCleDAcces(plateforme, systemeDeLAgent(env.agent))
    conditionnel.value = autoremplissage
    if (autoremplissage) {
      armerAutoremplissage()
    } else {
      // Pas d'autoremplissage : on prépare quand même le défi, pour que le
      // bouton n'ait rien à attendre (Safari, voir l'en-tête).
      try {
        await optionsFraiches()
      } catch {
        // Le bouton redemandera, et dira l'erreur.
      }
    }
  }

  async function seConnecter(): Promise<void> {
    if (!env.webauthn || enCours.value) return
    await ceremonie(false)
  }

  function arreter(): void {
    arrete = true
    interrompreAutoremplissage()
  }

  if (getCurrentScope()) onScopeDispose(arreter)

  return {
    possible: env.webauthn,
    libelle: readonly(libelle),
    conditionnel: readonly(conditionnel),
    enCours: readonly(enCours),
    message: readonly(message),
    demarrer,
    seConnecter,
    effacerMessage: () => {
      message.value = null
    },
    arreter,
  }
}


/* ------------------------------------------------------------------ */
/* L'invitation à l'arrivée                                            */
/* ------------------------------------------------------------------ */

/*
 * « Se connecter plus vite ? » — proposé **de lui-même**, à l'arrivée dans
 * l'application, comme l'invitation aux notifications : personne n'a à
 * trouver la section de Mon compte pour découvrir que Face ID suffirait.
 *
 * À chaque chargement de l'application (une fois par compte et par
 * chargement), sur un écran avec cadre, et seulement :
 *
 * - si l'appareil a un authentificateur à lui (Face ID, Touch ID, empreinte,
 *   Windows Hello) — proposer une clé USB qu'on n'a pas serait absurde ;
 * - si ce compte n'a pas déjà une clé **ici** (mémoire locale — corrigée par la
 *   liste du serveur quand elle est lisible : une clé supprimée ailleurs, ou un
 *   compte qui n'a plus aucune clé, rend l'appareil « sans clé ») ;
 * - si ce compte n'a pas répondu « Plus tard » ici depuis moins de trente jours ;
 * - hors incarnation.
 *
 * Elle passe **avant** l'invitation aux notifications, qui attend qu'elle soit
 * réglée (`fenetresAutomatiques.ts`) : la priorité est prise dès que la session
 * est là — avant de savoir si la fenêtre s'ouvrira, ce qui demande une
 * question asynchrone au navigateur — et rendue dès que c'est tranché.
 *
 * ── La preuve d'identité ────────────────────────────────────────────────────
 *
 * Le serveur ne délivre un défi d'enregistrement qu'à une session de moins de
 * dix minutes, ou contre le mot de passe (§ 2.4). À l'arrivée, la session a
 * souvent des jours : le défi est demandé **avant** d'ouvrir, et s'il est
 * refusé pour cette raison, la fenêtre s'ouvre sur l'étape « mot de passe ».
 * Deux gestes alors, à cause de Safari (voir l'en-tête) : « Confirmer » envoie
 * le mot de passe et obtient le défi ; « Activer Face ID / empreinte » part
 * ensuite, sans attente, dans son propre geste. Le mot de passe ne vit que le
 * temps de la requête : le champ est vidé à l'envoi, rien n'est retenu.
 */

/** Le temps de laisser l'écran d'arrivée s'afficher avant de le couvrir — le
 *  même que pour les notifications. */
export const DELAI_INVITATION_CLE_MS = 1_500

/** Durée du message qui suit l'ajout. */
const DUREE_CONFIRMATION_CLE_MS = 4_000

/** Les comptes déjà invités (ou écartés) depuis le chargement de l'application :
 *  une fois par chargement, quoi qu'il arrive. */
const examinesCeChargement = new Set<number>()

/** Remet l'état à zéro — un « chargement » neuf, pour les tests. */
export function reinitialiserClesDAcces(): void {
  examinesCeChargement.clear()
  libererApresLaCleDAcces()
  optionsDAjoutPreparees = null
}

/** Tout ce dont dépend la décision — rien d'autre n'est lu. */
export interface ContexteInvitationCleDAcces {
  webauthn: boolean
  /** `isUserVerifyingPlatformAuthenticatorAvailable()`. */
  plateforme: boolean
  connecte: boolean
  incarnation: boolean
  /** Ce compte a déjà une clé sur cet appareil (mémoire locale). */
  cleSurCetAppareil: boolean
  plusTardLe: number | null
  maintenant: number
}

/** Fonction pure : c'est elle que les tests passent au crible. Ne tient **pas**
 *  compte du « une fois par chargement » ni du cadre — c'est l'affaire de
 *  `piloterInvitationCleDAcces()`. */
export function invitationCleDAccesPossible(c: ContexteInvitationCleDAcces): boolean {
  if (!c.webauthn || !c.plateforme) return false
  if (!c.connecte || c.incarnation) return false
  if (c.cleSurCetAppareil) return false
  if (c.plusTardLe !== null && c.maintenant - c.plusTardLe < SILENCE_INVITATION_CLE_MS) return false
  return true
}

/**
 * La mémoire locale dit « une clé de ce compte sert ici » : on la confronte à
 * la liste du serveur. Liste vide → plus aucune clé, donc pas ici non plus ;
 * des identifiants retenus qui n'y sont plus → supprimés ailleurs, oubliés.
 * Une liste illisible (réseau, serveur) laisse la mémoire telle quelle.
 */
async function corrigerLaMemoireAvecLeServeur(utilisateurId: number): Promise<void> {
  let cles: CleDAcces[]
  try {
    cles = await listerClesDAcces()
  } catch {
    return
  }
  // Une autre session a pu s'ouvrir pendant la question : on ne corrige pas
  // la mémoire d'un compte avec la liste d'un autre.
  if (useSession().session.value?.utilisateur.id !== utilisateurId) return
  if (cles.length === 0) {
    oublierToutesLesClesDeCetAppareil(utilisateurId)
    return
  }
  const existantes = new Set(cles.map((cle) => cle.id))
  for (const cleId of clesDeCetAppareil(utilisateurId) ?? []) {
    if (!existantes.has(cleId)) oublierCleDeCetAppareil(utilisateurId, cleId)
  }
}

/** Ce que la fenêtre montre : le bouton qui crée la clé, ou d'abord le mot de
 *  passe que le serveur exige. */
export type EtapeInvitationCleDAcces = 'activer' | 'mot-de-passe'

export interface PilotageInvitationCleDAcces {
  ouverte: Readonly<Ref<boolean>>
  etape: Readonly<Ref<EtapeInvitationCleDAcces>>
  /** Le mot de passe vient d'être confirmé : le bouton dit ce qu'il va ouvrir
   *  (« Activer Face ID / empreinte »), c'est le second geste. */
  motDePasseConfirme: Readonly<Ref<boolean>>
  /** Pour les mots : Windows Hello sur un PC, Face ID ou l'empreinte ailleurs. */
  systeme: Readonly<Ref<SystemeAppareil>>
  enCours: Readonly<Ref<boolean>>
  /** Réponse du navigateur, en clair : la fenêtre reste ouverte pour réessayer. */
  message: Readonly<Ref<string | null>>
  /** Le mot de passe refusé, en clair, sous le champ. */
  erreurMotDePasse: Readonly<Ref<string | null>>
  /** Erreur du serveur, pour `MessageErreur`. */
  erreur: Readonly<Ref<unknown>>
  /** Montré brièvement après la fermeture : « Clé d'accès activée »… */
  confirmation: Readonly<Ref<string | null>>
  /** « Activer » — à appeler **dans** le clic. */
  accepter: () => Promise<void>
  /** « Confirmer » de l'étape mot de passe. Le texte n'est pas gardé. */
  confirmerMotDePasse: (motDePasse: string) => Promise<void>
  /** « Plus tard » ou Échap : trente jours de silence pour ce compte, ici. */
  plusTard: () => void
}

/** Le mot de passe refusé à la confirmation. */
export const MESSAGE_MOT_DE_PASSE_REFUSE = 'Ce mot de passe ne correspond pas. Vérifiez-le et réessayez.'

/**
 * Le pilote de la fenêtre, pour `InvitationCleDAcces`. À appeler dans un
 * `setup` : les minuteries tombent avec lui.
 *
 * Il observe la session et le cadre ; quand tout y est, il prend la priorité
 * sur les notifications, attend `DELAI_INVITATION_CLE_MS`, interroge le
 * navigateur (et, au besoin, le serveur), prépare le défi, puis ouvre — ou
 * renonce et rend la main.
 */
export function piloterInvitationCleDAcces(
  lireAvecCadre: () => boolean,
  horloge: () => number = Date.now,
): PilotageInvitationCleDAcces {
  const ouverte = shallowRef(false)
  const etape = shallowRef<EtapeInvitationCleDAcces>('activer')
  const motDePasseConfirme = shallowRef(false)
  const systeme = shallowRef<SystemeAppareil>('autre')
  const enCours = shallowRef(false)
  const message = shallowRef<string | null>(null)
  const erreurMotDePasse = shallowRef<string | null>(null)
  const erreur = shallowRef<unknown>(null)
  const confirmation = shallowRef<string | null>(null)
  let attente: ReturnType<typeof setTimeout> | null = null
  let minuterieConfirmation: ReturnType<typeof setTimeout> | null = null
  /** Augmente à chaque changement de situation : une décision asynchrone
   *  partie avant n'a plus le droit d'ouvrir. */
  let tour = 0
  const session = useSession()

  function utilisateurCourant(): number | null {
    return session.session.value?.utilisateur.id ?? null
  }

  function annulerAttente(): void {
    if (attente !== null) clearTimeout(attente)
    attente = null
  }

  /** Ce qu'on sait sans rien demander : le navigateur sait-il faire, pas
   *  d'incarnation, pas de « Plus tard » récent. L'authentificateur et la clé
   *  ici se vérifient après le délai. */
  function conditionsImmediates(utilisateurId: number): boolean {
    return invitationCleDAccesPossible({
      webauthn: environnementCleDAcces().webauthn,
      plateforme: true,
      connecte: session.connecte.value,
      incarnation: session.incarnation?.value != null,
      cleSurCetAppareil: false,
      plusTardLe: lirePlusTardCleDAcces(utilisateurId),
      maintenant: horloge(),
    })
  }

  /** Ce compte ne sera plus invité dans ce chargement ; les notifications
   *  peuvent passer. */
  function renoncer(utilisateurId: number): void {
    examinesCeChargement.add(utilisateurId)
    libererApresLaCleDAcces()
  }

  async function decider(utilisateurId: number): Promise<void> {
    const numero = tour
    const env = environnementCleDAcces()
    const plateforme = env.webauthn ? await env.plateforme() : false
    // La situation a pu changer pendant la question au navigateur.
    if (numero !== tour) return
    if (!plateforme) {
      renoncer(utilisateurId)
      return
    }

    if (cetAppareilAUneCle(utilisateurId)) {
      await corrigerLaMemoireAvecLeServeur(utilisateurId)
      if (numero !== tour) return
    }
    const possible =
      lireAvecCadre() &&
      invitationCleDAccesPossible({
        webauthn: env.webauthn,
        plateforme,
        connecte: session.connecte.value,
        incarnation: session.incarnation?.value != null,
        cleSurCetAppareil: cetAppareilAUneCle(utilisateurId),
        plusTardLe: lirePlusTardCleDAcces(utilisateurId),
        maintenant: horloge(),
      })
    if (!possible) {
      renoncer(utilisateurId)
      return
    }

    // Le défi d'abord (Safari, voir l'en-tête) — et c'est sa réponse qui dit
    // sur quelle étape ouvrir. Un autre refus (réseau…) ouvre quand même :
    // « Activer » redemandera, et dira l'erreur.
    const refus = await preparerAjoutCleDAcces(horloge)
    if (numero !== tour) return

    examinesCeChargement.add(utilisateurId)
    systeme.value = systemeDeLAgent(env.agent)
    etape.value = preuveDIdentiteRequise(refus) ? 'mot-de-passe' : 'activer'
    motDePasseConfirme.value = false
    message.value = null
    erreurMotDePasse.value = null
    erreur.value = null
    ouverte.value = true
    // Le registre sait ce qui est à l'écran ; `fermer()` l'en retire.
    marquerOuverte('cle-d-acces', true)
  }

  function fermer(): void {
    ouverte.value = false
    message.value = null
    erreurMotDePasse.value = null
    erreur.value = null
    motDePasseConfirme.value = false
    libererApresLaCleDAcces()
  }

  const arreterSurveillance = watch(
    () => [session.connecte.value, session.incarnation?.value != null, lireAvecCadre(), utilisateurCourant()] as const,
    ([connecte, incarnation, avecCadre, utilisateurId]) => {
      annulerAttente()
      tour++
      if (ouverte.value) {
        // Déconnecté, ou incarnation commencée, fenêtre ouverte : elle n'a
        // plus lieu d'être — sans valoir « Plus tard ».
        if (!connecte || incarnation) fermer()
        return
      }
      if (!connecte || utilisateurId === null || examinesCeChargement.has(utilisateurId)) {
        libererApresLaCleDAcces()
        return
      }
      if (!conditionsImmediates(utilisateurId)) {
        renoncer(utilisateurId)
        return
      }
      // La priorité, tout de suite : les notifications s'abstiennent tant que
      // ce n'est pas tranché.
      reserverPourLaCleDAcces()
      // Écran sans cadre (connexion, lien d'invitation…) : on attend le suivant,
      // en gardant la priorité.
      if (!avecCadre) return
      attente = setTimeout(() => {
        attente = null
        void decider(utilisateurId)
      }, DELAI_INVITATION_CLE_MS)
    },
    { immediate: true },
  )

  function confirmer(texte: string): void {
    confirmation.value = texte
    if (minuterieConfirmation !== null) clearTimeout(minuterieConfirmation)
    minuterieConfirmation = setTimeout(() => {
      confirmation.value = null
      minuterieConfirmation = null
    }, DUREE_CONFIRMATION_CLE_MS)
  }

  async function confirmerMotDePasse(motDePasse: string): Promise<void> {
    if (!ouverte.value || enCours.value || etape.value !== 'mot-de-passe' || motDePasse === '') return
    enCours.value = true
    message.value = null
    erreurMotDePasse.value = null
    erreur.value = null
    try {
      // Le défi est préparé ici : « Activer » partira sans attente.
      await confirmerMotDePassePourAjout(motDePasse, horloge)
      etape.value = 'activer'
      motDePasseConfirme.value = true
    } catch (cause) {
      // Le mot de passe est toujours envoyé non vide : ce `422` veut dire faux.
      if (preuveDIdentiteRequise(cause)) erreurMotDePasse.value = MESSAGE_MOT_DE_PASSE_REFUSE
      else erreur.value = cause
    } finally {
      enCours.value = false
    }
  }

  async function accepter(): Promise<void> {
    if (!ouverte.value || enCours.value || etape.value !== 'activer') return
    enCours.value = true
    message.value = null
    erreur.value = null
    try {
      await ajouterCleDAccesSurCetAppareil(null, horloge)
      fermer()
      confirmer('Clé d’accès activée')
    } catch (cause) {
      const issue = issueDeLErreur(cause)
      if (preuveDIdentiteRequise(cause)) {
        // La preuve a vieilli pendant que la fenêtre attendait (ou le défi
        // n'a pas pu être préparé) : on redemande le mot de passe, ici.
        etape.value = 'mot-de-passe'
        motDePasseConfirme.value = false
      } else if (issue === 'deja-presente') {
        fermer()
        confirmer(messageDeLIssue(issue, 'ajout') ?? '')
      } else if (issue !== null) {
        message.value = messageDeLIssue(issue, 'ajout')
        // Le défi a pu servir (ou non) : on en prépare un neuf pour l'essai
        // suivant. S'il exige désormais le mot de passe, l'étape suit.
        void preparerAjoutCleDAcces(horloge).then((refus) => {
          if (preuveDIdentiteRequise(refus) && ouverte.value && !enCours.value) {
            etape.value = 'mot-de-passe'
            motDePasseConfirme.value = false
          }
        })
      } else {
        erreur.value = cause
      }
    } finally {
      enCours.value = false
    }
  }

  function plusTard(): void {
    if (!ouverte.value || enCours.value) return
    const utilisateurId = utilisateurCourant()
    if (utilisateurId !== null) ecrirePlusTardCleDAcces(utilisateurId, horloge())
    fermer()
  }

  if (getCurrentScope()) {
    onScopeDispose(() => {
      arreterSurveillance()
      annulerAttente()
      tour++
      if (minuterieConfirmation !== null) clearTimeout(minuterieConfirmation)
      // Le pilote s'en va : il ne doit pas retenir les notifications avec lui.
      libererApresLaCleDAcces()
    })
  }

  return {
    ouverte: readonly(ouverte),
    etape: readonly(etape),
    motDePasseConfirme: readonly(motDePasseConfirme),
    systeme: readonly(systeme),
    enCours: readonly(enCours),
    message: readonly(message),
    erreurMotDePasse: readonly(erreurMotDePasse),
    erreur: readonly(erreur),
    confirmation: readonly(confirmation),
    accepter,
    confirmerMotDePasse,
    plusTard,
  }
}
