/**
 * L'écran de passage d'une notification push (`/notification/:id`).
 *
 * Il ne se voit qu'un instant ; ce qu'on vérifie, c'est ce qu'il **fait** :
 * marquer la notification lue, puis **remplacer** l'adresse par le lien de la
 * notification — ou par l'accueil quand ce lien ne se résout pas.
 */
import { createSSRApp, h } from 'vue'
import { renderToString } from 'vue/server-renderer'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const etat = vi.hoisted(() => ({
  params: {} as Record<string, string>,
  query: {} as Record<string, string>,
  replace: vi.fn(),
  marquer: vi.fn(),
  compteur: vi.fn(),
  regime: 'membre' as string,
  rafraichirSession: vi.fn(),
}))

vi.mock('vue-router', () => ({
  useRoute: () => ({ params: etat.params, query: etat.query }),
  useRouter: () => ({
    replace: etat.replace,
    hasRoute: (nom: string) => nom === 'demande.detail' || nom === 'accueil',
    resolve: (cible: { params?: Record<string, unknown> }) => {
      if (cible.params?.['id'] === undefined) throw new Error('paramètre manquant')
      return cible
    },
  }),
}))
vi.mock('@/lib/api', () => ({ marquerNotificationLue: etat.marquer }))
vi.mock('@/composables/useNotifications', () => ({ rafraichirCompteur: etat.compteur }))
vi.mock('@/composables/useSession', () => ({
  useSession: () => ({ regime: { value: etat.regime } }),
  rafraichirSession: etat.rafraichirSession,
}))

import OuvrirNotificationView from './OuvrirNotificationView.vue'

async function ouvrir(params: Record<string, string>, query: Record<string, string>): Promise<string> {
  etat.params = params
  etat.query = query
  const html = await renderToString(createSSRApp({ render: () => h(OuvrirNotificationView) }))
  await vi.waitFor(() => expect(etat.replace).toHaveBeenCalled())
  return html
}

beforeEach(() => {
  vi.clearAllMocks()
  etat.replace.mockResolvedValue(undefined)
  etat.marquer.mockResolvedValue({})
  etat.compteur.mockResolvedValue(undefined)
  etat.rafraichirSession.mockResolvedValue(undefined)
  etat.regime = 'membre'
})

describe('ouvrir une notification depuis le téléphone', () => {
  it('marque lue, relit le compteur, puis remplace par le lien calculé', async () => {
    const html = await ouvrir({ id: '42' }, { r: 'demande.detail', p: '{"id":6}' })

    expect(html).toContain('Ouverture…')
    expect(etat.marquer).toHaveBeenCalledWith(42)
    expect(etat.compteur).toHaveBeenCalled()
    expect(etat.replace).toHaveBeenCalledWith({ name: 'demande.detail', params: { id: 6 } })
  })

  it('retombe sur l’accueil sans lien', async () => {
    await ouvrir({ id: '7' }, {})
    expect(etat.marquer).toHaveBeenCalledWith(7)
    expect(etat.replace).toHaveBeenCalledWith({ name: 'accueil' })
  })

  it('retombe sur l’accueil quand la route a disparu', async () => {
    await ouvrir({ id: '7' }, { r: 'ecran.disparu', p: '{}' })
    expect(etat.replace).toHaveBeenCalledWith({ name: 'accueil' })
  })

  it('navigue quand même si le marquage échoue', async () => {
    etat.marquer.mockRejectedValue(new Error('panne'))
    await ouvrir({ id: '7' }, { r: 'demande.detail', p: '{"id":6}' })
    expect(etat.replace).toHaveBeenCalledWith({ name: 'demande.detail', params: { id: 6 } })
  })

  it('ne relit pas la session d’un membre', async () => {
    await ouvrir({ id: '7' }, { r: 'accueil', p: '{}' })
    expect(etat.rafraichirSession).not.toHaveBeenCalled()
  })

  it('relit la session d’un compte en attente avant de partir — « Votre compte est activé »', async () => {
    etat.regime = 'en_attente'
    const ordre: string[] = []
    etat.rafraichirSession.mockImplementation(async () => { ordre.push('session') })
    etat.replace.mockImplementation(async () => { ordre.push('navigation') })
    await ouvrir({ id: '7' }, { r: 'accueil', p: '{}' })
    expect(ordre).toEqual(['session', 'navigation'])
  })

  it('navigue quand même si la relecture de session échoue', async () => {
    etat.regime = 'en_attente'
    etat.rafraichirSession.mockRejectedValue(new Error('panne'))
    await ouvrir({ id: '7' }, { r: 'accueil', p: '{}' })
    expect(etat.replace).toHaveBeenCalledWith({ name: 'accueil' })
  })
})
