/**
 * Les deux fenêtres automatiques **ensemble**, à l'arrivée : « Se connecter
 * plus vite ? » (clé d'accès) et « Activer les notifications ».
 *
 * Chacune a ses tests (`clesDAcces.test.ts`, `invitationPush.test.ts`) ; ici on
 * monte les **deux pilotes** sur le même chargement, comme `App.vue`, et on
 * tient l'ordre :
 *
 * - la clé d'accès d'abord, les notifications ensuite — **jamais les deux
 *   ouvertes en même temps**, à aucun instant ;
 * - les notifications passent dans le **même** chargement dès que la première
 *   est réglée (« Plus tard », clé créée) ou écartée (clé déjà là, pas de
 *   Face ID).
 *
 * Même montage que les deux autres fichiers : navigateur écrit à la main, API
 * et session simulées, `localStorage` en `Map`, minuteries simulées.
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
import { invitationCleDAccesEnCours } from './fenetresAutomatiques'
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
 *  notifications aurait de quoi s'ouvrir. */
function navigateurPush(): void {
  definirEnvironnementPush({
    pushPossible: true,
    aInstallerDabord: false,
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
  cadre.value = true
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
