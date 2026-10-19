/**
 * Les clés d'accès — la mémoire de l'appareil, l'invitation qui s'ouvre à
 * l'arrivée, et la connexion par clé. Sans navigateur.
 *
 * Même montage que `invitationPush.test.ts` : le navigateur est un
 * `EnvironnementCleDAcces` écrit à la main, l'API et la session sont simulées,
 * le `localStorage` est une `Map` (vitest tourne sous `node`).
 *
 * Ce qui compte :
 *
 * - l'invitation s'ouvre **à l'arrivée**, sans qu'il y ait eu de connexion dans
 *   ce chargement, une fois par chargement, sur un appareil qui a Face ID / une
 *   empreinte, à un compte sans clé **ici** (mémoire locale corrigée par la
 *   liste du serveur), pas avant trente jours après « Plus tard », jamais
 *   pendant une incarnation ;
 * - une session trop ancienne pour le serveur ouvre la fenêtre sur le mot de
 *   passe : « Confirmer », puis « Activer » — deux gestes, le second sans
 *   attente (Safari) ;
 * - elle tient la priorité sur les notifications tant qu'elle n'est pas réglée,
 *   et la rend ensuite — quelle que soit l'issue (le passage de l'une à
 *   l'autre est dans `fenetresAutomatiques.test.ts`) ;
 * - à la connexion, un refus du navigateur se dit en clair, un refus du serveur
 *   remonte à l'écran comme celui du mot de passe, et l'autoremplissage ne
 *   tourne pas en boucle.
 */
import { effectScope, nextTick, shallowRef, type EffectScope } from 'vue'
import { afterEach, beforeEach, describe, expect, it, vi, type Mock } from 'vitest'

const api = vi.hoisted(() => ({
  lireOptionsEnregistrementCleDAcces: vi.fn(),
  enregistrerCleDAcces: vi.fn(),
  lireOptionsConnexionCleDAcces: vi.fn(),
  listerClesDAcces: vi.fn(),
}))
vi.mock('@/lib/api', () => api)

const session = vi.hoisted(() => ({
  connecte: null as unknown as { value: boolean },
  incarnation: null as unknown as { value: object | null },
  courante: null as unknown as { value: { utilisateur: { id: number } } | null },
  connecterParCleDAcces: null as unknown as Mock<(...args: unknown[]) => unknown>,
}))
vi.mock('@/composables/useSession', async () => {
  const { computed, shallowRef: sr } = await import('vue')
  const { vi: v } = await import('vitest')
  session.connecte = sr(true)
  session.incarnation = sr<object | null>(null)
  session.courante = sr<{ utilisateur: { id: number } } | null>({ utilisateur: { id: 7 } })
  session.connecterParCleDAcces = v.fn<(...args: unknown[]) => unknown>()
  return {
    connecterParCleDAcces: (...args: unknown[]) => session.connecterParCleDAcces(...args),
    useSession: () => ({
      session: computed(() => session.courante.value),
      connecte: computed(() => session.connecte.value),
      incarnation: computed(() => session.incarnation.value),
    }),
  }
})

import {
  DELAI_INVITATION_CLE_MS,
  DUREE_OPTIONS_MS,
  MESSAGE_MOT_DE_PASSE_REFUSE,
  SILENCE_INVITATION_CLE_MS,
  ajouterCleDAccesSurCetAppareil,
  cetAppareilAUneCle,
  clesDeCetAppareil,
  confirmerMotDePassePourAjout,
  ecrirePlusTardCleDAcces,
  invitationCleDAccesPossible,
  lirePlusTardCleDAcces,
  oublierCleDeCetAppareil,
  oublierToutesLesClesDeCetAppareil,
  piloterConnexionCleDAcces,
  piloterInvitationCleDAcces,
  preparerAjoutCleDAcces,
  preuveDIdentiteRequise,
  reinitialiserClesDAcces,
  retenirCleSurCetAppareil,
  type ContexteInvitationCleDAcces,
  type RappelsConnexionCleDAcces,
} from './useClesDAcces'
import { invitationCleDAccesEnCours } from './fenetresAutomatiques'
import { definirEnvironnementCleDAcces, type EnvironnementCleDAcces } from '@/lib/cleDAcces'
import { ErreurApi } from '@/lib/api/erreurs'
import type { CleDAcces } from '@/lib/api'

/** Le refus du serveur quand la connexion date de plus de dix minutes (§ 2.4). */
function preuveRequise(): ErreurApi {
  return new ErreurApi(422, 'donnees_invalides', 'Données invalides.', {
    motDePasseActuel: 'Confirmez votre mot de passe pour ajouter une clé d’accès.',
  })
}

/* ------------------------------------------------------------------ */
/* Montage                                                             */
/* ------------------------------------------------------------------ */

const AGENT_IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148 Safari/604.1'
const MAINTENANT = Date.UTC(2026, 9, 1, 12)

const OPTIONS_CREATION: PublicKeyCredentialCreationOptionsJSON = {
  rp: { id: 'heritage.naltpom.fr', name: 'Heritage' },
  user: { id: 'Bw', name: 'cobalt@exemple.test', displayName: 'Cobalt' },
  challenge: 'AQID',
  pubKeyCredParams: [{ type: 'public-key', alg: -7 }],
}
const OPTIONS_REQUETE: PublicKeyCredentialRequestOptionsJSON = {
  challenge: 'BAUG',
  rpId: 'heritage.naltpom.fr',
  allowCredentials: [],
  userVerification: 'required',
}

const octets = (...v: number[]): ArrayBuffer => new Uint8Array(v).buffer

/** Ce que rendrait `credentials.create()`. */
function identifiantCree(): Credential {
  return {
    id: 'CQ',
    rawId: octets(9),
    type: 'public-key',
    authenticatorAttachment: 'platform',
    getClientExtensionResults: () => ({}),
    response: { clientDataJSON: octets(1), attestationObject: octets(2), getTransports: () => ['internal'] },
  } as unknown as Credential
}

/** Ce que rendrait `credentials.get()`. */
function assertion(attachement: 'platform' | 'cross-platform' = 'platform'): Credential {
  return {
    id: 'CQ',
    rawId: octets(9),
    type: 'public-key',
    authenticatorAttachment: attachement,
    getClientExtensionResults: () => ({}),
    response: { clientDataJSON: octets(1), authenticatorData: octets(2), signature: octets(3), userHandle: octets(7) },
  } as unknown as Credential
}

interface Options {
  webauthn?: boolean
  plateforme?: boolean
  conditionnel?: boolean
}

let env: EnvironnementCleDAcces & { creer: ReturnType<typeof vi.fn>; obtenir: ReturnType<typeof vi.fn> }

function environnement(o: Options = {}): void {
  env = {
    webauthn: o.webauthn ?? true,
    plateforme: () => Promise.resolve(o.plateforme ?? true),
    conditionnel: () => Promise.resolve(o.conditionnel ?? false),
    creer: vi.fn(() => Promise.resolve(identifiantCree())),
    obtenir: vi.fn(() => Promise.resolve(assertion())),
    agent: AGENT_IPHONE,
  }
  definirEnvironnementCleDAcces(env)
}

function stockage(): Map<string, string> {
  const m = new Map<string, string>()
  vi.stubGlobal('localStorage', {
    getItem: (k: string) => m.get(k) ?? null,
    setItem: (k: string, v: string) => void m.set(k, v),
    removeItem: (k: string) => void m.delete(k),
  })
  return m
}

function stockageBloque(): void {
  const lever = (): never => {
    throw new Error('SecurityError')
  }
  vi.stubGlobal('localStorage', { getItem: lever, setItem: lever, removeItem: lever })
}

const nonPermis = (): DOMException => new DOMException('annulé', 'NotAllowedError')

const cadre = shallowRef(true)
let portee: EffectScope | null = null

function dansUnePortee<T>(f: () => T): T {
  portee?.stop()
  portee = effectScope()
  const r = portee.run(f)
  if (r === undefined) throw new Error('portée arrêtée')
  return r
}

function piloterInvitation(horloge: () => number = () => MAINTENANT): ReturnType<typeof piloterInvitationCleDAcces> {
  return dansUnePortee(() => piloterInvitationCleDAcces(() => cadre.value, horloge))
}

/** Laisse passer le délai **et** les questions asynchrones (navigateur, liste
 *  du serveur, défi préparé). */
async function laisserDecider(): Promise<void> {
  await vi.advanceTimersByTimeAsync(DELAI_INVITATION_CLE_MS)
  for (let i = 0; i < 10; i++) await Promise.resolve()
  await nextTick()
}

/** Une clé telle que la rend `GET /api/cles-d-acces`. */
function cleServeur(id: number): CleDAcces {
  return { id, nom: 'Clé', creeeLe: '2026-03-12T10:00:00+01:00', derniereUtilisation: null }
}

beforeEach(() => {
  vi.useFakeTimers()
  vi.setSystemTime(MAINTENANT)
  stockage()
  environnement()
  reinitialiserClesDAcces()
  cadre.value = true
  session.connecte.value = true
  session.incarnation.value = null
  session.courante.value = { utilisateur: { id: 7 } }
  session.connecterParCleDAcces.mockReset()
  session.connecterParCleDAcces.mockResolvedValue({ utilisateur: { id: 7 } })
  api.lireOptionsEnregistrementCleDAcces.mockReset().mockResolvedValue({ options: OPTIONS_CREATION })
  api.lireOptionsConnexionCleDAcces.mockReset().mockResolvedValue({ options: OPTIONS_REQUETE })
  api.listerClesDAcces.mockReset().mockResolvedValue([])
  api.enregistrerCleDAcces.mockReset().mockImplementation((_r: unknown, nom: string | null) =>
    Promise.resolve({ id: 31, nom: nom ?? 'Clé', creeeLe: '2026-10-01T12:00:00+02:00', derniereUtilisation: null }),
  )
})

afterEach(() => {
  portee?.stop()
  portee = null
  reinitialiserClesDAcces()
  definirEnvironnementCleDAcces(null)
  vi.unstubAllGlobals()
  vi.useRealTimers()
})

/* ------------------------------------------------------------------ */
/* Mémoire de l'appareil                                               */
/* ------------------------------------------------------------------ */

describe('la mémoire de l’appareil', () => {
  it('retient les clés créées ici, compte par compte', () => {
    expect(cetAppareilAUneCle(7)).toBe(false)
    retenirCleSurCetAppareil(7, 31)
    retenirCleSurCetAppareil(7, 31)
    retenirCleSurCetAppareil(7, 32)
    expect(clesDeCetAppareil(7)).toEqual([31, 32])
    expect(cetAppareilAUneCle(8)).toBe(false)
  })

  it('« une clé sert ici, on ne sait pas laquelle » : liste vide, mais présente', () => {
    retenirCleSurCetAppareil(7, null)
    expect(cetAppareilAUneCle(7)).toBe(true)
    expect(clesDeCetAppareil(7)).toEqual([])
  })

  it('oublie une clé supprimée, et l’appareil redevient « sans clé » après la dernière', () => {
    retenirCleSurCetAppareil(7, 31)
    retenirCleSurCetAppareil(7, 32)
    oublierCleDeCetAppareil(7, 31)
    expect(clesDeCetAppareil(7)).toEqual([32])
    oublierCleDeCetAppareil(7, 99) // pas d'ici : rien ne bouge
    expect(clesDeCetAppareil(7)).toEqual([32])
    oublierCleDeCetAppareil(7, 32)
    expect(cetAppareilAUneCle(7)).toBe(false)
  })

  it('« Plus tard » est retenu par compte', () => {
    ecrirePlusTardCleDAcces(7, MAINTENANT)
    expect(lirePlusTardCleDAcces(7)).toBe(MAINTENANT)
    expect(lirePlusTardCleDAcces(8)).toBeNull()
  })

  it('un stockage bloqué (navigation privée) ne lève jamais', () => {
    stockageBloque()
    expect(() => retenirCleSurCetAppareil(7, 31)).not.toThrow()
    expect(cetAppareilAUneCle(7)).toBe(false)
    expect(() => ecrirePlusTardCleDAcces(7, MAINTENANT)).not.toThrow()
    expect(lirePlusTardCleDAcces(7)).toBeNull()
  })
})

/* ------------------------------------------------------------------ */
/* La décision de l'invitation                                         */
/* ------------------------------------------------------------------ */

describe('invitationCleDAccesPossible()', () => {
  const base: ContexteInvitationCleDAcces = {
    webauthn: true,
    plateforme: true,
    connecte: true,
    incarnation: false,
    cleSurCetAppareil: false,
    plusTardLe: null,
    maintenant: MAINTENANT,
  }

  it('propose à qui a Face ID / une empreinte et pas encore de clé ici', () => {
    expect(invitationCleDAccesPossible(base)).toBe(true)
  })

  it('se tait sans WebAuthn, sans authentificateur de l’appareil, sans session, pendant une incarnation', () => {
    expect(invitationCleDAccesPossible({ ...base, webauthn: false })).toBe(false)
    expect(invitationCleDAccesPossible({ ...base, plateforme: false })).toBe(false)
    expect(invitationCleDAccesPossible({ ...base, connecte: false })).toBe(false)
    expect(invitationCleDAccesPossible({ ...base, incarnation: true })).toBe(false)
  })

  it('se tait si ce compte a déjà une clé sur cet appareil', () => {
    expect(invitationCleDAccesPossible({ ...base, cleSurCetAppareil: true })).toBe(false)
  })

  it('se tait trente jours après « Plus tard », pas un de plus', () => {
    expect(invitationCleDAccesPossible({ ...base, plusTardLe: MAINTENANT - 60_000 })).toBe(false)
    expect(invitationCleDAccesPossible({ ...base, plusTardLe: MAINTENANT - (SILENCE_INVITATION_CLE_MS - 1) })).toBe(false)
    expect(invitationCleDAccesPossible({ ...base, plusTardLe: MAINTENANT - SILENCE_INVITATION_CLE_MS })).toBe(true)
  })
})

/* ------------------------------------------------------------------ */
/* Le pilote de l'invitation                                           */
/* ------------------------------------------------------------------ */

describe('piloterInvitationCleDAcces() — à l’arrivée', () => {
  it('s’ouvre à l’arrivée, sans connexion récente, après le délai — le défi préparé avant', async () => {
    // Aucune connexion dans ce chargement : la session était déjà là.
    const { ouverte, etape } = piloterInvitation()
    // La priorité est prise tout de suite, avant même le délai.
    expect(invitationCleDAccesEnCours.value).toBe(true)
    await vi.advanceTimersByTimeAsync(DELAI_INVITATION_CLE_MS - 1)
    expect(ouverte.value).toBe(false)
    expect(api.lireOptionsEnregistrementCleDAcces).not.toHaveBeenCalled()
    await laisserDecider()
    expect(ouverte.value).toBe(true)
    expect(etape.value).toBe('activer')
    expect(api.lireOptionsEnregistrementCleDAcces).toHaveBeenCalledTimes(1)
    expect(invitationCleDAccesEnCours.value).toBe(true)
  })

  it('le délai est celui des notifications : 1,5 s', () => {
    expect(DELAI_INVITATION_CLE_MS).toBe(1_500)
  })

  it('une seule fois par chargement — un nouveau chargement la rend possible', async () => {
    const premier = piloterInvitation()
    await laisserDecider()
    expect(premier.ouverte.value).toBe(true)

    // Le composant est remonté dans le même chargement : rien.
    const second = piloterInvitation()
    await laisserDecider()
    expect(second.ouverte.value).toBe(false)
    expect(invitationCleDAccesEnCours.value).toBe(false)

    reinitialiserClesDAcces()
    const apresRechargement = piloterInvitation()
    await laisserDecider()
    expect(apresRechargement.ouverte.value).toBe(true)
  })

  it('se connecter après le chargement : la priorité est prise sur l’écran de connexion, la fenêtre suit', async () => {
    session.connecte.value = false
    session.courante.value = null
    cadre.value = false
    const { ouverte } = piloterInvitation()
    await laisserDecider()
    expect(ouverte.value).toBe(false)
    expect(invitationCleDAccesEnCours.value).toBe(false)

    session.connecte.value = true
    session.courante.value = { utilisateur: { id: 7 } }
    await nextTick()
    expect(invitationCleDAccesEnCours.value).toBe(true)
    await laisserDecider()
    expect(ouverte.value).toBe(false)

    cadre.value = true
    await nextTick()
    await laisserDecider()
    expect(ouverte.value).toBe(true)
  })

  it('renonce — et rend la main aux notifications — sans authentificateur de l’appareil', async () => {
    environnement({ plateforme: false })
    const { ouverte } = piloterInvitation()
    await laisserDecider()
    expect(ouverte.value).toBe(false)
    expect(invitationCleDAccesEnCours.value).toBe(false)
    expect(api.lireOptionsEnregistrementCleDAcces).not.toHaveBeenCalled()
  })

  it('sans WebAuthn du tout : ne prend même pas la priorité', async () => {
    environnement({ webauthn: false })
    const { ouverte } = piloterInvitation()
    expect(invitationCleDAccesEnCours.value).toBe(false)
    await laisserDecider()
    expect(ouverte.value).toBe(false)
  })

  it('pas de fenêtre si ce compte a une clé sur cet appareil (et que le serveur la connaît)', async () => {
    retenirCleSurCetAppareil(7, 31)
    api.listerClesDAcces.mockResolvedValue([cleServeur(31)])
    const { ouverte } = piloterInvitation()
    await laisserDecider()
    expect(ouverte.value).toBe(false)
    expect(invitationCleDAccesEnCours.value).toBe(false)
    expect(clesDeCetAppareil(7)).toEqual([31])
  })

  it('« une clé sert ici, on ne sait pas laquelle » + des clés au serveur : pas de fenêtre', async () => {
    retenirCleSurCetAppareil(7, null)
    api.listerClesDAcces.mockResolvedValue([cleServeur(40)])
    const { ouverte } = piloterInvitation()
    await laisserDecider()
    expect(ouverte.value).toBe(false)
  })

  it('une clé ici pour un autre compte n’empêche rien', async () => {
    retenirCleSurCetAppareil(8, 31)
    const { ouverte } = piloterInvitation()
    await laisserDecider()
    expect(ouverte.value).toBe(true)
    // La mémoire dit « rien ici » pour ce compte : le serveur n'est pas interrogé.
    expect(api.listerClesDAcces).not.toHaveBeenCalled()
  })

  it('la liste du serveur, vide, corrige une mémoire périmée : la fenêtre s’ouvre', async () => {
    retenirCleSurCetAppareil(7, null)
    api.listerClesDAcces.mockResolvedValue([])
    const { ouverte } = piloterInvitation()
    await laisserDecider()
    expect(ouverte.value).toBe(true)
    expect(cetAppareilAUneCle(7)).toBe(false)
  })

  it('une clé retenue ici mais supprimée ailleurs est oubliée : la fenêtre s’ouvre', async () => {
    retenirCleSurCetAppareil(7, 31)
    api.listerClesDAcces.mockResolvedValue([cleServeur(32)])
    const { ouverte } = piloterInvitation()
    await laisserDecider()
    expect(ouverte.value).toBe(true)
    expect(cetAppareilAUneCle(7)).toBe(false)
  })

  it('une liste illisible laisse la mémoire locale faire foi', async () => {
    retenirCleSurCetAppareil(7, 31)
    api.listerClesDAcces.mockRejectedValue(new Error('réseau'))
    const { ouverte } = piloterInvitation()
    await laisserDecider()
    expect(ouverte.value).toBe(false)
    expect(invitationCleDAccesEnCours.value).toBe(false)
  })

  it('« Plus tard » : trente jours de silence pour ce compte, pas un de plus', async () => {
    ecrirePlusTardCleDAcces(7, MAINTENANT - 29 * 24 * 3600 * 1000)
    const premier = piloterInvitation()
    // Su sans rien demander : la priorité n'est même pas prise.
    expect(invitationCleDAccesEnCours.value).toBe(false)
    await laisserDecider()
    expect(premier.ouverte.value).toBe(false)

    reinitialiserClesDAcces()
    ecrirePlusTardCleDAcces(7, MAINTENANT - SILENCE_INVITATION_CLE_MS)
    const second = piloterInvitation()
    await laisserDecider()
    expect(second.ouverte.value).toBe(true)
  })

  it('« Plus tard » ferme, retient la date pour ce compte et rend la main ; le chargement suivant se tait', async () => {
    const { ouverte, plusTard } = piloterInvitation()
    await laisserDecider()
    plusTard()
    expect(ouverte.value).toBe(false)
    expect(lirePlusTardCleDAcces(7)).toBe(MAINTENANT)
    expect(invitationCleDAccesEnCours.value).toBe(false)

    reinitialiserClesDAcces()
    const suivant = piloterInvitation(() => MAINTENANT + 24 * 3600 * 1000)
    await laisserDecider()
    expect(suivant.ouverte.value).toBe(false)
  })

  it('jamais pendant une incarnation', async () => {
    session.incarnation.value = { administrateur: 'a@exemple.test' }
    const { ouverte } = piloterInvitation()
    expect(invitationCleDAccesEnCours.value).toBe(false)
    await laisserDecider()
    expect(ouverte.value).toBe(false)
  })

  it('attend un écran avec cadre, en gardant la priorité', async () => {
    cadre.value = false
    const { ouverte } = piloterInvitation()
    await vi.advanceTimersByTimeAsync(DELAI_INVITATION_CLE_MS * 5)
    expect(ouverte.value).toBe(false)
    expect(invitationCleDAccesEnCours.value).toBe(true)

    cadre.value = true
    await nextTick()
    await laisserDecider()
    expect(ouverte.value).toBe(true)
  })

  it('une déconnexion pendant l’attente annule tout et rend la main', async () => {
    const { ouverte } = piloterInvitation()
    session.connecte.value = false
    session.courante.value = null
    await nextTick()
    await laisserDecider()
    expect(ouverte.value).toBe(false)
    expect(invitationCleDAccesEnCours.value).toBe(false)
  })

  it('« Activer » : la clé est créée avec le défi préparé, nommée d’après l’appareil, et retenue ici', async () => {
    const { ouverte, accepter, confirmation } = piloterInvitation()
    await laisserDecider()
    await accepter()

    expect(api.lireOptionsEnregistrementCleDAcces).toHaveBeenCalledTimes(1)
    expect(env.creer).toHaveBeenCalledTimes(1)
    const [reponse, nom] = api.enregistrerCleDAcces.mock.calls[0] as [{ response: Record<string, unknown> }, string]
    expect(reponse.response['attestationObject']).toBe('Ag')
    expect(nom).toBe('iPhone')
    expect(clesDeCetAppareil(7)).toEqual([31])
    expect(ouverte.value).toBe(false)
    expect(confirmation.value).toBe('Clé d’accès activée')
    expect(invitationCleDAccesEnCours.value).toBe(false)
  })

  it('« Activer » annulé dans Face ID : la fenêtre reste, avec une phrase, et la priorité aussi', async () => {
    env.creer.mockRejectedValueOnce(nonPermis())
    const { ouverte, accepter, message } = piloterInvitation()
    await laisserDecider()
    await accepter()
    expect(ouverte.value).toBe(true)
    expect(message.value).toContain('annulé')
    expect(api.enregistrerCleDAcces).not.toHaveBeenCalled()
    expect(invitationCleDAccesEnCours.value).toBe(true)
  })

  it('« Activer » sur un appareil qui a déjà une clé de ce compte : fermé, et retenu', async () => {
    env.creer.mockRejectedValueOnce(new DOMException('déjà', 'InvalidStateError'))
    const { ouverte, accepter, confirmation } = piloterInvitation()
    await laisserDecider()
    await accepter()
    expect(ouverte.value).toBe(false)
    expect(confirmation.value).toContain('déjà une clé')
    expect(cetAppareilAUneCle(7)).toBe(true)
  })

  it('« Activer » refusé par le serveur : l’erreur est gardée pour `MessageErreur`', async () => {
    const refus = Object.assign(new Error('Refusé.'), { name: 'ErreurApi' })
    api.enregistrerCleDAcces.mockRejectedValueOnce(refus)
    const { ouverte, accepter, erreur } = piloterInvitation()
    await laisserDecider()
    await accepter()
    expect(ouverte.value).toBe(true)
    expect(erreur.value).toBe(refus)
  })
})

describe('piloterInvitationCleDAcces() — la preuve d’identité dans la fenêtre', () => {
  /** Une session de plus de dix minutes : le serveur refuse le défi sans
   *  mot de passe, puis l'accorde avec. */
  function sessionAncienne(): void {
    api.lireOptionsEnregistrementCleDAcces.mockImplementation((motDePasse: string | null = null) =>
      motDePasse === 'le-bon'
        ? Promise.resolve({ options: OPTIONS_CREATION })
        : Promise.reject(
            motDePasse === null
              ? preuveRequise()
              : new ErreurApi(422, 'donnees_invalides', 'x', { motDePasseActuel: 'Le mot de passe actuel est incorrect.' }),
          ),
    )
  }

  it('session ancienne : la fenêtre s’ouvre sur l’étape « mot de passe », et « Activer » n’y fait rien', async () => {
    sessionAncienne()
    const { ouverte, etape, accepter } = piloterInvitation()
    await laisserDecider()
    expect(ouverte.value).toBe(true)
    expect(etape.value).toBe('mot-de-passe')
    await accepter()
    expect(env.creer).not.toHaveBeenCalled()
  })

  it('« Confirmer » puis « Activer » : deux gestes, et le second part sans attendre le serveur', async () => {
    sessionAncienne()
    const { etape, motDePasseConfirme, confirmerMotDePasse, accepter, confirmation, ouverte } = piloterInvitation()
    await laisserDecider()

    await confirmerMotDePasse('le-bon')
    expect(api.lireOptionsEnregistrementCleDAcces).toHaveBeenLastCalledWith('le-bon')
    expect(etape.value).toBe('activer')
    expect(motDePasseConfirme.value).toBe(true)
    expect(env.creer).not.toHaveBeenCalled()
    const appelsAvantLeGeste = api.lireOptionsEnregistrementCleDAcces.mock.calls.length

    await accepter()
    // Le défi obtenu avec le mot de passe a servi : aucun appel de plus.
    expect(api.lireOptionsEnregistrementCleDAcces).toHaveBeenCalledTimes(appelsAvantLeGeste)
    expect(env.creer).toHaveBeenCalledTimes(1)
    expect(ouverte.value).toBe(false)
    expect(confirmation.value).toBe('Clé d’accès activée')
    expect(invitationCleDAccesEnCours.value).toBe(false)
  })

  it('mot de passe faux : un message clair sous le champ, on reste à l’étape', async () => {
    sessionAncienne()
    const { etape, erreurMotDePasse, erreur, confirmerMotDePasse, ouverte } = piloterInvitation()
    await laisserDecider()
    await confirmerMotDePasse('faux')
    expect(ouverte.value).toBe(true)
    expect(etape.value).toBe('mot-de-passe')
    expect(erreurMotDePasse.value).toBe(MESSAGE_MOT_DE_PASSE_REFUSE)
    expect(erreur.value).toBeNull()

    // Le bon, ensuite : le message s'efface.
    await confirmerMotDePasse('le-bon')
    expect(erreurMotDePasse.value).toBeNull()
    expect(etape.value).toBe('activer')
  })

  it('un autre refus (trop d’essais) va à `MessageErreur`, pas sous le champ', async () => {
    sessionAncienne()
    const { erreur, erreurMotDePasse, confirmerMotDePasse } = piloterInvitation()
    await laisserDecider()
    const plafond = new ErreurApi(429, 'trop_de_requetes', 'Trop de tentatives.')
    api.lireOptionsEnregistrementCleDAcces.mockRejectedValueOnce(plafond)
    await confirmerMotDePasse('le-bon')
    expect(erreur.value).toBe(plafond)
    expect(erreurMotDePasse.value).toBeNull()
  })

  it('un mot de passe vide n’est pas envoyé', async () => {
    sessionAncienne()
    const { confirmerMotDePasse } = piloterInvitation()
    await laisserDecider()
    const appels = api.lireOptionsEnregistrementCleDAcces.mock.calls.length
    await confirmerMotDePasse('')
    expect(api.lireOptionsEnregistrementCleDAcces).toHaveBeenCalledTimes(appels)
  })

  it('la preuve a expiré pendant que la fenêtre attendait : « Activer » ramène au mot de passe', async () => {
    const { etape, accepter, ouverte, message } = piloterInvitation(Date.now)
    await laisserDecider()
    expect(ouverte.value).toBe(true)
    expect(etape.value).toBe('activer')

    // Le défi préparé a vieilli ; le serveur, lui, veut maintenant le mot de passe.
    vi.setSystemTime(Date.now() + DUREE_OPTIONS_MS)
    api.lireOptionsEnregistrementCleDAcces.mockRejectedValueOnce(preuveRequise())
    await accepter()
    expect(ouverte.value).toBe(true)
    expect(etape.value).toBe('mot-de-passe')
    expect(message.value).toBeNull()
    expect(env.creer).not.toHaveBeenCalled()
  })

  it('« Plus tard » depuis l’étape mot de passe vaut trente jours, comme ailleurs', async () => {
    sessionAncienne()
    const { plusTard, ouverte } = piloterInvitation()
    await laisserDecider()
    plusTard()
    expect(ouverte.value).toBe(false)
    expect(lirePlusTardCleDAcces(7)).toBe(MAINTENANT)
    expect(invitationCleDAccesEnCours.value).toBe(false)
  })
})

/* ------------------------------------------------------------------ */
/* Le défi préparé                                                     */
/* ------------------------------------------------------------------ */

describe('ajouterCleDAccesSurCetAppareil()', () => {
  it('ne sert un défi préparé qu’une fois, et pas s’il a plus de quatre minutes', async () => {
    await preparerAjoutCleDAcces()
    await ajouterCleDAccesSurCetAppareil()
    expect(api.lireOptionsEnregistrementCleDAcces).toHaveBeenCalledTimes(1)

    await ajouterCleDAccesSurCetAppareil()
    expect(api.lireOptionsEnregistrementCleDAcces).toHaveBeenCalledTimes(2)

    await preparerAjoutCleDAcces()
    vi.setSystemTime(MAINTENANT + DUREE_OPTIONS_MS)
    await ajouterCleDAccesSurCetAppareil()
    expect(api.lireOptionsEnregistrementCleDAcces).toHaveBeenCalledTimes(4)
  })

  it('passe au navigateur un défi en octets, et le nom choisi s’il y en a un', async () => {
    await ajouterCleDAccesSurCetAppareil('Tablette du salon')
    const options = (env.creer.mock.calls[0] as [CredentialCreationOptions])[0]
    expect(Array.from(new Uint8Array(options.publicKey!.challenge as ArrayBuffer))).toEqual([1, 2, 3])
    expect(api.enregistrerCleDAcces.mock.calls[0]?.[1]).toBe('Tablette du salon')
  })
})

/* ------------------------------------------------------------------ */
/* La preuve d'identité récente                                        */
/* ------------------------------------------------------------------ */

describe('la preuve d’identité récente', () => {
  it('reconnaît le seul refus qui demande le mot de passe', () => {
    expect(preuveDIdentiteRequise(preuveRequise())).toBe(true)
    expect(preuveDIdentiteRequise(new ErreurApi(422, 'donnees_invalides', 'x', { reponse: 'x' }))).toBe(false)
    expect(preuveDIdentiteRequise(new ErreurApi(409, 'incarnation_active', 'x'))).toBe(false)
    expect(preuveDIdentiteRequise(new DOMException('annulé', 'NotAllowedError'))).toBe(false)
    expect(preuveDIdentiteRequise(null)).toBe(false)
  })

  it('la préparation rend le refus au lieu de le taire', async () => {
    const refus = preuveRequise()
    api.lireOptionsEnregistrementCleDAcces.mockRejectedValueOnce(refus)
    expect(await preparerAjoutCleDAcces()).toBe(refus)
    expect(await preparerAjoutCleDAcces()).toBeNull()
  })

  it('le mot de passe confirmé prépare le défi : l’ajout part sans autre appel', async () => {
    await confirmerMotDePassePourAjout('le-bon-mot-de-passe')
    expect(api.lireOptionsEnregistrementCleDAcces).toHaveBeenCalledWith('le-bon-mot-de-passe')

    await ajouterCleDAccesSurCetAppareil()
    expect(api.lireOptionsEnregistrementCleDAcces).toHaveBeenCalledTimes(1)
    expect(env.creer).toHaveBeenCalledTimes(1)
  })

  it('un mot de passe faux remonte l’erreur du serveur, et rien n’est préparé', async () => {
    const faux = new ErreurApi(422, 'donnees_invalides', 'x', { motDePasseActuel: 'Le mot de passe actuel est incorrect.' })
    api.lireOptionsEnregistrementCleDAcces.mockRejectedValueOnce(faux)
    await expect(confirmerMotDePassePourAjout('faux')).rejects.toBe(faux)
    await ajouterCleDAccesSurCetAppareil()
    expect(api.lireOptionsEnregistrementCleDAcces).toHaveBeenCalledTimes(2)
  })

  it('après un changement de mot de passe, l’appareil redevient « sans clé »', () => {
    retenirCleSurCetAppareil(7, 31)
    retenirCleSurCetAppareil(8, 40)
    oublierToutesLesClesDeCetAppareil(7)
    expect(cetAppareilAUneCle(7)).toBe(false)
    expect(clesDeCetAppareil(8)).toEqual([40])
  })
})

/* ------------------------------------------------------------------ */
/* La connexion par clé                                                */
/* ------------------------------------------------------------------ */

function rappels(): RappelsConnexionCleDAcces & {
  apres: Mock<(...args: unknown[]) => void>
  surErreur: Mock<(...args: unknown[]) => void>
} {
  return { apres: vi.fn<(...args: unknown[]) => void>(), surErreur: vi.fn<(...args: unknown[]) => void>(), surDebut: vi.fn() }
}

describe('piloterConnexionCleDAcces()', () => {
  it('sans WebAuthn : rien à montrer, rien à démarrer', async () => {
    environnement({ webauthn: false })
    const pilote = dansUnePortee(() => piloterConnexionCleDAcces(rappels()))
    expect(pilote.possible).toBe(false)
    await pilote.demarrer()
    expect(api.lireOptionsConnexionCleDAcces).not.toHaveBeenCalled()
  })

  it('le libellé passe à « Face ID / empreinte » quand l’appareil a de quoi', async () => {
    const pilote = dansUnePortee(() => piloterConnexionCleDAcces(rappels()))
    expect(pilote.libelle.value).toBe('Se connecter avec une clé d’accès')
    await pilote.demarrer()
    expect(pilote.libelle.value).toBe('Se connecter avec Face ID / empreinte')
  })

  it('le libellé reste neutre sans authentificateur de l’appareil', async () => {
    environnement({ plateforme: false })
    const pilote = dansUnePortee(() => piloterConnexionCleDAcces(rappels()))
    await pilote.demarrer()
    expect(pilote.libelle.value).toBe('Se connecter avec une clé d’accès')
  })

  it('le bouton : défi préparé au montage, réponse envoyée, suite appelée, appareil retenu', async () => {
    const r = rappels()
    const pilote = dansUnePortee(() => piloterConnexionCleDAcces(r))
    await pilote.demarrer()
    expect(api.lireOptionsConnexionCleDAcces).toHaveBeenCalledTimes(1)

    await pilote.seConnecter()
    expect(api.lireOptionsConnexionCleDAcces).toHaveBeenCalledTimes(1)
    const demande = (env.obtenir.mock.calls[0] as [CredentialRequestOptions])[0]
    expect(demande.mediation).toBeUndefined()
    expect(Array.from(new Uint8Array(demande.publicKey!.challenge as ArrayBuffer))).toEqual([4, 5, 6])

    const [reponse] = session.connecterParCleDAcces.mock.calls[0] as [{ response: Record<string, unknown> }]
    expect(reponse.response['userHandle']).toBe('Bw')
    expect(r.apres).toHaveBeenCalledWith({ utilisateur: { id: 7 } })
    expect(r.surErreur).not.toHaveBeenCalled()
    expect(cetAppareilAUneCle(7)).toBe(true)
  })

  it('une clé d’un téléphone posé à côté (cross-platform) ne compte pas comme « clé ici »', async () => {
    env.obtenir.mockResolvedValueOnce(assertion('cross-platform'))
    const pilote = dansUnePortee(() => piloterConnexionCleDAcces(rappels()))
    await pilote.seConnecter()
    expect(cetAppareilAUneCle(7)).toBe(false)
  })

  it('annulé dans Face ID : une phrase claire, pas d’erreur serveur, pas de suite', async () => {
    env.obtenir.mockRejectedValueOnce(nonPermis())
    const r = rappels()
    const pilote = dansUnePortee(() => piloterConnexionCleDAcces(r))
    await pilote.seConnecter()
    expect(pilote.message.value).toContain('mot de passe')
    expect(r.surErreur).not.toHaveBeenCalled()
    expect(r.apres).not.toHaveBeenCalled()
    expect(session.connecterParCleDAcces).not.toHaveBeenCalled()
  })

  it('refusé par le serveur (clé inconnue, compte suspendu, plafond) : remonté tel quel à l’écran', async () => {
    const refus = Object.assign(new Error('Identifiants invalides.'), { name: 'ErreurApi' })
    session.connecterParCleDAcces.mockRejectedValueOnce(refus)
    const r = rappels()
    const pilote = dansUnePortee(() => piloterConnexionCleDAcces(r))
    await pilote.seConnecter()
    expect(r.surErreur).toHaveBeenCalledWith(refus)
    expect(pilote.message.value).toBeNull()
    expect(r.apres).not.toHaveBeenCalled()
  })

  it('autoremplissage : armé au montage, interrompu par le bouton qui reprend le même défi', async () => {
    environnement({ conditionnel: true })
    const signaux: AbortSignal[] = []
    env.obtenir.mockImplementationOnce(
      (o: CredentialRequestOptions) =>
        new Promise((_ok, ko) => {
          signaux.push(o.signal!)
          o.signal!.addEventListener('abort', () => ko(new DOMException('interrompu', 'AbortError')))
        }),
    )
    const r = rappels()
    const pilote = dansUnePortee(() => piloterConnexionCleDAcces(r))
    await pilote.demarrer()
    await vi.advanceTimersByTimeAsync(0)
    expect(pilote.conditionnel.value).toBe(true)
    expect((env.obtenir.mock.calls[0] as [CredentialRequestOptions])[0].mediation).toBe('conditional')

    await pilote.seConnecter()
    expect(signaux[0]?.aborted).toBe(true)
    expect(api.lireOptionsConnexionCleDAcces).toHaveBeenCalledTimes(1)
    expect((env.obtenir.mock.calls[1] as [CredentialRequestOptions])[0].mediation).toBeUndefined()
    expect(r.apres).toHaveBeenCalledTimes(1)
    expect(pilote.message.value).toBeNull()
  })

  it('autoremplissage refusé d’emblée par le navigateur : silence, et pas de boucle', async () => {
    environnement({ conditionnel: true })
    env.obtenir.mockRejectedValue(nonPermis())
    const r = rappels()
    const pilote = dansUnePortee(() => piloterConnexionCleDAcces(r))
    await pilote.demarrer()
    await vi.advanceTimersByTimeAsync(1000)
    expect(env.obtenir).toHaveBeenCalledTimes(1)
    expect(pilote.message.value).toBeNull()
    expect(r.surErreur).not.toHaveBeenCalled()
  })

  it('autoremplissage : renouvelé avant l’expiration du défi', async () => {
    environnement({ conditionnel: true })
    env.obtenir.mockImplementation(
      (o: CredentialRequestOptions) =>
        new Promise((_ok, ko) => {
          o.signal!.addEventListener('abort', () => ko(new DOMException('interrompu', 'AbortError')))
        }),
    )
    const pilote = dansUnePortee(() => piloterConnexionCleDAcces(rappels()))
    await pilote.demarrer()
    await vi.advanceTimersByTimeAsync(0)
    expect(api.lireOptionsConnexionCleDAcces).toHaveBeenCalledTimes(1)
    await vi.advanceTimersByTimeAsync(DUREE_OPTIONS_MS)
    expect(api.lireOptionsConnexionCleDAcces).toHaveBeenCalledTimes(2)
    expect(env.obtenir).toHaveBeenCalledTimes(2)
    pilote.arreter()
  })

  it('l’écran quitté : l’autoremplissage est interrompu', async () => {
    environnement({ conditionnel: true })
    let signal: AbortSignal | undefined
    env.obtenir.mockImplementation(
      (o: CredentialRequestOptions) =>
        new Promise((_ok, ko) => {
          signal = o.signal!
          o.signal!.addEventListener('abort', () => ko(new DOMException('interrompu', 'AbortError')))
        }),
    )
    const pilote = dansUnePortee(() => piloterConnexionCleDAcces(rappels()))
    await pilote.demarrer()
    await vi.advanceTimersByTimeAsync(0)
    portee?.stop()
    portee = null
    expect(signal?.aborted).toBe(true)
    await vi.advanceTimersByTimeAsync(DUREE_OPTIONS_MS * 2)
    expect(env.obtenir).toHaveBeenCalledTimes(1)
  })
})
