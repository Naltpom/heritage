/**
 * « Notifications sur ce téléphone », rendu côté serveur, état par état.
 *
 * La règle tenue ici : **un bouton seulement quand il peut quelque chose.**
 * Permission refusée, le navigateur ne redemandera jamais — il faut dire où la
 * rendre. iPhone hors écran d'accueil, le push n'existe pas — il faut dire
 * d'installer. Dans la cloche, l'invitation n'apparaît que si un clic suffit.
 */
import { createSSRApp, h } from 'vue'
import { renderToString } from 'vue/server-renderer'
import { afterEach, describe, expect, it, vi } from 'vitest'

vi.mock('@/lib/api', () => ({
  lireClePush: vi.fn(),
  enregistrerAbonnementPush: vi.fn(),
  supprimerAbonnementPush: vi.fn(),
}))
vi.mock('@/composables/useNotifications', () => ({ rafraichirCompteur: vi.fn() }))
vi.mock('@/composables/useSession', async () => {
  const { computed } = await import('vue')
  return { useSession: () => ({ connecte: computed(() => true), incarnation: computed(() => null) }) }
})

import NotificationsTelephone from './NotificationsTelephone.vue'
import { definirEnvironnementPush, evaluerPush, reinitialiserPush, type EnvironnementPush, type PermissionPush } from '@/composables/usePush'

function environnement(o: { permission?: PermissionPush; abonne?: boolean; pushPossible?: boolean; aInstaller?: boolean; refuse?: boolean }): void {
  const e: EnvironnementPush = {
    pushPossible: o.pushPossible ?? true,
    aInstallerDabord: o.aInstaller ?? false,
    permission: () => o.permission ?? 'default',
    demanderPermission: () => Promise.resolve('granted'),
    gestionnaire: () =>
      Promise.resolve({
        getSubscription: () =>
          Promise.resolve(
            o.abonne
              ? { endpoint: 'https://push.exemple.test/1', toJSON: () => ({}), unsubscribe: () => Promise.resolve(true) }
              : null,
          ),
        subscribe: () => Promise.reject(new Error('non')),
      }),
    lireRefus: () => o.refuse ?? false,
    ecrireRefus: () => undefined,
  }
  definirEnvironnementPush(e)
}

async function rendre(forme: 'ligne' | 'lien', o: Parameters<typeof environnement>[0]): Promise<string> {
  reinitialiserPush()
  environnement(o)
  await evaluerPush()
  return renderToString(createSSRApp({ render: () => h(NotificationsTelephone, { forme }) }))
}

afterEach(() => definirEnvironnementPush(null))

describe('la ligne de « Moi »', () => {
  it('désactivées : propose « Activer »', async () => {
    const html = await rendre('ligne', {})
    expect(html).toContain('Notifications sur ce téléphone')
    expect(html).toContain('Désactivées')
    expect(html).toMatch(/<button[^>]*>[\s\S]*Activer/)
  })

  it('activées : propose « Désactiver »', async () => {
    const html = await rendre('ligne', { permission: 'granted', abonne: true })
    expect(html).toContain('Activées')
    expect(html).toContain('Désactiver')
    expect(html).not.toMatch(/>\s*Activer\s*</)
  })

  it('bloquées : aucun bouton, mais où les réautoriser', async () => {
    const html = await rendre('ligne', { permission: 'denied' })
    expect(html).toContain('Bloquées par le navigateur')
    expect(html).toContain('réglages du navigateur')
    expect(html).not.toContain('<button')
  })

  it('iPhone hors écran d’accueil : installer d’abord', async () => {
    const html = await rendre('ligne', { aInstaller: true, pushPossible: false })
    expect(html).toContain('Installez d’abord l’application')
    expect(html).toContain('Sur l’écran d’accueil')
    expect(html).not.toContain('<button')
  })

  it('navigateur sans push : le dit, sans bouton', async () => {
    const html = await rendre('ligne', { pushPossible: false })
    expect(html).toContain('Non disponibles sur ce navigateur')
    expect(html).not.toContain('<button')
  })
})

describe('le lien de la cloche', () => {
  it('invite à recevoir aussi sur ce téléphone quand un clic suffit', async () => {
    expect(await rendre('lien', {})).toContain('Recevoir aussi sur ce téléphone')
  })

  it('ne relance pas qui a touché « Désactiver » : c’est dans Moi qu’il le retrouve', async () => {
    expect(await rendre('lien', { refuse: true })).not.toContain('Recevoir aussi')
    expect(await rendre('ligne', { refuse: true })).toContain('Activer')
  })

  it('se tait quand c’est déjà activé, bloqué ou impossible', async () => {
    for (const o of [{ permission: 'granted' as const, abonne: true }, { permission: 'denied' as const }, { pushPossible: false }, { aInstaller: true }]) {
      expect(await rendre('lien', o)).not.toContain('Recevoir aussi')
    }
  })
})
