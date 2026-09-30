/**
 * La cloche, rendue côté serveur (donc fermée, sans geste).
 *
 * 1. **La pastille** : absente à zéro, le nombre jusqu'à neuf, « 9+ » au-delà —
 *    et l'étiquette du bouton porte, elle, le vrai nombre.
 * 2. **Anonyme** : rien du tout. `entete-rendu.test.ts` exige qu'un en-tête
 *    anonyme n'ait aucun `<button>`.
 * 3. **Le corps** (`ListeNotifications`, rendu seul puisque la cloche fermée ne
 *    le rend pas) : état vide, erreur avec « Réessayer », liste avec non lues
 *    marquées, lien quand la route existe, bouton quand elle a disparu.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createSSRApp, defineComponent, h } from 'vue'
import { renderToString } from 'vue/server-renderer'
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

// Un routeur réduit à ce que lit `lienDeNotification` : `demande` existe et
// exige un `id`, tout le reste a disparu.
vi.mock('vue-router', () => ({
  useRouter: () => ({
    push: () => Promise.resolve(),
    hasRoute: (nom: string) => nom === 'demande',
    resolve: (cible: { params?: Record<string, unknown> }) => {
      if (cible.params?.['id'] === undefined) throw new Error('paramètre manquant')
      return cible
    },
  }),
}))

import ClocheNotifications from './ClocheNotifications.vue'
import ListeNotifications from './ListeNotifications.vue'
import { rafraichirCompteur, reinitialiserNotifications, type EtatListe } from '@/composables/useNotifications'

const LienFactice = defineComponent({
  name: 'RouterLink',
  props: { to: { type: [String, Object], default: '' } },
  setup: (props, { slots }) => () => h('a', { href: '#', 'data-vers': JSON.stringify(props.to) }, slots['default']?.()),
})

async function cloche(nonLues: number): Promise<string> {
  api.compterNotificationsNonLues.mockResolvedValue({ nonLues })
  await rafraichirCompteur()
  const app = createSSRApp({ render: () => h(ClocheNotifications) })
  return renderToString(app)
}

function liste(notifications: Notification[], etatListe: EtatListe = 'prete'): Promise<string> {
  const app = createSSRApp({
    render: () => h(ListeNotifications, { notifications, etat: etatListe, maintenant: new Date(2026, 8, 30, 10) }),
  })
  app.component('RouterLink', LienFactice)
  return renderToString(app)
}

function notif(partiel: Partial<Notification>): Notification {
  return {
    id: 1,
    kind: 'demande_refusee',
    title: 'Votre demande a été refusée',
    body: 'Motif : la date de naissance contredit l’acte.',
    linkRoute: 'demande',
    linkParams: { id: 6 },
    readAt: null,
    createdAt: new Date(2026, 8, 30, 9, 55).toISOString(),
    ...partiel,
  }
}

beforeEach(() => {
  etat.connecte.value = true
  reinitialiserNotifications()
})
afterEach(() => vi.clearAllMocks())

describe('la cloche', () => {
  it('n’a pas de pastille quand tout est lu', async () => {
    const html = await cloche(0)

    expect(html).not.toContain('data-pastille')
    expect(html).toContain('aria-label="Notifications, aucune non lue"')
  })

  it('montre le nombre non lu', async () => {
    const html = await cloche(4)

    expect(html).toMatch(/data-pastille[^>]*>4<\/span>/)
    expect(html).toContain('aria-label="Notifications, 4 non lues"')
  })

  it('écrit « 9+ » au-delà de neuf, mais dit le vrai nombre au lecteur d’écran', async () => {
    const html = await cloche(23)

    expect(html).toMatch(/data-pastille[^>]*>9\+<\/span>/)
    expect(html).toContain('aria-label="Notifications, 23 non lues"')
  })

  it('est fermée au rendu, et annonce ce qu’elle commande', async () => {
    const html = await cloche(1)

    expect(html).toContain('aria-expanded="false"')
    expect(html).toContain('aria-controls="panneau-notifications"')
    expect(html).not.toContain('id="panneau-notifications"')
  })

  it('ne rend rien pour un visiteur anonyme', async () => {
    etat.connecte.value = false
    const html = await cloche(5)

    expect(html).not.toMatch(/<button/)
    expect(api.compterNotificationsNonLues).not.toHaveBeenCalled()
  })
})

describe('le corps de la cloche', () => {
  it('dit « Rien de nouveau » quand il n’y a aucune notification', async () => {
    const html = await liste([])

    expect(html).toContain('data-vide')
    expect(html).toContain('Rien de nouveau')
    expect(html).not.toContain('<ul')
  })

  it('propose de réessayer quand le chargement a échoué', async () => {
    const html = await liste([], 'erreur')

    expect(html).toContain('role="alert"')
    expect(html).toContain('Réessayer')
  })

  it('montre le type en clair, le texte du serveur et la date relative', async () => {
    const html = await liste([notif({})])

    expect(html).toContain('Demande refusée')
    expect(html).toContain('Votre demande a été refusée')
    expect(html).toContain('Motif : la date de naissance contredit l’acte.')
    expect(html).toContain('il y a 5 min')
  })

  it('marque les non lues, et elles seules', async () => {
    const html = await liste([notif({ id: 1 }), notif({ id: 2, readAt: '2026-09-30T08:00:00Z' })])

    expect(html.match(/data-non-lue/g)).toHaveLength(1)
    expect(html.match(/\(non lue\)/g)).toHaveLength(1)
  })

  it('fait un lien vers la route de la notification', async () => {
    const html = await liste([notif({})])

    expect(html).toContain('data-vers="{&quot;name&quot;:&quot;demande&quot;,&quot;params&quot;:{&quot;id&quot;:6}}"')
  })

  it('reste lisible, en bouton, quand la route a disparu', async () => {
    const html = await liste([notif({ linkRoute: 'ecran_supprime_en_2026' })])

    expect(html).not.toContain('data-vers')
    expect(html).toMatch(/<button type="button"[^>]*data-non-lue/)
    expect(html).toContain('Votre demande a été refusée')
  })
})
