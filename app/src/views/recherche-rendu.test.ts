/**
 * `/recherche` — tous les résultats d'une recherche de personnes.
 *
 * Rendu serveur : il prouve ce que l'écran écrit au premier rendu (la saisie
 * reprise de l'adresse, aucun « personne » avant la réponse) et la lecture de
 * `?q=`. Les états suivants sont ceux de `useRecherchePersonnes`, testés à part.
 */
import { describe, expect, it, vi } from 'vitest'
import { createSSRApp, h } from 'vue'
import { renderToString } from 'vue/server-renderer'

const routeur = vi.hoisted(() => ({ query: {} as Record<string, unknown>, replace: vi.fn() }))
vi.mock('vue-router', () => ({
  useRoute: () => ({ query: routeur.query }),
  useRouter: () => ({ replace: routeur.replace }),
}))
vi.mock('@/lib/api', async (original) => ({
  ...(await original<typeof import('@/lib/api')>()),
  rechercherPersonnes: () => new Promise(() => undefined),
}))

import RechercheView, { saisieDeLAdresse } from './RechercheView.vue'

const LienFactice = {
  props: { to: { type: [String, Object], default: '' } },
  template: '<a href="#"><slot /></a>',
}

function rendre(q?: string): Promise<string> {
  routeur.query = q === undefined ? {} : { q }
  const app = createSSRApp({ render: () => h(RechercheView) })
  app.component('RouterLink', LienFactice)
  return renderToString(app)
}

describe('saisieDeLAdresse', () => {
  it('lit `?q=` comme une chaîne, et rien d’autre', () => {
    expect(saisieDeLAdresse('bourrache')).toBe('bourrache')
    expect(saisieDeLAdresse(['sureau', 'x'])).toBe('sureau')
    expect(saisieDeLAdresse(undefined)).toBe('')
    expect(saisieDeLAdresse(12)).toBe('')
  })
})

describe('l’écran des résultats', () => {
  it('reprend la saisie de l’adresse, et attend la réponse sans rien conclure', async () => {
    const html = await rendre('bourrache')

    expect(html).toContain('value="bourrache"')
    expect(html).toContain('aria-busy="true"')
    expect(html).not.toContain('Personne ne correspond')
  })

  it('demande deux caractères quand l’adresse n’en porte pas', async () => {
    const html = await rendre()

    expect(html).toContain('Tapez au moins 2 caractères.')
    expect(html).not.toContain('Personne ne correspond')
  })
})
