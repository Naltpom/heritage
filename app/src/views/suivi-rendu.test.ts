/**
 * Le suivi en direct — ce qui se prouve sans navigateur (SSR, `onMounted` ne
 * s'exécute pas : aucune sonde ne part).
 *
 * Ce que cette suite tient : la route est réservée à l'administration et rendue
 * sans le cadre applicatif ; l'écran, avant toute donnée, dit qu'il attend au
 * lieu de rendre une page blanche ou des zéros inventés ; ses cinq onglets
 * suivent le motif ARIA, et la sortie vers l'administration est toujours là.
 */
import { describe, expect, it } from 'vitest'
import { createSSRApp } from 'vue'
import { renderToString } from 'vue/server-renderer'
import { createMemoryHistory, createRouter } from 'vue-router'
import SuiviView from './administration/SuiviView.vue'
import { routes, sectionDe } from '@/router/routes'

function trouver(nom: string): (typeof routes)[number] | undefined {
  return routes.find((r) => r.name === nom)
}

describe('la route du suivi', () => {
  it('est réservée à l’administration, sans le cadre applicatif, dans la section administration', () => {
    const r = trouver('administration.suivi')
    expect(r?.path).toBe('/administration/suivi')
    expect(r?.meta).toMatchObject({ acces: 'administration', cadre: false })
    expect(sectionDe('administration.suivi', {})).toBe('administration')
  })
})

async function rendre(): Promise<string> {
  const vide = { render: () => null }
  const routeur = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/administration/suivi', name: 'administration.suivi', component: SuiviView },
      { path: '/administration/a-traiter', name: 'administration.a-traiter', component: vide },
      { path: '/administration/utilisateurs', name: 'administration.utilisateurs', component: vide },
      { path: '/administration/invitations', name: 'administration.invitations', component: vide },
      { path: '/administration/monde', name: 'administration.monde', component: vide },
    ],
  })
  await routeur.push('/administration/suivi')
  await routeur.isReady()
  const racine = createSSRApp(SuiviView)
  racine.use(routeur)
  return renderToString(racine)
}

describe('l’écran avant la première mesure', () => {
  it('dit qu’il attend, sans affirmer un état ni inventer de zéro', async () => {
    const html = await rendre()
    expect(html).toContain('data-niveau="inconnu"')
    expect(html).toContain('Première mesure…')
    expect(html).toContain('Connexion…')
    expect(html).toMatch(/data-compteur="a-traiter"[^>]*>—</)
    expect(html).not.toContain('undefined')
    expect(html).not.toContain('NaN')
  })

  it('porte ses cinq onglets en motif ARIA, « En direct » choisi', async () => {
    const html = await rendre()
    expect(html.match(/role="tab"/g)).toHaveLength(5)
    expect(html).toMatch(/id="onglet-direct"[^>]*aria-selected="true"/)
    expect(html).toContain('role="tabpanel"')
  })

  it('garde la sortie vers l’administration, et mène au Monde', async () => {
    const html = await rendre()
    expect(html).toContain('href="/administration/a-traiter"')
    expect(html).toMatch(/href="\/administration\/monde"[^>]*data-vers-monde/)
  })
})
