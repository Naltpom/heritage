/**
 * Le push du téléphone, sans navigateur ni serveur.
 *
 * Le navigateur est un `EnvironnementPush` écrit à la main (vitest tourne sous
 * `node` : ni `Notification`, ni service worker), l'API et la session sont
 * simulées.
 *
 * Les cas qui comptent le plus sont ceux de `synchroniserPush()` : c'est lui
 * qui empêche « le jeton qui casse à chaque mise en prod ». Il doit se
 * réabonner quand la clé du serveur a changé, et renvoyer l'abonnement au
 * serveur **à chaque fois** — même quand rien n'a changé côté navigateur,
 * parce que c'est peut-être le serveur qui l'a perdu.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const api = vi.hoisted(() => ({
  lireClePush: vi.fn(),
  enregistrerAbonnementPush: vi.fn(),
  supprimerAbonnementPush: vi.fn(),
}))
vi.mock('@/lib/api', () => api)

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
  activerPush,
  definirEnvironnementPush,
  desactiverPush,
  evaluerPush,
  memeCle,
  octetsDepuisBase64Url,
  oublierPushAvantDeconnexion,
  reinitialiserPush,
  synchroniserPush,
  usePush,
  type AbonnementNavigateur,
  type EnvironnementPush,
  type PermissionPush,
} from './usePush'

/* Deux clés publiques P-256 factices (65 octets, préfixe 0x04). */
const octetsA = new Uint8Array(65).fill(1)
octetsA[0] = 4
const octetsB = new Uint8Array(65).fill(2)
octetsB[0] = 4
const CLE_A = Buffer.from(octetsA).toString('base64url')

interface NavigateurFactice {
  env: EnvironnementPush
  permission: PermissionPush
  reponsePermission: PermissionPush
  abonnement: AbonnementNavigateur | null
  refus: boolean
  subscribe: ReturnType<typeof vi.fn>
  demanderPermission: ReturnType<typeof vi.fn>
}

let compteur = 0
function abonnement(cle: Uint8Array | null, nav: NavigateurFactice): AbonnementNavigateur {
  compteur += 1
  const endpoint = `https://push.exemple.test/${compteur}`
  const a: AbonnementNavigateur = {
    endpoint,
    options: { applicationServerKey: cle ? cle.slice().buffer : null },
    toJSON: () => ({ endpoint, keys: { p256dh: 'P256DH', auth: 'AUTH' } }),
    unsubscribe: vi.fn(() => {
      if (nav.abonnement === a) nav.abonnement = null
      return Promise.resolve(true)
    }),
  }
  return a
}

function navigateur(options: { permission?: PermissionPush; pushPossible?: boolean; aInstaller?: boolean } = {}): NavigateurFactice {
  const nav = {
    permission: options.permission ?? 'default',
    reponsePermission: 'granted',
    abonnement: null,
    refus: false,
  } as NavigateurFactice
  nav.subscribe = vi.fn((o: { applicationServerKey: Uint8Array }) => {
    nav.abonnement = abonnement(o.applicationServerKey, nav)
    return Promise.resolve(nav.abonnement)
  })
  nav.demanderPermission = vi.fn(() => {
    nav.permission = nav.reponsePermission
    return Promise.resolve(nav.reponsePermission)
  })
  nav.env = {
    pushPossible: options.pushPossible ?? true,
    aInstallerDabord: options.aInstaller ?? false,
    permission: () => nav.permission,
    demanderPermission: nav.demanderPermission as unknown as () => Promise<PermissionPush>,
    gestionnaire: () =>
      Promise.resolve({
        getSubscription: () => Promise.resolve(nav.abonnement),
        subscribe: nav.subscribe as never,
      }),
    lireRefus: () => nav.refus,
    ecrireRefus: (r: boolean) => {
      nav.refus = r
    },
  }
  definirEnvironnementPush(nav.env)
  return nav
}

beforeEach(() => {
  reinitialiserPush()
  session.connecte.value = true
  session.incarnation.value = null
  api.lireClePush.mockResolvedValue({ clePublique: CLE_A })
  api.enregistrerAbonnementPush.mockResolvedValue(undefined)
  api.supprimerAbonnementPush.mockResolvedValue(undefined)
})

afterEach(() => {
  vi.clearAllMocks()
  definirEnvironnementPush(null)
})

describe('les clés', () => {
  it('décode le base64url non paddé que rend le serveur', () => {
    expect([...octetsDepuisBase64Url(CLE_A)]).toEqual([...octetsA])
  })

  it('reconnaît un abonnement pris avec la même clé, et pas un autre', () => {
    const nav = navigateur()
    expect(memeCle(abonnement(octetsA, nav), octetsA)).toBe(true)
    expect(memeCle(abonnement(octetsB, nav), octetsA)).toBe(false)
    // Un navigateur qui ne dit pas sa clé : on se réabonne plutôt que de
    // garder un abonnement peut-être muet.
    expect(memeCle(abonnement(null, nav), octetsA)).toBe(false)
  })
})

describe('les états', () => {
  it('iPhone hors écran d’accueil : à installer d’abord', async () => {
    navigateur({ aInstaller: true, pushPossible: false })
    expect(await evaluerPush()).toBe('a_installer')
  })

  it('sans service worker ni PushManager : indisponible', async () => {
    navigateur({ pushPossible: false })
    expect(await evaluerPush()).toBe('indisponible')
  })

  it('permission refusée : bloqué', async () => {
    navigateur({ permission: 'denied' })
    expect(await evaluerPush()).toBe('bloque')
  })

  it('jamais demandé : désactivé', async () => {
    navigateur()
    expect(await evaluerPush()).toBe('desactive')
  })

  it('permission accordée et abonnement présent : activé', async () => {
    const nav = navigateur({ permission: 'granted' })
    nav.abonnement = abonnement(octetsA, nav)
    expect(await evaluerPush()).toBe('active')
    expect(usePush().etat.value).toBe('active')
  })
})

describe('activer', () => {
  it('demande la permission, s’abonne avec la clé du serveur et envoie l’abonnement', async () => {
    const nav = navigateur()

    expect(await activerPush()).toBe(true)

    expect(nav.demanderPermission).toHaveBeenCalledOnce()
    expect(nav.subscribe).toHaveBeenCalledWith({ userVisibleOnly: true, applicationServerKey: expect.any(Uint8Array) })
    const cle = (nav.subscribe.mock.calls[0]?.[0] as { applicationServerKey: Uint8Array }).applicationServerKey
    expect([...cle]).toEqual([...octetsA])
    expect(api.enregistrerAbonnementPush).toHaveBeenCalledWith({
      endpoint: nav.abonnement?.endpoint,
      keys: { p256dh: 'P256DH', auth: 'AUTH' },
    })
    expect(usePush().etat.value).toBe('active')
  })

  it('demande la permission avant tout appel réseau — le geste serait perdu sinon', async () => {
    const nav = navigateur()
    const ordre: string[] = []
    nav.demanderPermission.mockImplementation(() => {
      ordre.push('permission')
      nav.permission = 'granted'
      return Promise.resolve('granted')
    })
    api.lireClePush.mockImplementation(() => {
      ordre.push('cle')
      return Promise.resolve({ clePublique: CLE_A })
    })

    const promesse = activerPush()
    // La demande est partie dans le même tour que le clic.
    expect(ordre).toEqual(['permission'])
    await promesse
    expect(ordre).toEqual(['permission', 'cle'])
  })

  it('refusée : bloqué, sans abonnement ni appel', async () => {
    const nav = navigateur()
    nav.reponsePermission = 'denied'

    expect(await activerPush()).toBe(false)

    expect(usePush().etat.value).toBe('bloque')
    expect(nav.subscribe).not.toHaveBeenCalled()
    expect(api.enregistrerAbonnementPush).not.toHaveBeenCalled()
  })

  it('lève le refus mémorisé sur l’appareil', async () => {
    const nav = navigateur()
    nav.refus = true
    await activerPush()
    expect(nav.refus).toBe(false)
  })

  it('un échec du serveur se dit, et l’état reste vrai', async () => {
    navigateur()
    api.enregistrerAbonnementPush.mockRejectedValue(new Error('panne'))

    expect(await activerPush()).toBe(false)
    expect(usePush().erreur.value).toMatch(/n’ont pas pu être activées/)
  })

  it('ne fait rien sur un iPhone hors écran d’accueil', async () => {
    const nav = navigateur({ aInstaller: true })
    expect(await activerPush()).toBe(false)
    expect(nav.demanderPermission).not.toHaveBeenCalled()
  })
})

describe('désactiver', () => {
  it('retire l’abonnement du serveur puis du navigateur, et s’en souvient', async () => {
    const nav = navigateur({ permission: 'granted' })
    const a = abonnement(octetsA, nav)
    nav.abonnement = a

    await desactiverPush()

    expect(api.supprimerAbonnementPush).toHaveBeenCalledWith(a.endpoint)
    expect(a.unsubscribe).toHaveBeenCalled()
    expect(nav.refus).toBe(true)
    expect(usePush().etat.value).toBe('desactive')
  })
})

describe('synchroniser au démarrage', () => {
  it('renvoie au serveur l’abonnement existant, même inchangé', async () => {
    // C'est le cas « base restaurée » ou « abonnement oublié par le serveur
    // après un 410 » : le navigateur n'a rien vu, le serveur n'a plus rien.
    const nav = navigateur({ permission: 'granted' })
    const a = abonnement(octetsA, nav)
    nav.abonnement = a

    await synchroniserPush()

    expect(nav.subscribe).not.toHaveBeenCalled()
    expect(api.enregistrerAbonnementPush).toHaveBeenCalledWith({ endpoint: a.endpoint, keys: { p256dh: 'P256DH', auth: 'AUTH' } })
    expect(usePush().etat.value).toBe('active')
  })

  it('se réabonne quand la clé du serveur a changé — le « jeton cassé » de la mise en prod', async () => {
    const nav = navigateur({ permission: 'granted' })
    const ancien = abonnement(octetsB, nav)
    nav.abonnement = ancien

    await synchroniserPush()

    expect(ancien.unsubscribe).toHaveBeenCalled()
    expect(api.supprimerAbonnementPush).toHaveBeenCalledWith(ancien.endpoint)
    expect(nav.subscribe).toHaveBeenCalledOnce()
    expect(nav.abonnement).not.toBe(ancien)
    expect(api.enregistrerAbonnementPush).toHaveBeenCalledWith(expect.objectContaining({ endpoint: nav.abonnement?.endpoint }))
  })

  it('se réabonne quand l’abonnement a disparu du navigateur', async () => {
    const nav = navigateur({ permission: 'granted' })

    await synchroniserPush()

    expect(nav.subscribe).toHaveBeenCalledOnce()
    expect(api.enregistrerAbonnementPush).toHaveBeenCalledOnce()
  })

  it('ne demande jamais la permission — elle exige un geste', async () => {
    const nav = navigateur()
    await synchroniserPush()
    expect(nav.demanderPermission).not.toHaveBeenCalled()
    expect(api.enregistrerAbonnementPush).not.toHaveBeenCalled()
    expect(usePush().etat.value).toBe('desactive')
  })

  it('respecte « Désactiver » : pas de réabonnement derrière le lecteur', async () => {
    const nav = navigateur({ permission: 'granted' })
    nav.refus = true
    await synchroniserPush()
    expect(nav.subscribe).not.toHaveBeenCalled()
    expect(api.enregistrerAbonnementPush).not.toHaveBeenCalled()
  })

  it('ne fait rien sans session — une 401 renverrait à la connexion', async () => {
    const nav = navigateur({ permission: 'granted' })
    nav.abonnement = abonnement(octetsA, nav)
    session.connecte.value = false

    await synchroniserPush()

    expect(api.lireClePush).not.toHaveBeenCalled()
    expect(api.enregistrerAbonnementPush).not.toHaveBeenCalled()
  })

  it('ne fait rien pendant une incarnation — le téléphone de l’administrateur changerait de compte', async () => {
    const nav = navigateur({ permission: 'granted' })
    nav.abonnement = abonnement(octetsA, nav)
    session.incarnation.value = { id: 3 }

    await synchroniserPush()

    expect(api.enregistrerAbonnementPush).not.toHaveBeenCalled()
  })

  it('ne lève jamais, même serveur éteint', async () => {
    navigateur({ permission: 'granted' })
    api.lireClePush.mockRejectedValue(new Error('réseau'))
    await expect(synchroniserPush()).resolves.toBeUndefined()
  })
})

describe('à la déconnexion', () => {
  it('retire l’abonnement de ce compte côté serveur, sans toucher au navigateur', async () => {
    const nav = navigateur({ permission: 'granted' })
    const a = abonnement(octetsA, nav)
    nav.abonnement = a

    await oublierPushAvantDeconnexion()

    expect(api.supprimerAbonnementPush).toHaveBeenCalledWith(a.endpoint)
    expect(a.unsubscribe).not.toHaveBeenCalled()
  })

  it('ne lève pas si le serveur refuse', async () => {
    const nav = navigateur({ permission: 'granted' })
    nav.abonnement = abonnement(octetsA, nav)
    api.supprimerAbonnementPush.mockRejectedValue(new Error('panne'))
    await expect(oublierPushAvantDeconnexion()).resolves.toBeUndefined()
  })
})
