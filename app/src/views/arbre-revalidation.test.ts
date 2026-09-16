/**
 * `ArbreView` — les deux défauts qui vidaient ou faisaient déborder l'écran de
 * l'arbre. Deux défauts sans rapport, un seul fichier fautif, donc une seule
 * suite.
 *
 * ────────────────────────────────────────────────────────────────────
 * **Ce qui peut être prouvé ici, et ce qui ne le peut pas.**
 *
 * Le projet n'a ni `jsdom`, ni `happy-dom`, ni `@vue/test-utils`, et il n'en
 * prendra pas pour ce seul fichier — même arbitrage que
 * `components/arbre/canevas.test.ts`. Sous l'environnement `node` de Vitest, les
 * `.vue` sont d'ailleurs compilés pour le **rendu serveur** : le composant porte
 * un `ssrRender` et aucune fonction de rendu client, si bien qu'un montage
 * ordinaire ne rendrait rien et que `onMounted` ne s'exécuterait jamais.
 * L'idiome du dépôt — `createSSRApp` + `renderToString`, voir
 * `personne/fiche-rendu.test.ts` — est donc le seul rendu disponible.
 *
 * D'où la forme de ce fichier :
 *
 *  - les deux **décisions** en cause sont exportées par `ArbreView.vue` et
 *    testées telles quelles, avec les vrais modules d'API derrière ;
 *  - la seule **règle de gabarit** en cause — le rognage du bloc qui porte le
 *    panneau — est lue sur le balisage rendu.
 *
 * Ce fichier ne prouve **rien de la peinture** : ni que le rognage supprime bien
 * les 347 px de débordement mesurés, ni qu'un panneau ouvert reste entier dans
 * son cadre, ni que `onMounted` appelle ces fonctions-là. Cela se mesure dans un
 * navigateur, et cela a été mesuré à part.
 * ────────────────────────────────────────────────────────────────────
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createSSRApp, defineComponent, h } from 'vue'
import { renderToString } from 'vue/server-renderer'
import { chargerGraphe, oublierEtag, type Graphe, type NoeudPersonne } from '@/lib/api'
import ArbreView, { grapheApresRevalidation, hauteurDuCadre } from './ArbreView.vue'

/* ==================================================================== */
/* Le serveur factice — `GET /api/arbre/graphe` et son ETag             */
/* ==================================================================== */

/**
 * `revalidation.ts` n'est **pas** remplacé par un double, et c'est le point :
 * c'est sa mémoire d'`ETag`, de portée **module**, qui cause le premier défaut.
 * La simuler rendrait le test aveugle à ce qu'il doit voir. On descend donc d'un
 * cran, jusqu'au transport, et `appelBrut` répond comme le serveur : `200` +
 * `ETag` quand il n'y a pas d'`If-None-Match`, `304` quand il y en a un qui
 * correspond.
 */

const ETAG = '"9f1c0e5b2d7a4836bb10cc93ee42a17d5480f6c3a9b2d1e7f0a3c6b95d84e2f1"'

interface AppelObserve {
  chemin: string
  ifNoneMatch: string | undefined
  statut: number
}

/** Le journal du serveur factice : une entrée par appel reçu. */
let appels: AppelObserve[] = []

function reponseDuServeur(
  chemin: string,
  options: { entetes?: Record<string, string> },
): Promise<{ statut: number; entetes: Headers; corps: unknown }> {
  const ifNoneMatch = options.entetes?.['If-None-Match']
  const statut = ifNoneMatch === ETAG ? 304 : 200
  appels.push({ chemin, ifNoneMatch, statut })

  if (statut === 304) {
    return Promise.resolve({ statut, entetes: new Headers(), corps: null })
  }
  return Promise.resolve({
    statut,
    entetes: new Headers({ ETag: ETAG }),
    corps: grapheFactice(),
  })
}

vi.mock('@/lib/api/client', async (importOriginal) => {
  const reel = await importOriginal<Record<string, unknown>>()
  return {
    ...reel,
    appelBrut: (chemin: string, options: { entetes?: Record<string, string> } = {}) =>
      reponseDuServeur(chemin, options),
  }
})

/* Aucune donnée réelle n'entre dans le dépôt (§ 8) : ces deux personnes sont
   inventées, sur le patron des jeux d'essai des autres suites. */
function grapheFactice(): Graphe {
  const personne = (id: string, personId: number, nom: string): NoeudPersonne => ({
    type: 'personne',
    id,
    personId,
    x: 0,
    y: 0,
    w: 120,
    h: 40,
    displayName: nom,
    etiquetteNom: nom,
    sex: 'feminin',
    generation: 0,
    branch: 'tr',
    vivant: false,
    visibilite: 'complete',
  })
  return {
    empreinte: '0'.repeat(64),
    ego: 'p1',
    boite: { x: 0, y: 0, w: 100, h: 100 },
    calque: { actif: false, demandes: [] },
    noeuds: [personne('p1', 1, 'Amarante Sureau'), personne('p2', 2, 'Basalte Romarin')],
    aretes: [],
  }
}

beforeEach(() => {
  appels = []
  // La mémoire des ETag est de portée module : sans cela, un test hériterait de
  // l'ETag du précédent — ce qui est exactement le piège qu'on teste.
  oublierEtag()
})

/* ==================================================================== */
/* Défaut n° 1 — l'arbre disparaît quand on y revient                   */
/* ==================================================================== */

/**
 * Le montage d'une vue, réduit à ce qui compte ici : un graphe affiché qui naît
 * `null`, une revalidation, et un démontage qui emporte le graphe — mais **pas**
 * la mémoire des `ETag`, qui vit aussi longtemps que l'onglet.
 */
function vueArbre() {
  let affiche: Graphe | null = null
  return {
    async revalider(): Promise<void> {
      affiche = await grapheApresRevalidation(affiche, chargerGraphe)
    },
    demonter(): void {
      affiche = null
    },
    get grapheAffiche(): Graphe | null {
      return affiche
    },
  }
}

describe('la revalidation au montage, quand la vue n’a plus de graphe', () => {
  it('obtient l’arbre au premier montage : rien à opposer, donc un 200', async () => {
    const vue = vueArbre()
    await vue.revalider()

    expect(appels).toEqual([
      { chemin: '/arbre/graphe', ifNoneMatch: undefined, statut: 200 },
    ])
    expect(vue.grapheAffiche).not.toBeNull()
  })

  it('obtient encore l’arbre au second montage, ETag mémorisé ou non', async () => {
    /*
     * LE DÉFAUT, ET SA MÉCANIQUE EXACTE.
     *
     * La mémoire des `ETag` est de portée **module** : elle survit au démontage
     * de la vue. `graphe`, lui, est de portée **composant** : il revient à `null`
     * à chaque montage. Au second montage, la vue opposait donc un
     * `If-None-Match` au nom d'un SVG qu'elle n'avait plus, récoltait un `304`,
     * et gardait `null` — sur quoi `v-show="graphe !== null"` posait
     * `display: none`, la légende (`v-if`) n'était pas rendue, et comme
     * `erreurGraphe` était nul et `chargementGraphe` retombé à faux, il ne
     * restait ni squelette ni message : un écran vide sans plainte.
     *
     * La règle du contrat « `304` ⇒ garder son SVG en place » n'est pas en
     * cause : c'est sa précondition — « il en a un » — qui était fausse à tout
     * montage après le premier.
     */
    const premier = vueArbre()
    await premier.revalider()
    expect(premier.grapheAffiche).not.toBeNull()
    premier.demonter()

    const second = vueArbre()
    await second.revalider()

    expect(second.grapheAffiche).not.toBeNull()
  })

  it('n’oppose pas un ETag qu’elle ne peut pas honorer : le second appel est un 200', async () => {
    /* La même chose vue du réseau, parce que c'est là que se prend la décision :
       la seule façon d'obtenir un graphe quand on n'en a plus est de ne pas
       prétendre au serveur qu'on l'a déjà. */
    const premier = vueArbre()
    await premier.revalider()
    premier.demonter()

    const second = vueArbre()
    await second.revalider()

    expect(appels).toHaveLength(2)
    expect(appels[1]?.ifNoneMatch).toBeUndefined()
    expect(appels[1]?.statut).toBe(200)
  })

  it('tient sur trois allers-retours, pas seulement sur le deuxième', async () => {
    /* Le défaut était définitif à partir du second montage : une correction qui
       ne vaudrait qu'une fois se verrait ici. */
    for (let passage = 0; passage < 3; passage += 1) {
      const vue = vueArbre()
      await vue.revalider()
      expect(vue.grapheAffiche).not.toBeNull()
      vue.demonter()
    }
    expect(appels).toHaveLength(3)
    expect(appels.map((appel) => appel.statut)).toEqual([200, 200, 200])
  })
})

describe('l’ETag garde son emploi là où il en a un', () => {
  it('l’oppose au retour d’onglet, parce que le SVG est bien en place', async () => {
    /*
     * LE GARDE-FOU CONTRE LE SUR-CORRECTIF. Oublier l'`ETag` à chaque appel
     * ferait passer ce test au rouge. La revalidation du retour d'onglet
     * (`visibilitychange`) existe pour coûter un `304` et rien de plus ; elle
     * n'a de sens que parce que le canevas, là, n'a pas bougé. La correction ne
     * doit toucher qu'un seul cas : « je n'ai rien à garder en place ».
     */
    const vue = vueArbre()
    await vue.revalider()
    expect(appels).toHaveLength(1)

    await vue.revalider() // retour d'onglet : la vue a toujours son graphe

    expect(appels).toHaveLength(2)
    expect(appels[1]?.ifNoneMatch).toBe(ETAG)
    expect(appels[1]?.statut).toBe(304)
  })

  it('ne remplace pas le graphe affiché sur un 304 — « garder son SVG en place »', async () => {
    const vue = vueArbre()
    await vue.revalider()
    const avant = vue.grapheAffiche

    await vue.revalider()

    expect(appels[1]?.statut).toBe(304)
    // La même instance, pas une copie : un `nouveau` déclencherait la
    // reconstruction complète du canevas par la surveillance de la prop.
    expect(vue.grapheAffiche).toBe(avant)
  })

  it('garde le graphe affiché quand l’appel échoue', async () => {
    const vue = vueArbre()
    await vue.revalider()
    const avant = vue.grapheAffiche

    // `useAppelApi` rend `null` sur échec comme sur appel supplanté.
    const apres = await grapheApresRevalidation(avant, () => Promise.resolve(null))

    expect(apres).toBe(avant)
  })
})


/* ==================================================================== */
/* Défaut n° 2 — le débordement horizontal et le défilement vertical    */
/* ==================================================================== */

/**
 * Deux défauts distincts dans le même fichier, et trois termes de cause. Ce
 * qu'on isole ici, un terme à la fois, c'est ce que la vue **décide** : quelle
 * hauteur elle pose, et quel bloc rogne son débordement. Ce que produit
 * réellement la peinture — 347 px de page en trop, 13 px de défilement
 * vertical — a été mesuré dans un navigateur, et ne peut pas l'être ici.
 */

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

describe('la hauteur posée sur le cadre de l’arbre', () => {
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

/* ------------------------------------------------------------------ */
/* Le rognage du bloc qui porte le panneau                             */
/* ------------------------------------------------------------------ */

/**
 * Un scanner de balises minuscule — le pendant du « DOM minuscule » de
 * `components/arbre/canevas.test.ts`. Il ne sait qu'une chose, et c'est la seule
 * dont ce test ait besoin : **quel élément contient directement celui-ci**.
 */
const SANS_FERMETURE = new Set([
  'area',
  'base',
  'br',
  'col',
  'embed',
  'hr',
  'img',
  'input',
  'link',
  'meta',
  'param',
  'source',
  'track',
  'wbr',
])

function classesDuParentDe(html: string, balise: string): string | null {
  const sansCommentaires = html.replace(/<!--[\s\S]*?-->/g, '')
  const balises = /<(\/?)([a-zA-Z][a-zA-Z0-9-]*)((?:"[^"]*"|'[^']*'|[^>])*)>/g
  const pile: string[] = []

  for (const trouve of sansCommentaires.matchAll(balises)) {
    const nom = (trouve[2] ?? '').toLowerCase()
    const attributs = trouve[3] ?? ''

    if (trouve[1] === '/') {
      pile.pop()
      continue
    }
    if (nom === balise) {
      const parent = pile[pile.length - 1]
      if (parent === undefined) return null
      return /class="([^"]*)"/.exec(parent)?.[1] ?? ''
    }
    if (SANS_FERMETURE.has(nom) || attributs.trimEnd().endsWith('/')) continue
    pile.push(attributs)
  }
  return null
}

/** Le panneau porte deux `RouterLink`, qui ne se résolvent pas hors d'une
 *  application routée. Même remplacement que `personne/fiche-rendu.test.ts`. */
const LienFactice = defineComponent({
  name: 'RouterLink',
  props: { to: { type: [String, Object], default: '' } },
  setup: (_, { slots }) => () => h('a', { href: '#' }, slots['default']?.()),
})

async function rendreLaVue(): Promise<string> {
  const app = createSSRApp(ArbreView)
  app.component('RouterLink', LienFactice)
  return renderToString(app)
}

describe('le bloc qui porte le panneau de fiche', () => {
  it('rogne son débordement, sinon le panneau fermé élargit la page', async () => {
    /*
     * LE DÉFAUT. `PanneauFiche` est `absolute inset-y-0 right-0 w-[340px]`, mis
     * hors cadre par `translate-x-[102%]`. Fermé, il reste **peint et mesuré** :
     * `inert` et `aria-hidden` le retirent du clavier et du lecteur d'écran,
     * pas de la mise en page. Aucun ancêtre jusqu'à `<html>` ne masquait ce
     * débordement — `CanevasArbre` a bien son `overflow-hidden`, mais il est le
     * FRÈRE du panneau, pas son parent, et ne le rogne donc jamais.
     *
     * Mesuré dans Chrome : 347 px de page en trop — 1,02 × la largeur du
     * panneau — à sept largeurs de fenêtre, soit +89 % sur un écran de 400 px.
     * Et cette barre de défilement horizontale prenait à son tour 12 px de
     * hauteur, ce qui est le terme (2a) ci-dessus.
     */
    const html = await rendreLaVue()
    const classes = classesDuParentDe(html, 'aside')

    expect(classes).not.toBeNull()
    expect(classes).toMatch(/\boverflow-(hidden|clip)\b/)
  })

  it('rogne sans devenir lui-même défilable — `clip`, pas `hidden`', async () => {
    /*
     * Les deux suppriment le débordement de la page ; ils ne laissent pas le
     * même bloc derrière eux. `overflow-hidden` en fait un **conteneur de
     * défilement** : il garde 347 px de course cachée, sans barre pour la
     * montrer, qu'un `scrollIntoView`, un saut d'ancre ou une prise de focus
     * dans la partie rognée suffirait à parcourir — l'arbre glisserait de côté
     * et le lecteur n'aurait aucun moyen de le ramener.
     *
     * Mesuré sur le bloc, à 1524 px, contre la feuille compilée du produit :
     * `scrollLeft = 300` est honoré sous `hidden` (rendu 300), refusé sous
     * `clip` (rendu 0). Les deux donnent bien `pageX = 0`.
     */
    const classes = classesDuParentDe(await rendreLaVue(), 'aside') ?? ''

    expect(classes).toMatch(/\boverflow-clip\b/)
  })

  it('reste le bloc conteneur du panneau : il rogne, il ne déplace pas', async () => {
    /* `relative` est ce qui fait de ce bloc la référence de `right-0`. Rogner
       ailleurs, ou perdre `relative` en le faisant, recadrerait le panneau sur
       un ancêtre plus large — et le débordement reviendrait par la fenêtre. */
    const classes = classesDuParentDe(await rendreLaVue(), 'aside') ?? ''

    expect(classes).toMatch(/\brelative\b/)
    expect(classes).toMatch(/\bflex-1\b/)
    expect(classes).toMatch(/\bmin-h-0\b/)
  })

  it('n’ampute pas un panneau ouvert : il se termine au bord du bloc', async () => {
    /*
     * LE PIÈGE. Ce qui supprime le débordement ne doit pas empêcher le panneau
     * de s'ouvrir. La condition est structurelle, et c'est elle qu'on tient
     * ici : le panneau est calé sur le bord DROIT du bloc qu'on rogne
     * (`right-0`), donc ouvert (`translate-x-0`) il s'arrête exactement à cette
     * frontière — mesuré dans Chrome, `aside.right == zone.clientWidth`, à
     * 1524 px comme à 388 px. Un panneau calé à gauche, ou plus large que son
     * bloc, serait amputé par le rognage.
     *
     * Et il doit continuer de défiler **à l'intérieur** : `overflow-y-auto` lui
     * appartient, le rognage du parent ne le lui retire pas.
     */
    const aside = /<aside class="([^"]*)"/.exec(await rendreLaVue())?.[1] ?? ''

    expect(aside).toMatch(/\bright-0\b/)
    expect(aside).toMatch(/\binset-y-0\b/)
    expect(aside).toMatch(/\boverflow-y-auto\b/)
    // Pas de `\b` après un crochet fermant : il n'y a pas de frontière de mot
    // entre `]` et l'espace qui suit, et l'assertion ne se déclencherait jamais.
    expect(aside).toContain('max-w-[88vw]')
  })
})
