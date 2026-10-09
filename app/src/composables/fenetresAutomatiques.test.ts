/**
 * Les fenêtres automatiques **ensemble** : « Se connecter plus vite ? » (clé
 * d'accès), « Installer Heritage sur votre téléphone » et « Activer les
 * notifications » à l'arrivée, puis « Comment afficher les fiches de
 * l'arbre ? » sur l'arbre, depuis un téléphone.
 *
 * Chacune a ses tests (`clesDAcces.test.ts`, `invitationInstallation.test.ts`,
 * `invitationPush.test.ts`, `formatDesCartes.test.ts`) ; ici on tient le
 * registre lui-même, puis on monte les pilotes sur le même chargement, comme
 * `App.vue`, et on tient l'ordre :
 *
 * - la clé d'accès d'abord, l'installation ensuite, les notifications après —
 *   **jamais deux ouvertes en même temps**, à aucun instant ;
 * - chacune passe dans le **même** chargement dès que celles d'avant sont
 *   réglées (« Plus tard », clé créée) ou écartées (clé déjà là, pas de
 *   Face ID, rien à installer) ;
 * - l'installation et les notifications, prêtes **au même instant** (l'une et
 *   l'autre attendent le service worker) : l'installation passe, les
 *   notifications suivent — quel que soit l'ordre d'arrivée des deux
 *   nouvelles, et sans relance en boucle ;
 * - l'installation ne referme jamais une fenêtre ouverte : elle attend la
 *   fermeture des notifications ou du format, et passe ensuite ;
 * - le format des fiches passe **en dernier** ; et s'il est déjà ouvert, ce
 *   sont les autres qui attendent qu'il soit fermé.
 *
 * Même montage que les autres fichiers : navigateur écrit à la main, API et
 * session simulées, `localStorage` en `Map`, minuteries simulées.
 */
import { effectScope, nextTick, shallowRef, type EffectScope } from 'vue'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const api = vi.hoisted(() => ({
  // Clés d'accès.
  lireOptionsEnregistrementCleDAcces: vi.fn(),
  enregistrerCleDAcces: vi.fn(),
  lireOptionsConnexionCleDAcces: vi.fn(),
  listerClesDAcces: vi.fn(),
  // Notifications.
  lireClePush: vi.fn(() => Promise.resolve({ clePublique: 'BAEBAQ' })),
  enregistrerAbonnementPush: vi.fn(() => Promise.resolve()),
  supprimerAbonnementPush: vi.fn(() => Promise.resolve()),
}))
vi.mock('@/lib/api', () => api)
vi.mock('@/composables/useNotifications', () => ({ rafraichirCompteur: vi.fn() }))

const session = vi.hoisted(() => ({
  connecte: null as unknown as { value: boolean },
}))
vi.mock('@/composables/useSession', async () => {
  const { computed, shallowRef: sr } = await import('vue')
  session.connecte = sr(true)
  return {
    connecterParCleDAcces: () => Promise.resolve(),
    useSession: () => ({
      session: computed(() => (session.connecte.value ? { utilisateur: { id: 7 } } : null)),
      connecte: computed(() => session.connecte.value),
      incarnation: computed(() => null),
    }),
  }
})

import {
  DELAI_INVITATION_CLE_MS,
  piloterInvitationCleDAcces,
  reinitialiserClesDAcces,
  retenirCleSurCetAppareil,
} from './useClesDAcces'
import {
  DELAI_INVITATION_MS,
  definirEnvironnementPush,
  evaluerPush,
  piloterInvitationPush,
  reinitialiserPush,
} from './usePush'
import {
  ORDRE,
  doitCeder,
  invitationCleDAccesEnCours,
  liberer,
  marquerOuverte,
  occuper,
  uneAutreFenetreQue,
  type FenetreAutomatique,
} from './fenetresAutomatiques'
import {
  DELAI_INVITATION_FORMAT_MS,
  piloterInvitationFormat,
  reinitialiserFormatDesCartes,
} from './formatDesCartes'
import {
  DELAI_INVITATION_INSTALLATION_MS,
  piloterInvitationInstallation,
  reinitialiserInvitationInstallation,
} from './invitationInstallation'
import { simulerInstallation, type InvitationDInstallation } from './installation'
import { definirEnvironnementCleDAcces } from '@/lib/cleDAcces'
import { ErreurApi } from '@/lib/api/erreurs'

/* ------------------------------------------------------------------ */
/* Montage                                                             */
/* ------------------------------------------------------------------ */

const MAINTENANT = Date.UTC(2026, 9, 1, 12)
const OPTIONS_CREATION: PublicKeyCredentialCreationOptionsJSON = {
  rp: { id: 'heritage.naltpom.fr', name: 'Heritage' },
  user: { id: 'Bw', name: 'cobalt@exemple.test', displayName: 'Cobalt' },
  challenge: 'AQID',
  pubKeyCredParams: [{ type: 'public-key', alg: -7 }],
}

const octets = (...v: number[]): ArrayBuffer => new Uint8Array(v).buffer

function navigateurCle(plateforme = true): void {
  definirEnvironnementCleDAcces({
    webauthn: true,
    plateforme: () => Promise.resolve(plateforme),
    conditionnel: () => Promise.resolve(false),
    creer: () =>
      Promise.resolve({
        id: 'CQ',
        rawId: octets(9),
        type: 'public-key',
        authenticatorAttachment: 'platform',
        getClientExtensionResults: () => ({}),
        response: { clientDataJSON: octets(1), attestationObject: octets(2), getTransports: () => ['internal'] },
      } as unknown as Credential),
    obtenir: () => Promise.resolve(null),
    agent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X)',
  })
}

/** Un navigateur où le push est possible, jamais demandé : la fenêtre des
 *  notifications aurait de quoi s'ouvrir. `aInstaller` : un iPhone hors écran
 *  d'accueil, où elle n'a rien à proposer. */
function navigateurPush(aInstaller = false): void {
  definirEnvironnementPush({
    pushPossible: !aInstaller,
    aInstallerDabord: aInstaller,
    permission: () => 'default',
    demanderPermission: () => Promise.resolve('granted'),
    gestionnaire: () =>
      Promise.resolve({
        getSubscription: () => Promise.resolve(null),
        subscribe: () => Promise.reject(new Error('inutile ici')),
      }),
    lireRefus: () => false,
    ecrireRefus: () => undefined,
  })
}

const cadre = shallowRef(true)
/** Le cadre vu des seules notifications : pour les faire arriver **après**
 *  l'ouverture du format, sans toucher à la clé d'accès. */
const cadreNotifications = shallowRef(true)
let portee: EffectScope | null = null

/** Un chargement de l'application : les deux pilotes, comme `App.vue`. */
async function charger(): Promise<{
  cle: ReturnType<typeof piloterInvitationCleDAcces>
  push: ReturnType<typeof piloterInvitationPush>
}> {
  reinitialiserClesDAcces()
  reinitialiserPush()
  navigateurPush()
  await evaluerPush()
  portee?.stop()
  portee = effectScope()
  const pilotes = portee.run(() => ({
    // Même ordre de montage que dans `App.vue`.
    cle: piloterInvitationCleDAcces(() => cadre.value),
    push: piloterInvitationPush(() => cadre.value),
  }))
  if (!pilotes) throw new Error('portée arrêtée')
  return pilotes
}

type Pilotes3 = Awaited<ReturnType<typeof charger>> & { format: ReturnType<typeof piloterInvitationFormat> }

/** Un chargement **sur l'arbre**, depuis un téléphone : les trois pilotes. */
async function chargerSurLArbre(): Promise<Pilotes3> {
  reinitialiserClesDAcces()
  reinitialiserPush()
  reinitialiserFormatDesCartes()
  navigateurPush()
  await evaluerPush()
  portee?.stop()
  portee = effectScope()
  const pilotes = portee.run(() => ({
    cle: piloterInvitationCleDAcces(() => cadre.value),
    push: piloterInvitationPush(() => cadre.value && cadreNotifications.value),
    // L'arbre est dessiné d'emblée : le format n'attend que sa place.
    format: piloterInvitationFormat(() => true, () => true),
  }))
  if (!pilotes) throw new Error('portée arrêtée')
  return pilotes
}

/** Combien de fenêtres automatiques sont ouvertes en ce moment. */
function ouvertes(p: Pilotes3): number {
  return [p.cle.ouverte.value, p.push.forme.value !== null, p.format.ouverte.value].filter(Boolean).length
}

/** `avancer`, pour les trois : jamais plus d'une ouverte, à aucun pas. */
async function avancer3(ms: number, p: Pilotes3): Promise<void> {
  const pas = 100
  for (let t = 0; t < ms; t += pas) {
    await vi.advanceTimersByTimeAsync(pas)
    for (let i = 0; i < 10; i++) await Promise.resolve()
    await nextTick()
    expect(ouvertes(p)).toBeLessThanOrEqual(1)
  }
}

/** Avance le temps par petits pas, en vérifiant à chacun que les deux
 *  fenêtres ne sont jamais ouvertes ensemble. */
async function avancer(
  ms: number,
  p: Awaited<ReturnType<typeof charger>>,
): Promise<void> {
  const pas = 100
  for (let t = 0; t < ms; t += pas) {
    await vi.advanceTimersByTimeAsync(pas)
    for (let i = 0; i < 10; i++) await Promise.resolve()
    await nextTick()
    expect(p.cle.ouverte.value && p.push.forme.value !== null).toBe(false)
  }
}

beforeEach(() => {
  vi.useFakeTimers()
  vi.setSystemTime(MAINTENANT)
  const m = new Map<string, string>()
  vi.stubGlobal('localStorage', {
    getItem: (k: string) => m.get(k) ?? null,
    setItem: (k: string, v: string) => void m.set(k, v),
    removeItem: (k: string) => void m.delete(k),
  })
  navigateurCle()
  // Rien à installer tant qu'un cas ne le dit pas.
  simulerInstallation(null)
  simulerInstallation({ ios: false })
  cadre.value = true
  cadreNotifications.value = true
  session.connecte.value = true
  api.lireOptionsEnregistrementCleDAcces.mockReset().mockResolvedValue({ options: OPTIONS_CREATION })
  api.listerClesDAcces.mockReset().mockResolvedValue([])
  api.enregistrerCleDAcces.mockReset().mockResolvedValue({
    id: 31,
    nom: 'iPhone',
    creeeLe: '2026-10-01T12:00:00+02:00',
    derniereUtilisation: null,
  })
})

afterEach(() => {
  portee?.stop()
  portee = null
  reinitialiserClesDAcces()
  reinitialiserPush()
  reinitialiserFormatDesCartes()
  reinitialiserInvitationInstallation()
  simulerInstallation(null)
  for (const f of ['cle-d-acces', 'installation', 'notifications', 'format-des-cartes'] as const) liberer(f)
  definirEnvironnementCleDAcces(null)
  definirEnvironnementPush(null)
  vi.unstubAllGlobals()
  vi.useRealTimers()
})

/* ------------------------------------------------------------------ */

describe('à l’arrivée : la clé d’accès d’abord, les notifications ensuite', () => {
  it('la clé d’accès s’ouvre seule ; « Plus tard », et les notifications suivent dans le même chargement', async () => {
    const p = await charger()
    expect(invitationCleDAccesEnCours.value).toBe(true)

    await avancer(DELAI_INVITATION_CLE_MS + 100, p)
    expect(p.cle.ouverte.value).toBe(true)
    expect(p.push.forme.value).toBeNull()

    // Tant qu'elle est ouverte, les notifications attendent — longtemps.
    await avancer(DELAI_INVITATION_MS * 10, p)
    expect(p.push.forme.value).toBeNull()

    p.cle.plusTard()
    await avancer(DELAI_INVITATION_MS + 100, p)
    expect(p.cle.ouverte.value).toBe(false)
    expect(p.push.forme.value).toBe('activer')
  })

  it('clé créée : les notifications suivent', async () => {
    const p = await charger()
    await avancer(DELAI_INVITATION_CLE_MS + 100, p)
    await p.cle.accepter()
    expect(p.cle.confirmation.value).toBe('Clé d’accès activée')
    await avancer(DELAI_INVITATION_MS + 100, p)
    expect(p.push.forme.value).toBe('activer')
  })

  it('pendant l’étape « mot de passe », les notifications attendent aussi', async () => {
    // Session de plus de dix minutes : le serveur veut le mot de passe (§ 2.4).
    api.lireOptionsEnregistrementCleDAcces.mockRejectedValue(
      new ErreurApi(422, 'donnees_invalides', 'Données invalides.', {
        motDePasseActuel: 'Confirmez votre mot de passe pour ajouter une clé d’accès.',
      }),
    )
    const p = await charger()
    await avancer(DELAI_INVITATION_CLE_MS + 100, p)
    expect(p.cle.etape.value).toBe('mot-de-passe')
    await avancer(DELAI_INVITATION_MS * 5, p)
    expect(p.push.forme.value).toBeNull()
  })

  it('une clé déjà sur cet appareil : pas de première fenêtre, les notifications passent dans le même chargement', async () => {
    retenirCleSurCetAppareil(7, 31)
    api.listerClesDAcces.mockResolvedValue([
      { id: 31, nom: 'iPhone', creeeLe: '2026-03-12T10:00:00+01:00', derniereUtilisation: null },
    ])
    const p = await charger()
    await avancer(DELAI_INVITATION_CLE_MS + DELAI_INVITATION_MS + 200, p)
    expect(p.cle.ouverte.value).toBe(false)
    expect(p.push.forme.value).toBe('activer')
  })

  it('pas de Face ID ni d’empreinte : les notifications passent dans le même chargement', async () => {
    navigateurCle(false)
    const p = await charger()
    await avancer(DELAI_INVITATION_CLE_MS + DELAI_INVITATION_MS + 200, p)
    expect(p.cle.ouverte.value).toBe(false)
    expect(p.push.forme.value).toBe('activer')
  })
})

describe('sur l’arbre, depuis un téléphone : le format des fiches passe en dernier', () => {
  it('clé d’accès, puis notifications, puis format — jamais deux ensemble', async () => {
    const p = await chargerSurLArbre()

    await avancer3(DELAI_INVITATION_CLE_MS + 100, p)
    expect(p.cle.ouverte.value).toBe(true)
    await avancer3(DELAI_INVITATION_FORMAT_MS * 5, p)
    expect(p.format.ouverte.value).toBe(false)

    p.cle.plusTard()
    await avancer3(DELAI_INVITATION_MS + 100, p)
    expect(p.push.forme.value).toBe('activer')
    // Les notifications tiennent leur place : le format attend encore.
    await avancer3(DELAI_INVITATION_FORMAT_MS * 5, p)
    expect(p.format.ouverte.value).toBe(false)

    p.push.plusTard()
    await avancer3(DELAI_INVITATION_FORMAT_MS + 100, p)
    expect(p.format.ouverte.value).toBe(true)

    p.format.garder()
    await avancer3(DELAI_INVITATION_FORMAT_MS * 5, p)
    expect(ouvertes(p)).toBe(0)
  })

  it('pendant l’attente des notifications, le format ne s’ouvre pas', async () => {
    // Clé déjà là : pas de première fenêtre. Les notifications et le format
    // sont prêts au même instant, avec le même délai : les notifications
    // passent, parce qu'elles réservent dès leur attente.
    retenirCleSurCetAppareil(7, 31)
    api.listerClesDAcces.mockResolvedValue([
      { id: 31, nom: 'iPhone', creeeLe: '2026-03-12T10:00:00+01:00', derniereUtilisation: null },
    ])
    const p = await chargerSurLArbre()
    await avancer3(DELAI_INVITATION_CLE_MS + DELAI_INVITATION_MS + 200, p)
    expect(p.push.forme.value).toBe('activer')
    expect(p.format.ouverte.value).toBe(false)

    p.push.plusTard()
    await avancer3(DELAI_INVITATION_FORMAT_MS + 100, p)
    expect(p.format.ouverte.value).toBe(true)
  })

  it('le format déjà ouvert : les notifications ne démarrent pas, et passent après lui', async () => {
    retenirCleSurCetAppareil(7, 31)
    api.listerClesDAcces.mockResolvedValue([
      { id: 31, nom: 'iPhone', creeeLe: '2026-03-12T10:00:00+01:00', derniereUtilisation: null },
    ])
    cadreNotifications.value = false
    const p = await chargerSurLArbre()
    await avancer3(DELAI_INVITATION_CLE_MS + DELAI_INVITATION_FORMAT_MS + 200, p)
    expect(p.format.ouverte.value).toBe(true)

    // Les notifications deviendraient possibles : elles attendent.
    cadreNotifications.value = true
    await avancer3(DELAI_INVITATION_MS * 5, p)
    expect(p.push.forme.value).toBeNull()

    p.format.choisir('portrait')
    await avancer3(DELAI_INVITATION_MS + 100, p)
    expect(p.format.ouverte.value).toBe(false)
    expect(p.push.forme.value).toBe('activer')
  })
})

/* ------------------------------------------------------------------ */
/* Le registre seul                                                    */
/* ------------------------------------------------------------------ */

describe('le registre', () => {
  it('une fenêtre ne cède jamais à elle-même, inscrite ou ouverte', () => {
    for (const f of ORDRE) {
      occuper(f)
      expect(doitCeder(f)).toBe(false)
      expect(uneAutreFenetreQue(f)).toBe(false)
      marquerOuverte(f, true)
      expect(doitCeder(f)).toBe(false)
      expect(uneAutreFenetreQue(f)).toBe(false)
      liberer(f)
    }
  })

  it('l’ordre : clé d’accès, installation, notifications, format', () => {
    expect(ORDRE).toEqual(['cle-d-acces', 'installation', 'notifications', 'format-des-cartes'])
  })

  it('`doitCeder` : aux inscrites qui précèdent, pas à celles qui suivent tant qu’elles attendent', () => {
    occuper('notifications')
    expect(doitCeder('installation')).toBe(false)
    expect(doitCeder('cle-d-acces')).toBe(false)
    expect(doitCeder('format-des-cartes')).toBe(true)
    liberer('notifications')

    occuper('cle-d-acces')
    for (const f of ['installation', 'notifications', 'format-des-cartes'] as const) expect(doitCeder(f)).toBe(true)
  })

  it.each(ORDRE.map((f) => [f]))('`doitCeder` : à toute autre ouverte, quel que soit son rang — « %s »', (ouverte) => {
    marquerOuverte(ouverte, true)
    for (const f of ORDRE) expect(doitCeder(f)).toBe(f !== ouverte)
  })

  it('ouverte implique inscrite ; refermée, elle reste inscrite jusqu’à `liberer`', () => {
    marquerOuverte('notifications', true)
    expect(uneAutreFenetreQue('installation')).toBe(true)
    expect(doitCeder('installation')).toBe(true)

    marquerOuverte('notifications', false)
    expect(uneAutreFenetreQue('installation')).toBe(true)
    expect(doitCeder('installation')).toBe(false)

    liberer('notifications')
    expect(uneAutreFenetreQue('installation')).toBe(false)
  })

  it('`liberer` retire l’inscription et l’ouverture d’un coup', () => {
    marquerOuverte('format-des-cartes', true)
    liberer('format-des-cartes')
    expect(doitCeder('installation')).toBe(false)
    expect(uneAutreFenetreQue('installation')).toBe(false)
    // Sans effet sur une fenêtre absente.
    expect(() => liberer('format-des-cartes')).not.toThrow()
    marquerOuverte('format-des-cartes', false)
    expect(uneAutreFenetreQue('installation')).toBe(false)
  })
})

/* ------------------------------------------------------------------ */
/* Avec l'installation : les quatre pilotes                            */
/* ------------------------------------------------------------------ */

/** Un `beforeinstallprompt` retenu : Chrome sait installer. */
function invitationDuNavigateur(): InvitationDInstallation {
  return {
    prompt: () => Promise.resolve(),
    userChoice: Promise.resolve({ outcome: 'dismissed' }),
  } as unknown as InvitationDInstallation
}

interface Pilotes4 {
  cle: ReturnType<typeof piloterInvitationCleDAcces>
  installation: ReturnType<typeof piloterInvitationInstallation>
  push: ReturnType<typeof piloterInvitationPush>
  format: ReturnType<typeof piloterInvitationFormat> | null
}

interface OptionsChargement {
  /** Une clé d'accès déjà sur ce téléphone : pas de première fenêtre. */
  cleIci?: boolean
  /** L'état du push est lu d'emblée (sinon : `inconnu`, rien à proposer). */
  pushEvalue?: boolean
  /** Le navigateur a déjà proposé l'installation. */
  installable?: boolean
  /** Safari sur iPhone, hors écran d'accueil. */
  iphone?: boolean
  /** Sur l'arbre : le pilote du format en plus. */
  arbre?: boolean
  /** Notifications montées **avant** l'installation : l'issue ne doit pas
   *  dépendre de l'ordre de montage. */
  inverse?: boolean
}

/** Un chargement sur téléphone, les pilotes dans l'ordre de `App.vue`. */
async function chargerTout(o: OptionsChargement = {}): Promise<Pilotes4> {
  reinitialiserClesDAcces()
  reinitialiserPush()
  reinitialiserFormatDesCartes()
  reinitialiserInvitationInstallation()
  if (o.cleIci === true) {
    retenirCleSurCetAppareil(7, 31)
    api.listerClesDAcces.mockResolvedValue([
      { id: 31, nom: 'iPhone', creeeLe: '2026-03-12T10:00:00+01:00', derniereUtilisation: null },
    ])
  }
  navigateurPush(o.iphone === true)
  if (o.pushEvalue ?? true) await evaluerPush()
  simulerInstallation({
    invitation: (o.installable ?? true) ? invitationDuNavigateur() : null,
    ios: o.iphone === true,
    installee: false,
  })
  portee?.stop()
  portee = effectScope()
  const pilotes = portee.run((): Pilotes4 => {
    const cle = piloterInvitationCleDAcces(() => cadre.value)
    const monterInstallation = (): ReturnType<typeof piloterInvitationInstallation> =>
      piloterInvitationInstallation(
        () => cadre.value,
        () => true,
      )
    const monterPush = (): ReturnType<typeof piloterInvitationPush> => piloterInvitationPush(() => cadre.value)
    let installation: ReturnType<typeof piloterInvitationInstallation>
    let push: ReturnType<typeof piloterInvitationPush>
    if (o.inverse === true) {
      push = monterPush()
      installation = monterInstallation()
    } else {
      installation = monterInstallation()
      push = monterPush()
    }
    const format = o.arbre === true ? piloterInvitationFormat(() => true, () => true) : null
    return { cle, installation, push, format }
  })
  if (!pilotes) throw new Error('portée arrêtée')
  return pilotes
}

function ouvertes4(p: Pilotes4): FenetreAutomatique[] {
  const liste: FenetreAutomatique[] = []
  if (p.cle.ouverte.value) liste.push('cle-d-acces')
  if (p.installation.forme.value !== null) liste.push('installation')
  if (p.push.forme.value !== null) liste.push('notifications')
  if (p.format?.ouverte.value === true) liste.push('format-des-cartes')
  return liste
}

/** Avance par petits pas : jamais plus d'une fenêtre ouverte, à aucun pas — et
 *  le registre dit, à chaque pas, ce qui est à l'écran. */
async function avancer4(ms: number, p: Pilotes4): Promise<void> {
  const pas = 100
  for (let t = 0; t < ms; t += pas) {
    await vi.advanceTimersByTimeAsync(pas)
    for (let i = 0; i < 10; i++) await Promise.resolve()
    await nextTick()
    const ouvertes = ouvertes4(p)
    expect(ouvertes.length).toBeLessThanOrEqual(1)
    // Une fenêtre ouverte fait céder toutes les autres, et pas elle-même.
    for (const f of ouvertes) for (const g of ORDRE) expect(doitCeder(g)).toBe(g !== f)
  }
}

describe('à l’arrivée sur téléphone : la clé d’accès, l’installation, puis les notifications', () => {
  it('l’une après l’autre, dans le même chargement, jamais deux ensemble', async () => {
    const p = await chargerTout()

    await avancer4(DELAI_INVITATION_CLE_MS + 100, p)
    expect(ouvertes4(p)).toEqual(['cle-d-acces'])
    // Tant qu'elle est ouverte, les deux autres attendent — longtemps.
    await avancer4(DELAI_INVITATION_INSTALLATION_MS * 10, p)
    expect(ouvertes4(p)).toEqual(['cle-d-acces'])

    p.cle.plusTard()
    await avancer4(DELAI_INVITATION_INSTALLATION_MS + 100, p)
    expect(p.installation.forme.value).toBe('android')
    expect(p.push.forme.value).toBeNull()
    await avancer4(DELAI_INVITATION_MS * 10, p)
    expect(p.push.forme.value).toBeNull()

    p.installation.plusTard()
    await avancer4(DELAI_INVITATION_MS + 100, p)
    expect(ouvertes4(p)).toEqual(['notifications'])
  })

  it('application installée d’un clic : les notifications suivent', async () => {
    const p = await chargerTout({ cleIci: true })
    await avancer4(DELAI_INVITATION_CLE_MS + DELAI_INVITATION_INSTALLATION_MS + 200, p)
    expect(p.installation.forme.value).toBe('android')
    await p.installation.installer()
    expect(p.installation.forme.value).toBeNull()
    await avancer4(DELAI_INVITATION_MS + 100, p)
    expect(ouvertes4(p)).toEqual(['notifications'])
  })

  it('iPhone hors écran d’accueil : la clé d’accès, puis l’installation sous sa forme « ios », et pas de notifications', async () => {
    const p = await chargerTout({ installable: false, iphone: true })
    await avancer4(DELAI_INVITATION_CLE_MS + 100, p)
    expect(ouvertes4(p)).toEqual(['cle-d-acces'])

    p.cle.plusTard()
    await avancer4(DELAI_INVITATION_INSTALLATION_MS + 100, p)
    expect(p.installation.forme.value).toBe('ios')

    p.installation.plusTard()
    await avancer4(DELAI_INVITATION_MS * 10, p)
    expect(ouvertes4(p)).toEqual([])
  })

  it('rien à installer : les notifications suivent la clé d’accès, comme avant', async () => {
    const p = await chargerTout({ installable: false })
    await avancer4(DELAI_INVITATION_CLE_MS + 100, p)
    p.cle.plusTard()
    await avancer4(DELAI_INVITATION_MS + 100, p)
    expect(ouvertes4(p)).toEqual(['notifications'])
  })
})

describe('l’installation et les notifications prêtes au même instant', () => {
  // Dans un même instant, Vue traite les pilotes dans l'ordre où leurs
  // conditions ont changé : on fait varier l'ordre d'arrivée des deux
  // nouvelles, et l'ordre de montage. L'issue ne doit dépendre ni de l'un ni
  // de l'autre — avec deux pilotes qui se céderaient l'un à l'autre, la
  // première nouvelle arrivée gagnerait.
  it.each([
    ['l’invitation du navigateur d’abord, pilotes dans l’ordre de `App.vue`', 'invitation', false],
    ['l’état du push d’abord, pilotes dans l’ordre de `App.vue`', 'push', false],
    ['l’invitation du navigateur d’abord, pilotes montés à l’envers', 'invitation', true],
    ['l’état du push d’abord, pilotes montés à l’envers', 'push', true],
  ] as const)('l’installation passe, les notifications suivent (%s)', async (_cas, premiere, inverse) => {
    const avertissements = vi.spyOn(console, 'warn').mockImplementation(() => undefined)
    try {
      // Clé déjà là : pas de première fenêtre. Ni l'invitation du navigateur
      // ni l'état du push ne sont encore connus — le service worker s'installe.
      const p = await chargerTout({ cleIci: true, pushEvalue: false, installable: false, inverse })
      await avancer4(DELAI_INVITATION_CLE_MS + 500, p)
      expect(invitationCleDAccesEnCours.value).toBe(false)
      expect(uneAutreFenetreQue('cle-d-acces')).toBe(false)

      // Le service worker est prêt : les deux arrivent dans le même instant.
      const invitation = (): void => simulerInstallation({ invitation: invitationDuNavigateur() })
      const push = (): void => void evaluerPush()
      if (premiere === 'invitation') {
        invitation()
        push()
      } else {
        push()
        invitation()
      }
      await nextTick()
      await nextTick()

      // Une seule attente court, celle de l'installation — pas deux, et pas
      // une attente relancée à chaque réaction de l'autre pilote.
      expect(vi.getTimerCount()).toBe(1)
      expect(uneAutreFenetreQue('notifications')).toBe(true)
      expect(uneAutreFenetreQue('installation')).toBe(false)

      await avancer4(DELAI_INVITATION_INSTALLATION_MS - 100, p)
      expect(ouvertes4(p)).toEqual([])
      await avancer4(200, p)
      expect(ouvertes4(p)).toEqual(['installation'])
      await avancer4(DELAI_INVITATION_MS * 10, p)
      expect(ouvertes4(p)).toEqual(['installation'])

      p.installation.plusTard()
      await avancer4(DELAI_INVITATION_MS + 100, p)
      expect(ouvertes4(p)).toEqual(['notifications'])

      // Aucune boucle signalée par Vue (« Maximum recursive updates »…).
      expect(avertissements).not.toHaveBeenCalled()
    } finally {
      avertissements.mockRestore()
    }
  })
})

describe('l’installation ne referme jamais une fenêtre ouverte', () => {
  it('possible pendant que les notifications sont ouvertes : elle attend leur fermeture, puis s’ouvre dans le même chargement', async () => {
    const p = await chargerTout({ cleIci: true, installable: false })
    await avancer4(DELAI_INVITATION_CLE_MS + DELAI_INVITATION_MS + 300, p)
    expect(ouvertes4(p)).toEqual(['notifications'])

    simulerInstallation({ invitation: invitationDuNavigateur() })
    await nextTick()
    await avancer4(DELAI_INVITATION_INSTALLATION_MS * 5, p)
    expect(ouvertes4(p)).toEqual(['notifications'])

    p.push.plusTard()
    await avancer4(DELAI_INVITATION_INSTALLATION_MS + 100, p)
    expect(ouvertes4(p)).toEqual(['installation'])
  })

  it('possible pendant l’attente des notifications : les notifications cèdent, et passent après', async () => {
    const p = await chargerTout({ cleIci: true, installable: false })
    await avancer4(DELAI_INVITATION_CLE_MS + 300, p)
    expect(invitationCleDAccesEnCours.value).toBe(false)
    // Les notifications attendent leur délai : elles tiennent la place.
    expect(uneAutreFenetreQue('installation')).toBe(true)
    expect(ouvertes4(p)).toEqual([])

    simulerInstallation({ invitation: invitationDuNavigateur() })
    await nextTick()
    await nextTick()
    // L'installation a pris la place ; les notifications ont rendu la leur.
    expect(uneAutreFenetreQue('notifications')).toBe(true)
    expect(uneAutreFenetreQue('installation')).toBe(false)

    await avancer4(DELAI_INVITATION_INSTALLATION_MS + 100, p)
    expect(ouvertes4(p)).toEqual(['installation'])
    await avancer4(DELAI_INVITATION_MS * 5, p)
    expect(ouvertes4(p)).toEqual(['installation'])

    p.installation.plusTard()
    await avancer4(DELAI_INVITATION_MS + 100, p)
    expect(ouvertes4(p)).toEqual(['notifications'])
  })

  it('le format déjà ouvert sur l’arbre : elle attend qu’il soit fermé', async () => {
    const p = await chargerTout({ cleIci: true, pushEvalue: false, installable: false, arbre: true })
    await avancer4(DELAI_INVITATION_CLE_MS + DELAI_INVITATION_FORMAT_MS + 300, p)
    expect(ouvertes4(p)).toEqual(['format-des-cartes'])

    simulerInstallation({ invitation: invitationDuNavigateur() })
    await nextTick()
    await avancer4(DELAI_INVITATION_INSTALLATION_MS * 5, p)
    expect(ouvertes4(p)).toEqual(['format-des-cartes'])

    p.format?.garder()
    await avancer4(DELAI_INVITATION_INSTALLATION_MS + 100, p)
    expect(ouvertes4(p)).toEqual(['installation'])
  })
})

describe('sur l’arbre, depuis un téléphone : les quatre, une par une', () => {
  it('clé d’accès, installation, notifications, puis format — jamais deux ensemble', async () => {
    const p = await chargerTout({ arbre: true })

    await avancer4(DELAI_INVITATION_CLE_MS + 100, p)
    expect(ouvertes4(p)).toEqual(['cle-d-acces'])
    p.cle.plusTard()

    await avancer4(DELAI_INVITATION_INSTALLATION_MS + 100, p)
    expect(ouvertes4(p)).toEqual(['installation'])
    // L'installation tient sa place : le format attend.
    await avancer4(DELAI_INVITATION_FORMAT_MS * 5, p)
    expect(ouvertes4(p)).toEqual(['installation'])
    p.installation.plusTard()

    await avancer4(DELAI_INVITATION_MS + 100, p)
    expect(ouvertes4(p)).toEqual(['notifications'])
    await avancer4(DELAI_INVITATION_FORMAT_MS * 5, p)
    expect(ouvertes4(p)).toEqual(['notifications'])
    p.push.plusTard()

    await avancer4(DELAI_INVITATION_FORMAT_MS + 100, p)
    expect(ouvertes4(p)).toEqual(['format-des-cartes'])
    p.format?.garder()

    await avancer4(DELAI_INVITATION_FORMAT_MS * 5, p)
    expect(ouvertes4(p)).toEqual([])
  })
})
