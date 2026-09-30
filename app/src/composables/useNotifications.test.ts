/**
 * Le compteur de la cloche, sans navigateur ni serveur.
 *
 * L'API est simulée (`vi.mock('@/lib/api')`) et la session aussi : la seule
 * chose qu'on lit d'elle est `connecte`. La visibilité de l'onglet est une
 * `CibleVisibilite` écrite à la main — l'environnement de vitest est `node`.
 *
 * La propriété la plus importante est la dernière du fichier : **aucun appel
 * sans session**. Un appel anonyme rendrait une 401, et une 401 renvoie à la
 * connexion — la cloche chasserait le visiteur de la page publique où il est.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'
import type { Notification } from '@/lib/api'

const api = vi.hoisted(() => ({
  compterNotificationsNonLues: vi.fn(),
  listerNotifications: vi.fn(),
  marquerNotificationLue: vi.fn(),
  marquerToutesNotificationsLues: vi.fn(),
}))
vi.mock('@/lib/api', () => api)

const etat = vi.hoisted(() => ({ connecte: null as unknown as { value: boolean } }))
vi.mock('@/composables/useSession', async () => {
  const { computed, shallowRef } = await import('vue')
  const connecte = shallowRef(true)
  etat.connecte = connecte
  return { useSession: () => ({ connecte: computed(() => connecte.value) }) }
})

import {
  PERIODE_SONDAGE_MS,
  chargerNotifications,
  demarrerSuivi,
  marquerLue,
  rafraichirCompteur,
  reinitialiserNotifications,
  toutMarquerLu,
  useNotifications,
  type CibleVisibilite,
} from './useNotifications'

function notif(id: number, lue = false): Notification {
  return {
    id,
    kind: 'demande_validee',
    title: `Notification ${id}`,
    body: '',
    linkRoute: null,
    linkParams: null,
    readAt: lue ? '2026-09-29T10:00:00Z' : null,
    createdAt: '2026-09-29T09:00:00Z',
  }
}

/** Un onglet dont on règle la visibilité à la main. */
interface OngletFactice {
  visibilityState: string
  addEventListener: CibleVisibilite['addEventListener']
  removeEventListener: CibleVisibilite['removeEventListener']
  montrer(v: boolean): void
}

function onglet(): OngletFactice {
  let ecouteur: (() => void) | null = null
  const cible: OngletFactice = {
    visibilityState: 'visible',
    addEventListener: (_: 'visibilitychange', f: () => void) => {
      ecouteur = f
    },
    removeEventListener: () => {
      ecouteur = null
    },
    montrer(v: boolean) {
      cible.visibilityState = v ? 'visible' : 'hidden'
      ecouteur?.()
    },
  }
  return cible
}

const vider = (): Promise<void> => new Promise((r) => setTimeout(r, 0))

beforeEach(() => {
  etat.connecte.value = true
  reinitialiserNotifications()
  api.compterNotificationsNonLues.mockResolvedValue({ nonLues: 3 })
  api.listerNotifications.mockResolvedValue({ elements: [notif(1), notif(2), notif(3, true)], total: 3, page: 1, parPage: 30 })
  api.marquerNotificationLue.mockImplementation((id: number) => Promise.resolve({ ...notif(id), readAt: 'x' }))
  api.marquerToutesNotificationsLues.mockResolvedValue({ marquees: 2 })
})

afterEach(() => {
  vi.clearAllMocks()
  vi.useRealTimers()
})

describe('le compteur', () => {
  it('est partagé : deux appels du composable lisent le même nombre', async () => {
    await rafraichirCompteur()

    expect(useNotifications().nonLues.value).toBe(3)
    expect(useNotifications().nonLues.value).toBe(3)
  })

  it('garde son dernier chiffre si le serveur ne répond pas', async () => {
    await rafraichirCompteur()
    api.compterNotificationsNonLues.mockRejectedValueOnce(new Error('réseau'))
    await rafraichirCompteur()

    expect(useNotifications().nonLues.value).toBe(3)
  })

  it('est relu au démarrage, puis toutes les 60 s tant que l’onglet est visible', async () => {
    vi.useFakeTimers()
    const cible = onglet()
    const arreter = demarrerSuivi(cible)
    expect(api.compterNotificationsNonLues).toHaveBeenCalledTimes(1)

    await vi.advanceTimersByTimeAsync(PERIODE_SONDAGE_MS)
    expect(api.compterNotificationsNonLues).toHaveBeenCalledTimes(2)

    cible.visibilityState = 'hidden'
    await vi.advanceTimersByTimeAsync(PERIODE_SONDAGE_MS * 5)
    expect(api.compterNotificationsNonLues).toHaveBeenCalledTimes(2)

    arreter()
  })

  it('est relu au retour de visibilité', () => {
    const cible = onglet()
    const arreter = demarrerSuivi(cible)
    cible.montrer(false)
    expect(api.compterNotificationsNonLues).toHaveBeenCalledTimes(1)

    cible.montrer(true)
    expect(api.compterNotificationsNonLues).toHaveBeenCalledTimes(2)
    arreter()
  })

  it('s’arrête : plus aucun appel une fois le suivi arrêté', async () => {
    vi.useFakeTimers()
    const arreter = demarrerSuivi(onglet())
    arreter()
    await vi.advanceTimersByTimeAsync(PERIODE_SONDAGE_MS * 3)

    expect(api.compterNotificationsNonLues).toHaveBeenCalledTimes(1)
  })
})

describe('la liste', () => {
  it('est chargée à la demande, et recalcule le compteur quand elle est complète', async () => {
    api.compterNotificationsNonLues.mockResolvedValue({ nonLues: 7 })
    await rafraichirCompteur()
    await chargerNotifications()
    const { notifications, etatListe, nonLues } = useNotifications()

    expect(etatListe.value).toBe('prete')
    expect(notifications.value.map((n) => n.id)).toEqual([1, 2, 3])
    expect(nonLues.value).toBe(2)
  })

  it('dit son erreur, pour que la cloche propose de réessayer', async () => {
    api.listerNotifications.mockRejectedValueOnce(new Error('500'))
    await chargerNotifications()

    expect(useNotifications().etatListe.value).toBe('erreur')
  })
})

describe('marquer lu', () => {
  it('une notification : la ligne et la pastille changent sans attendre le serveur', async () => {
    await chargerNotifications()
    const { notifications, nonLues } = useNotifications()
    const cible = notifications.value[0]!
    const promesse = marquerLue(cible)

    expect(notifications.value[0]!.readAt).not.toBeNull()
    expect(nonLues.value).toBe(1)
    await promesse
    expect(api.marquerNotificationLue).toHaveBeenCalledWith(1)
  })

  it('n’appelle pas le serveur pour une notification déjà lue', async () => {
    await chargerNotifications()
    await marquerLue(useNotifications().notifications.value[2]!)

    expect(api.marquerNotificationLue).not.toHaveBeenCalled()
  })

  it('relit le compteur si le serveur refuse', async () => {
    await chargerNotifications()
    api.marquerNotificationLue.mockRejectedValueOnce(new Error('404'))
    api.compterNotificationsNonLues.mockResolvedValue({ nonLues: 2 })
    await marquerLue(useNotifications().notifications.value[0]!)

    expect(useNotifications().nonLues.value).toBe(2)
  })

  it('tout : zéro non lue, toutes les lignes lues', async () => {
    await chargerNotifications()
    expect(await toutMarquerLu()).toBe(true)
    const { notifications, nonLues } = useNotifications()

    expect(nonLues.value).toBe(0)
    expect(notifications.value.every((n) => n.readAt !== null)).toBe(true)
  })

  it('tout, refusé : rien ne change à l’écran', async () => {
    await chargerNotifications()
    api.marquerToutesNotificationsLues.mockRejectedValueOnce(new Error('500'))

    expect(await toutMarquerLu()).toBe(false)
    expect(useNotifications().nonLues.value).toBe(2)
  })
})

describe('sans session', () => {
  it('aucun appel : ni compteur, ni liste, ni marquage', async () => {
    etat.connecte.value = false
    await rafraichirCompteur()
    await chargerNotifications()
    await marquerLue(notif(1))
    await toutMarquerLu()
    const arreter = demarrerSuivi(onglet())
    arreter()

    expect(api.compterNotificationsNonLues).not.toHaveBeenCalled()
    expect(api.listerNotifications).not.toHaveBeenCalled()
    expect(api.marquerNotificationLue).not.toHaveBeenCalled()
    expect(api.marquerToutesNotificationsLues).not.toHaveBeenCalled()
  })

  it('à la déconnexion, la pastille retombe à zéro et la liste est oubliée', async () => {
    const arreter = demarrerSuivi(onglet())
    await vider()
    await chargerNotifications()
    etat.connecte.value = false
    await nextTick()
    const { nonLues, notifications } = useNotifications()

    expect(nonLues.value).toBe(0)
    expect(notifications.value).toEqual([])
    arreter()
  })

  it('à la connexion, le compteur est relu', async () => {
    etat.connecte.value = false
    const arreter = demarrerSuivi(onglet())
    etat.connecte.value = true
    await nextTick()
    await vider()

    expect(api.compterNotificationsNonLues).toHaveBeenCalledTimes(1)
    expect(useNotifications().nonLues.value).toBe(3)
    arreter()
  })
})
