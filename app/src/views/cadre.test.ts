/**
 * `hauteurDuCadre()` — la hauteur qu'un écran à canevas pose sur son cadre.
 *
 * Ces cinq cas viennent d'`arbre-revalidation.test.ts`, où ils tenaient l'un des
 * deux défauts d'`ArbreView`. Ils déménagent avec la fonction, pour la raison
 * qui l'a fait déménager : la chronologie gardait une **seconde** copie de la
 * formule, qui portait encore les trois défauts que ceux-ci décrivent, et
 * qu'aucun test ne regardait. Une règle tenue à un endroit et recopiée à un
 * autre n'est pas tenue.
 *
 * Ce qu'on isole ici, un terme à la fois, c'est ce que la vue **décide**. Ce que
 * produit réellement la peinture — 347 px de page en trop, 13 px de défilement
 * vertical — a été mesuré dans un navigateur, et ne peut pas l'être ici.
 */
import { afterEach, describe, expect, it } from 'vitest'
import { hauteurDuCadre } from './cadre'

/** Les deux hauteurs du navigateur, qui ne sont pas la même chose :
 *  `window.innerHeight` **inclut** la bande d'une barre de défilement
 *  horizontale, `document.documentElement.clientHeight` l'exclut. Douze pixels
 *  d'écart, mesurés dans Chrome à 1,25 dpr. */
function poserFenetre(innerHeight: number, clientHeight: number): void {
  Object.defineProperty(globalThis, 'window', {
    value: { innerHeight },
    configurable: true,
    writable: true,
  })
  Object.defineProperty(globalThis, 'document', {
    value: { documentElement: { clientHeight } },
    configurable: true,
    writable: true,
  })
}

afterEach(() => {
  Reflect.deleteProperty(globalThis, 'window')
  Reflect.deleteProperty(globalThis, 'document')
})

describe('la hauteur posée sur le cadre d’un canevas', () => {
  it('se mesure sur la hauteur de mise en page, pas sur celle de la fenêtre', () => {
    /*
     * TERME (2a). `window.innerHeight` compte la bande de la barre de défilement
     * horizontale ; `clientHeight` ne la compte pas. Le cadre était donc posé
     * 12 px trop bas, ce qui faisait apparaître une barre verticale sur une page
     * qui n'avait rien de plus à montrer.
     *
     * Les deux nombres sont choisis pour que l'arrondi ne puisse pas expliquer
     * l'écart : seule la source lue distingue 638 de 650.
     */
    poserFenetre(700, 688)
    expect(hauteurDuCadre(50)).toBe('638px')
  })

  it('arrondit vers le bas : un en-tête ne tombe pas sur un pixel entier', () => {
    /*
     * TERME (2a bis). Le haut du cadre est fractionnaire — 54,40 px pour un
     * en-tête à 1,25 dpr. `Math.round(617,6)` rendait 618, soit 0,40 px de trop,
     * et 0,40 px de trop font un pixel de défilement. Ici les deux hauteurs sont
     * égales : seul le sens de l'arrondi distingue 617 de 618.
     */
    poserFenetre(672, 672)
    expect(hauteurDuCadre(54.4)).toBe('617px')
  })

  it('n’impose aucun plancher : sur un téléphone, il n’y a pas 320 px à donner', () => {
    /*
     * TERME (2b), indépendant du précédent et dominant en fenêtre courte.
     * `Math.max(320, …)` garantissait mathématiquement le débordement dès que la
     * place restante tombait sous 320 px — mesuré : 164 px de trop en 400×348,
     * où l'en-tête applicatif s'enroule sur quatre rangs et prend 192 px.
     *
     * Le plancher ne peut pas coexister avec « jamais de défilement vertical de
     * page ». La garantie anti-effondrement qu'il prétendait donner est déjà
     * portée par la chaîne flex (`min-h-0` / `flex-1`) du gabarit.
     */
    poserFenetre(348, 348)
    expect(hauteurDuCadre(192)).toBe('156px')
  })

  it('ne rend jamais une hauteur négative quand il ne reste rien', () => {
    poserFenetre(200, 200)
    expect(hauteurDuCadre(260)).toBe('0px')
  })

  it('cumule les deux termes dans la fenêtre large où le défaut a été mesuré', () => {
    /* L'état livré : 1524×684, barre horizontale présente (donc clientHeight
       672), en-tête à 54,40. L'ancienne formule posait 630px, et le bas du cadre
       tombait à 684,40 pour 672 de place — 13 px de défilement vertical. */
    poserFenetre(684, 672)
    expect(hauteurDuCadre(54.4)).toBe('617px')
  })
})

describe('aucun écran ne refait la formule dans son coin', () => {
  /*
   * LE DÉFAUT QUE CELUI-CI TIENT. `ChronologieView` portait sa propre mesure —
   * `Math.max(320, Math.round(window.innerHeight - haut))` — c'est-à-dire les
   * trois défauts ci-dessus réunis, six mois après qu'ArbreView les eut corrigés
   * et documentés. Personne ne l'a vu, parce qu'aucun test ne lisait ce
   * fichier-là.
   *
   * On lit donc le source des vues à canevas. C'est grossier, et c'est exprès :
   * un test qui appellerait la fonction ne dirait rien d'un écran qui ne
   * l'appelle pas. Le `?raw` est déjà l'idiome de la maison
   * (`entete-creation.test.ts`).
   */
  const VUES = [
    'ArbreView.vue',
    'ChronologieView.vue',
    'CarteView.vue',
    'AnniversairesView.vue',
  ] as const

  it('ne laisse aucun plancher de 320 px revenir dans une vue à canevas', async () => {
    for (const vue of VUES) {
      const source = await sourceDe(vue)
      if (source === null) continue
      expect(source, vue).not.toContain('Math.max(320')
    }
  })

  it('ne laisse aucune vue relire `window.innerHeight` pour se mesurer', async () => {
    for (const vue of VUES) {
      const source = await sourceDe(vue)
      if (source === null) continue
      expect(source, vue).not.toContain('window.innerHeight')
    }
  })
})

/** `null` quand l'écran n'existe pas encore : la carte et les anniversaires
 *  arrivent dans un lot séparé, et une liste qui attend un fichier absent
 *  ferait échouer la suite pour une raison qui n'a rien à voir. */
async function sourceDe(vue: string): Promise<string | null> {
  try {
    const module = (await import(`./${vue}?raw`)) as { default: string }
    return module.default
  } catch {
    return null
  }
}
