/**
 * Le rendu d'Explorer, sans navigateur.
 *
 * ────────────────────────────────────────────────────────────────────
 * Ce que cette suite tient : la rangée porte **trois onglets, dans l'ordre**,
 * avec les libellés que la décision du 30/09/2026 a fixés — « Calendrier » et
 * non « Anniversaires » —, l'onglet de la route affichée porte
 * `aria-current="page"` et lui seul, et l'écran de l'onglet est bien rendu
 * dessous.
 *
 * Les trois vraies vues sont remplacées par des bouchons : ce qui se teste ici,
 * c'est l'enveloppe. Chacune a sa propre suite (`carte-rendu`,
 * `anniversaires-rendu`, `chronologie-filtre-vivants`), et les monter ici
 * mêlerait leurs pannes à celles de la rangée.
 *
 * Ce que le rendu SSR ne peut pas tenir : le glissement lui-même (une
 * `Transition` ne joue qu'au navigateur) et la position du soulignement (mesurée
 * au montage). Il tient en revanche que **rien n'écoute le balayage** : aucun
 * gestionnaire tactile n'est posé par l'enveloppe — le doigt appartient à la
 * carte et à la chronologie.
 * ────────────────────────────────────────────────────────────────────
 */
import { describe, expect, it } from 'vitest'
import { createSSRApp, defineComponent, h } from 'vue'
import { renderToString } from 'vue/server-renderer'
import { createMemoryHistory, createRouter, RouterView } from 'vue-router'
import ExplorerView, { ONGLETS_EXPLORER, ongletDe } from './ExplorerView.vue'

function bouchon(texte: string) {
  return defineComponent({ name: `Bouchon-${texte}`, setup: () => () => h('p', `écran ${texte}`) })
}

/** La même forme que la table réelle — noms, chemins, redirection —, avec des
 *  bouchons pour écrans. `explorer-routage.test.ts` tient la table réelle. */
function routeur() {
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      {
        path: '/explorer',
        name: 'explorer',
        component: ExplorerView,
        redirect: { name: 'chronologie' },
        children: [
          { path: 'chronologie', name: 'chronologie', component: bouchon('chronologie') },
          { path: 'carte', name: 'carte', component: bouchon('carte') },
          { path: 'calendrier', name: 'anniversaires', component: bouchon('calendrier') },
        ],
      },
    ],
  })
}

async function rendre(chemin: string): Promise<string> {
  const r = routeur()
  await r.push(chemin)
  await r.isReady()
  const app = createSSRApp({ render: () => h(RouterView) })
  app.use(r)
  return renderToString(app)
}

/** Les liens de la rangée, dans l'ordre : libellé et `aria-current`. */
function onglets(html: string): { libelle: string; courant: boolean; href: string }[] {
  const nav = /<nav[^>]*aria-label="Explorer"[^>]*>([\s\S]*?)<\/nav>/.exec(html)?.[1] ?? ''
  return [...nav.matchAll(/<a([^>]*)>([\s\S]*?)<\/a>/g)].map(([, attributs = '', contenu = '']) => ({
    libelle: contenu.replace(/<[^>]+>/g, '').trim(),
    courant: /aria-current="page"/.test(attributs),
    href: /href="([^"]*)"/.exec(attributs)?.[1] ?? '',
  }))
}

describe('la rangée d’Explorer', () => {
  it('porte trois onglets, dans l’ordre : Chronologie, Carte, Calendrier', async () => {
    const html = await rendre('/explorer/chronologie')

    expect(onglets(html).map((o) => o.libelle)).toEqual(['Chronologie', 'Carte', 'Calendrier'])
  })

  it('chaque onglet est un lien vers sa route, pour qu’il se partage et se recharge', async () => {
    const html = await rendre('/explorer/chronologie')

    expect(onglets(html).map((o) => o.href)).toEqual([
      '/explorer/chronologie',
      '/explorer/carte',
      '/explorer/calendrier',
    ])
  })

  it.each([
    ['/explorer/chronologie', 'Chronologie', 'écran chronologie'],
    ['/explorer/carte', 'Carte', 'écran carte'],
    ['/explorer/calendrier', 'Calendrier', 'écran calendrier'],
  ])('sur %s, « %s » est l’onglet courant, et lui seul', async (chemin, libelle, ecran) => {
    const html = await rendre(chemin)
    const courants = onglets(html).filter((o) => o.courant)

    expect(courants.map((o) => o.libelle)).toEqual([libelle])
    expect(html).toContain(ecran)
  })

  it('/explorer ouvre le premier onglet, jamais une rangée sans onglet actif', async () => {
    const html = await rendre('/explorer')

    expect(onglets(html).filter((o) => o.courant).map((o) => o.libelle)).toEqual(['Chronologie'])
    expect(html).toContain('écran chronologie')
  })

  it('n’écoute pas le balayage : seul le tap sur l’onglet change d’onglet', async () => {
    /* Un gestionnaire `touch*` ne se voit pas dans le HTML SSR — mais un
       attribut de balayage posé à la main, si. Le vrai garde-fou est l'en-tête
       d'`ExplorerView.vue` ; ce test en est le rappel. */
    const html = await rendre('/explorer/carte')

    expect(html).not.toMatch(/ontouch|data-balayage/i)
  })
})

describe('ongletDe', () => {
  it('rend l’onglet d’un nom de route d’Explorer', () => {
    expect(ongletDe('carte')).toBe('carte')
    expect(ongletDe('anniversaires')).toBe('anniversaires')
  })

  it('retombe sur le premier onglet hors des trois', () => {
    expect(ongletDe('explorer')).toBe('chronologie')
    expect(ongletDe(undefined)).toBe('chronologie')
    expect(ongletDe(Symbol('route'))).toBe('chronologie')
  })

  it('les clés des onglets sont les noms de route, qui sont un contrat', () => {
    expect(ONGLETS_EXPLORER.map((o) => o.cle)).toEqual(['chronologie', 'carte', 'anniversaires'])
    expect(ONGLETS_EXPLORER.map((o) => o.vers.name)).toEqual(['chronologie', 'carte', 'anniversaires'])
  })
})
