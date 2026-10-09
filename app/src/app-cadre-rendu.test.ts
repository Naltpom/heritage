import { createSSRApp, h } from 'vue'
import { renderToString } from 'vue/server-renderer'
import { describe, expect, it, vi } from 'vitest'
import App from './App.vue'

/**
 * Le cadre de l'application : la barre du bas vit avec l'en-tête.
 *
 * Refonte du 30/09/2026. La barre de navigation du bas est montée dans le même
 * bloc `v-if="avecCadre"` que l'en-tête : une page de connexion n'a ni l'un ni
 * l'autre. Et le `<main>` lui réserve sa hauteur — sans quoi le bas de chaque
 * page passerait sous une barre `fixed`.
 *
 * Les enfants du cadre sont remplacés par des marqueurs : ce qu'ils rendent est
 * tenu par leurs propres suites ; ici, on ne vérifie que leur présence.
 */

const etat = vi.hoisted(() => ({ cadre: true }))

vi.mock('vue-router', () => ({
  useRoute: () => ({ meta: etat.cadre ? {} : { cadre: false } }),
  useRouter: () => ({ push: () => Promise.resolve() }),
}))

function marqueur(nom: string) {
  return { default: { name: nom, render: () => h('i', { 'data-marqueur': nom }) } }
}

/** Le marqueur d'une invitation automatique : il dit aussi le cadre reçu. */
function invitation(nom: string) {
  return {
    default: {
      name: nom,
      props: { avecCadre: { type: Boolean, required: true } },
      render(this: { avecCadre: boolean }) {
        return h('i', { 'data-invitation': nom, 'data-avec-cadre': String(this.avecCadre) })
      },
    },
  }
}

vi.mock('@/components/mise-en-page/EnTeteApplication.vue', () => marqueur('entete'))
vi.mock('@/components/mise-en-page/BarreNavigation.vue', () => marqueur('barre-bas'))
vi.mock('@/components/mise-en-page/BandeauPortrait.vue', () => marqueur('portrait'))
vi.mock('@/components/mise-en-page/BandeauRegime.vue', () => marqueur('regime'))
vi.mock('@/components/administration/QuitterIncarnation.vue', () => marqueur('incarnation'))
vi.mock('@/components/administration/QuitterVueEmpruntee.vue', () => marqueur('vue-empruntee'))
vi.mock('@/components/cles-d-acces/InvitationCleDAcces.vue', () => invitation('cle-d-acces'))
vi.mock('@/components/mise-en-page/InvitationInstallation.vue', () => invitation('installation'))
vi.mock('@/components/notifications/InvitationNotifications.vue', () => invitation('notifications'))

function rendre(cadre: boolean): Promise<string> {
  etat.cadre = cadre
  const app = createSSRApp({ render: () => h(App) })
  app.component('RouterView', { render: () => h('p', 'écran') })
  return renderToString(app)
}

describe('la barre du bas dans le cadre', () => {
  it('est montée avec l’en-tête sur un écran avec cadre', async () => {
    const html = await rendre(true)

    expect(html).toContain('data-marqueur="entete"')
    expect(html).toContain('data-marqueur="barre-bas"')
  })

  it('est absente, comme l’en-tête, d’un écran sans cadre (connexion, inscription)', async () => {
    const html = await rendre(false)

    expect(html).not.toContain('data-marqueur="entete"')
    expect(html).not.toContain('data-marqueur="barre-bas"')
    expect(html).toContain('écran')
  })

  it('fait réserver au contenu la hauteur que la barre publie', async () => {
    const html = await rendre(true)
    const main = /<main id="contenu"[^>]*class="([^"]*)"/.exec(html)?.[1] ?? ''

    expect(main.split(/\s+/)).toContain('pb-[var(--hauteur-barre-bas,0px)]')
  })
})

/**
 * Les fenêtres qui s'ouvrent d'elles-mêmes à l'arrivée. Montées **hors** du
 * bloc du cadre, qui ne leur passe que `avec-cadre` : la connexion est un écran
 * sans cadre, et c'est en la quittant qu'elles doivent pouvoir naître. Leur
 * ordre de passage est tenu par `composables/fenetresAutomatiques.ts` ; ici, on
 * tient qu'elles sont toutes là — l'installation comprise, qui était un
 * bandeau sous l'en-tête.
 */
describe('les invitations automatiques', () => {
  it.each([true, false])('sont montées hors du cadre, qu’il y en ait un ou non (cadre : %s)', async (cadre) => {
    const html = await rendre(cadre)
    const ordre = [...html.matchAll(/data-invitation="([^"]+)"/g)].map((m) => m[1])

    expect(ordre).toEqual(['cle-d-acces', 'installation', 'notifications'])
    for (const m of html.matchAll(/data-avec-cadre="([^"]+)"/g)) expect(m[1]).toBe(String(cadre))
  })

  it('ne laissent plus de bandeau d’installation dans le cadre', async () => {
    const html = await rendre(true)
    const cadre = html.slice(0, html.indexOf('<main'))

    expect(cadre).not.toContain('installation')
    expect(cadre).not.toContain('Installez Heritage')
  })
})
