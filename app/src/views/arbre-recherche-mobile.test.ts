/**
 * Le champ de recherche de l'arbre, et l'endroit où il vit.
 *
 * ────────────────────────────────────────────────────────────────────
 * **CE QUE CES CAS TIENNENT, ET POURQUOI ILS EXISTENT.**
 *
 * Sur téléphone, la barre d'outils passe dans un tiroir. Le champ de recherche y
 * était parti avec le reste — et c'était une erreur : chercher est le geste qu'on
 * répète, filtrer sur « vérifiées » se fait une fois. Il fallait ouvrir un tiroir
 * pour taper trois lettres.
 *
 * Il en sort donc, et se fixe en bas de l'écran. Le CSS seul ne pouvait pas l'en
 * faire sortir : un tiroir fermé est en `display: none`, et rien n'échappe à un
 * ancêtre qui ne s'affiche pas. Il faut déplacer l'élément dans le DOM, ce que
 * fait `<Teleport>`.
 *
 * **La première tentative a cassé la mise en page**, et ces cas en gardent la
 * trace : la destination était un élément du flux, `order-last`, dans la colonne
 * à hauteur mesurée. Elle prenait donc de la hauteur au canevas au lieu de
 * flotter au-dessus — l'arbre rétrécissait. Elle est `fixed`.
 * ────────────────────────────────────────────────────────────────────
 */
import { describe, expect, it } from 'vitest'
import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'

const vue = readFileSync(fileURLToPath(new URL('./ArbreView.vue', import.meta.url)), 'utf8')
const barre = readFileSync(
  fileURLToPath(new URL('../components/arbre/BarreOutilsArbre.vue', import.meta.url)),
  'utf8',
)

/** Les classes de la destination du téléportage, en jetons. */
function classesDeLaDestination(): string[] {
  const bloc = /id="recherche-arbre-mobile"\s*\n\s*class="([^"]*)"/.exec(vue)?.[1] ?? ''
  return bloc.split(/\s+/).filter((jeton) => jeton.length > 0)
}

describe('le champ de recherche sur téléphone', () => {
  it('a une destination hors du tiroir, et elle existe', () => {
    expect(classesDeLaDestination().length).toBeGreaterThan(0)
  })

  it('flotte au-dessus du canevas au lieu de lui prendre de la hauteur', () => {
    /*
     * LE DÉFAUT. Dans le flux de la colonne — `order-last` —, cette barre
     * rétrécissait l'arbre : la hauteur du cadre est **mesurée**, et tout ce qui
     * partage la colonne la réduit d'autant. `fixed` la sort du flux.
     */
    const jetons = classesDeLaDestination()

    expect(jetons).toContain('fixed')
    expect(jetons).toContain('bottom-0')
    expect(jetons).not.toContain('order-last')
  })

  it('n’existe pas au-delà du seuil : au large, le champ reste dans la barre', () => {
    expect(classesDeLaDestination()).toContain('md:hidden')
  })

  it('ne montre pas un filet vide avant que le champ n’y arrive', () => {
    /* La destination porte la bordure et le fond. Vide — au premier rendu, et sur
       grand écran — elle dessinerait un trait en bas de l'écran sans rien dedans. */
    expect(classesDeLaDestination()).toContain('empty:hidden')
  })

  it('laisse passer la fiche plein écran par-dessus elle', () => {
    /* Les deux portent `z-30`. C'est l'ordre du document qui tranche, et la
       destination est déclarée **avant** le bloc du canevas — donc avant le
       panneau de fiche, qui gagne. */
    const destination = vue.indexOf('id="recherche-arbre-mobile"')
    const canevas = vue.indexOf('relative min-h-0 flex-1 overflow-clip')

    expect(destination).toBeGreaterThan(-1)
    expect(canevas).toBeGreaterThan(-1)
    expect(destination).toBeLessThan(canevas)
  })
})

describe('le champ n’est rendu qu’une fois', () => {
  it('n’existe qu’un seul `arbre-recherche` dans la barre d’outils', () => {
    /*
     * Deux champs auraient deux valeurs, deux fois le même `id` — ce qu'aucun
     * document n'autorise — et `focusRecherche()`, donc le raccourci `/`, ne
     * saurait plus lequel viser. Le téléportage **déplace** ; il ne recopie pas.
     */
    expect((barre.match(/id="arbre-recherche"/g) ?? []).length).toBe(1)
    expect((barre.match(/ref="champ"/g) ?? []).length).toBe(1)
  })

  it('est téléporté, et seulement sur téléphone', () => {
    expect(barre).toContain('<Teleport to="#recherche-arbre-mobile"')
    expect(barre).toContain(':disabled="!estMobile"')
  })
})

describe('ce qui reste dans le tiroir', () => {
  it('garde les filtres documentaires et la case « suivre la recherche »', () => {
    /* La demande était explicite : le champ sort, le reste ne bouge pas. */
    expect(barre).toContain('Suivre la recherche')
    expect(barre).toContain("emit('filtre', f.id)")
    expect(barre).toContain('Tout afficher')
  })

  it('ne laisse pas le champ dans le tiroir sur téléphone', () => {
    /* L'input doit se trouver **dans** le bloc téléporté, pas à côté. */
    const debut = barre.indexOf('<Teleport to="#recherche-arbre-mobile"')
    const fin = barre.indexOf('</Teleport>')
    const dedans = barre.slice(debut, fin)

    expect(debut).toBeGreaterThan(-1)
    expect(fin).toBeGreaterThan(debut)
    expect(dedans).toContain('id="arbre-recherche"')
  })
})
