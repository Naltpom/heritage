/**
 * Le Monde — ce qui se prouve sans navigateur (SSR : `onMounted` ne s'exécute
 * pas, donc ni Three.js ni sonde ne partent).
 *
 * Ce que cette suite tient : la route est réservée à l'administration et sans
 * cadre ; avant la 3D, l'écran n'est pas blanc — il dit qu'il se construit, et
 * son dock (un bouton par zone, au clavier) et ses étiquettes sont déjà là ;
 * le retour au tableau de suivi est toujours visible.
 */
import { describe, expect, it } from 'vitest'
import { createSSRApp } from 'vue'
import { renderToString } from 'vue/server-renderer'
import { createMemoryHistory, createRouter } from 'vue-router'
import MondeView from './administration/MondeView.vue'
import { routes, sectionDe } from '@/router/routes'

describe('la route du Monde', () => {
  it('est réservée à l’administration, sans le cadre applicatif', () => {
    const r = routes.find((x) => x.name === 'administration.monde')
    expect(r?.path).toBe('/administration/monde')
    expect(r?.meta).toMatchObject({ acces: 'administration', cadre: false })
    expect(sectionDe('administration.monde', {})).toBe('administration')
  })
})

async function rendre(): Promise<string> {
  const vide = { render: () => null }
  const routeur = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/administration/monde', name: 'administration.monde', component: MondeView },
      { path: '/administration/suivi', name: 'administration.suivi', component: vide },
      { path: '/administration/a-traiter', name: 'administration.a-traiter', component: vide },
      { path: '/administration/utilisateurs', name: 'administration.utilisateurs', component: vide },
      { path: '/administration/invitations', name: 'administration.invitations', component: vide },
    ],
  })
  await routeur.push('/administration/monde')
  await routeur.isReady()
  const app = createSSRApp(MondeView)
  app.use(routeur)
  return renderToString(app)
}

describe('l’écran avant la 3D', () => {
  it('dit qu’il se construit, sans affirmer d’état', async () => {
    const html = await rendre()
    expect(html).toContain('Le monde se construit…')
    expect(html).toContain('data-niveau="inconnu"')
    expect(html).not.toContain('undefined')
    expect(html).not.toContain('NaN')
  })

  it('porte déjà son dock, un bouton par zone, et ses sept étiquettes', async () => {
    const html = await rendre()
    expect(html.match(/data-zone="/g)).toHaveLength(7)
    expect(html.match(/data-etiquette="/g)).toHaveLength(7)
    expect(html).toContain('aria-label="Zones du monde"')
    expect(html).toMatch(/data-zone="maison"[^>]*aria-pressed="false"|aria-pressed="false"[^>]*data-zone="maison"/)
  })

  it('garde le retour au tableau de suivi, et son panneau fermé est hors d’atteinte', async () => {
    const html = await rendre()
    expect(html).toContain('href="/administration/suivi"')
    expect(html).toMatch(/<aside[^>]*aria-hidden="true"[^>]*inert/)
  })
})
