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
 * Il en sort donc, et se fixe en bas de l'écran.
 *
 * ── Deux tentatives, et ce que la seconde a appris ─────────────────────────────
 *
 * **La première** posait la destination dans le flux de la colonne (`order-last`)
 * dont la hauteur est mesurée : elle prenait de la hauteur au canevas au lieu de
 * flotter au-dessus, et l'arbre rétrécissait. Elle est `fixed`, et ces cas le
 * tiennent encore.
 *
 * **La seconde** employait `<Teleport>` : le champ restait déclaré dans la barre,
 * et Vue le déplaçait vers le pied de page quand la largeur passait sous le seuil.
 * Le raisonnement tenait — un tiroir fermé est en `display: none`, et le CSS seul
 * ne fait pas sortir un enfant d'un ancêtre qui ne s'affiche pas. Le résultat non :
 * en production, sur la largeur même qu'il visait, le déplacement levait
 * `Cannot read properties of null (reading 'emitsOptions')`, une erreur interne de
 * Vue pendant le patch. Le rendu de cette branche mourait là — le champ restait
 * dans le tiroir **et** le bouton « Filtres » n'ouvrait plus rien. Un seul défaut,
 * deux symptômes, et aucun test node ne pouvait le voir : il fallait un navigateur.
 *
 * Ce que le téléportage avait de fragile tient en une phrase : **déplacer un
 * sous-arbre vivant, après le montage, à travers une frontière `display: none`**.
 * Rien de cela n'était nécessaire. Deux `v-if` mutuellement exclusifs montent le
 * champ là où il doit être et nulle part ailleurs.
 *
 * Ces cas gardent donc la trace des deux : la destination reste `fixed`, et plus
 * rien n'est téléporté.
 * ────────────────────────────────────────────────────────────────────
 */
import { describe, expect, it } from 'vitest'
import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'

const lire = (chemin: string): string =>
  readFileSync(fileURLToPath(new URL(chemin, import.meta.url)), 'utf8')

/**
 * Le texte **sans les commentaires** — même précaution que le balayage du nom
 * d'usage dans `administration/diff.test.ts`. Ces cas portent sur ce qui est
 * *rendu* ; un commentaire qui explique pourquoi `<Teleport>` a été retiré doit
 * pouvoir le nommer sans faire rougir la garde qui l'interdit.
 */
const sansCommentaires = (source: string): string =>
  source
    .replace(/\/\*[\s\S]*?\*\//g, ' ')
    .replace(/<!--[\s\S]*?-->/g, ' ')
    .replace(/(^|[^:])\/\/.*$/gm, '$1')

const vue = lire('./ArbreView.vue')
const barre = lire('../components/arbre/BarreOutilsArbre.vue')
const champ = lire('../components/arbre/ChampRechercheArbre.vue')
const composable = lire('../composables/useTiroirs.ts')

const vueNu = sansCommentaires(vue)
const barreNu = sansCommentaires(barre)
const champNu = sansCommentaires(champ)

/** Les classes du pied de page fixe, en jetons. */
function classesDuPiedDePage(): string[] {
  const bloc = /id="recherche-arbre-mobile"\s*\n\s*class="([^"]*)"/.exec(vue)?.[1] ?? ''
  return bloc.split(/\s+/).filter((jeton) => jeton.length > 0)
}

describe('le champ de recherche sur téléphone', () => {
  it('a un pied de page à lui, hors du tiroir', () => {
    expect(classesDuPiedDePage().length).toBeGreaterThan(0)
  })

  it('flotte au-dessus du canevas au lieu de lui prendre de la hauteur', () => {
    /*
     * LE PREMIER DÉFAUT. Dans le flux de la colonne — `order-last` —, cette barre
     * rétrécissait l'arbre : la hauteur du cadre est **mesurée**, et tout ce qui
     * partage la colonne la réduit d'autant. `fixed` la sort du flux.
     */
    const jetons = classesDuPiedDePage()

    expect(jetons).toContain('fixed')
    expect(jetons).toContain('bottom-0')
    expect(jetons).not.toContain('order-last')
  })

  it('n’existe pas au-delà du seuil : au large, le champ reste dans la barre', () => {
    expect(classesDuPiedDePage()).toContain('md:hidden')
    expect(vue).toMatch(/v-if="estMobile"\s*\n\s*id="recherche-arbre-mobile"/)
  })

  it('laisse passer la fiche plein écran par-dessus lui', () => {
    /* Les deux portent `z-30`. C'est l'ordre du document qui tranche, et le pied
       de page est déclaré **avant** le bloc du canevas — donc avant le panneau de
       fiche, qui gagne. */
    const pied = vue.indexOf('id="recherche-arbre-mobile"')
    const canevas = vue.indexOf('relative min-h-0 flex-1 overflow-clip')

    expect(pied).toBeGreaterThan(-1)
    expect(canevas).toBeGreaterThan(-1)
    expect(pied).toBeLessThan(canevas)
  })
})

describe('le champ n’est monté qu’une fois', () => {
  it('n’a qu’une définition, et un seul `id` dans tout le produit', () => {
    /*
     * Deux champs montés auraient deux valeurs, deux fois le même `id` — ce
     * qu'aucun document n'autorise — et le raccourci `/` ne saurait plus lequel
     * viser. Les deux emplacements partagent **un** composant, et leurs `v-if`
     * sont complémentaires.
     */
    expect((champNu.match(/id="arbre-recherche"/g) ?? []).length).toBe(1)
    expect(barreNu).not.toContain('id="arbre-recherche"')
    expect(vueNu).not.toContain('id="arbre-recherche"')
  })

  it('est monté par la barre au large, et par la vue sur téléphone', () => {
    expect(barre).toMatch(/<ChampRechercheArbre\s*\n\s*v-if="!estMobile"/)
    expect(vue).toContain('<ChampRechercheArbre')
    expect(vue).toContain(':compact="true"')
    expect(barre).toContain(':compact="false"')
  })

  it('ne téléporte plus rien', () => {
    /*
     * LA GARDE. C'est le déplacement lui-même qui cassait le rendu de Vue, sur la
     * largeur même qu'il visait. Le réintroduire ramènerait les deux symptômes —
     * dont un, le tiroir de filtres bloqué, n'a aucun rapport apparent avec la
     * recherche.
     */
    for (const [nom, source] of [
      ['ArbreView', vueNu],
      ['BarreOutilsArbre', barreNu],
      ['ChampRechercheArbre', champNu],
    ] as const) {
      expect(source, nom).not.toMatch(/<Teleport/)
    }
  })
})

describe('le seuil est connu dès le premier rendu', () => {
  it('ne se lit pas dans `onMounted`', () => {
    /*
     * LE DÉFAUT DE FOND. Lu au montage, le drapeau valait `false` au premier
     * rendu **même sur un écran étroit**, et un second rendu le corrigeait : la
     * structure changeait de forme juste après le montage, pour rien. C'est cette
     * bascule qui cassait le téléportage.
     *
     * `matchMedia` est une propriété de la fenêtre et n'attend pas le DOM : on la
     * lit à la construction. Le garde `typeof … !== 'function'` couvre le rendu
     * SSR, où il n'y a pas de fenêtre.
     */
    const corps = /export function useEstMobile[\s\S]*$/.exec(composable)?.[0] ?? ''

    expect(corps).toContain("typeof globalThis.matchMedia === 'function'")
    expect(corps).toMatch(/const estMobile = ref\(liste\?\.matches \?\? false\)/)
  })

  it('rend `false` hors navigateur : un rendu SSR donne la forme large', () => {
    expect(composable).toContain('Readonly<Ref<boolean>>')
    expect(/export function useEstMobile[\s\S]*$/.exec(composable)?.[0]).toContain('?? false')
  })
})

describe('ce qui reste dans le tiroir', () => {
  it('garde les filtres documentaires et la case « suivre la recherche »', () => {
    /* La demande était explicite : le champ sort, le reste ne bouge pas. */
    expect(barre).toContain('Suivre la recherche')
    expect(barre).toContain("emit('filtre', f.id)")
    expect(barre).toContain('Tout afficher')
  })

  it('n’annonce pas deux fois le décompte à un lecteur d’écran', () => {
    /* `aria-live` est porté par le champ, qui n'est monté qu'une fois. Un second
       exemplaire dans la barre annoncerait « 12 fiches » en double. */
    expect((barreNu.match(/aria-live/g) ?? []).length).toBe(0)
    expect((champNu.match(/aria-live/g) ?? []).length).toBe(2)
  })
})
