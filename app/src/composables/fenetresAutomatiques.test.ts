/**
 * Les fenêtres automatiques **ensemble** : « Se connecter plus vite ? » (clé
 * d'accès) et « Activer les notifications » à l'arrivée, puis « Comment
 * afficher les fiches de l'arbre ? » sur l'arbre, depuis un téléphone.
 *
 * Chacune a ses tests (`clesDAcces.test.ts`, `invitationPush.test.ts`) ; ici on
 * monte les **deux pilotes** sur le même chargement, comme `App.vue`, et on
 * tient l'ordre :
 *
 * - la clé d'accès d'abord, les notifications ensuite — **jamais les deux
 *   ouvertes en même temps**, à aucun instant ;
 * - les notifications passent dans le **même** chargement dès que la première
 *   est réglée (« Plus tard », clé créée) ou écartée (clé déjà là, pas de
 *   Face ID) ;
 * - le format des fiches passe **en dernier** ; et s'il est déjà ouvert, ce
 *   sont les notifications qui attendent qu'il soit fermé.
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
import {
  DELAI_INVITATION_FORMAT_MS,
  piloterInvitationFormat,
  reinitialiserFormatDesCartes,
} from './formatDesCartes'
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
