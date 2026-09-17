import { createSSRApp, h } from 'vue'
import { renderToString } from 'vue/server-renderer'
import { afterEach, describe, expect, it } from 'vitest'
import TiroirOutils from './TiroirOutils.vue'
import { reinitialiserTiroirs, useTiroirs } from '@/composables/useTiroirs'

/**
 * Le tiroir des filtres.
 *
 * ────────────────────────────────────────────────────────────────────
 * **LE CAS LE PLUS IMPORTANT DE CE FICHIER EST CELUI SUR `<aside>`.**
 *
 * `arbre-revalidation.test.ts` localise le bloc qui rogne le panneau de fiche
 * en cherchant le parent du **premier `<aside>` dans l'ordre du document**, et
 * `PanneauFiche` se trouve être le seul `<aside>` de toute l'application — ce
 * qui n'est écrit nulle part et que personne n'irait vérifier avant d'en
 * ajouter un.
 *
 * Un tiroir rendu en `<aside>` se serait glissé **avant** le panneau dans
 * l'arbre de l'écran. Trois de ces quatre cas auraient alors mesuré les classes
 * d'un autre bloc — en continuant de passer, ou d'échouer, pour une raison qui
 * n'aurait plus rien à voir avec ce qu'ils décrivent. C'est exactement le genre
 * de panne muette que cette maison fait tenir par un test.
 * ────────────────────────────────────────────────────────────────────
 */

function rendre(ouvrir = false): Promise<string> {
  if (ouvrir) useTiroirs().basculerTiroir('outils')
  const app = createSSRApp({
    render: () =>
      h(TiroirOutils, { titre: 'Filtres' }, { default: () => h('div', { class: 'barre' }, 'ici') }),
  })
  return renderToString(app)
}

afterEach(() => {
  reinitialiserTiroirs()
})

describe('le tiroir des outils', () => {
  it('n’est pas un `aside` — l’écran de l’arbre n’en compte qu’un, et c’est la fiche', async () => {
    expect(await rendre()).not.toContain('<aside')
  })

  it('laisse sa barre exactement où elle était au-delà du seuil', async () => {
    /* `md:contents` : au-delà de 48rem le conteneur ne génère aucune boîte, et la
       barre redevient enfant flex direct de la vue — donc l'écran large rend le
       même arbre de boîtes qu'avant l'existence de ce composant. */
    const html = await rendre()
    const conteneur = /<div id="tiroir-outils"[^>]*class="([^"]*)"/.exec(html)?.[1] ?? ''

    expect(conteneur.split(/\s+/)).toContain('md:contents')
  })

  it('rend la barre qu’on lui confie, une seule fois', async () => {
    const html = await rendre()

    expect((html.match(/class="barre"/g) ?? []).length).toBe(1)
  })

  it('cache son panneau au-delà du seuil quand il est fermé', async () => {
    const html = await rendre()
    const conteneur = /<div id="tiroir-outils"[^>]*class="([^"]*)"/.exec(html)?.[1] ?? ''

    expect(conteneur.split(/\s+/)).toContain('max-md:hidden')
  })

  it('ne peint aucun voile tant qu’il est fermé', async () => {
    /* Un voile monté en permanence recouvrirait la page sur grand écran, où il
       n'a rien à faire — et `md:hidden` seul ne suffit pas à le rendre gratuit :
       il resterait dans l'arbre. On ne le monte qu'à l'ouverture. */
    expect(await rendre()).not.toContain('bg-black/40')
  })

  it('peint un voile refermable dès qu’il s’ouvre', async () => {
    const html = await rendre(true)

    expect(html).toContain('bg-black/40')
  })

  it('retire « max-md:hidden » à l’ouverture', async () => {
    const html = await rendre(true)
    const conteneur = /<div id="tiroir-outils"[^>]*class="([^"]*)"/.exec(html)?.[1] ?? ''

    expect(conteneur.split(/\s+/)).not.toContain('max-md:hidden')
  })

  it('porte l’identifiant que le bouton de l’en-tête désigne', async () => {
    /* `aria-controls` traverse la frontière des composants : l'attribut est un
       IDREF, de portée document. C'est ce qui permet au déclencheur de vivre
       dans l'en-tête, à l'autre bout de la barre, sans plomberie. */
    expect(await rendre()).toContain('id="tiroir-outils"')
  })

  it('annonce son titre au lecteur d’écran', async () => {
    expect(await rendre()).toContain('aria-label="Filtres"')
  })
})
