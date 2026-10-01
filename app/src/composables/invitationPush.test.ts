/**
 * L'invitation automatique à activer les notifications — la décision et le
 * pilote, sans navigateur.
 *
 * Même montage que `usePush.test.ts` : le navigateur est un `EnvironnementPush`
 * écrit à la main, l'API et la session sont simulées. Le `localStorage` est une
 * `Map` (vitest tourne sous `node`), remplacée au besoin par un stockage qui
 * lève — la navigation privée de Safari.
 *
 * Ce qui compte : la fenêtre ne s'ouvre **qu'à qui un clic suffit** (ou un
 * iPhone à installer), jamais sur un écran sans cadre, jamais pendant une
 * incarnation, jamais à qui a désactivé, pas avant sept jours après « Plus
 * tard », et une seule fois par chargement.
 */
import { effectScope, nextTick, shallowRef, type EffectScope } from 'vue'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/lib/api', () => ({
  lireClePush: vi.fn(() => Promise.resolve({ clePublique: 'BAEBAQ' })),
  enregistrerAbonnementPush: vi.fn(() => Promise.resolve()),
  supprimerAbonnementPush: vi.fn(() => Promise.resolve()),
}))
vi.mock('@/composables/useNotifications', () => ({ rafraichirCompteur: vi.fn() }))

const session = vi.hoisted(() => ({
  connecte: null as unknown as { value: boolean },
  incarnation: null as unknown as { value: object | null },
}))
vi.mock('@/composables/useSession', async () => {
  const { computed, shallowRef } = await import('vue')
  session.connecte = shallowRef(true)
  session.incarnation = shallowRef<object | null>(null)
  return {
    useSession: () => ({
      connecte: computed(() => session.connecte.value),
      incarnation: computed(() => session.incarnation.value),
    }),
  }
})

import {
  DELAI_INVITATION_MS,
  SILENCE_INVITATION_MS,
  definirEnvironnementPush,
  ecrirePlusTardInvitation,
  evaluerPush,
  formeInvitationPush,
  lirePlusTardInvitation,
  piloterInvitationPush,
  reinitialiserPush,
  usePush,
  type ContexteInvitationPush,
  type EnvironnementPush,
  type PermissionPush,
} from './usePush'

/* ------------------------------------------------------------------ */
/* Montage                                                             */
/* ------------------------------------------------------------------ */

interface Options {
  permission?: PermissionPush
  reponsePermission?: PermissionPush
  abonne?: boolean
  pushPossible?: boolean
  aInstaller?: boolean
  refus?: boolean
}

function environnement(o: Options = {}): void {
  const abonnement = {
    endpoint: 'https://push.exemple.test/1',
    options: { applicationServerKey: new Uint8Array([4, 1, 1, 1]).buffer },
    toJSON: () => ({ endpoint: 'https://push.exemple.test/1', keys: { p256dh: 'p', auth: 'a' } }),
    unsubscribe: () => Promise.resolve(true),
  }
  const e: EnvironnementPush = {
    pushPossible: o.pushPossible ?? true,
    aInstallerDabord: o.aInstaller ?? false,
    permission: () => o.permission ?? 'default',
    demanderPermission: vi.fn(() => Promise.resolve(o.reponsePermission ?? 'granted')),
    gestionnaire: () =>
      Promise.resolve({
        getSubscription: () => Promise.resolve(o.abonne ? abonnement : null),
        subscribe: () => Promise.resolve(abonnement),
      }),
    lireRefus: () => o.refus ?? false,
    ecrireRefus: () => undefined,
  }
  definirEnvironnementPush(e)
}

/** Un `localStorage` en mémoire. */
function stockage(): Map<string, string> {
  const m = new Map<string, string>()
  vi.stubGlobal('localStorage', {
    getItem: (k: string) => m.get(k) ?? null,
    setItem: (k: string, v: string) => void m.set(k, v),
    removeItem: (k: string) => void m.delete(k),
  })
  return m
}

/** Un `localStorage` qui lève à chaque accès. */
function stockageBloque(): void {
  const lever = (): never => {
    throw new Error('SecurityError')
  }
  vi.stubGlobal('localStorage', { getItem: lever, setItem: lever, removeItem: lever })
}

const CLE = 'heritage.push.invitation.plus-tard'
const MAINTENANT = Date.UTC(2026, 9, 1, 12)
const cadre = shallowRef(true)
let portee: EffectScope | null = null

/** Un « chargement de l'application » : l'état du module repart de zéro. */
async function charger(o: Options = {}): Promise<void> {
  reinitialiserPush()
  environnement(o)
  await evaluerPush()
}

function piloter(horloge: () => number = () => MAINTENANT): ReturnType<typeof piloterInvitationPush> {
  portee?.stop()
  portee = effectScope()
  const pilote = portee.run(() => piloterInvitationPush(() => cadre.value, horloge))
  if (!pilote) throw new Error('portée arrêtée')
  return pilote
}

let memoire: Map<string, string>

beforeEach(() => {
  vi.useFakeTimers()
  memoire = stockage()
  cadre.value = true
  session.connecte.value = true
  session.incarnation.value = null
})

afterEach(() => {
  portee?.stop()
  portee = null
  definirEnvironnementPush(null)
  vi.unstubAllGlobals()
  vi.useRealTimers()
})

/* ------------------------------------------------------------------ */
/* La décision, cas par cas                                            */
/* ------------------------------------------------------------------ */

describe('formeInvitationPush()', () => {
  const base: ContexteInvitationPush = {
    etat: 'desactive',
    refuse: false,
    connecte: true,
    incarnation: false,
    avecCadre: true,
    plusTardLe: null,
    maintenant: MAINTENANT,
  }

  it('propose d’activer quand un clic suffit', () => {
    expect(formeInvitationPush(base)).toBe('activer')
  })

  it('propose d’installer sur un iPhone hors écran d’accueil', () => {
    expect(formeInvitationPush({ ...base, etat: 'a_installer' })).toBe('installer')
  })

  it.each(['active', 'bloque', 'indisponible', 'inconnu'] as const)('se tait quand l’état est « %s »', (etat) => {
    expect(formeInvitationPush({ ...base, etat })).toBeNull()
  })

  it('se tait sans session, sur un écran sans cadre, pendant une incarnation, après « Désactiver »', () => {
    expect(formeInvitationPush({ ...base, connecte: false })).toBeNull()
    expect(formeInvitationPush({ ...base, avecCadre: false })).toBeNull()
    expect(formeInvitationPush({ ...base, incarnation: true })).toBeNull()
    expect(formeInvitationPush({ ...base, refuse: true })).toBeNull()
    expect(formeInvitationPush({ ...base, etat: 'a_installer', refuse: true })).toBeNull()
  })

  it('se tait sept jours après « Plus tard », pas un de plus', () => {
    const il = (ms: number): number => MAINTENANT - ms
    expect(formeInvitationPush({ ...base, plusTardLe: il(60_000) })).toBeNull()
    expect(formeInvitationPush({ ...base, plusTardLe: il(SILENCE_INVITATION_MS - 1) })).toBeNull()
    expect(formeInvitationPush({ ...base, plusTardLe: il(SILENCE_INVITATION_MS) })).toBe('activer')
    expect(formeInvitationPush({ ...base, etat: 'a_installer', plusTardLe: il(60_000) })).toBeNull()
  })
})

/* ------------------------------------------------------------------ */
/* Le pilote                                                           */
/* ------------------------------------------------------------------ */

describe('piloterInvitationPush()', () => {
  it('ouvre après le petit délai, pas avant', async () => {
    await charger()
    const { forme } = piloter()
    vi.advanceTimersByTime(DELAI_INVITATION_MS - 1)
    expect(forme.value).toBeNull()
    vi.advanceTimersByTime(1)
    expect(forme.value).toBe('activer')
  })

  it('attend que l’état soit connu : `inconnu` puis `desactive`', async () => {
    reinitialiserPush()
    environnement()
    const { forme } = piloter()
    vi.advanceTimersByTime(DELAI_INVITATION_MS * 2)
    expect(forme.value).toBeNull()
    await evaluerPush()
    await nextTick()
    vi.advanceTimersByTime(DELAI_INVITATION_MS)
    expect(forme.value).toBe('activer')
  })

  it('jamais sur un écran sans cadre ; s’ouvre en arrivant sur un écran qui en a un', async () => {
    await charger()
    cadre.value = false
    const { forme } = piloter()
    vi.advanceTimersByTime(DELAI_INVITATION_MS * 3)
    expect(forme.value).toBeNull()
    cadre.value = true
    await nextTick()
    vi.advanceTimersByTime(DELAI_INVITATION_MS)
    expect(forme.value).toBe('activer')
  })

  it('renonce si le lecteur part vers un écran sans cadre pendant l’attente', async () => {
    await charger()
    const { forme } = piloter()
    vi.advanceTimersByTime(DELAI_INVITATION_MS - 100)
    cadre.value = false
    await nextTick()
    vi.advanceTimersByTime(DELAI_INVITATION_MS * 3)
    expect(forme.value).toBeNull()
  })

  it('jamais sans session, ni pendant une incarnation', async () => {
    await charger()
    session.connecte.value = false
    let { forme } = piloter()
    vi.advanceTimersByTime(DELAI_INVITATION_MS * 3)
    expect(forme.value).toBeNull()

    session.connecte.value = true
    session.incarnation.value = { administrateur: 'admin@exemple.test' }
    ;({ forme } = piloter())
    vi.advanceTimersByTime(DELAI_INVITATION_MS * 3)
    expect(forme.value).toBeNull()
  })

  it('jamais à qui a touché « Désactiver » dans Moi', async () => {
    await charger({ refus: true })
    expect(usePush().refuse.value).toBe(true)
    const { forme } = piloter()
    vi.advanceTimersByTime(DELAI_INVITATION_MS * 3)
    expect(forme.value).toBeNull()
  })

  it.each([
    ['active', { permission: 'granted' as const, abonne: true }],
    ['bloque', { permission: 'denied' as const }],
    ['indisponible', { pushPossible: false }],
  ])('jamais quand l’état est « %s »', async (attendu, o) => {
    await charger(o)
    expect(usePush().etat.value).toBe(attendu)
    const { forme } = piloter()
    vi.advanceTimersByTime(DELAI_INVITATION_MS * 3)
    expect(forme.value).toBeNull()
  })

  it('iPhone hors écran d’accueil : la forme « installer », et « Compris » vaut « Plus tard »', async () => {
    await charger({ aInstaller: true, pushPossible: false })
    const { forme, plusTard } = piloter()
    vi.advanceTimersByTime(DELAI_INVITATION_MS)
    expect(forme.value).toBe('installer')
    plusTard()
    expect(forme.value).toBeNull()
    expect(memoire.get(CLE)).toBe(String(MAINTENANT))
  })

  it('« Plus tard » est retenu sept jours, d’un chargement à l’autre', async () => {
    await charger()
    const premier = piloter()
    vi.advanceTimersByTime(DELAI_INVITATION_MS)
    expect(premier.forme.value).toBe('activer')
    premier.plusTard()
    expect(premier.forme.value).toBeNull()
    expect(lirePlusTardInvitation()).toBe(MAINTENANT)

    // Six jours plus tard, nouveau chargement : rien.
    await charger()
    const sixJours = piloter(() => MAINTENANT + 6 * 24 * 3600 * 1000)
    vi.advanceTimersByTime(DELAI_INVITATION_MS * 3)
    expect(sixJours.forme.value).toBeNull()

    // Sept jours plus tard : à nouveau.
    await charger()
    const septJours = piloter(() => MAINTENANT + SILENCE_INVITATION_MS)
    vi.advanceTimersByTime(DELAI_INVITATION_MS)
    expect(septJours.forme.value).toBe('activer')
  })

  it('une seule fois par chargement, même quand le stockage est bloqué', async () => {
    stockageBloque()
    await charger()
    const premier = piloter()
    vi.advanceTimersByTime(DELAI_INVITATION_MS)
    expect(premier.forme.value).toBe('activer')
    expect(() => premier.plusTard()).not.toThrow()

    // Même chargement (pas de `reinitialiserPush()`), nouveau montage : rien.
    const second = piloter()
    vi.advanceTimersByTime(DELAI_INVITATION_MS * 3)
    expect(second.forme.value).toBeNull()
  })

  it('« Activer » accordé : ferme, puis « Notifications activées » un instant', async () => {
    await charger({ reponsePermission: 'granted' })
    const { forme, confirmation, accepter } = piloter()
    vi.advanceTimersByTime(DELAI_INVITATION_MS)
    await accepter()
    expect(forme.value).toBeNull()
    expect(usePush().etat.value).toBe('active')
    expect(confirmation.value).toBe(true)
    vi.advanceTimersByTime(3_000)
    expect(confirmation.value).toBe(false)
    // Accepter n'est pas « Plus tard » : rien n'est retenu.
    expect(memoire.has(CLE)).toBe(false)
  })

  it('« Activer » refusé par le navigateur : ferme, l’état devient `bloque`, sans confirmation', async () => {
    await charger({ reponsePermission: 'denied' })
    const { forme, confirmation, accepter } = piloter()
    vi.advanceTimersByTime(DELAI_INVITATION_MS)
    await accepter()
    expect(forme.value).toBeNull()
    expect(confirmation.value).toBe(false)
    expect(usePush().etat.value).toBe('bloque')
  })
})

describe('le « Plus tard » stocké', () => {
  it('se lit et s’écrit', () => {
    expect(lirePlusTardInvitation()).toBeNull()
    ecrirePlusTardInvitation(MAINTENANT)
    expect(lirePlusTardInvitation()).toBe(MAINTENANT)
  })

  it('une valeur illisible vaut « jamais répondu »', () => {
    memoire.set(CLE, 'n’importe quoi')
    expect(lirePlusTardInvitation()).toBeNull()
  })

  it('un stockage bloqué ne lève jamais', () => {
    stockageBloque()
    expect(lirePlusTardInvitation()).toBeNull()
    expect(() => ecrirePlusTardInvitation(MAINTENANT)).not.toThrow()
  })
})
