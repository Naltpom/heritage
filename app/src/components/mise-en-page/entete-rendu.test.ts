import { createSSRApp, h } from 'vue'
import { renderToString } from 'vue/server-renderer'
import { afterEach, describe, expect, it, vi } from 'vitest'
import EnTeteApplication from './EnTeteApplication.vue'
import { annoncerBarreDOutils, reinitialiserTiroirs, useTiroirs } from '@/composables/useTiroirs'
import { definirPage, reinitialiserEntete } from '@/composables/entete'

/**
 * L'en-tête après la refonte de la navigation (30/09/2026).
 *
 * ────────────────────────────────────────────────────────────────────
 * **CE QUE CES TESTS PROTÈGENT.** La règle de la refonte : *chaque chose a une
 * seule porte d'entrée*. L'en-tête portait « Mes demandes » trois fois (barre,
 * tiroir, menu du compte), « Validation » deux fois, et « + Proposer » ; le
 * tiroir du téléphone recopiait la navigation. Tout cela est parti — les
 * demandes et l'administration dans Moi, la navigation du téléphone dans la
 * barre du bas, « Proposer » dans l'assistant. Rien n'avertirait si un lien en
 * double revenait « pour dépanner » : d'où ces cas.
 *
 * Remplace `entete-creation.test.ts` et `entete-mobile.test.ts`, qui tenaient le
 * tiroir et « Proposer une personne » dans l'en-tête — précisément ce que la
 * refonte retire.
 * ────────────────────────────────────────────────────────────────────
 */

const etat = vi.hoisted(() => ({
  regime: null as unknown as { value: string },
}))

vi.mock('@/composables/useSession', async () => {
  const { computed: c, shallowRef: sr } = await import('vue')
  etat.regime = sr('membre')
  return {
    deconnecter: () => Promise.resolve(),
    useSession: () => ({
      session: c(() => ({ utilisateur: { email: 'cobalt@exemple.test' }, demandesEnAttente: [3, 4] })),
      regime: etat.regime,
      vueEmpruntee: c(() => null),
      connecte: c(() => etat.regime.value !== 'anonyme'),
      rattache: c(() => etat.regime.value === 'membre' || etat.regime.value === 'administrateur'),
      administrateur: c(() => etat.regime.value === 'administrateur'),
      nomAffiche: c(() => 'Cobalt Romarin'),
    }),
  }
})

// La cloche est construite par un autre chantier : on la remplace par un
// marqueur, pour vérifier qu'elle est montée sans dépendre de ce qu'elle rend.
vi.mock('@/components/notifications/ClocheNotifications.vue', () => ({
  default: { name: 'ClocheNotifications', render: () => h('span', { 'data-test': 'cloche' }) },
}))

vi.mock('@/lib/api', () => ({
  listerDemandesAdministration: () => Promise.resolve({ total: 0 }),
  rechercherPersonnes: () => Promise.resolve({ elements: [], tronque: false }),
}))

// **`useRouter` seulement** : un `useRoute()` dans l'en-tête ferait lever tous
// ces cas. C'est pour cela que la section active vient de `entete.ts`.
vi.mock('vue-router', () => ({
  useRouter: () => ({ push: () => Promise.resolve(), back: () => {} }),
}))

const RouterLinkBouchon = {
  props: { to: { type: [String, Object], required: false, default: '' } },
  template: '<a href="#" :data-vers="typeof to === \'object\' ? to.name : to"><slot /></a>',
}

function rendre(regime = 'membre'): Promise<string> {
  etat.regime.value = regime
  const app = createSSRApp({ render: () => h(EnTeteApplication) })
  app.component('RouterLink', RouterLinkBouchon)
  return renderToString(app)
}

/** Le nombre de liens rendus vers une route nommée. */
function liensVers(html: string, nom: string): number {
  return (html.match(new RegExp(`data-vers="${nom.replace('.', '\\.')}"`, 'g')) ?? []).length
}

afterEach(() => {
  reinitialiserTiroirs()
  reinitialiserEntete()
})

describe('plus de tiroir sur téléphone', () => {
  it('n’a plus de burger ni de panneau latéral', async () => {
    const html = await rendre()

    expect(html).not.toContain('menu-principal')
    expect(html).not.toContain('Ouvrir le menu')
    expect(html).not.toContain('bg-black/40')
  })

  it('ne rend aucun bouton à un visiteur anonyme — ni loupe, ni cloche, ni avatar', async () => {
    /* L'assertion porte sur l'élément, pas sur le texte : un commentaire de
       gabarit suffisait jadis à faire mentir un test écrit sur la chaîne. */
    const html = await rendre('anonyme')

    expect(html).not.toMatch(/<button/)
    expect(html).not.toContain('data-test="cloche"')
    expect(html).toMatch(/>\s*Heritage\s*</)
  })
})

describe('une seule porte d’entrée', () => {
  it('ne porte plus « Mes demandes », « Validation » ni « Proposer »', async () => {
    for (const regime of ['membre', 'administrateur']) {
      const html = await rendre(regime)
      expect(html, regime).not.toContain('Mes demandes')
      expect(html, regime).not.toContain('Validation')
      expect(html, regime).not.toContain('Proposer')
      expect(liensVers(html, 'demandes.miennes'), regime).toBe(0)
      expect(liensVers(html, 'administration.demandes'), regime).toBe(0)
      expect(liensVers(html, 'proposition.creation'), regime).toBe(0)
    }
  })

  it('ne rend chaque onglet qu’une fois : Accueil, Arbre, Explorer', async () => {
    const html = await rendre()

    // La marque « Heritage » n'est pas un lien : l'onglet Accueil l'est déjà.
    expect(liensVers(html, 'accueil')).toBe(1)
    expect(liensVers(html, 'arbre')).toBe(1)
    expect(liensVers(html, 'explorer')).toBe(1)
    expect((html.match(/aria-label="Navigation principale"/g) ?? []).length).toBe(1)
  })

  it('n’a plus d’onglet par écran d’exploration : ils sont dans Explorer', async () => {
    const html = await rendre()

    expect(html).not.toMatch(/>\s*Chronologie\s*</)
    expect(html).not.toMatch(/>\s*Carte\s*</)
    expect(html).not.toMatch(/>\s*Anniversaires\s*</)
  })

  it('allume l’onglet de la section courante', async () => {
    definirPage({ section: 'explorer', titre: 'Chronologie', parent: null })
    const html = await rendre()
    const actif = /<a[^>]*aria-current="page"[^>]*>/.exec(html)?.[0] ?? ''

    expect(actif).toContain('data-vers="explorer"')
    expect(actif).toContain('border-accent')
  })
})

describe('un compte en attente (ADR 0003, révision du 01/10/2026)', () => {
  // Il ne lit plus rien de l'arbre avant sa validation : une entrée qui le
  // mènerait à un refus est pire qu'absente.
  it('n’a qu’un onglet, son inscription — ni Accueil, ni Arbre, ni Explorer', async () => {
    const html = await rendre('en_attente')

    expect(liensVers(html, 'inscription.etat')).toBe(1)
    expect(html).toContain('Mon inscription')
    for (const nom of ['accueil', 'arbre', 'explorer']) {
      expect(liensVers(html, nom), nom).toBe(0)
    }
  })

  it('n’a ni recherche ni loupe : elles interrogent l’arbre', async () => {
    const html = await rendre('en_attente')

    expect(html).not.toContain('type="search"')
    expect(html).not.toContain('id="recherche-entete"')
    expect(html).not.toContain('Rechercher une personne')
  })

  it('garde la cloche et l’avatar : ses notifications et son compte sont à lui', async () => {
    const html = await rendre('en_attente')

    expect(html).toContain('data-test="cloche"')
    expect(html).toContain('aria-label="Moi"')
  })
})

describe('ce que l’en-tête garde', () => {
  it('monte la cloche pour un compte connecté, y compris en attente', async () => {
    expect(await rendre('membre')).toContain('data-test="cloche"')
    expect(await rendre('en_attente')).toContain('data-test="cloche"')
  })

  it('garde une seule recherche, repliée derrière la loupe sur téléphone', async () => {
    const html = await rendre()

    expect((html.match(/type="search"/g) ?? []).length).toBe(1)
    const zone = /<div id="recherche-entete"[^>]*class="([^"]*)"/.exec(html)?.[1] ?? ''
    expect(zone.split(/\s+/)).toContain('md:contents')
    expect(zone.split(/\s+/)).toContain('max-md:hidden')
    expect(html).toContain('aria-controls="recherche-entete"')
  })

  it('met la marque, les onglets, la recherche, la cloche puis l’avatar dans cet ordre', async () => {
    const html = await rendre()
    const positions = [
      html.search(/>\s*Heritage\s*</),
      html.indexOf('aria-label="Navigation principale"'),
      html.indexOf('type="search"'),
      html.indexOf('data-test="cloche"'),
      html.indexOf('aria-label="Moi"'),
    ]

    expect(positions.every((p) => p >= 0)).toBe(true)
    expect([...positions].sort((a, b) => a - b)).toEqual(positions)
  })

  it('montre « ‹ parent » et le titre sur un écran de détail', async () => {
    definirPage({ section: 'arbre', titre: 'Jeanne Martin', parent: { nom: 'arbre', libelle: 'Arbre' } })
    const html = await rendre()

    expect(html).toMatch(/‹<\/span>\s*Arbre/)
    expect(html).toContain('Jeanne Martin')
  })

  it('ouvre, dans le menu de l’avatar, le contenu de Moi', async () => {
    const { basculerTiroir } = useTiroirs()
    basculerTiroir('compte')
    const html = await rendre('administrateur')
    const menu = html.slice(html.indexOf('id="menu-compte"'))

    expect(menu).toContain('Mon compte')
    expect(menu).toContain('Mes demandes')
    expect(menu).toContain('Administration')
    expect(menu).toContain('Se déconnecter')
  })

  it('n’ouvre pas le menu de l’avatar tant qu’on ne l’a pas demandé', async () => {
    const html = await rendre()

    expect(html).not.toContain('id="menu-compte"')
    expect(html).toContain('aria-controls="menu-compte"')
  })
})

describe('le bouton filtres', () => {
  it('ne paraît que lorsqu’un écran a déclaré une barre d’outils', async () => {
    expect(await rendre()).not.toContain('aria-controls="tiroir-outils"')

    annoncerBarreDOutils()

    expect(await rendre()).toContain('aria-controls="tiroir-outils"')
  })

  it('n’est pas proposé à un anonyme, même si une barre est déclarée', async () => {
    annoncerBarreDOutils()

    expect(await rendre('anonyme')).not.toMatch(/<button/)
  })

  it('se tient au bout de la barre, après le titre', async () => {
    annoncerBarreDOutils()
    const html = await rendre()

    expect(html.indexOf('titre-ecran')).toBeLessThan(html.indexOf('aria-controls="tiroir-outils"'))
  })
})

describe('l’état des tiroirs', () => {
  it('ne connaît qu’un tiroir ouvert à la fois', () => {
    const { ouvert, basculerTiroir } = useTiroirs()

    basculerTiroir('compte')
    expect(ouvert.value).toBe('compte')

    basculerTiroir('outils')
    expect(ouvert.value).toBe('outils')
  })

  it('se remet à zéro entre deux cas', () => {
    const { ouvert, basculerTiroir } = useTiroirs()
    basculerTiroir('compte')

    reinitialiserTiroirs()

    expect(ouvert.value).toBe('aucun')
  })
})
