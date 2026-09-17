/**
 * L'écran des anniversaires — ce qui se prouve sans navigateur.
 *
 * ────────────────────────────────────────────────────────────────────
 * **CE QUI PEUT ÊTRE PROUVÉ ICI, ET CE QUI NE LE PEUT PAS.**
 *
 * Le projet n'a ni `jsdom`, ni `happy-dom`, ni `@vue/test-utils`, et il n'en
 * prendra pas pour ce fichier — même arbitrage qu'`arbre-revalidation.test.ts`.
 * Sous l'environnement `node` de Vitest, les `.vue` sont compilés pour le
 * **rendu serveur** : `createSSRApp` + `renderToString` est le seul rendu
 * disponible, et `onMounted` ne s'exécute jamais.
 *
 * Cela oriente la forme du fichier :
 *
 *  - les composants qui reçoivent tout par leurs propriétés — la grille, une
 *    case, le panneau, la barre — sont rendus tels quels, avec de vraies
 *    données, et l'on lit le balisage ;
 *  - la seule **décision** de la vue qu'aucun rendu ne peut atteindre — que
 *    faire d'une erreur quand on a déjà une charge — est exportée par
 *    `AnniversairesView.vue` et appelée directement ;
 *  - la vue entière est rendue une fois, pour ce qu'elle seule peut prouver :
 *    qu'aucune interface de navigateur n'est touchée pendant `setup()`.
 *
 * Ce fichier ne prouve **rien de la peinture** : ni qu'une case fait 44 px à
 * 340 px de large, ni qu'un panneau flottant couvre la grille. Cela se mesure
 * dans un navigateur.
 *
 * Les noms de ce jeu d'essai sont inventés. La base porte l'arbre réel du
 * commanditaire, et une suite de tests n'est pas un endroit où en recopier des
 * lignes.
 * ────────────────────────────────────────────────────────────────────
 */
import { describe, expect, it } from 'vitest'
import { createSSRApp, defineComponent, h, type Component } from 'vue'
import { renderToString } from 'vue/server-renderer'
import type {
  EvenementAnniversaire,
  GenreEvenement,
  ResumeAnniversaires,
  SujetEvenement,
} from '@/lib/api'
import { grilleDuMois } from '@/components/anniversaires/calendrier'
import { parJour } from '@/components/anniversaires/regroupement'
import BarreOutilsAnniversaires from '@/components/anniversaires/BarreOutilsAnniversaires.vue'
import GrilleMois from '@/components/anniversaires/GrilleMois.vue'
import PanneauJour from '@/components/anniversaires/PanneauJour.vue'
import AnniversairesView, { etatDeLEcran } from './AnniversairesView.vue'

/** Les panneaux portent des `RouterLink`, qui ne se résolvent pas hors d'une
 *  application routée. Même remplacement qu'`arbre-revalidation.test.ts`. */
const LienFactice = defineComponent({
  name: 'RouterLink',
  props: { to: { type: [String, Object], default: '' } },
  setup: (_, { slots }) => () => h('a', { href: '#' }, slots['default']?.()),
})

function rendre(composant: Component, proprietes: Record<string, unknown> = {}): Promise<string> {
  const app = createSSRApp({ render: () => h(composant, proprietes) })
  app.component('RouterLink', LienFactice)
  return renderToString(app)
}

/* ==================================================================== */
/* Le jeu d'essai                                                       */
/* ==================================================================== */

const JAMAIS = new Date(1970, 0, 1)

function sujet(personId: number, displayName: string): SujetEvenement {
  return { personId, displayName, sex: 'inconnu', vivant: false }
}

const NAISSANCE: EvenementAnniversaire = {
  cle: 'n-1',
  genre: 'naissance',
  type: 'naissance',
  annee: 1903,
  texte: 'vers 1903',
  lieu: 'Quimperlé',
  sujets: [sujet(11, 'Aldegonde Vertbois')],
  familyId: null,
  libelle: null,
}

const MARIAGE: EvenementAnniversaire = {
  cle: 'u-1',
  genre: 'union',
  type: 'mariage',
  annee: 1930,
  texte: '14 mars 1930',
  lieu: null,
  sujets: [sujet(12, 'Barnabé Sauveterre'), sujet(13, 'Clémence Aubrelong')],
  familyId: 40,
  libelle: null,
}

const DECES: EvenementAnniversaire = {
  cle: 'd-1',
  genre: 'deces',
  type: 'deces',
  annee: 1871,
  texte: '14 mars 1871',
  lieu: null,
  sujets: [sujet(14, 'Désiré Fontclaire')],
  familyId: null,
  libelle: null,
}

const QUATORZE_MARS = [{ mois: 3, jour: 14, evenements: [NAISSANCE, MARIAGE, DECES] }]

const TOUS = new Set<GenreEvenement>(['naissance', 'deces', 'union', 'bapteme', 'autre'])
const NAISSANCES = new Set<GenreEvenement>(['naissance'])

const GRILLE_MARS = grilleDuMois(2026, 3, JAMAIS)

/** Le nombre de cases réellement désactivées.
 *
 *  Compter ` disabled` sur tout le balisage donnerait 83 pour 41 cases muettes :
 *  chaque bouton porte aussi `disabled:cursor-default` dans sa liste de classes.
 *  On isole donc la balise, puis l'attribut — le deux-points qui suit la classe
 *  utilitaire est ce qui les distingue. */
function casesDesactivees(html: string): number {
  return [...html.matchAll(/<button[^>]*>/g)].filter((balise) =>
    / disabled(>|\s)/.test(balise[0]),
  ).length
}

/** Les classes du bouton qui porte ce `aria-label`. */
function classesDeLaCase(html: string, libelle: string): string {
  const motif = new RegExp(`<button[^>]*aria-label="${libelle}"[^>]*>`)
  const balise = motif.exec(html)?.[0] ?? ''
  return /class="([^"]*)"/.exec(balise)?.[1] ?? ''
}

/* ==================================================================== */
/* La grille                                                            */
/* ==================================================================== */

describe('la grille du mois se rend sans navigateur', () => {
  it('peint quarante-deux cases, et les sept en-têtes de colonne', async () => {
    /* Un nombre de cases variable ferait changer la hauteur de la grille entre
       deux mois — voir la décision 2 de `calendrier.ts`. Ce cas tient au rendu
       ce que `calendrier.test.ts` tient au calcul. */
    const html = await rendre(GrilleMois, {
      grille: GRILLE_MARS,
      jours: parJour(QUATORZE_MARS, TOUS),
      titre: 'mars 2026',
      selection: null,
    })

    expect((html.match(/<button/g) ?? []).length).toBe(42)
    expect(html).toContain('>L</span>')
    expect(html).toContain('>D</span>')
  })

  it('annonce chaque case par sa date complète et son nombre d’anniversaires', async () => {
    /*
     * LE DÉFAUT TENU. Le contenu visible d'une case est « 14 » et trois noms
     * tronqués. Sans `aria-label`, un lecteur d'écran qui parcourt la grille
     * énonce quarante-deux nombres nus, et l'écran devient illisible sans les
     * yeux.
     */
    const html = await rendre(GrilleMois, {
      grille: GRILLE_MARS,
      jours: parJour(QUATORZE_MARS, TOUS),
      titre: 'mars 2026',
      selection: null,
    })

    expect(html).toContain('aria-label="14 mars — 3 anniversaires"')
    expect(html).toContain('aria-label="1er mars — aucun anniversaire"')
  })

  it('désactive une case vide, et laisse cliquable celle qui porte quelque chose', async () => {
    /* Sans cela, atteindre le 14 mars au clavier demande quatorze tabulations
       dans le vide. */
    const html = await rendre(GrilleMois, {
      grille: GRILLE_MARS,
      jours: parJour(QUATORZE_MARS, TOUS),
      titre: 'mars 2026',
      selection: null,
    })

    expect(casesDesactivees(html)).toBe(41)
    expect(classesDeLaCase(html, '14 mars — 3 anniversaires')).toContain('cursor-pointer')
  })

  it('cache la liste des noms sous `sm` et laisse un pastillon à la place', async () => {
    /*
     * LE DÉFAUT TENU. À 340 px, sept colonnes laissent 44 px par case : un nom
     * y devient trois lettres et une ellipse. La grille redevient un
     * **sélecteur**, et la lecture se fait dans le panneau.
     */
    const html = await rendre(GrilleMois, {
      grille: GRILLE_MARS,
      jours: parJour(QUATORZE_MARS, TOUS),
      titre: 'mars 2026',
      selection: null,
    })

    expect(html).toContain('hidden w-full min-w-0 sm:block')
    expect(html).toMatch(/sm:hidden[^"]*"[^<]*>\s*3\s*</)
    expect(classesDeLaCase(html, '14 mars — 3 anniversaires')).toContain('max-md:aspect-square')
  })

  it('montre trois noms au plus, et compte le reste', async () => {
    /* Le mariage porte deux sujets : quatre noms pour trois événements, donc un
       « +1 ». Le compte annoncé, lui, reste celui des événements. */
    const html = await rendre(GrilleMois, {
      grille: GRILLE_MARS,
      jours: parJour(QUATORZE_MARS, TOUS),
      titre: 'mars 2026',
      selection: null,
    })

    expect(html).toContain('Désiré Fontclaire')
    expect(html).toContain('+1')
    expect(html).not.toContain('Clémence Aubrelong')
  })

  it('n’applique aucun style de fin de semaine : ceci n’est pas un agenda', async () => {
    /* Une naissance ne vaut pas moins un dimanche. Les sept colonnes portent
       exactement les mêmes classes. */
    const html = await rendre(GrilleMois, {
      grille: GRILLE_MARS,
      jours: new Map(),
      titre: 'mars 2026',
      selection: null,
    })

    const cases = [...html.matchAll(/<button[^>]*class="([^"]*)"/g)].map((trouve) => trouve[1])
    const duMois = cases.slice(6, 13)
    expect(new Set(duMois).size).toBe(1)
  })

  it('dit qu’un mois est vide, plutôt que de montrer 42 cases muettes', async () => {
    /*
     * LE DÉFAUT TENU. Quarante-deux cases grises sans un mot se lisent comme
     * une panne, et non comme un choix. La ligne est explicite — et la grille
     * **reste** : la remplacer par un panneau ferait changer la hauteur de
     * l'écran d'un mois à l'autre, c'est-à-dire ramener par la porte de l'état
     * vide le défaut que les six rangs fixes empêchent.
     */
    const html = await rendre(GrilleMois, {
      grille: GRILLE_MARS,
      jours: parJour(QUATORZE_MARS, new Set<GenreEvenement>(['bapteme'])),
      titre: 'mars 2026',
      selection: null,
    })

    expect(html).toContain('role="status"')
    expect(html).toContain('Aucun anniversaire connu en mars 2026')
    expect((html.match(/<button/g) ?? []).length).toBe(42)
  })

  it('n’ouvre pas un jour emprunté au mois voisin', async () => {
    /*
     * Les six cases de tête de mars 2026 sont des jours de février. Un panneau
     * montrant un 28 février sous un titre « mars 2026 » n'aurait aucun moyen
     * de se faire comprendre, et le jour est à une flèche.
     */
    const fevrier = [{ mois: 2, jour: 24, evenements: [NAISSANCE] }]
    const html = await rendre(GrilleMois, {
      grille: GRILLE_MARS,
      jours: parJour(fevrier, TOUS),
      titre: 'mars 2026',
      selection: null,
    })

    expect(html).not.toContain('Aldegonde Vertbois')
    expect(casesDesactivees(html)).toBe(42)
  })
})

/* ==================================================================== */
/* Le panneau d'un jour                                                 */
/* ==================================================================== */

describe('le panneau d’un jour', () => {
  const CELLULE = {
    jour: 14,
    mois: 3,
    annee: 2026,
    cle: '03-14',
    dansLeMois: true,
    aujourdhui: false,
  }

  it('recopie la date du serveur, sans jamais la recomposer', async () => {
    /*
     * LE DÉFAUT TENU. « vers 1903 » est une date généalogique dont
     * l'imprécision **est** une information (§ 14). Un front qui reformerait
     * « 14/03/1903 » à partir de ses morceaux la supprimerait, et toutes les
     * dates approchées deviendraient certaines.
     */
    const html = await rendre(PanneauJour, {
      cellule: CELLULE,
      evenements: [NAISSANCE],
      anneeAffichee: 2026,
    })

    expect(html).toContain('vers 1903')
    expect(html).not.toContain('14/03/1903')
  })

  it('rend les deux époux d’une union, sans en inventer ni en perdre', async () => {
    const html = await rendre(PanneauJour, {
      cellule: CELLULE,
      evenements: [MARIAGE],
      anneeAffichee: 2026,
    })

    expect(html).toContain('Barnabé Sauveterre')
    expect(html).toContain('Clémence Aubrelong')
    expect((html.match(/<a href="#"/g) ?? []).length).toBe(2)
    expect(html).toContain('Mariage')
  })

  it('titre le panneau « 14 mars », et dit « 1er » pour le premier du mois', async () => {
    const quatorze = await rendre(PanneauJour, {
      cellule: CELLULE,
      evenements: [NAISSANCE],
      anneeAffichee: 2026,
    })
    const premier = await rendre(PanneauJour, {
      cellule: { ...CELLULE, jour: 1, cle: '03-01' },
      evenements: [NAISSANCE],
      anneeAffichee: 2026,
    })

    expect(quatorze).toContain('>14 mars</h2>')
    expect(premier).toContain('>1er mars</h2>')
  })

  it('compte les années écoulées, et se tait quand l’anniversaire n’a pas eu lieu', async () => {
    const apres = await rendre(PanneauJour, {
      cellule: CELLULE,
      evenements: [NAISSANCE],
      anneeAffichee: 2026,
    })
    const avant = await rendre(PanneauJour, {
      cellule: CELLULE,
      evenements: [NAISSANCE],
      anneeAffichee: 1890,
    })

    expect(apres).toContain('il y a 123 ans')
    expect(avant).not.toContain('il y a')
    expect(avant).not.toContain('-13')
  })

  it('ne flotte pas sur un téléphone : il descend sous la grille', async () => {
    /*
     * LE DÉFAUT TENU, ET LA DIVERGENCE ASSUMÉE. L'arbre et la chronologie
     * posent leur fiche en `absolute` à toutes les largeurs. Sur un écran de
     * 360 px, une carte flottante couvre les sept colonnes de bout en bout : le
     * lecteur ouvrirait un jour et perdrait le calendrier, c'est-à-dire le seul
     * moyen d'en ouvrir un autre.
     *
     * Découpe en jetons plutôt qu'expressions régulières : un `\b` se comporte
     * mal au voisinage d'un deux-points, et `absolute` reconnaîtrait
     * `md:absolute`.
     */
    const html = await rendre(PanneauJour, {
      cellule: CELLULE,
      evenements: [NAISSANCE],
      anneeAffichee: 2026,
    })
    const jetons = (/<div class="([^"]*)"/.exec(html)?.[1] ?? '').split(/\s+/)

    expect(jetons).toContain('md:absolute')
    expect(jetons).toContain('md:top-3.5')
    expect(jetons).toContain('md:right-3.5')
    expect(jetons).not.toContain('absolute')
    expect(jetons).not.toContain('fixed')
  })

  it('laisse `PanneauBase` ne porter que son ombre', async () => {
    /* Le positionnement vit sur le `<div>` enveloppant — partage de
       `ChronologieView`. Un panneau qui se positionnerait lui-même obligerait à
       toucher les deux pour en déplacer un. */
    const html = await rendre(PanneauJour, {
      cellule: CELLULE,
      evenements: [NAISSANCE],
      anneeAffichee: 2026,
    })
    const section = /<section class="([^"]*)"/.exec(html)?.[1] ?? ''

    expect(section).toContain('shadow-panneau')
    expect(section).not.toContain('absolute')
  })
})

/* ==================================================================== */
/* La barre d'outils                                                    */
/* ==================================================================== */

describe('la barre d’outils', () => {
  const RESUME: ResumeAnniversaires = {
    parGenre: { naissance: 224, deces: 171, union: 64, bapteme: 0, autre: 31 },
    sansJour: { naissance: 166, deces: 42, union: 3, bapteme: 0, autre: 7 },
  }

  it('dit combien d’événements n’ont pas de jour connu', async () => {
    /*
     * LE DÉFAUT TENU. Sans cette ligne, un lecteur qui voit trente et une
     * naissances en septembre lit la grille comme un inventaire complet —
     * alors qu'il en manque cinq fois plus, dans une charge où ils sont
     * pourtant comptés.
     */
    const html = await rendre(BarreOutilsAnniversaires, {
      actifs: NAISSANCES,
      resume: RESUME,
      chargement: false,
    })

    expect(html).toContain('<details')
    expect(html.replace(/\s+/g, ' ')).toContain('166 événements n’ont pas de jour connu')
  })

  it('annonce à part ce que les pastilles éteintes retiennent', async () => {
    /* Le total de tête compte les genres allumés, pour rester le compagnon de
       ce qui est à l'écran ; le reste est dit sur sa propre ligne, pour que
       l'addition du détail tombe juste sans rien taire. */
    const html = await rendre(BarreOutilsAnniversaires, {
      actifs: NAISSANCES,
      resume: RESUME,
      chargement: false,
    })
      .then((rendu) => rendu.replace(/\s+/g, ' '))

    expect(html).toContain('Naissances — 166')
    expect(html).toContain('et 52 de plus dans les genres que vous n’affichez pas')
  })

  it('désactive un genre que l’arbre ne porte pas, en disant pourquoi', async () => {
    /* La règle de `graphe.ts` : un filtre dont la donnée n'est pas servie est
       désactivé, pas silencieusement vide. */
    const html = await rendre(BarreOutilsAnniversaires, {
      actifs: NAISSANCES,
      resume: RESUME,
      chargement: false,
    })

    expect(html).toMatch(/<button[^>]*disabled[^>]*title="Aucun événement de ce genre/)
  })

  it('n’écrit aucune ligne de trou quand la charge n’est pas arrivée', async () => {
    const html = await rendre(BarreOutilsAnniversaires, {
      actifs: NAISSANCES,
      resume: null,
      chargement: true,
    })

    expect(html).not.toContain('<details')
  })

  it('entre dans le tiroir : aucune bordure ni fond ne lui reste sous `md`', async () => {
    /* La condition posée par `TiroirOutils` — le décor appartient à l'hôte. */
    const html = await rendre(BarreOutilsAnniversaires, {
      actifs: NAISSANCES,
      resume: RESUME,
      chargement: false,
    })
    const jetons = (/<div class="([^"]*)"/.exec(html)?.[1] ?? '').split(/\s+/)

    expect(jetons).toContain('max-md:border-b-0')
    expect(jetons).toContain('max-md:bg-transparent')
    expect(jetons).toContain('max-md:px-0')
  })
})

/* ==================================================================== */
/* Ce que la vue décide                                                 */
/* ==================================================================== */

describe('une erreur ne remplace pas un calendrier juste', () => {
  const CHARGE = { jours: QUATORZE_MARS, resume: null as unknown as ResumeAnniversaires }

  it('reste un bandeau d’une ligne quand la charge est déjà là', async () => {
    /*
     * LE DÉFAUT TENU. L'année entière est arrivée, les filtres et les deux
     * flèches sont du rendu pur, et le mois affiché reste exact. Effacer la
     * grille pour y mettre une alerte ferait perdre au lecteur une donnée
     * correcte à cause d'un appel qui ne lui apportait rien de neuf.
     */
    expect(etatDeLEcran(CHARGE, new Error('réseau'))).toEqual({
      corps: 'contenu',
      bandeau: true,
    })
  })

  it('remplace bien l’écran quand il n’y a rien à préserver', () => {
    expect(etatDeLEcran(null, new Error('réseau'))).toEqual({ corps: 'panne', bandeau: false })
  })

  it('montre un squelette tant que rien n’est arrivé et que rien n’a échoué', () => {
    expect(etatDeLEcran(null, null)).toEqual({ corps: 'squelette', bandeau: false })
  })

  it('ne pose aucun bandeau quand tout va bien', () => {
    expect(etatDeLEcran(CHARGE, null)).toEqual({ corps: 'contenu', bandeau: false })
  })

  it('traite `undefined` comme « pas d’erreur », et non comme une panne', () => {
    /* `erreur` est un `shallowRef<unknown>` : une valeur non initialisée s'y
       glisse sans que le typeur s'en émeuve. */
    expect(etatDeLEcran(null, undefined).corps).toBe('squelette')
  })
})

describe('la vue entière', () => {
  it('se rend sans toucher une seule interface de navigateur', async () => {
    /*
     * CE QUE CELUI-CI TIENT, ET LUI SEUL. `setup()` s'exécute au rendu serveur,
     * `onMounted` non. Tout appel à la fenêtre, à `matchMedia` ou à `document`
     * posé hors des crochets de cycle de vie ferait tomber ce cas — et, en
     * production, toute la page avec, sur un rendu serveur.
     */
    const html = await rendre(AnniversairesView)

    expect(html).toContain('aria-busy="true"')
  })
})
