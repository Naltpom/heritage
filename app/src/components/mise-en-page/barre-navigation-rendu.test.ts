import { createSSRApp, h } from 'vue'
import { renderToString } from 'vue/server-renderer'
import { afterEach, describe, expect, it, vi } from 'vitest'
import BarreNavigation from './BarreNavigation.vue'
import { definirPage, reinitialiserEntete, type Section } from '@/composables/entete'
import { useTiroirs } from '@/composables/useTiroirs'

/**
 * La barre de navigation du bas (téléphone), rendue côté serveur.
 *
 * Ce qu'un rendu peut tenir : les quatre entrées et elles seules, l'entrée
 * active, les initiales de Moi, la barre absente pour un anonyme, et les
 * classes qui la cachent sur bureau et la posent au-dessus du trait d'accueil
 * de l'iPhone. Le reste — qu'elle ne recouvre pas l'arbre — est tenu par
 * `cadre.test.ts` (la formule) et par `App.vue` (la marge basse du `<main>`).
 */

const etat = vi.hoisted(() => ({
  regime: null as unknown as { value: string },
}))

vi.mock('@/composables/useSession', async () => {
  const { computed: c, shallowRef: sr } = await import('vue')
  etat.regime = sr('membre')
  return {
    useSession: () => ({
      regime: etat.regime,
      connecte: c(() => etat.regime.value !== 'anonyme'),
      rattache: c(() => etat.regime.value === 'membre' || etat.regime.value === 'administrateur'),
      administrateur: c(() => etat.regime.value === 'administrateur'),
      nomAffiche: c(() => 'Cobalt Romarin'),
    }),
  }
})

vi.mock('@/lib/api', () => ({
  listerDemandesAdministration: () => Promise.resolve({ total: 3 }),
}))

const RouterLinkBouchon = {
  props: { to: { type: [String, Object], required: false, default: '' } },
  template: '<a href="#" :data-vers="typeof to === \'object\' ? to.name : to"><slot /></a>',
}

function rendre(regime = 'membre', section: Section = 'autre'): Promise<string> {
  etat.regime.value = regime
  definirPage({ section, titre: '', parent: null })
  const app = createSSRApp({ render: () => h(BarreNavigation) })
  app.component('RouterLink', RouterLinkBouchon)
  return renderToString(app)
}

afterEach(() => reinitialiserEntete())

describe('la barre du bas', () => {
  it('porte quatre entrées, dans l’ordre : Accueil, Arbre, Explorer, Moi', async () => {
    const html = await rendre()
    const vers = [...html.matchAll(/data-vers="([^"]+)"/g)].map((m) => m[1])

    expect(vers).toEqual(['accueil', 'arbre', 'explorer', 'moi'])
    for (const libelle of ['Accueil', 'Arbre', 'Explorer', 'Moi']) {
      expect(html).toMatch(new RegExp(`<span>${libelle}</span>`))
    }
  })

  it('les porte à un compte en attente comme à un membre', async () => {
    const html = await rendre('en_attente')

    expect((html.match(/data-vers=/g) ?? []).length).toBe(4)
  })

  it('marque l’entrée de la section courante, et elle seule', async () => {
    for (const section of ['accueil', 'arbre', 'explorer', 'moi'] as const) {
      const html = await rendre('membre', section)
      const actifs = [...html.matchAll(/<a[^>]*aria-current="page"[^>]*>/g)].map((m) => m[0])

      expect(actifs, section).toHaveLength(1)
      expect(actifs[0], section).toContain(`data-vers="${section}"`)
      expect(actifs[0], section).toContain('text-accent')
    }
  })

  it('n’allume rien sur un écran hors des quatre sections', async () => {
    expect(await rendre('membre', 'autre')).not.toContain('aria-current')
  })

  it('montre les initiales du compte sur Moi', async () => {
    const html = await rendre()

    expect(html).toMatch(/>CR<\/span>/)
  })

  it('n’existe pas pour un visiteur anonyme', async () => {
    const html = await rendre('anonyme')

    expect(html).not.toContain('<nav')
    expect(html).not.toContain('data-vers')
  })

  it('se cache sur écran large et laisse la place au trait d’accueil de l’iPhone', async () => {
    const html = await rendre()
    const classes = (/<nav[^>]*class="([^"]*)"/.exec(html)?.[1] ?? '').split(/\s+/)

    expect(classes).toContain('md:hidden')
    expect(classes).toContain('fixed')
    expect(classes).toContain('bottom-0')
    expect(classes).toContain('pb-[env(safe-area-inset-bottom)]')
  })

  it('s’efface sous le feuillet des filtres, sans quitter la page', async () => {
    // Le feuillet de `BarreEcran` vit sous la barre (contexte `z-20` contre
    // `z-30`) : ouvert, il passerait dessous. `invisible` et non `v-if` : la
    // hauteur publiée ne change pas, la carte ne se redimensionne pas.
    const classesDeNav = (html: string): string[] => (/<nav[^>]*class="([^"]*)"/.exec(html)?.[1] ?? '').split(/\s+/)
    const { basculerTiroir, fermerTiroirs } = useTiroirs()

    expect(classesDeNav(await rendre())).not.toContain('invisible')
    basculerTiroir('outils')
    try {
      const html = await rendre()
      expect(html).toContain('<nav')
      expect(classesDeNav(html)).toContain('invisible')
    } finally {
      fermerTiroirs()
    }
  })

  it('ne rend pas de pastille avant d’avoir compté la file (rien n’est inventé)', async () => {
    // Le compte part au montage, qui n'a pas lieu en SSR : la pastille ne
    // paraît qu'une fois le total connu.
    expect(await rendre('administrateur')).not.toContain('pastille-moi')
  })
})
