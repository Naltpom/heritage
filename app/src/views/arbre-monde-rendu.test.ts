/**
 * Le Monde de l'arbre — ce qui se prouve sans navigateur (SSR : ni graphe ni
 * Three.js ne se chargent, `onMounted` ne s'exécute pas).
 *
 * Ce que cette suite tient : la route est `/arbre/monde`, ouverte aux membres
 * (pas seulement à l'administration), sans le cadre applicatif, rangée sous
 * « Arbre » ; elle ne masque pas `/arbre` ; avant le graphe, l'écran dit qu'il
 * charge au lieu d'être blanc, et le retour à l'arbre classique est là.
 */
import { describe, expect, it } from 'vitest'
import { createSSRApp } from 'vue'
import { renderToString } from 'vue/server-renderer'
import { createMemoryHistory, createRouter } from 'vue-router'
import ArbreMondeView from './ArbreMondeView.vue'
import { routes, sectionDe } from '@/router/routes'

describe('la route du Monde de l’arbre', () => {
  it('est ouverte aux membres, sans cadre, sous la section Arbre', () => {
    const r = routes.find((x) => x.name === 'arbre.monde')
    expect(r?.path).toBe('/arbre/monde')
    expect(r?.meta).toMatchObject({ acces: 'membre', cadre: false })
    expect(sectionDe('arbre.monde', r!.meta as { section?: 'arbre' })).toBe('arbre')
  })

  it('ne prend pas la place de `/arbre`', async () => {
    const routeur = createRouter({ history: createMemoryHistory(), routes: routes.map((r) => ({ ...r, component: { render: () => null } }) as never) })
    expect(routeur.resolve('/arbre').name).toBe('arbre')
    expect(routeur.resolve('/arbre/monde').name).toBe('arbre.monde')
  })
})

describe('l’écran avant le graphe', () => {
  it('dit qu’il charge, et garde le retour à l’arbre classique', async () => {
    const vide = { render: () => null }
    const routeur = createRouter({
      history: createMemoryHistory(),
      routes: [
        { path: '/arbre/monde', name: 'arbre.monde', component: ArbreMondeView },
        { path: '/arbre', name: 'arbre', component: vide },
        { path: '/personnes/:id', name: 'personne.detail', component: vide },
      ],
    })
    await routeur.push('/arbre/monde')
    await routeur.isReady()
    const app = createSSRApp(ArbreMondeView)
    app.use(routeur)
    const html = await renderToString(app)
    expect(html).toContain('L’arbre pousse…')
    expect(html).toContain('href="/arbre"')
    expect(html).toContain('role="search"')
    expect(html).toMatch(/<aside[^>]*aria-hidden="true"[^>]*inert/)
    expect(html).not.toContain('undefined')
  })
})
