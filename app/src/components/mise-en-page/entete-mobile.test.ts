import { createSSRApp, h } from 'vue'
import { renderToString } from 'vue/server-renderer'
import { afterEach, describe, expect, it, vi } from 'vitest'
import EnTeteApplication from './EnTeteApplication.vue'
import { annoncerBarreDOutils, reinitialiserTiroirs, useTiroirs } from '@/composables/useTiroirs'

/**
 * L'en-tête sur téléphone.
 *
 * ────────────────────────────────────────────────────────────────────
 * **CE QUE CES TESTS PROTÈGENT.** Sur un écran de 400 px, cette barre
 * s'enroulait sur quatre rangs et prenait 192 px — mesuré, et c'est la moitié du
 * terme qui faisait déborder l'arbre. Les entrées passent donc dans un tiroir.
 *
 * Le danger de cette transformation n'est pas le mobile : c'est le **grand
 * écran**, qui ne doit pas bouger d'un pixel et que personne ne regarde en
 * écrivant du code mobile. La garantie est structurelle — toute déclaration
 * mobile s'écrit `max-md:`, qui compile en `@media (width < 48rem)` et ne peut
 * donc pas s'appliquer au-delà — et `md:contents` efface le conteneur du tiroir
 * de la mise en page dès le seuil franchi, si bien que ses enfants redeviennent
 * enfants directs de l'en-tête, dans le même ordre.
 *
 * Ces cas tiennent ce qu'un rendu SSR peut tenir : la présence, l'ordre et les
 * attributs. Le reste — que 768 px rende les mêmes pixels — est tenu par la
 * comparaison des deux feuilles compilées, décrite dans le plan.
 * ────────────────────────────────────────────────────────────────────
 */

const etat = vi.hoisted(() => ({
  regime: null as unknown as { value: string },
  vueEmpruntee: null as unknown as { value: { memberId: number; memberLabel: string } | null },
}))

vi.mock('@/composables/useSession', async () => {
  const { computed: c, shallowRef: sr } = await import('vue')
  etat.regime = sr('membre')
  etat.vueEmpruntee = sr(null)
  return {
    deconnecter: () => Promise.resolve(),
    useSession: () => ({
      regime: etat.regime,
      vueEmpruntee: etat.vueEmpruntee,
      connecte: c(() => etat.regime.value !== 'anonyme'),
      rattache: c(() => etat.regime.value === 'membre' || etat.regime.value === 'administrateur'),
      administrateur: c(() => etat.regime.value === 'administrateur'),
      nomAffiche: c(() => 'Cobalt Romarin'),
    }),
  }
})

// Le même bouchon que `entete-creation.test.ts` : **`useRouter` seulement**. Un
// `useRoute()` dans l'en-tête ferait lever les dix cas de ce fichier-là, et les
// neuf d'ici. C'est pour cela que la fermeture des tiroirs à la navigation vit
// dans `router/index.ts`.
vi.mock('vue-router', () => ({
  useRouter: () => ({ push: () => Promise.resolve() }),
}))

const RouterLinkBouchon = {
  props: { to: { type: [String, Object], required: false, default: '' } },
  template: '<a href="#"><slot /></a>',
}

function rendre(regime = 'membre'): Promise<string> {
  etat.regime.value = regime
  etat.vueEmpruntee.value = null
  const app = createSSRApp({ render: () => h(EnTeteApplication) })
  app.component('RouterLink', RouterLinkBouchon)
  return renderToString(app)
}

afterEach(() => {
  reinitialiserTiroirs()
})

describe('le bouton burger', () => {
  it('n’existe pas pour un visiteur anonyme — il n’aurait rien à ouvrir', async () => {
    /*
     * LE PIÈGE. `entete-creation.test.ts` vérifie qu'un anonyme ne reçoit AUCUN
     * `<button>`, et l'assertion porte sur l'élément et non sur le texte —
     * précisément parce qu'une assertion sur le texte avait déjà menti. Un
     * burger inconditionnel la ferait tomber. Et elle a raison sur le fond : la
     * navigation d'un en-tête anonyme est vide.
     */
    expect(await rendre('anonyme')).not.toMatch(/<button/)
  })

  it('annonce l’état du menu et désigne le panneau qu’il commande', async () => {
    const html = await rendre()

    expect(html).toContain('aria-controls="menu-principal"')
    expect(html).toContain('aria-expanded="false"')
    expect(html).toContain('aria-label="Ouvrir le menu"')
  })

  it('disparaît au-delà du seuil', async () => {
    /* `md:hidden` : le burger n'existe visuellement que sous 48rem, et l'en-tête
       large retrouve exactement ses enfants d'avant. */
    const html = await rendre()
    const burger = /<button[^>]*aria-controls="menu-principal"[^>]*>/.exec(html)?.[0] ?? ''

    expect(burger.split(/\s+/)).toContain('md:hidden')
  })
})

describe('le menu lui-même', () => {
  it('est le même balisage aux deux largeurs : « md:contents » l’efface de la mise en page', async () => {
    /*
     * C'est la pièce maîtresse. Au-delà du seuil, ce conteneur ne génère aucune
     * boîte : la navigation, l'espaceur, « Proposer une personne », le créneau
     * d'actions et le bloc du compte redeviennent enfants flex directs de
     * l'en-tête, dans le même ordre et avec les mêmes gouttières.
     */
    const html = await rendre()
    const conteneur = /<div id="menu-principal"[^>]*class="([^"]*)"/.exec(html)?.[1] ?? ''

    expect(conteneur.split(/\s+/)).toContain('md:contents')
  })

  it('ne rend la navigation qu’une fois — jamais deux jeux de liens', async () => {
    /* Un tiroir bâti en dupliquant la navigation donnerait deux fois les mêmes
       liens dans l'arbre d'accessibilité, et deux « Arbre » à la recherche
       clavier. Le tiroir DÉPLACE, il ne recopie pas. */
    const html = await rendre()

    expect((html.match(/aria-label="Navigation principale"/g) ?? []).length).toBe(1)
    // Le bouchon de `RouterLink` rend `<a href="#"> Chronologie </a>` : le
    // libellé est entouré d'espaces, et une correspondance collée ne trouve rien.
    expect((html.match(/>\s*Chronologie\s*</g) ?? []).length).toBe(1)
  })

  it('sort du flux, du clavier et du lecteur d’écran quand il est fermé', async () => {
    /*
     * `max-md:hidden` vaut `display: none` : retiré de la mise en page, du
     * parcours clavier ET de l'arbre d'accessibilité — et **borné au point de
     * rupture**. C'est pour cela qu'on n'emploie pas `inert`, qui n'a pas de
     * variante de largeur et resterait posé sur grand écran.
     */
    const html = await rendre()
    const conteneur = /<div id="menu-principal"[^>]*class="([^"]*)"/.exec(html)?.[1] ?? ''

    expect(conteneur.split(/\s+/)).toContain('max-md:hidden')
  })

  it('ne peint aucun voile tant qu’il est fermé', async () => {
    expect(await rendre()).not.toContain('bg-black/40')
  })

  it('porte les deux nouveaux écrans dans la même navigation que les anciens', async () => {
    const html = await rendre()

    expect(html).toMatch(/>\s*Carte\s*</)
    expect(html).toMatch(/>\s*Anniversaires\s*</)
  })

  it('garde l’ordre exact des enfants de l’en-tête au-delà du seuil', async () => {
    /* Aplati par `md:contents`, l'ordre doit être celui d'avant : la marque, le
       filet, la navigation, l'espaceur, la contribution, puis le compte. */
    const html = await rendre()
    const positions = [
      html.search(/>\s*Heritage\s*</),
      html.indexOf('aria-label="Navigation principale"'),
      html.indexOf('Proposer une personne'),
      html.indexOf('Cobalt Romarin'),
      html.indexOf('Se déconnecter'),
    ]

    expect(positions.every((p) => p >= 0)).toBe(true)
    expect([...positions].sort((a, b) => a - b)).toEqual(positions)
  })
})

describe('le second bouton, celui des outils', () => {
  it('ne paraît que lorsqu’un écran a déclaré une barre d’outils', async () => {
    /* Un bouton de filtres sur un écran qui n'en a pas ouvrirait un tiroir vide.
       C'est `TiroirOutils` qui s'annonce en se montant, et l'en-tête suit. */
    expect(await rendre()).not.toContain('aria-controls="tiroir-outils"')

    annoncerBarreDOutils()

    expect(await rendre()).toContain('aria-controls="tiroir-outils"')
  })

  it('n’est pas proposé à un anonyme, même si une barre est déclarée', async () => {
    annoncerBarreDOutils()

    expect(await rendre('anonyme')).not.toMatch(/<button/)
  })

  it('se tient à l’autre bout de la barre que le burger', async () => {
    /* Les deux tiroirs s'ouvrent de deux côtés opposés ; leurs boutons doivent
       en dire autant. Entre les deux, un espaceur qui n'existe que sur mobile. */
    annoncerBarreDOutils()
    const html = await rendre()

    const burger = html.indexOf('aria-controls="menu-principal"')
    const espaceur = html.indexOf('flex-1 md:hidden')
    const outils = html.indexOf('aria-controls="tiroir-outils"')

    expect(burger).toBeLessThan(espaceur)
    expect(espaceur).toBeLessThan(outils)
  })
})

describe('l’état des tiroirs', () => {
  it('ne connaît qu’un tiroir ouvert à la fois, vu depuis un composant', () => {
    const { ouvert, basculerTiroir } = useTiroirs()

    basculerTiroir('menu')
    expect(ouvert.value).toBe('menu')

    basculerTiroir('outils')
    expect(ouvert.value).toBe('outils')
  })

  it('se remet à zéro entre deux cas, comme la mémoire des ETag', () => {
    const { ouvert, basculerTiroir } = useTiroirs()
    basculerTiroir('menu')

    reinitialiserTiroirs()

    expect(ouvert.value).toBe('aucun')
  })
})
