/**
 * Les clés d'accès (passkeys, WebAuthn) — ce qui touche au **navigateur**, sans
 * état et sans appel au serveur.
 *
 * Le serveur parle JSON, le navigateur parle `ArrayBuffer`. Entre les deux, le
 * **base64url non paddé** (RFC 4648 § 5) : c'est le format de
 * `PublicKeyCredentialCreationOptionsJSON` et de `PublicKeyCredential.toJSON()`
 * dans la spécification WebAuthn niveau 3, et celui qu'écrit la bibliothèque
 * serveur. Ce module fait la traduction dans les deux sens.
 *
 * **Les fonctions natives d'abord, la nôtre en repli.** Chrome, Edge, Firefox
 * et Safari récents savent faire seuls (`parseCreationOptionsFromJSON`,
 * `parseRequestOptionsFromJSON`, `toJSON`) ; un Safari d'iPhone de 2023 ne le
 * sait pas, et c'est précisément le téléphone de la famille qu'on veut servir.
 * Aucune dépendance npm : quarante lignes de conversion ne justifient pas un
 * paquet de plus dans la page de connexion.
 *
 * Tout ce qui lit le navigateur passe par `EnvironnementCleDAcces`, que les
 * tests remplacent : vitest tourne sous `node`, sans `window` ni
 * `PublicKeyCredential`.
 */

/* ------------------------------------------------------------------ */
/* base64url ↔ octets                                                  */
/* ------------------------------------------------------------------ */

/** Octets → base64url **sans** `=` final. */
export function octetsVersBase64url(source: ArrayBuffer | ArrayBufferView): string {
  const octets =
    source instanceof ArrayBuffer
      ? new Uint8Array(source)
      : new Uint8Array(source.buffer, source.byteOffset, source.byteLength)
  let binaire = ''
  for (const octet of octets) binaire += String.fromCharCode(octet)
  return btoa(binaire).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '')
}

/**
 * base64url → octets. Accepte aussi le base64 classique (`+`, `/`) et un
 * remplissage `=` : un serveur qui en enverrait ne doit pas faire échouer une
 * connexion pour une affaire d'alphabet. Lève sur une chaîne illisible.
 */
export function base64urlVersOctets(texte: string): ArrayBuffer {
  const normal = texte.replace(/-/g, '+').replace(/_/g, '/').replace(/=+$/, '')
  if (!/^[A-Za-z0-9+/]*$/.test(normal) || normal.length % 4 === 1) {
    throw new TypeError('Chaîne base64url illisible.')
  }
  const binaire = atob(normal + '='.repeat((4 - (normal.length % 4)) % 4))
  const octets = new Uint8Array(binaire.length)
  for (let i = 0; i < binaire.length; i++) octets[i] = binaire.charCodeAt(i)
  return octets.buffer
}

/* ------------------------------------------------------------------ */
/* Options : JSON du serveur → objets du navigateur                    */
/* ------------------------------------------------------------------ */

/** La forme minimale d'un `PublicKeyCredentialDescriptorJSON`. */
interface DescripteurJson {
  id: string
  type: string
  transports?: string[]
}

function descripteur(d: DescripteurJson): PublicKeyCredentialDescriptor {
  return {
    id: base64urlVersOctets(d.id),
    type: d.type as PublicKeyCredentialType,
    ...(d.transports !== undefined ? { transports: d.transports as AuthenticatorTransport[] } : {}),
  }
}

/** Ce que `PublicKeyCredential` expose de statique, ou rien. Lu à chaque appel :
 *  les tests le posent et le retirent sur `globalThis`. */
type StatiquesWebAuthn = Partial<{
  parseCreationOptionsFromJSON: (o: PublicKeyCredentialCreationOptionsJSON) => PublicKeyCredentialCreationOptions
  parseRequestOptionsFromJSON: (o: PublicKeyCredentialRequestOptionsJSON) => PublicKeyCredentialRequestOptions
}>

function statiques(): StatiquesWebAuthn | null {
  const pkc = (globalThis as { PublicKeyCredential?: unknown }).PublicKeyCredential
  return typeof pkc === 'function' ? (pkc as unknown as StatiquesWebAuthn) : null
}

/**
 * Retire récursivement toute clé à `null`.
 *
 * `parseCreationOptionsFromJSON()` convertit un `null` en la **chaîne** « null »
 * (conversion WebIDL d'un `DOMString`) : `authenticatorAttachment: null` devient
 * un type d'appareil « null ». Chrome l'ignore ; Dashlane, qui remplace
 * `navigator.credentials`, ferme sa fenêtre et ne répond jamais (constaté le
 * 01/10/2026). Le serveur ne les envoie plus ; on les retire aussi ici, pour un
 * serveur plus ancien et pour tout autre champ.
 */
export function sansNuls<T>(valeur: T): T {
  if (Array.isArray(valeur)) return valeur.map((v) => sansNuls(v)) as T
  if (valeur === null || typeof valeur !== 'object') return valeur
  const propre: Record<string, unknown> = {}
  for (const [cle, v] of Object.entries(valeur as Record<string, unknown>)) {
    if (v !== null) propre[cle] = sansNuls(v)
  }
  return propre as T
}

/**
 * `PublicKeyCredentialCreationOptionsJSON` → options de `credentials.create()`.
 * Trois champs sont binaires : le défi, l'identifiant de l'utilisateur (le
 * « user handle » que la clé découvrable rendra à la connexion) et les clés
 * déjà enregistrées, à exclure.
 */
export function optionsDeCreation(brut: PublicKeyCredentialCreationOptionsJSON): PublicKeyCredentialCreationOptions {
  const json = sansNuls(brut)
  const natif = statiques()?.parseCreationOptionsFromJSON
  if (natif !== undefined) {
    try {
      return natif(json)
    } catch {
      // Un navigateur qui refuse une extension qu'il ne connaît pas : on refait
      // à la main, il ignorera ce qu'il ne comprend pas.
    }
  }
  const { challenge, user, excludeCredentials, ...reste } = json
  return {
    // Le reste passe tel quel (TypeScript 6 type les extensions binaires : `unknown` d'abord).
    ...(reste as unknown as Omit<PublicKeyCredentialCreationOptions, 'challenge' | 'user' | 'excludeCredentials'>),
    challenge: base64urlVersOctets(challenge),
    user: { ...user, id: base64urlVersOctets(user.id) },
    ...(excludeCredentials !== undefined ? { excludeCredentials: excludeCredentials.map(descripteur) } : {}),
  }
}

/** `PublicKeyCredentialRequestOptionsJSON` → options de `credentials.get()`. */
export function optionsDeRequete(brut: PublicKeyCredentialRequestOptionsJSON): PublicKeyCredentialRequestOptions {
  const json = sansNuls(brut)
  const natif = statiques()?.parseRequestOptionsFromJSON
  if (natif !== undefined) {
    try {
      return natif(json)
    } catch {
      // Même repli que pour la création.
    }
  }
  const { challenge, allowCredentials, ...reste } = json
  return {
    ...(reste as unknown as Omit<PublicKeyCredentialRequestOptions, 'challenge' | 'allowCredentials'>),
    challenge: base64urlVersOctets(challenge),
    ...(allowCredentials !== undefined ? { allowCredentials: allowCredentials.map(descripteur) } : {}),
  }
}

/* ------------------------------------------------------------------ */
/* Réponse du navigateur → JSON pour le serveur                        */
/* ------------------------------------------------------------------ */

/** `RegistrationResponseJSON` ou `AuthenticationResponseJSON` (WebAuthn L3). */
export interface ReponseCleDAccesJson {
  id: string
  rawId: string
  type: string
  authenticatorAttachment?: string | null
  clientExtensionResults: Record<string, unknown>
  response: Record<string, unknown>
}

/** Les champs d'une réponse que le repli sait lire. Tout est facultatif : la
 *  même fonction sert l'enregistrement et la connexion. */
interface ReponseBrute {
  clientDataJSON: ArrayBuffer
  attestationObject?: ArrayBuffer
  authenticatorData?: ArrayBuffer
  signature?: ArrayBuffer
  userHandle?: ArrayBuffer | null
  getTransports?: () => string[]
  getPublicKeyAlgorithm?: () => number
  getPublicKey?: () => ArrayBuffer | null
  getAuthenticatorData?: () => ArrayBuffer
}

/** La part de `PublicKeyCredential` que lit `reponseEnJson()`. */
export interface IdentifiantBrut {
  id: string
  rawId: ArrayBuffer
  type: string
  authenticatorAttachment?: string | null
  response: unknown
  getClientExtensionResults?: () => AuthenticationExtensionsClientOutputs
  toJSON?: () => unknown
}

/**
 * La réponse du navigateur, prête pour `JSON.stringify`.
 *
 * `toJSON()` natif quand il existe — sauf s'il rend autre chose qu'un objet :
 * certains gestionnaires de mots de passe en extension remplacent
 * `credentials.create` et rendent un objet dont le `toJSON` est incomplet.
 */
export function reponseEnJson(identifiant: IdentifiantBrut): ReponseCleDAccesJson {
  if (typeof identifiant.toJSON === 'function') {
    try {
      const natif = identifiant.toJSON() as Partial<ReponseCleDAccesJson> | null
      if (natif !== null && typeof natif === 'object' && typeof natif.id === 'string' && natif.response !== undefined) {
        return natif as ReponseCleDAccesJson
      }
    } catch {
      // Repli ci-dessous.
    }
  }

  const r = identifiant.response as ReponseBrute
  const response: Record<string, unknown> = { clientDataJSON: octetsVersBase64url(r.clientDataJSON) }

  if (r.attestationObject !== undefined) {
    // Enregistrement. `transports` aide le navigateur, à la connexion suivante,
    // à proposer le bon moyen (téléphone à côté, clé USB) ; les trois autres
    // évitent au serveur de redécoder l'attestation — il le fait quand même.
    response['attestationObject'] = octetsVersBase64url(r.attestationObject)
    response['transports'] = typeof r.getTransports === 'function' ? r.getTransports() : []
    if (typeof r.getPublicKeyAlgorithm === 'function') response['publicKeyAlgorithm'] = r.getPublicKeyAlgorithm()
    const clePublique = typeof r.getPublicKey === 'function' ? r.getPublicKey() : null
    if (clePublique !== null) response['publicKey'] = octetsVersBase64url(clePublique)
    if (typeof r.getAuthenticatorData === 'function') {
      response['authenticatorData'] = octetsVersBase64url(r.getAuthenticatorData())
    }
  } else {
    // Connexion. `userHandle` est ce qui désigne le compte : c'est lui qui
    // permet de se connecter sans taper son courriel.
    if (r.authenticatorData !== undefined) response['authenticatorData'] = octetsVersBase64url(r.authenticatorData)
    if (r.signature !== undefined) response['signature'] = octetsVersBase64url(r.signature)
    if (r.userHandle != null) response['userHandle'] = octetsVersBase64url(r.userHandle)
  }

  return {
    id: identifiant.id,
    rawId: octetsVersBase64url(identifiant.rawId),
    type: identifiant.type,
    authenticatorAttachment: identifiant.authenticatorAttachment ?? null,
    clientExtensionResults: (identifiant.getClientExtensionResults?.() ?? {}) as Record<string, unknown>,
    response,
  }
}

/* ------------------------------------------------------------------ */
/* Le navigateur                                                       */
/* ------------------------------------------------------------------ */

/** Ce que l'application demande au navigateur. Remplacé dans les tests. */
export interface EnvironnementCleDAcces {
  /** `PublicKeyCredential` et `navigator.credentials` existent, en contexte sûr. */
  webauthn: boolean
  /** Un authentificateur **de l'appareil** (Face ID, Touch ID, empreinte,
   *  Windows Hello) est prêt. Faux en cas de doute. */
  plateforme: () => Promise<boolean>
  /** L'autoremplissage (`mediation: 'conditional'`) est pris en charge. */
  conditionnel: () => Promise<boolean>
  creer: (options: CredentialCreationOptions) => Promise<Credential | null>
  obtenir: (options: CredentialRequestOptions) => Promise<Credential | null>
  /** `navigator.userAgent`, pour le libellé et le nom par défaut. */
  agent: string
}

function environnementDuNavigateur(): EnvironnementCleDAcces {
  const fenetre = globalThis as {
    PublicKeyCredential?: {
      isUserVerifyingPlatformAuthenticatorAvailable?: () => Promise<boolean>
      isConditionalMediationAvailable?: () => Promise<boolean>
    }
    isSecureContext?: boolean
    navigator?: Navigator
  }
  const pkc = fenetre.PublicKeyCredential
  const credentials = fenetre.navigator?.credentials
  const webauthn = pkc !== undefined && credentials !== undefined && fenetre.isSecureContext !== false

  return {
    webauthn,
    plateforme: async () => {
      if (!webauthn) return false
      try {
        return (await pkc?.isUserVerifyingPlatformAuthenticatorAvailable?.()) === true
      } catch {
        return false
      }
    },
    conditionnel: async () => {
      if (!webauthn) return false
      try {
        return (await pkc?.isConditionalMediationAvailable?.()) === true
      } catch {
        return false
      }
    },
    creer: (options) => {
      if (credentials === undefined) return Promise.reject(new Error('WebAuthn indisponible.'))
      return sousDelai((signal) => credentials.create({ ...options, signal: options.signal ?? signal }))
    },
    obtenir: (options) => {
      if (credentials === undefined) return Promise.reject(new Error('WebAuthn indisponible.'))
      return credentials.get(options)
    },
    agent: fenetre.navigator?.userAgent ?? '',
  }
}

/**
 * Au-delà, on n'attend plus. Un gestionnaire de mots de passe (Dashlane, vu en
 * vrai) peut prendre `credentials.create()` à sa charge, refermer sa fenêtre et
 * ne **jamais** répondre : sans plafond, le bouton tournerait pour toujours.
 * Un peu plus que le `timeout` du serveur (60 s), pour ne pas couper une
 * personne qui cherche son doigt.
 */
export const DELAI_SANS_REPONSE_MS = 75_000

/** `appel` reçoit un signal qu'on interrompt au plafond, et la promesse est
 *  rejetée avec une `TimeoutError` — même si l'appel ignore le signal. */
export function sousDelai<T>(appel: (signal: AbortSignal) => Promise<T>, delai = DELAI_SANS_REPONSE_MS): Promise<T> {
  const controleur = new AbortController()
  let minuterie: ReturnType<typeof setTimeout> | undefined
  const plafond = new Promise<never>((_, rejeter) => {
    minuterie = setTimeout(() => {
      const erreur = Object.assign(new Error('Pas de réponse du navigateur.'), { name: 'TimeoutError' })
      controleur.abort(erreur)
      rejeter(erreur)
    }, delai)
  })
  return Promise.race([appel(controleur.signal), plafond]).finally(() => clearTimeout(minuterie))
}

let environnement: EnvironnementCleDAcces | null = null

export function environnementCleDAcces(): EnvironnementCleDAcces {
  environnement ??= environnementDuNavigateur()
  return environnement
}

/** Pour les tests : `null` revient au vrai navigateur. */
export function definirEnvironnementCleDAcces(e: EnvironnementCleDAcces | null): void {
  environnement = e
}

/* ------------------------------------------------------------------ */
/* Les mots                                                            */
/* ------------------------------------------------------------------ */

export type SystemeAppareil = 'apple' | 'android' | 'windows' | 'autre'

/** Le système, deviné de l'agent. Ne sert **qu'aux mots** : jamais à décider
 *  si une clé d'accès est possible — `plateforme()` le dit, lui. */
export function systemeDeLAgent(agent: string): SystemeAppareil {
  if (/android/i.test(agent)) return 'android'
  if (/iphone|ipad|ipod|macintosh|mac os x/i.test(agent)) return 'apple'
  if (/windows/i.test(agent)) return 'windows'
  return 'autre'
}

/**
 * Le libellé du bouton de connexion. On ne promet Face ID ou l'empreinte que si
 * le navigateur dit qu'un authentificateur de l'appareil est prêt ; Windows
 * Hello a son nom, que tout le monde reconnaît sur un PC. Sinon — clé USB,
 * téléphone à côté, ou on ne sait pas — le libellé neutre.
 */
export function libelleConnexionCleDAcces(plateforme: boolean, systeme: SystemeAppareil): string {
  if (!plateforme) return 'Se connecter avec une clé d’accès'
  if (systeme === 'windows') return 'Se connecter avec Windows Hello'
  return 'Se connecter avec Face ID / empreinte'
}

/** La phrase de l'invitation « Se connecter plus vite ? ». Ne s'affiche que si
 *  `plateforme()` a dit oui : on peut nommer le geste. Sur un PC, c'est Windows
 *  Hello ; partout ailleurs, Face ID ou l'empreinte. */
export function texteInvitationCleDAcces(systeme: SystemeAppareil): string {
  return systeme === 'windows'
    ? 'Activez Windows Hello sur cet ordinateur pour vous connecter sans mot de passe.'
    : 'Activez Face ID ou l’empreinte de ce téléphone pour vous connecter sans mot de passe.'
}

/** Le bouton du second geste, après le mot de passe : il dit ce qui va
 *  s'ouvrir. */
export function libelleActivationCleDAcces(systeme: SystemeAppareil): string {
  return systeme === 'windows' ? 'Activer Windows Hello' : 'Activer Face ID / empreinte'
}

/** Le nom proposé pour une clé créée ici : ce que la personne reconnaîtra dans
 *  la liste de Mon compte, sans avoir à le taper. `null` si on ne sait pas — le
 *  serveur en choisit un. */
export function nomParDefautDeLAppareil(agent: string): string | null {
  if (/iphone/i.test(agent)) return 'iPhone'
  if (/ipad/i.test(agent)) return 'iPad'
  if (/android/i.test(agent)) return 'Téléphone Android'
  if (/macintosh|mac os x/i.test(agent)) return 'Mac'
  if (/windows/i.test(agent)) return 'PC Windows'
  if (/cros/i.test(agent)) return 'Chromebook'
  if (/linux/i.test(agent)) return 'Ordinateur Linux'
  return null
}

/** Ce qu'une erreur du **navigateur** veut dire pour la personne. */
export type IssueWebAuthn =
  /** Annulé, refusé, ou délai dépassé : la spécification les confond exprès. */
  | 'annulee'
  /** Une clé de ce compte existe déjà sur cet authentificateur (`excludeCredentials`). */
  | 'deja-presente'
  /** Interrompue par l'application elle-même (`AbortController`) : rien à dire. */
  | 'interrompue'
  /** Personne n'a répondu dans le délai (`sousDelai`) : un gestionnaire muet. */
  | 'sans-reponse'
  | 'impossible'

export function issueDeLErreur(cause: unknown): IssueWebAuthn | null {
  // Par le nom seul : une `DOMException` n'est pas une `Error` partout, et une
  // `ErreurApi` du serveur porte un autre nom — elle tombe dans `null`.
  const nom = typeof cause === 'object' && cause !== null ? (cause as { name?: unknown }).name : undefined
  if (typeof nom !== 'string') return null
  if (nom === 'NotAllowedError') return 'annulee'
  if (nom === 'InvalidStateError') return 'deja-presente'
  if (nom === 'AbortError') return 'interrompue'
  if (nom === 'TimeoutError') return 'sans-reponse'
  if (nom === 'SecurityError' || nom === 'NotSupportedError' || nom === 'ConstraintError' || nom === 'UnknownError') {
    return 'impossible'
  }
  return null
}

/** La phrase à montrer, ou `null` pour une interruption voulue. */
export function messageDeLIssue(issue: IssueWebAuthn, contexte: 'connexion' | 'ajout'): string | null {
  switch (issue) {
    case 'annulee':
      return contexte === 'connexion'
        ? 'Connexion annulée. Réessayez, ou utilisez votre mot de passe.'
        : 'Ajout annulé. Rien n’a changé.'
    case 'deja-presente':
      return 'Cet appareil a déjà une clé d’accès pour votre compte.'
    case 'interrompue':
      return null
    case 'sans-reponse':
      return 'Votre gestionnaire de mots de passe n’a pas répondu. Réessayez, ou désactivez-le pour ce site.'
    case 'impossible':
      return contexte === 'connexion'
        ? 'Cet appareil n’a pas pu utiliser de clé d’accès. Utilisez votre mot de passe.'
        : 'Cet appareil n’a pas pu créer de clé d’accès.'
  }
}
