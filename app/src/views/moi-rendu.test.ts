import { createSSRApp, h } from 'vue'
import { renderToString } from 'vue/server-renderer'
import { describe, expect, it, vi } from 'vitest'
import MoiView from './MoiView.vue'

/**
 * « Moi » (`/moi`), rendu côté serveur.
 *
 * C'est désormais la **seule** porte d'entrée de Mes demandes, des événements (tirages au sort compris), des
 * actes demandés, des questions et de l'administration : si une entrée
 * disparaissait d'ici, la capacité entière deviendrait introuvable — l'écran
 * resterait juste, la route continuerait de répondre, et personne ne le
 * verrait. Ces cas tiennent donc la liste, régime par régime.
 */

const etat = vi.hoisted(() => ({
  regime: null as unknown as { value: string },
  demandes: [] as number[],
}))

vi.mock('@/composables/useSession', async () => {
  const { computed: c, shallowRef: sr } = await import('vue')
  etat.regime = sr('membre')
  return {
    deconnecter: () => Promise.resolve(),
    useSession: () => ({
      session: c(() => ({ utilisateur: { email: 'cobalt@exemple.test' }, demandesEnAttente: etat.demandes })),
      regime: etat.regime,
      vueEmpruntee: c(() => null),
      connecte: c(() => etat.regime.value !== 'anonyme'),
      rattache: c(() => etat.regime.value === 'membre' || etat.regime.value === 'administrateur'),
      administrateur: c(() => etat.regime.value === 'administrateur'),
      nomAffiche: c(() => 'Cobalt Romarin'),
    }),
  }
})

vi.mock('@/lib/api', () => ({
  listerDemandesAdministration: () => Promise.resolve({ total: 0 }),
  listerRattachements: () => Promise.resolve({ elements: [] }),
  listerDemandesRgpd: () => Promise.resolve({ elements: [] }),
  listerFilsAdministration: () => Promise.resolve({ elements: [], total: 0, nonLus: 0 }),
}))

vi.mock('vue-router', () => ({
  useRouter: () => ({ push: () => Promise.resolve() }),
}))

const RouterLinkBouchon = {
  props: { to: { type: [String, Object], required: false, default: '' } },
  template: '<a href="#" :data-vers="typeof to === \'object\' ? to.name : to"><slot /></a>',
}

function rendre(regime: string, forme?: 'page' | 'menu'): Promise<string> {
  etat.regime.value = regime
  const app = createSSRApp({ render: () => h(MoiView, forme ? { forme } : {}) })
  app.component('RouterLink', RouterLinkBouchon)
  return renderToString(app)
}

const vers = (html: string): string[] => [...html.matchAll(/data-vers="([^"]+)"/g)].map((m) => m[1] ?? '')

describe('Moi, selon le régime', () => {
  it('montre qui lit : nom, rôle, courriel, initiales', async () => {
    const html = await rendre('membre')

    expect(html).toContain('Cobalt Romarin')
    expect(html).toContain('Membre')
    expect(html).toContain('cobalt@exemple.test')
    expect(html).toMatch(/>CR<\/span>/)
  })

  it('donne à un membre son compte, les quatre entrées de contribution, l’aide et la messagerie', async () => {
    expect(vers(await rendre('membre'))).toEqual(['compte', 'demandes.miennes', 'evenements', 'demarches', 'questions', 'aide', 'messagerie'])
  })

  it('ne donne à un compte en attente que son inscription, son compte, la messagerie — et la déconnexion', async () => {
    // L'API lui refuserait le reste, et la garde de route le renverrait vers
    // l'écran d'attente : une entrée qui mène à un refus est pire qu'absente.
    // Son écran d'attente, lui, est en tête (ADR 0003, révision du 01/10/2026).
    // La messagerie ne lit rien de l'arbre : c'est souvent l'attente qui fait
    // écrire à l'administration.
    const html = await rendre('en_attente')

    expect(vers(html)).toEqual(['inscription.etat', 'compte', 'messagerie'])
    expect(html).toContain('Mon inscription')
    expect(html).toContain('En attente de validation')
    expect(html).toContain('Se déconnecter')
  })

  it('ajoute l’administration, une seule entrée, pour un administrateur', async () => {
    const html = await rendre('administrateur')

    expect(vers(html)).toEqual([
      'compte',
      'demandes.miennes',
      'evenements',
      'demarches',
      'questions',
      'aide',
      'messagerie',
      'administration.a-traiter',
    ])
    expect(html).not.toContain('Voir comme un membre')
    expect(html).toContain('Administrateur')
  })

  it('n’affiche l’administration à aucun membre', async () => {
    const html = await rendre('membre')

    expect(html).not.toContain('Administration')
    expect(html).not.toContain('Voir comme un membre')
  })

  it('compte les demandes du lecteur encore en attente', async () => {
    etat.demandes = [11, 12, 13]
    const html = await rendre('membre')
    etat.demandes = []

    expect(html).toMatch(/Mes demandes<\/span><span[^>]*><span[^>]*>3<\/span>/)
  })

  it('offre des lignes tactiles de 48 px au moins, en forme page', async () => {
    const html = await rendre('membre')
    const lignes = [...html.matchAll(/<a[^>]*?\sclass="([^"]*)"/g)].map((m) => m[1] ?? '')

    expect(lignes.length).toBeGreaterThan(0)
    for (const l of lignes) expect(l.split(/\s+/)).toContain('min-h-12')
    expect(html).toMatch(/<button[^>]*min-h-12[^>]*>\s*Se déconnecter/)
  })

  it('porte la ligne « Notifications sur ce téléphone », en forme page seulement', async () => {
    // Le push est un réglage de l'appareil : il vit ici, pas dans le menu de
    // l'avatar du bureau.
    expect(await rendre('en_attente')).toContain('Notifications sur ce téléphone')
    expect(await rendre('membre', 'menu')).not.toContain('Notifications sur ce téléphone')
  })

  it('a une forme « menu » plus serrée, pour l’avatar du bureau, sans titre de page', async () => {
    const html = await rendre('membre', 'menu')

    expect(html).not.toContain('<h1')
    expect(html).not.toContain('min-h-12')
    expect(vers(html)).toEqual(['compte', 'demandes.miennes', 'evenements', 'demarches', 'questions', 'aide', 'messagerie'])
  })
})
