/**
 * L'invitation automatique à installer Heritage sur le téléphone — la décision
 * et le pilote, sans navigateur.
 *
 * Même montage que `invitationPush.test.ts` : la session est simulée, le
 * `localStorage` est une `Map` (vitest tourne sous `node`), remplacée au besoin
 * par un stockage qui lève — la navigation privée de Safari. Ce que le
 * navigateur aurait signalé (`beforeinstallprompt`, iPhone, déjà installée)
 * est imposé par `simulerInstallation()`.
 *
 * Ce qui compte : la fenêtre ne s'ouvre **que sur téléphone** (ou iPhone,
 * iPad), à une session ouverte, hors incarnation, sur un écran avec cadre,
 * quand il y a quelque chose à installer ; pas avant sept jours après « Plus
 * tard » (ou un refus dans la boîte du navigateur) ; une seule fois par
 * chargement ; « Installer » rejoue la boîte **dans** le clic ; et elle passe
 * après la clé d'accès, sans jamais refermer une autre fenêtre ouverte.
 */
import { effectScope, nextTick, shallowRef, type EffectScope } from 'vue'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const session = vi.hoisted(() => ({
  connecte: null as unknown as { value: boolean },
  incarnation: null as unknown as { value: object | null },
}))
vi.mock('@/composables/useSession', async () => {
  const { computed, shallowRef: sr } = await import('vue')
  session.connecte = sr(true)
  session.incarnation = sr<object | null>(null)
  return {
    useSession: () => ({
      connecte: computed(() => session.connecte.value),
      incarnation: computed(() => session.incarnation.value),
    }),
  }
})

import {
  DELAI_INVITATION_INSTALLATION_MS,
  SILENCE_INVITATION_INSTALLATION_MS,
  ecrirePlusTardInstallation,
  formeInvitationInstallation,
  lirePlusTardInstallation,
  piloterInvitationInstallation,
  reinitialiserInvitationInstallation,
  type ContexteInvitationInstallation,
} from './invitationInstallation'
import { simulerInstallation, type InvitationDInstallation } from './installation'
import { doitCeder, liberer, marquerOuverte, occuper, uneAutreFenetreQue } from './fenetresAutomatiques'

/* ------------------------------------------------------------------ */
/* Montage                                                             */
/* ------------------------------------------------------------------ */

const CLE = 'heritage.installation.invitation.plus-tard'
const ANCIENNE_CLE = 'heritage.installation.plus-tard'
const MAINTENANT = Date.UTC(2026, 9, 9, 12)
const UN_JOUR = 24 * 60 * 60 * 1000

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

/**
 * Un `beforeinstallprompt` retenu. `reponse` est ce que le lecteur répond dans
 * la boîte du navigateur ; `erreur`, un navigateur qui refuse de l'ouvrir.
 * `prompt` est espionné : on vérifie qu'il part **dans** le clic.
 */
function fausseInvitation(reponse: 'accepted' | 'dismissed' | 'erreur' = 'accepted'): {
  invitation: InvitationDInstallation
  prompt: ReturnType<typeof vi.fn>
} {
  const prompt = vi.fn(() => (reponse === 'erreur' ? Promise.reject(new Error('NotAllowedError')) : Promise.resolve()))
  const invitation = {
    prompt,
    userChoice: Promise.resolve({ outcome: reponse === 'accepted' ? 'accepted' : 'dismissed' }),
  } as unknown as InvitationDInstallation
  return { invitation, prompt }
}

const cadre = shallowRef(true)
const mobile = shallowRef(true)
let portee: EffectScope | null = null

/** Un « chargement de l'application » : l'état du module repart de zéro. */
function charger(horloge: () => number = () => MAINTENANT): ReturnType<typeof piloterInvitationInstallation> {
  reinitialiserInvitationInstallation()
  return monter(horloge)
}

/** Le pilote seul, sans remettre le module à zéro (même chargement). */
function monter(horloge: () => number = () => MAINTENANT): ReturnType<typeof piloterInvitationInstallation> {
  portee?.stop()
  portee = effectScope()
  const pilote = portee.run(() =>
    piloterInvitationInstallation(
      () => cadre.value,
      () => mobile.value,
      horloge,
    ),
  )
  if (!pilote) throw new Error('portée arrêtée')
  return pilote
}

async function avancer(ms: number): Promise<void> {
  await vi.advanceTimersByTimeAsync(ms)
  await nextTick()
}

let memoire: Map<string, string>

beforeEach(() => {
  vi.useFakeTimers()
  memoire = stockage()
  cadre.value = true
  mobile.value = true
  session.connecte.value = true
  session.incarnation.value = null
  simulerInstallation(null)
  simulerInstallation({ invitation: fausseInvitation().invitation, ios: false })
})

afterEach(() => {
  portee?.stop()
  portee = null
  reinitialiserInvitationInstallation()
  for (const f of ['cle-d-acces', 'installation', 'notifications', 'format-des-cartes'] as const) liberer(f)
  simulerInstallation(null)
  vi.unstubAllGlobals()
  vi.useRealTimers()
})

/* ------------------------------------------------------------------ */
/* La décision, cas par cas                                            */
/* ------------------------------------------------------------------ */

describe('formeInvitationInstallation()', () => {
  const base: ContexteInvitationInstallation = {
    connecte: true,
    incarnation: false,
    avecCadre: true,
    mobile: true,
    ios: false,
    installee: false,
    installable: true,
    plusTardLe: null,
    maintenant: MAINTENANT,
  }

  it('propose la boîte du navigateur quand il sait installer, sur téléphone', () => {
    expect(formeInvitationInstallation(base)).toBe('android')
  })

  it('dit les gestes de Safari sur iPhone — et sur iPad, même large', () => {
    expect(formeInvitationInstallation({ ...base, installable: false, ios: true })).toBe('ios')
    expect(formeInvitationInstallation({ ...base, installable: false, ios: true, mobile: false })).toBe('ios')
  })

  it('se tait sur un écran large qui n’est pas un iPhone ou un iPad : le panneau de Moi suffit', () => {
    expect(formeInvitationInstallation({ ...base, mobile: false })).toBeNull()
  })

  it('se tait quand il n’y a rien à installer, ou que c’est déjà fait', () => {
    expect(formeInvitationInstallation({ ...base, installable: false })).toBeNull()
    expect(formeInvitationInstallation({ ...base, installee: true })).toBeNull()
    expect(formeInvitationInstallation({ ...base, installable: false, ios: true, installee: true })).toBeNull()
  })

  it('se tait sans session, pendant une incarnation, sur un écran sans cadre', () => {
    expect(formeInvitationInstallation({ ...base, connecte: false })).toBeNull()
    expect(formeInvitationInstallation({ ...base, incarnation: true })).toBeNull()
    expect(formeInvitationInstallation({ ...base, avecCadre: false })).toBeNull()
  })

  it('se tait quand une autre fenêtre automatique passe avant', () => {
    expect(formeInvitationInstallation({ ...base, doitCeder: true })).toBeNull()
    expect(formeInvitationInstallation({ ...base, doitCeder: false })).toBe('android')
  })

  it('se tait sept jours après « Plus tard », pas un de plus', () => {
    const il = (ms: number): number => MAINTENANT - ms
    expect(formeInvitationInstallation({ ...base, plusTardLe: il(60_000) })).toBeNull()
    expect(formeInvitationInstallation({ ...base, plusTardLe: il(SILENCE_INVITATION_INSTALLATION_MS - 1) })).toBeNull()
    expect(formeInvitationInstallation({ ...base, plusTardLe: il(SILENCE_INVITATION_INSTALLATION_MS) })).toBe('android')
    const ios = { ...base, installable: false, ios: true }
    expect(formeInvitationInstallation({ ...ios, plusTardLe: il(UN_JOUR) })).toBeNull()
    expect(formeInvitationInstallation({ ...ios, plusTardLe: il(SILENCE_INVITATION_INSTALLATION_MS) })).toBe('ios')
  })
})

/* ------------------------------------------------------------------ */
/* Le pilote                                                           */
/* ------------------------------------------------------------------ */

describe('piloterInvitationInstallation()', () => {
  it('ouvre après le petit délai, pas avant, et revérifie au déclenchement', async () => {
    const { forme } = charger()
    await avancer(DELAI_INVITATION_INSTALLATION_MS - 1)
    expect(forme.value).toBeNull()
    await avancer(1)
    expect(forme.value).toBe('android')
  })

  it('attend que le navigateur propose l’installation, puis ouvre après le délai', async () => {
    simulerInstallation({ invitation: null })
    const { forme } = charger()
    await avancer(DELAI_INVITATION_INSTALLATION_MS * 3)
    expect(forme.value).toBeNull()

    simulerInstallation({ invitation: fausseInvitation().invitation })
    await nextTick()
    await avancer(DELAI_INVITATION_INSTALLATION_MS - 1)
    expect(forme.value).toBeNull()
    await avancer(1)
    expect(forme.value).toBe('android')
  })

  it('iPhone : la forme « ios », et « Compris » vaut « Plus tard »', async () => {
    simulerInstallation({ invitation: null, ios: true })
    const { forme, plusTard } = charger()
    await avancer(DELAI_INVITATION_INSTALLATION_MS)
    expect(forme.value).toBe('ios')
    plusTard()
    expect(forme.value).toBeNull()
    expect(memoire.get(CLE)).toBe(String(MAINTENANT))
  })

  it('jamais sur un écran large ; s’ouvre si l’écran devient étroit', async () => {
    mobile.value = false
    const { forme } = charger()
    await avancer(DELAI_INVITATION_INSTALLATION_MS * 3)
    expect(forme.value).toBeNull()
    mobile.value = true
    await nextTick()
    await avancer(DELAI_INVITATION_INSTALLATION_MS)
    expect(forme.value).toBe('android')
  })

  it('jamais déjà installée', async () => {
    simulerInstallation({ installee: true })
    const { forme } = charger()
    await avancer(DELAI_INVITATION_INSTALLATION_MS * 3)
    expect(forme.value).toBeNull()
  })

  it('jamais sur un écran sans cadre ; s’ouvre en arrivant sur un écran qui en a un', async () => {
    cadre.value = false
    const { forme } = charger()
    await avancer(DELAI_INVITATION_INSTALLATION_MS * 3)
    expect(forme.value).toBeNull()
    cadre.value = true
    await nextTick()
    await avancer(DELAI_INVITATION_INSTALLATION_MS)
    expect(forme.value).toBe('android')
  })

  it('renonce si une condition tombe pendant l’attente — et rend la place', async () => {
    const { forme } = charger()
    await avancer(DELAI_INVITATION_INSTALLATION_MS - 100)
    expect(uneAutreFenetreQue('notifications')).toBe(true)
    cadre.value = false
    await nextTick()
    await avancer(DELAI_INVITATION_INSTALLATION_MS * 3)
    expect(forme.value).toBeNull()
    expect(uneAutreFenetreQue('notifications')).toBe(false)
  })

  it('jamais sans session, ni pendant une incarnation', async () => {
    session.connecte.value = false
    let { forme } = charger()
    await avancer(DELAI_INVITATION_INSTALLATION_MS * 3)
    expect(forme.value).toBeNull()

    session.connecte.value = true
    session.incarnation.value = { administrateur: 'admin@exemple.test' }
    ;({ forme } = charger())
    await avancer(DELAI_INVITATION_INSTALLATION_MS * 3)
    expect(forme.value).toBeNull()
  })

  it('« Plus tard » est retenu sept jours, d’un chargement à l’autre', async () => {
    const premier = charger()
    await avancer(DELAI_INVITATION_INSTALLATION_MS)
    expect(premier.forme.value).toBe('android')
    premier.plusTard()
    expect(premier.forme.value).toBeNull()
    expect(lirePlusTardInstallation()).toBe(MAINTENANT)

    // Six jours plus tard, nouveau chargement : rien.
    const sixJours = charger(() => MAINTENANT + 6 * UN_JOUR)
    await avancer(DELAI_INVITATION_INSTALLATION_MS * 3)
    expect(sixJours.forme.value).toBeNull()

    // Sept jours plus tard : à nouveau.
    const septJours = charger(() => MAINTENANT + SILENCE_INVITATION_INSTALLATION_MS)
    await avancer(DELAI_INVITATION_INSTALLATION_MS)
    expect(septJours.forme.value).toBe('android')
  })

  it('une seule fois par chargement, même quand le stockage est bloqué', async () => {
    stockageBloque()
    const premier = charger()
    await avancer(DELAI_INVITATION_INSTALLATION_MS)
    expect(premier.forme.value).toBe('android')
    expect(() => premier.plusTard()).not.toThrow()
    expect(premier.forme.value).toBeNull()

    // Même chargement, nouveau montage : rien.
    const second = monter()
    await avancer(DELAI_INVITATION_INSTALLATION_MS * 3)
    expect(second.forme.value).toBeNull()
  })

  it('l’ancien « Plus tard » définitif du bandeau est ignoré, et effacé', async () => {
    memoire.set(ANCIENNE_CLE, '1')
    const { forme } = charger()
    expect(memoire.has(ANCIENNE_CLE)).toBe(false)
    await avancer(DELAI_INVITATION_INSTALLATION_MS)
    expect(forme.value).toBe('android')
  })

  it('« Installer » rejoue la boîte du navigateur dans le clic ; acceptée : fermée, rien de retenu, place rendue', async () => {
    const { invitation, prompt } = fausseInvitation('accepted')
    simulerInstallation({ invitation })
    const { forme, enCours, installer } = charger()
    await avancer(DELAI_INVITATION_INSTALLATION_MS)
    expect(forme.value).toBe('android')

    const promesse = installer()
    // Synchrone : la boîte est demandée avant tout `await`, dans le geste.
    expect(prompt).toHaveBeenCalledTimes(1)
    expect(enCours.value).toBe(true)
    await promesse
    expect(enCours.value).toBe(false)
    expect(forme.value).toBeNull()
    expect(memoire.has(CLE)).toBe(false)
    expect(uneAutreFenetreQue('notifications')).toBe(false)
  })

  it('« Installer » refusé dans la boîte du navigateur : fermée, et compté comme « Plus tard »', async () => {
    simulerInstallation({ invitation: fausseInvitation('dismissed').invitation })
    const { forme, installer } = charger()
    await avancer(DELAI_INVITATION_INSTALLATION_MS)
    await installer()
    expect(forme.value).toBeNull()
    expect(memoire.get(CLE)).toBe(String(MAINTENANT))
  })

  it('« Installer » impossible (le navigateur n’ouvre pas sa boîte) : fermée, sans rien retenir', async () => {
    simulerInstallation({ invitation: fausseInvitation('erreur').invitation })
    const { forme, installer } = charger()
    await avancer(DELAI_INVITATION_INSTALLATION_MS)
    await expect(installer()).resolves.toBeUndefined()
    expect(forme.value).toBeNull()
    expect(memoire.has(CLE)).toBe(false)
  })

  it('pendant la boîte du navigateur, « Plus tard » ne fait rien : sa réponse est attendue', async () => {
    let repondre: (r: { outcome: 'accepted' | 'dismissed' }) => void = () => undefined
    const invitation = {
      prompt: () => Promise.resolve(),
      userChoice: new Promise<{ outcome: 'accepted' | 'dismissed' }>((r) => {
        repondre = r
      }),
    } as unknown as InvitationDInstallation
    simulerInstallation({ invitation })
    const { forme, enCours, installer, plusTard } = charger()
    await avancer(DELAI_INVITATION_INSTALLATION_MS)
    const promesse = installer()
    await nextTick()
    expect(enCours.value).toBe(true)
    plusTard()
    expect(forme.value).toBe('android')
    expect(memoire.has(CLE)).toBe(false)

    repondre({ outcome: 'accepted' })
    await promesse
    expect(forme.value).toBeNull()
  })

  it('« Installer » n’a rien à faire sous la forme « ios »', async () => {
    simulerInstallation({ invitation: null, ios: true })
    const { forme, enCours, installer } = charger()
    await avancer(DELAI_INVITATION_INSTALLATION_MS)
    await installer()
    expect(forme.value).toBe('ios')
    expect(enCours.value).toBe(false)
  })

  it('ouverte : la session qui tombe ou l’installation faite ailleurs la ferment, sans « Plus tard »', async () => {
    let p = charger()
    await avancer(DELAI_INVITATION_INSTALLATION_MS)
    expect(p.forme.value).toBe('android')
    session.connecte.value = false
    await nextTick()
    expect(p.forme.value).toBeNull()

    session.connecte.value = true
    simulerInstallation({ invitation: fausseInvitation().invitation })
    p = charger()
    await avancer(DELAI_INVITATION_INSTALLATION_MS)
    expect(p.forme.value).toBe('android')
    // `appinstalled` : le menu du navigateur a installé.
    simulerInstallation({ installee: true, invitation: null })
    await nextTick()
    expect(p.forme.value).toBeNull()
    expect(memoire.has(CLE)).toBe(false)
    expect(uneAutreFenetreQue('notifications')).toBe(false)
  })

  it('tient sa place pendant l’attente et ouverte, signale son ouverture, et la rend en partant', async () => {
    const { forme } = charger()
    await nextTick()
    // En attente : inscrite, pas ouverte. `doitCeder('cle-d-acces')` ne voit
    // que les fenêtres ouvertes — la clé d'accès est première.
    expect(uneAutreFenetreQue('notifications')).toBe(true)
    expect(doitCeder('cle-d-acces')).toBe(false)

    await avancer(DELAI_INVITATION_INSTALLATION_MS)
    expect(forme.value).toBe('android')
    expect(doitCeder('cle-d-acces')).toBe(true)

    portee?.stop()
    portee = null
    expect(uneAutreFenetreQue('notifications')).toBe(false)
    expect(doitCeder('cle-d-acces')).toBe(false)
  })
})

/* ------------------------------------------------------------------ */
/* Sa place parmi les fenêtres automatiques                            */
/* ------------------------------------------------------------------ */

describe('après la clé d’accès, sans jamais refermer une autre fenêtre', () => {
  it('attend que la clé d’accès soit réglée, puis s’ouvre dans le même chargement', async () => {
    occuper('cle-d-acces')
    const { forme } = charger()
    await avancer(DELAI_INVITATION_INSTALLATION_MS * 5)
    expect(forme.value).toBeNull()
    liberer('cle-d-acces')
    await nextTick()
    await avancer(DELAI_INVITATION_INSTALLATION_MS)
    expect(forme.value).toBe('android')
  })

  it('renonce à une ouverture programmée si la clé d’accès prend la main', async () => {
    const { forme } = charger()
    await avancer(DELAI_INVITATION_INSTALLATION_MS - 100)
    occuper('cle-d-acces')
    await nextTick()
    await avancer(DELAI_INVITATION_INSTALLATION_MS * 3)
    expect(forme.value).toBeNull()
  })

  it.each(['notifications', 'format-des-cartes'] as const)(
    '« %s » seulement en attente : elle passe quand même',
    async (autre) => {
      occuper(autre)
      const { forme } = charger()
      await avancer(DELAI_INVITATION_INSTALLATION_MS)
      expect(forme.value).toBe('android')
    },
  )

  it.each(['notifications', 'format-des-cartes'] as const)(
    '« %s » ouverte : elle attend sa fermeture, puis s’ouvre dans le même chargement',
    async (autre) => {
      marquerOuverte(autre, true)
      const { forme } = charger()
      await avancer(DELAI_INVITATION_INSTALLATION_MS * 5)
      expect(forme.value).toBeNull()
      liberer(autre)
      await nextTick()
      await avancer(DELAI_INVITATION_INSTALLATION_MS)
      expect(forme.value).toBe('android')
    },
  )
})

describe('le « Plus tard » stocké', () => {
  it('se lit et s’écrit', () => {
    expect(lirePlusTardInstallation()).toBeNull()
    ecrirePlusTardInstallation(MAINTENANT)
    expect(lirePlusTardInstallation()).toBe(MAINTENANT)
  })

  it('une valeur illisible vaut « jamais répondu »', () => {
    memoire.set(CLE, 'n’importe quoi')
    expect(lirePlusTardInstallation()).toBeNull()
  })

  it('un stockage bloqué ne lève jamais', () => {
    stockageBloque()
    expect(lirePlusTardInstallation()).toBeNull()
    expect(() => ecrirePlusTardInstallation(MAINTENANT)).not.toThrow()
  })
})
