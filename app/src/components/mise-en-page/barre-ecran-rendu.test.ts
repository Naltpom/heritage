import { createSSRApp, h } from 'vue'
import { renderToString } from 'vue/server-renderer'
import { beforeEach, describe, expect, it } from 'vitest'
import BarreEcran from './BarreEcran.vue'
import { reinitialiserTiroirs } from '@/composables/useTiroirs'

/**
 * Ce que ces tests protègent : la barre d'écran commune de la refonte garde les
 * contraintes de `TiroirOutils` — jamais un `<aside>`, un seul exemplaire des
 * filtres, fermée hors de la mise en page — et n'offre pas de bouton Filtres à
 * un écran qui n'a rien au-delà de sa vue.
 */

function rendre(slots: Record<string, () => unknown>, props: Record<string, unknown> = {}) {
  const app = createSSRApp({ render: () => h(BarreEcran, { ecran: 'Arbre', ...props }, slots) })
  return renderToString(app)
}

describe('la barre d’écran', () => {
  beforeEach(() => reinitialiserTiroirs())

  it('n’est jamais un `aside`', async () => {
    const html = await rendre({ vue: () => 'VUE', filtres: () => h('p', { class: 'contenu' }, 'CONTENU') })
    expect(html).not.toContain('<aside')
  })

  it('rend le contenu des filtres une seule fois — menu et feuillet sont le même bloc', async () => {
    const html = await rendre({ vue: () => 'VUE', filtres: () => h('p', { class: 'contenu' }, 'CONTENU') })
    expect((html.match(/class="contenu"/g) ?? []).length).toBe(1)
    expect(html).toContain('id="tiroir-outils"')
  })

  it('garde le feuillet hors de la mise en page tant qu’il est fermé, aux deux largeurs', async () => {
    const html = await rendre({ vue: () => 'VUE', filtres: () => 'CONTENU' })
    const classes = /id="tiroir-outils"[^>]*class="([^"]*)"/.exec(html)?.[1] ?? /class="([^"]*)"[^>]*id="tiroir-outils"/.exec(html)?.[1] ?? ''
    expect(classes.split(/\s+/)).toContain('max-md:hidden')
    expect(classes.split(/\s+/)).toContain('md:hidden')
    expect(html).not.toContain('bg-black/40')
  })

  it('n’a ni bouton Filtres ni feuillet sans rien au-delà de la vue', async () => {
    const html = await rendre({ vue: () => 'VUE' })
    expect(html).not.toContain('tiroir-outils')
    expect(html).not.toMatch(/>\s*Filtres\s*</)
  })

  it('affiche le nombre de filtres actifs sur son bouton', async () => {
    const html = await rendre({ vue: () => 'VUE', filtres: () => 'CONTENU' }, { filtresActifs: 3 })
    expect(html).toMatch(/Filtres\s*<span[^>]*>3<\/span>/)
  })
})
