/**
 * La page « Aide » (`/aide`), rendue côté serveur, et sa route.
 *
 * Ce qui se casse sans bruit, et se tient donc ici :
 *
 * - **la route** : son nom (`aide`, cité par « Moi »), son niveau (`membre`),
 *   l'onglet qu'elle allume (« Moi » — pas de cinquième entrée en bas) ;
 * - **les deux sections**, dans l'ordre, et toutes les vidéos du catalogue —
 *   une vidéo absente de la page serait une vidéo que personne ne voit ;
 * - **la durée** écrite comme un lecteur vidéo l'écrit, « 0:35 » ;
 * - **`?video=<id>`** : la bonne vidéo s'ouvre, une inconnue n'ouvre rien.
 *
 * Le comportement du lecteur lui-même est tenu par ses propres tests
 * (`components/video/`). Les noms de vidéo de ce fichier sont ceux du catalogue.
 */
import { createSSRApp, h } from 'vue'
import { renderToString } from 'vue/server-renderer'
import { createMemoryHistory, createRouter } from 'vue-router'
import { describe, expect, it, vi } from 'vitest'

const etat = vi.hoisted(() => ({ query: {} as Record<string, unknown> }))

vi.mock('vue-router', async (original) => ({
  ...(await original<typeof import('vue-router')>()),
  useRoute: () => ({ query: etat.query, params: {} }),
  useRouter: () => ({ replace: () => Promise.resolve() }),
}))

import { routes, sectionDe } from '@/router/routes'
import { resoudreAcces } from '@/router/acces'
import { VIDEOS } from '@/lib/videos'
import AideView from './AideView.vue'

const RouterLinkBouchon = {
  props: { to: { type: [String, Object], required: false, default: '' } },
  template: '<a href="#" :data-vers="typeof to === \'object\' ? to.name : to"><slot /></a>',
}

function rendre(query: Record<string, unknown> = {}): Promise<string> {
  etat.query = query
  const app = createSSRApp({ render: () => h(AideView) })
  app.component('RouterLink', RouterLinkBouchon)
  return renderToString(app)
}

const cartes = (html: string): string[] => [...html.matchAll(/data-carte-video="([^"]+)"/g)].map((m) => m[1] ?? '')

describe('la route de l’aide', () => {
  const routeur = createRouter({ history: createMemoryHistory(), routes })

  it('répond à /aide, sous le nom « aide », réservée aux membres', () => {
    const resolue = routeur.resolve('/aide')

    expect(resolue.name).toBe('aide')
    expect(resolue.meta.acces).toBe('membre')
    expect(resolue.meta.titre).toBe('Aide')
  })

  it('garde ?video= dans la requête', () => {
    expect(routeur.resolve('/aide?video=decouvrir').query['video']).toBe('decouvrir')
  })

  it('allume l’onglet « Moi », d’où l’on y entre', () => {
    expect(sectionDe('aide', {})).toBe('moi')
  })

  it('renvoie un compte en attente à son écran d’attente, et un anonyme à la connexion', () => {
    expect(resoudreAcces('membre', 'en_attente', '/aide')).toMatchObject({
      autorise: false,
      redirection: { name: 'inscription.etat' },
    })
    expect(resoudreAcces('membre', 'anonyme', '/aide?video=decouvrir')).toMatchObject({
      autorise: false,
      redirection: { name: 'connexion', query: { suite: '/aide?video=decouvrir' } },
    })
    expect(resoudreAcces('membre', 'membre', '/aide')).toEqual({ autorise: true })
  })
})

describe('la page Aide', () => {
  it('range « Bien commencer » avant « Chaque fonctionnalité »', async () => {
    const html = await rendre()

    const debut = html.indexOf('Bien commencer')
    const chapitres = html.indexOf('Chaque fonctionnalité')
    expect(debut).toBeGreaterThan(-1)
    expect(chapitres).toBeGreaterThan(debut)
    // Bienvenue et Découvrir sont dans la première section, avant les chapitres.
    expect(html.indexOf('data-carte-video="decouvrir"')).toBeLessThan(chapitres)
    expect(html.indexOf('data-carte-video="lire-l-arbre"')).toBeGreaterThan(chapitres)
  })

  it('montre toutes les vidéos du catalogue, dans son ordre', async () => {
    expect(cartes(await rendre())).toEqual(VIDEOS.map((v) => v.id))
  })

  it('donne à chaque carte son affiche, son titre, sa phrase et sa durée', async () => {
    const html = await rendre()

    expect(html).toContain('src="/videos/bienvenue.jpg"')
    expect(html).toContain('Bienvenue dans Heritage')
    expect(html).toContain('Le tour de l’application en moins d’une minute.')
    expect(html).toMatch(/>0:35<\/span>/)
    expect(html).toMatch(/>0:28<\/span>/)
  })

  it('dit la durée en mots aux lecteurs d’écran', async () => {
    expect(await rendre()).toContain('aria-label="Bienvenue dans Heritage — vidéo de 37 secondes"')
  })

  it('offre d’aller à l’écran d’un chapitre, hors du bouton de la carte', async () => {
    const html = await rendre()

    // `<!--[-->` : la marque de fragment que le rendu serveur pose autour d'un créneau.
    expect(html).toMatch(/data-vers="arbre"[^>]*>(?:<!--\[-->)?\s*Ouvrir l’arbre/)
    expect(html).toMatch(/data-vers="carte"/)
    // Pas de lien dans un bouton.
    expect(html).not.toMatch(/<button[^>]*>(?:(?!<\/button>)[\s\S])*<a\b/)
  })

  it('n’ouvre aucun lecteur sans ?video=', async () => {
    expect(await rendre()).not.toContain('data-lecteur-video')
  })

  it('ouvre directement la vidéo nommée par ?video=', async () => {
    const html = await rendre({ video: 'filtres-de-l-arbre' })

    expect(html).toContain('data-lecteur-video')
    expect(html).toContain('data-video="filtres-de-l-arbre"')
    expect(html).toContain('src="/videos/filtres-de-l-arbre.mp4"')
  })

  it('ignore une vidéo inconnue : la page s’ouvre, sans lecteur', async () => {
    const html = await rendre({ video: 'video-retiree' })

    expect(html).not.toContain('data-lecteur-video')
    expect(cartes(html)).toHaveLength(VIDEOS.length)
  })
})
