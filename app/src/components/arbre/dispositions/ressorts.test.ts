/**
 * ════════════════════════════════════════════════════════════════════
 * **CE QUI EST ÉPROUVÉ ICI, C'EST L'EXACTITUDE — PAS UNE AMÉLIORATION.**
 *
 * Les autres dispositions promettent de ne pas empirer. Celle-ci promet plus, et
 * doit donc être éprouvée plus durement : pour un ordre donné, elle rend **la**
 * solution du problème posé, pas une bonne solution. Trois gardes en découlent, et
 * aucune n'est une impression :
 *
 * 1. **la contrainte est tenue par construction** — aucune rangée ne chevauche,
 *    quels que soient les désirs, même absurdes ;
 * 2. **l'objectif est meilleur que celui du balayage** que le dépôt possède déjà
 *    (`ecarterVersLaDroite`, recopie de `CalculDePlacement`) — c'est la
 *    comparaison qui dit si « exact » veut dire quelque chose ;
 * 3. **le mouvement retombe.** Une relaxation qui rebondit est le défaut que ce
 *    solveur remplace ; si le mouvement ne tombe pas, il ne l'a pas remplacé.
 *
 * Les noms sont inventés — aucune personne réelle n'entre dans le dépôt (ADR 0006).
 * ════════════════════════════════════════════════════════════════════
 */
import { beforeAll, describe, expect, it } from 'vitest'
import { readFileSync } from 'node:fs'
import type { AreteGraphe, Graphe, NoeudGraphe } from '@/lib/api'
import {
  echangerVersLUnionNonRompue,
  famillesRompues,
  FRATRIE_CONSEQUENTE,
  fratriesVoisines,
  separerLesGrossesFratries,
  ITERATIONS,
  modeleLocal,
  mouvements,
  pava,
  placerParIsotonie,
  rangeesCompletes,
  ressortsIsotoniques,
  losangesHorsDeLeursEpoux,
  sommeDesCarres,
  unions,
  voisinage,
} from './ressorts'
import { croisementsMinimises } from './croisements'
import { ecarterVersLaDroite, ESPACE, mesurerLisibilite, OPTIONS_PAR_DEFAUT } from './socle'

function personne(id: string, x: number, y: number, w = 100): NoeudGraphe {
  return {
    type: 'personne',
    id,
    personId: null,
    displayName: id,
    etiquetteNom: id,
    sexe: 'inconnu',
    vivant: false,
    branche: null,
    x,
    y,
    w,
    h: 40,
  } as unknown as NoeudGraphe
}

function famille(id: string, x: number, y: number): NoeudGraphe {
  return { type: 'famille', id, familyId: null, divorced: false, x, y, w: 22, h: 14 } as NoeudGraphe
}

const u = (de: string, vers: string): AreteGraphe => ({ de, vers, genre: 'union' }) as AreteGraphe
const f = (de: string, vers: string): AreteGraphe =>
  ({ de, vers, genre: 'filiation' }) as AreteGraphe

/** L'objectif que le solveur prétend minimiser, calculé du dehors. */
function objectif(
  rangee: readonly NoeudGraphe[],
  x: ReadonlyMap<string, number>,
  desirs: ReadonlyMap<string, number>,
  poids: ReadonlyMap<string, number>,
): number {
  return rangee.reduce((somme, noeud) => {
    const ecart = (x.get(noeud.id) ?? 0) - (desirs.get(noeud.id) ?? 0)
    return somme + (poids.get(noeud.id) ?? 1) * ecart * ecart
  }, 0)
}

/* ------------------------------------------------------------------ */

describe('PAVA', () => {
  it('ne touche pas à une suite déjà croissante — il n’y a rien à corriger', () => {
    expect(pava([1, 2, 3, 10], [1, 1, 1, 1])).toEqual([1, 2, 3, 10])
  })

  it('fusionne deux voisins qui violent l’ordre, à leur moyenne pondérée', () => {
    expect(pava([10, 0], [1, 1])).toEqual([5, 5])
    expect(pava([10, 0], [3, 1])).toEqual([7.5, 7.5])
    expect(pava([10, 0], [1, 3])).toEqual([2.5, 2.5])
  })

  it('propage la fusion quand elle en déclenche une autre', () => {
    // 5 casse 6, leur bloc vaut 5,5 — qui casse encore 7. Tout tient en un bloc.
    expect(pava([7, 6, 5], [1, 1, 1])).toEqual([6, 6, 6])
  })

  it('rend une suite croissante, quelle que soit l’entrée', () => {
    const y = pava([9, 1, 8, 2, 7, 3], [1, 2, 3, 4, 5, 6])
    for (let i = 1; i < y.length; i += 1) expect(y[i]).toBeGreaterThanOrEqual(y[i - 1]! - 1e-9)
  })

  it('laisse la branche lourde sur sa valeur et fait céder la légère', () => {
    const [lourd, leger] = pava([10, 0], [1000, 1])
    expect(lourd).toBeGreaterThan(9.9)
    expect(leger).toBe(lourd)
  })

  it('est déterministe', () => {
    const entree = [4, 1, 9, 2, 2, 7]
    const masses = [1, 5, 2, 3, 1, 4]
    expect(pava(entree, masses)).toEqual(pava(entree, masses))
  })

  it('traverse le vide et le singleton sans dommage', () => {
    expect(pava([], [])).toEqual([])
    expect(pava([42], [7])).toEqual([42])
  })
})

/* ------------------------------------------------------------------ */

describe('une rangée', () => {
  const rangee = [personne('A', 0, 0), personne('B', 10, 0), personne('C', 20, 0)]
  const poids = new Map([
    ['A', 1],
    ['B', 1],
    ['C', 1],
  ])

  it('sépare exactement, même quand tout le monde veut la même place', () => {
    const tous = new Map([
      ['A', 0],
      ['B', 0],
      ['C', 0],
    ])
    const { abscisses: x } = placerParIsotonie(rangee, tous, poids)

    expect(x.get('B')! - x.get('A')!).toBeCloseTo(100 / 2 + ESPACE + 100 / 2, 9)
    expect(x.get('C')! - x.get('B')!).toBeCloseTo(100 / 2 + ESPACE + 100 / 2, 9)
  })

  it('centre le bloc sur la moyenne des désirs — il ne le pousse pas à droite', () => {
    const tous = new Map([
      ['A', 0],
      ['B', 0],
      ['C', 0],
    ])
    const { abscisses: x } = placerParIsotonie(rangee, tous, poids)
    const moyenne = (x.get('A')! + x.get('B')! + x.get('C')!) / 3

    expect(moyenne).toBeCloseTo(0, 9)
  })

  it('respecte l’écart minimal avec des largeurs inégales', () => {
    const inegale = [personne('P', 0, 0, 60), personne('Q', 0, 0, 300)]
    const { abscisses: x } = placerParIsotonie(inegale, new Map([['P', 0], ['Q', 0]]), new Map())

    expect(x.get('Q')! - x.get('P')!).toBeCloseTo(60 / 2 + ESPACE + 300 / 2, 9)
  })

  it('FAIT MIEUX QUE LE BALAYAGE sur l’objectif — c’est tout l’intérêt', () => {
    const voulus = new Map([
      ['A', 300],
      ['B', 0],
      ['C', 150],
    ])

    const { abscisses: exact } = placerParIsotonie(rangee, voulus, poids)

    // Le balayage du moteur, **dans le même ordre** : on part des désirs et l'on
    // pousse vers la droite. Le trier par abscisse désirée résoudrait un AUTRE
    // problème — un ordre différent — et la comparaison ne dirait plus rien.
    const depart = rangee.map((noeud) => ({ ...noeud, x: voulus.get(noeud.id)! }) as NoeudGraphe)
    const balaye = ecarterVersLaDroite(depart)

    expect(objectif(rangee, exact, voulus, poids)).toBeLessThan(
      objectif(rangee, balaye, voulus, poids),
    )
  })

  it('laisse la personne lourde sur son désir et déplace la légère', () => {
    const voulus = new Map([
      ['A', 0],
      ['B', 0],
      ['C', 0],
    ])
    const inegaux = new Map([
      ['A', 1],
      ['B', 500],
      ['C', 1],
    ])

    const { abscisses: x } = placerParIsotonie(rangee, voulus, inegaux)

    expect(Math.abs(x.get('B')!)).toBeLessThan(2)
    expect(Math.abs(x.get('A')!)).toBeGreaterThan(100)
  })
})

/* ------------------------------------------------------------------ */

describe('une rangée sous bornes', () => {
  // Trois fiches de 100 px : l'écart minimal entre deux voisines est de 120 px.
  const rangee = [personne('A', 0, 0), personne('B', 200, 0), personne('C', 400, 0)]
  const poids = new Map([
    ['A', 1],
    ['B', 1],
    ['C', 1],
  ])
  const voulus = new Map([
    ['A', 0],
    ['B', 200],
    ['C', 400],
  ])

  it('tient la borne quand elle mord sur le désir', () => {
    const { abscisses, bornesInfaisables } = placerParIsotonie(
      rangee,
      voulus,
      poids,
      new Map([['B', { bas: 240, haut: 300 }]]),
    )

    expect(bornesInfaisables).toEqual([])
    expect(abscisses.get('B')!).toBeGreaterThanOrEqual(240 - 0.001)
    expect(abscisses.get('B')!).toBeLessThanOrEqual(300 + 0.001)
  })

  it('ne casse pas la séparation en tenant la borne', () => {
    const { abscisses } = placerParIsotonie(
      rangee,
      voulus,
      poids,
      new Map([['B', { bas: 240, haut: 300 }]]),
    )

    expect(abscisses.get('B')! - abscisses.get('A')!).toBeGreaterThanOrEqual(120 - 0.001)
    expect(abscisses.get('C')! - abscisses.get('B')!).toBeGreaterThanOrEqual(120 - 0.001)
  })

  it('laisse le désir tranquille quand la borne ne mord pas', () => {
    const large = placerParIsotonie(
      rangee,
      voulus,
      poids,
      new Map([['B', { bas: -10000, haut: 10000 }]]),
    )
    const sans = placerParIsotonie(rangee, voulus, poids)

    expect([...large.abscisses]).toEqual([...sans.abscisses])
  })

  it('SIGNALE une borne inconciliable avec la séparation au lieu de la forcer', () => {
    // A et B doivent être à 120 px l'un de l'autre, et on exige que les deux
    // tiennent dans une fenêtre de 20 px. C'est impossible, et ça se dit.
    const { bornesInfaisables } = placerParIsotonie(
      rangee,
      voulus,
      poids,
      new Map([
        ['A', { bas: 0, haut: 10 }],
        ['B', { bas: 0, haut: 20 }],
      ]),
    )

    expect(bornesInfaisables).toContain('B')
  })
})

/* ------------------------------------------------------------------ */

describe('la séparation des grosses fratries', () => {
  // Deux fratries de trois, et l'un des VERT épouse l'une des BLEU. Le serveur
  // les a collés, donc l'épouse a été tirée hors des siens.
  const rangee = [
    personne('VERT1', 0, 0),
    personne('VERT2', 120, 0),
    personne('VERT3', 240, 0),
    personne('BLEU2', 360, 0), // tirée près de VERT3, loin de sa fratrie
    personne('BLEU1', 480, 0),
    personne('BLEU3', 600, 0),
  ]
  const aretes = [
    f('fV', 'VERT1'),
    f('fV', 'VERT2'),
    f('fV', 'VERT3'),
    f('fB', 'BLEU1'),
    f('fB', 'BLEU2'),
    f('fB', 'BLEU3'),
    u('VERT3', 'fC'),
    u('BLEU2', 'fC'),
  ]

  const ordre = (x: Map<string, number>): string[] =>
    [...x.entries()].sort((a, b) => a[1] - b[1]).map(([id]) => id)

  it('RAMÈNE l’époux tiré hors des siens, et les deux fratries redeviennent contiguës', () => {
    const resultat = ordre(separerLesGrossesFratries(rangee, aretes, 3))

    const positions = (ids: string[]): number[] => ids.map((id) => resultat.indexOf(id)).sort((a, b) => a - b)
    const contigue = (ids: string[]): boolean => {
      const p = positions(ids)
      return p[p.length - 1]! - p[0]! === p.length - 1
    }

    expect(contigue(['VERT1', 'VERT2', 'VERT3'])).toBe(true)
    expect(contigue(['BLEU1', 'BLEU2', 'BLEU3'])).toBe(true)
  })

  it('ne touche à RIEN sous le seuil', () => {
    const avant = ordre(new Map(rangee.map((n) => [n.id, n.x])))
    const apres = ordre(separerLesGrossesFratries(rangee, aretes, 4))

    expect(apres).toEqual(avant)
  })

  it('ne s’applique PAS à une union rompue — la règle du remariage l’emporte', () => {
    const rompue = aretes.map((a) =>
      a.genre === 'union' && a.vers === 'fC' ? ({ ...a, divorce: true } as typeof a) : a,
    )
    const avant = ordre(new Map(rangee.map((n) => [n.id, n.x])))

    expect(ordre(separerLesGrossesFratries(rangee, rompue, 3))).toEqual(avant)
  })

  it('garde la rangée à la même largeur : elle permute des occupants, pas des écarts', () => {
    const avant = [...rangee.map((n) => n.x)].sort((a, b) => a - b)
    const apres = [...separerLesGrossesFratries(rangee, aretes, 3).values()].sort((a, b) => a - b)

    expect(apres).toEqual(avant)
  })

  /**
   * **La condition qui est venue après le seuil, et qui ne le remplace pas.**
   *
   * Mesurée sur l'arbre réel le 20/09/2026 : la règle écartait un couple de
   * **quatorze fiches et 3 210 px** parce que les deux fratries étaient aux deux
   * bouts de la rangée, avec deux fiches étrangères au milieu. Ramener chacun
   * chez les siens n'y réparait rien — ça fabriquait le trait que le dessin
   * montre traverser l'écran.
   *
   * **On n'a pas déplacé le seuil, on a ajouté une condition** : un seuil déplacé
   * fait réapparaître le défaut ailleurs, une condition nomme le cas.
   */
  it('S’ABSTIENT quand une fiche étrangère sépare les deux fratries', () => {
    // La même rangée, mais un ÉTRANGER s'assoit entre les VERT et les BLEU.
    const eloignee = [
      personne('VERT1', 0, 0),
      personne('VERT2', 120, 0),
      personne('VERT3', 240, 0),
      personne('BLEU2', 360, 0),
      personne('ETRANGER', 480, 0),
      personne('BLEU1', 600, 0),
      personne('BLEU3', 720, 0),
    ]
    const avant = ordre(new Map(eloignee.map((n) => [n.id, n.x])))

    expect(ordre(separerLesGrossesFratries(eloignee, aretes, 3))).toEqual(avant)
  })

  it('la condition se lit sur les fratries SANS le couple examiné', () => {
    const familles = new Map([
      ['VERT3', ['fC']],
      ['BLEU2', ['fC']],
    ])
    const epoux = new Map([['fC', ['VERT3', 'BLEU2']]])
    const rangeeSerree = ['VERT1', 'VERT2', 'VERT3', 'BLEU2', 'BLEU1', 'BLEU3']
    const rangeeCoupee = ['VERT1', 'VERT2', 'VERT3', 'BLEU2', 'ETRANGER', 'BLEU1', 'BLEU3']

    const vert = ['VERT1', 'VERT2', 'VERT3']
    const bleu = ['BLEU1', 'BLEU2', 'BLEU3']

    expect(fratriesVoisines(vert, bleu, rangeeSerree, familles, epoux, ['VERT3', 'BLEU2'])).toBe(true)
    expect(fratriesVoisines(vert, bleu, rangeeCoupee, familles, epoux, ['VERT3', 'BLEU2'])).toBe(false)

    // **Sans retirer le couple, la condition ne dit plus rien** : l'épouse est
    // comptée parmi les conjoints de la fratrie de son mari, les deux
    // intervalles se chevauchent, et tout devient « voisin ».
    expect(fratriesVoisines(vert, bleu, rangeeCoupee, familles, epoux)).toBe(true)
  })

  it('le seuil du dossier est TROIS, et c’est une décision mesurée', () => {
    // À 2, une fratrie de deux serait « conséquente » (12 couples séparés sur
    // l'arbre réel) ; à 4, il n'en reste qu'UN. Trois en sépare huit.
    expect(FRATRIE_CONSEQUENTE).toBe(3)
  })
})

/* ------------------------------------------------------------------ */

describe('le modèle local', () => {
  // AIEUL x AIEULE -> PERE ; PERE x MERE -> FILS.
  const aretes = [
    u('AIEUL', 'f1'),
    u('AIEULE', 'f1'),
    f('f1', 'PERE'),
    u('PERE', 'f2'),
    u('MERE', 'f2'),
    f('f2', 'FILS'),
  ]
  const noeuds = [
    personne('AIEUL', 0, 0),
    personne('AIEULE', 0, 0),
    famille('f1', 0, 26),
    personne('PERE', 0, 150),
    personne('MERE', 0, 150),
    famille('f2', 0, 176),
    personne('FILS', 0, 300),
  ]
  const voisins = voisinage(aretes)

  it('désire la MOYENNE des voisins, et rien d’autre', () => {
    const x = new Map([
      ['AIEUL', -100],
      ['AIEULE', 300],
      ['f1', 0],
      ['PERE', 0],
      ['MERE', 0],
      ['f2', 0],
      ['FILS', 0],
    ])
    const { desirs } = modeleLocal(noeuds, voisins, x)

    // f1 a trois voisins : AIEUL (-100), AIEULE (300), PERE (0).
    expect(desirs.get('f1')).toBeCloseTo(200 / 3, 9)
    // PERE a deux voisins : f1 (0) et f2 (0).
    expect(desirs.get('PERE')).toBeCloseTo(0, 9)
  })

  it('pondère par le NOMBRE de voisins — la courbure', () => {
    const x = new Map(noeuds.map((n) => [n.id, 0]))
    const { poids } = modeleLocal(noeuds, voisins, x)

    expect(poids.get('f1')).toBe(3) // deux époux et un enfant
    expect(poids.get('AIEUL')).toBe(1) // sa seule union
    expect(poids.get('PERE')).toBe(2) // sa filiation et son union
  })

  it('laisse sur place un nœud sans voisin, avec un poids de un', () => {
    const seule = [personne('ISOLEE', 42, 0)]
    const { desirs, poids } = modeleLocal(seule, voisinage([]), new Map([['ISOLEE', 42]]))

    expect(desirs.get('ISOLEE')).toBe(42)
    expect(poids.get('ISOLEE')).toBe(1)
  })

  it('ne désire aucun déplacement quand tout est déjà aligné', () => {
    const x = new Map(noeuds.map((n) => [n.id, 0]))
    const { desirs } = modeleLocal(noeuds, voisins, x)

    for (const noeud of noeuds) expect(desirs.get(noeud.id)).toBeCloseTo(0, 9)
  })
})

describe('les rangées', () => {
  it('comprennent les losanges — deux losanges aussi se chevauchent', () => {
    const noeuds = [personne('A', 0, 0), famille('g', 0, 26), personne('B', 0, 0)]
    const rangees = rangeesCompletes(noeuds)

    expect(rangees).toHaveLength(2)
    expect(rangees[1]!.map((n) => n.id)).toEqual(['g'])
  })

  it('vont de haut en bas : les parents avant les enfants', () => {
    const noeuds = [personne('BAS', 0, 300), personne('HAUT', 0, 0), personne('MILIEU', 0, 150)]
    expect(rangeesCompletes(noeuds).map((r) => r[0]!.id)).toEqual(['HAUT', 'MILIEU', 'BAS'])
  })
})

/* ------------------------------------------------------------------ */

describe('la règle de l’union non rompue', () => {
  // EPOUX a deux unions sur la même rangée : ROMPUE colle à lui, INTACTE est loin.
  const noeuds = [
    personne('EPOUX', 0, 0),
    personne('ROMPUE', 120, 0),
    personne('PASSANT', 240, 0),
    personne('INTACTE', 360, 0),
  ]
  const aretes = [
    u('EPOUX', 'fa'),
    u('ROMPUE', 'fa'),
    u('EPOUX', 'fb'),
    u('INTACTE', 'fb'),
  ]
  const rompues = new Set(['fa'])

  it('échange les deux conjoints, et ne touche pas à la personne', () => {
    const x = echangerVersLUnionNonRompue(noeuds, unions(aretes), rompues)

    expect(x.get('EPOUX')).toBe(0) // la personne ne bouge pas
    expect(x.get('INTACTE')).toBe(120) // l'intacte prend la place accolée
    expect(x.get('ROMPUE')).toBe(360) // la rompue prend celle de l'intacte
    expect(x.get('PASSANT')).toBe(240) // le reste de la rangée ne bouge pas
  })

  it('ne fait RIEN quand l’union accolée n’est pas rompue', () => {
    const x = echangerVersLUnionNonRompue(noeuds, unions(aretes), new Set(['fb']))

    expect(x.get('ROMPUE')).toBe(120)
    expect(x.get('INTACTE')).toBe(360)
  })

  it('ne fait RIEN quand TOUTES les unions sont rompues — l’ordre reçu vaut', () => {
    const x = echangerVersLUnionNonRompue(noeuds, unions(aretes), new Set(['fa', 'fb']))

    expect(x.get('ROMPUE')).toBe(120)
    expect(x.get('INTACTE')).toBe(360)
  })

  it('ignore un conjoint qui n’est pas sur la même rangée', () => {
    const ailleurs = [...noeuds.slice(0, 3), personne('INTACTE', 360, 150)]
    const x = echangerVersLUnionNonRompue(ailleurs, unions(aretes), rompues)

    expect(x.get('ROMPUE')).toBe(120)
  })

  it('lit le divorce sur l’arête d’union', () => {
    const avec = [
      { de: 'EPOUX', vers: 'fa', genre: 'union', divorce: true },
      { de: 'ROMPUE', vers: 'fa', genre: 'union', divorce: true },
      { de: 'EPOUX', vers: 'fb', genre: 'union' },
    ] as unknown as AreteGraphe[]

    expect([...famillesRompues(avec)]).toEqual(['fa'])
  })
})

/* ------------------------------------------------------------------ */

function petitArbre(): Graphe {
  return {
    empreinte: 'essai',
    ego: 'FILS',
    boite: { x: 0, y: 0, w: 0, h: 0 },
    calque: { actif: false, demandes: [], proposes: 0, places: 0, nonPlaces: [] },
    noeuds: [
      personne('PERE', -400, 0),
      personne('MERE', 900, 0),
      famille('f1', 250, 26),
      personne('FILS', 0, 150),
      personne('FILLE', 700, 150),
    ],
    aretes: [u('PERE', 'f1'), u('MERE', 'f1'), f('f1', 'FILS'), f('f1', 'FILLE')],
  } as unknown as Graphe
}

describe('la mesure affichée dans le bandeau', () => {
  // Le bandeau des maquettes n'affichait que les couples accolés. Une disposition
  // qui ne réordonne pas y lisait « 163 → 163 » en ayant raccourci tous les
  // traits : elle se serait présentée comme ne faisant rien.
  it('porte la somme des carrés et le trait le plus long', () => {
    const mesure = mesurerLisibilite(petitArbre())

    // PERE(−400) ↔ f1(250) : 650 ; MERE(900) ↔ f1 : 650 ; f1 ↔ FILS(0) : 250 ;
    // f1 ↔ FILLE(700) : 450.
    expect(mesure.traitLePlusLong).toBe(650)
    expect(mesure.carres).toBe(650 ** 2 + 650 ** 2 + 250 ** 2 + 450 ** 2)
  })

  it('les fait baisser quand la disposition resserre', () => {
    const avant = mesurerLisibilite(petitArbre())
    const apres = mesurerLisibilite(ressortsIsotoniques(petitArbre(), OPTIONS_PAR_DEFAUT))

    expect(apres.carres).toBeLessThan(avant.carres)
  })
})

describe('la disposition', () => {
  it('ne déplace aucune ordonnée', () => {
    const avant = petitArbre()
    const apres = ressortsIsotoniques(avant, OPTIONS_PAR_DEFAUT)

    for (const noeud of avant.noeuds) {
      expect(apres.noeuds.find((n) => n.id === noeud.id)!.y).toBe(noeud.y)
    }
  })

  it('laisse le lecteur à l’abscisse zéro', () => {
    const apres = ressortsIsotoniques(petitArbre(), OPTIONS_PAR_DEFAUT)
    expect(apres.noeuds.find((n) => n.id === 'FILS')!.x).toBeCloseTo(0, 9)
  })

  it('rapproche les époux, qui partaient à 1300 px l’un de l’autre', () => {
    const avant = petitArbre()
    const apres = ressortsIsotoniques(avant, OPTIONS_PAR_DEFAUT)
    const x = (id: string) => apres.noeuds.find((n) => n.id === id)!.x

    expect(Math.abs(x('PERE') - x('MERE'))).toBeLessThan(Math.abs(-400 - 900))
    expect(mesurerLisibilite(apres).accoles).toBe(1)
  })

  it('recale le losange sur le barycentre de ses époux', () => {
    const apres = ressortsIsotoniques(petitArbre(), OPTIONS_PAR_DEFAUT)
    const x = (id: string) => apres.noeuds.find((n) => n.id === id)!.x

    expect(x('f1')).toBeCloseTo((x('PERE') + x('MERE')) / 2, 9)
  })

  it('recalcule la boîte englobante, qui contient tous les nœuds', () => {
    const apres = ressortsIsotoniques(petitArbre(), OPTIONS_PAR_DEFAUT)

    for (const noeud of apres.noeuds) {
      expect(noeud.x - noeud.w / 2).toBeGreaterThanOrEqual(apres.boite.x)
      expect(noeud.x + noeud.w / 2).toBeLessThanOrEqual(apres.boite.x + apres.boite.w)
    }
  })

  it('est déterministe : deux appels rendent exactement les mêmes abscisses', () => {
    const a = ressortsIsotoniques(petitArbre(), OPTIONS_PAR_DEFAUT)
    const b = ressortsIsotoniques(petitArbre(), OPTIONS_PAR_DEFAUT)

    expect(a.noeuds.map((n) => [n.id, n.x])).toEqual(b.noeuds.map((n) => [n.id, n.x]))
  })

  it('ne mute pas le graphe qu’on lui donne', () => {
    const avant = petitArbre()
    const copie = JSON.parse(JSON.stringify(avant))
    ressortsIsotoniques(avant, OPTIONS_PAR_DEFAUT)

    expect(avant).toEqual(copie)
  })

  it('traverse un graphe vide sans dommage', () => {
    const vide = { ...petitArbre(), noeuds: [], aretes: [] } as unknown as Graphe
    expect(ressortsIsotoniques(vide, OPTIONS_PAR_DEFAUT)).toBe(vide)
  })

  it('LE MOUVEMENT RETOMBE — c’est ce qui remplace la relaxation qui rebondissait', () => {
    const { deplacements } = mouvements(petitArbre())

    expect(deplacements).toHaveLength(ITERATIONS)
    expect(deplacements[deplacements.length - 1]!).toBeLessThan(deplacements[0]! / 10)
  })

  it('L’OBJECTIF NE REMONTE À AUCUN TOUR — c’est une propriété, pas un espoir', () => {
    const { objectifs } = mouvements(petitArbre())

    for (let i = 1; i < objectifs.length; i += 1) {
      expect(objectifs[i]!).toBeLessThanOrEqual(objectifs[i - 1]! + 1e-6)
    }
  })
})

/* ------------------------------------------------------------------ */
/* Le graphe réel — hors dépôt (ADR 0006)                              */
/* ------------------------------------------------------------------ */

const CHEMIN = process.env.HERITAGE_GRAPHE_CLIENT

describe.runIf(CHEMIN !== undefined)('sur le graphe réel', () => {
  // Lu À L'APPEL, pas au chargement du module : `describe.runIf` saute les
  // TESTS, jamais le CORPS du bloc.
  let g!: Graphe
  let apres!: Graphe

  beforeAll(() => {
    g = JSON.parse(readFileSync(CHEMIN as string, 'utf8')) as Graphe
    apres = ressortsIsotoniques(g, OPTIONS_PAR_DEFAUT)
  })

  it('confronte plus de cent nœuds — sinon la mesure ne prouve rien', () => {
    expect(g.noeuds.length).toBeGreaterThan(100)
  })

  it('ne laisse AUCUN chevauchement, sur aucune rangée', () => {
    const rangees = new Map<number, NoeudGraphe[]>()
    for (const noeud of apres.noeuds) {
      if (noeud.type !== 'personne') continue
      rangees.set(noeud.y, [...(rangees.get(noeud.y) ?? []), noeud])
    }

    for (const rangee of rangees.values()) {
      rangee.sort((a, b) => a.x - b.x)
      for (let i = 1; i < rangee.length; i += 1) {
        expect(rangee[i]!.x - rangee[i]!.w / 2).toBeGreaterThanOrEqual(
          rangee[i - 1]!.x + rangee[i - 1]!.w / 2 + ESPACE - 0.001,
        )
      }
    }
  })

  it('ne déplace aucune ordonnée de personne', () => {
    for (const noeud of g.noeuds) {
      if (noeud.type !== 'personne') continue
      expect(apres.noeuds.find((n) => n.id === noeud.id)!.y).toBe(noeud.y)
    }
  })

  it('NE LAISSE SÉPARÉE AUCUNE UNION INTACTE quand l’autre est rompue', () => {
    const rompues = famillesRompues(g.aretes)
    expect(rompues.size).toBeGreaterThan(0) // témoin : la charge porte bien des divorces

    const x = new Map(apres.noeuds.map((n) => [n.id, n.x]))
    const yy = new Map(apres.noeuds.map((n) => [n.id, n.y]))
    const rangees = new Map<number, string[]>()
    for (const n of apres.noeuds) {
      if (n.type !== 'personne') continue
      rangees.set(n.y, [...(rangees.get(n.y) ?? []), n.id])
    }
    const rang = new Map<string, number>()
    for (const [, ids] of rangees) {
      ids.sort((p, q) => x.get(p)! - x.get(q)!)
      ids.forEach((id, i) => rang.set(id, i))
    }

    const liens = unions(apres.aretes)

    // **Pour chaque personne à plusieurs unions sur une même rangée : si l'une de
    // ses unions est rompue et une autre ne l'est pas, ce n'est PAS la rompue qui
    // doit être la plus proche.** C'est la règle, énoncée sur ce qu'elle interdit.
    for (const [personne, familles] of liens.familles) {
      if (familles.length < 2) continue

      const candidats = familles
        .map((famille) => ({
          famille,
          conjoint: (liens.epoux.get(famille) ?? []).find((id) => id !== personne),
        }))
        .filter((c): c is { famille: string; conjoint: string } => c.conjoint !== undefined)
        .filter((c) => yy.get(c.conjoint) === yy.get(personne))
        .map((c) => ({
          rompue: rompues.has(c.famille),
          ecart: Math.abs(rang.get(c.conjoint)! - rang.get(personne)!),
        }))

      if (candidats.length < 2) continue
      if (!candidats.some((c) => c.rompue) || !candidats.some((c) => !c.rompue)) continue

      const plusProche = candidats.reduce((a, b) => (b.ecart < a.ecart ? b : a))
      expect(plusProche.rompue).toBe(false)
    }
  })

  it('TOUTE FRATRIE D’UN COUPLE SÉPARÉ RESTE ENTIÈRE — dont celle des grands-parents', () => {
    const enfants = new Map<string, string[]>()
    const familleDesParents = new Map<string, string>()
    for (const a of g.aretes) {
      if (a.genre !== 'filiation') continue
      enfants.set(a.de, [...(enfants.get(a.de) ?? []), a.vers])
      familleDesParents.set(a.vers, a.de)
    }
    const epx = new Map<string, string[]>()
    for (const a of g.aretes) {
      if (a.genre === 'union') epx.set(a.vers, [...(epx.get(a.vers) ?? []), a.de])
    }

    const x = new Map(apres.noeuds.map((n) => [n.id, n.x]))
    const yy = new Map(apres.noeuds.map((n) => [n.id, n.y]))
    const rang = new Map<string, number>()
    const parRangee = new Map<number, string[]>()
    for (const n of apres.noeuds) {
      if (n.type !== 'personne') continue
      parRangee.set(n.y, [...(parRangee.get(n.y) ?? []), n.id])
    }
    for (const [, ids] of parRangee) {
      ids.sort((p, q) => x.get(p)! - x.get(q)!)
      ids.forEach((id, i) => rang.set(id, i))
    }

    let verifiees = 0
    for (const [famille, membres] of epx) {
      if (membres.length !== 2) continue
      const [a, b] = membres as [string, string]
      if (yy.get(a) !== yy.get(b)) continue

      const fa = familleDesParents.get(a)
      const fb = familleDesParents.get(b)
      if (fa === undefined || fb === undefined) continue
      if ((enfants.get(fa) ?? []).length < FRATRIE_CONSEQUENTE) continue
      if ((enfants.get(fb) ?? []).length < FRATRIE_CONSEQUENTE) continue
      if (famillesRompues(g.aretes).has(famille)) continue

      // **Les deux fratries ne s'entrelacent pas** : c'est ça, « chacun reste
      // entier au milieu des siens ». On n'exige PAS que chaque fratrie soit
      // contiguë au sens étroit — le conjoint d'un frère s'assied à côté de lui,
      // donc à l'intérieur de l'intervalle, et c'est normal. Mesuré le
      // 20/09/2026 : l'assertion étroite échouait sur une fratrie de trois dont
      // l'intervalle vaut onze, uniquement peuplé de conjoints.
      //
      // Le cas le plus lourd de l'arbre — une fratrie de neuf alliée à une de
      // sept — passe par ici **sans être nommé** : aucune personne réelle
      // n'entre dans le dépôt (ADR 0006).
      const places = (fratrie: string[]): number[] =>
        fratrie
          .map((id) => rang.get(id))
          .filter((r): r is number => r !== undefined)
          .sort((p, q) => p - q)

      const pa = places(enfants.get(fa) ?? [])
      const pb = places(enfants.get(fb) ?? [])
      if (pa.length < 2 || pb.length < 2) continue

      const disjointes =
        pa[pa.length - 1]! < pb[0]! || pb[pb.length - 1]! < pa[0]!

      expect(disjointes).toBe(true)
      verifiees += 1
    }

    expect(verifiees).toBeGreaterThan(0) // témoin : des cas existent bien
  })

  it('RESSERRE LES TRAITS : la somme des carrés passe SOUS celle du serveur', () => {
    const carres = (graphe: Graphe): number =>
      sommeDesCarres(graphe.aretes, new Map(graphe.noeuds.map((n) => [n.id, n.x])))

    expect(carres(apres)).toBeLessThan(carres(g))
  })

  it('NE LAISSE PAS PLUS DE LOSANGES HORS DE LEURS ÉPOUX QUE LA PRODUCTION', () => {
    const x = new Map(apres.noeuds.map((n) => [n.id, n.x]))
    const epoux = new Map<string, string[]>()
    for (const arete of apres.aretes) {
      if (arete.genre !== 'union') continue
      epoux.set(arete.vers, [...(epoux.get(arete.vers) ?? []), arete.de])
    }

    const { combien, depassementMaximal } = losangesHorsDeLeursEpoux(epoux, x)

    // **Le témoin est la PRODUCTION, pas zéro.** L'écran de référence laisse
    // lui-même 63 losanges sur 178 hors de l'intervalle de leurs époux : comparer
    // à zéro ferait passer pour un défaut de cette disposition un trait de l'arbre
    // qu'elle imite. On borne donc le NOMBRE, et par ce que fait le serveur.
    const serveur = losangesHorsDeLeursEpoux(epoux, new Map(g.noeuds.map((n) => [n.id, n.x])))

    // **La borne se lit en trois temps, mesurés le 20/09/2026 :** 63 chez le
    // serveur, 68 après le solveur et la règle de l'union non rompue, 70 après la
    // séparation des grosses fratries. Cette dernière en coûte donc DEUX, et c'est
    // le prix de l'arbitrage choisi : un couple séparé a un trait d'union long, et
    // son losange, tiré par les enfants, sort plus facilement de l'intervalle.
    expect(serveur.combien).toBeGreaterThan(50)
    expect(combien).toBeLessThanOrEqual(serveur.combien + 10)
    expect(depassementMaximal).toBeLessThan(serveur.depassementMaximal * 1.5)
  })

  it('ALLONGE LE TRAIT LE PLUS LONG — le prix de la somme des carrés, dit ici', () => {
    const traitMax = (graphe: Graphe): number => {
      const x = new Map(graphe.noeuds.map((n) => [n.id, n.x]))
      return Math.max(
        ...graphe.aretes.map((a) => Math.abs((x.get(a.de) ?? 0) - (x.get(a.vers) ?? 0))),
      )
    }

    // **Minimiser une somme de carrés n'est pas minimiser le maximum.** Raccourcir
    // beaucoup de traits peut en laisser un s'allonger, et c'est ce qui arrive :
    // mesuré le 20/09/2026, 3 185 → 3 486 px, soit +9,5 %. Le serveur, lui, tient
    // que son trait le plus long ne remonte jamais. **C'est un vrai coût, et le
    // test le borne au lieu de l'ignorer** — si un changement le fait doubler, il
    // faudra le voir ici et non à l'écran.
    expect(traitMax(apres)).toBeGreaterThan(traitMax(g))
    expect(traitMax(apres)).toBeLessThan(traitMax(g) * 1.25)
  })

  it('garde le lecteur à zéro, et une largeur du même ordre', () => {
    expect(apres.noeuds.find((n) => n.id === g.ego)!.x).toBeCloseTo(0, 6)

    const etendue = (graphe: Graphe): number => {
      const xs = graphe.noeuds.map((n) => n.x)
      return Math.max(...xs) - Math.min(...xs)
    }

    expect(etendue(apres)).toBeLessThan(etendue(g) * 2)
  })

  it('le mouvement retombe, à cette échelle aussi', () => {
    const { deplacements } = mouvements(g)
    expect(deplacements[deplacements.length - 1]!).toBeLessThan(deplacements[0]! / 2)
  })

  it('L’OBJECTIF NE REMONTE À AUCUN TOUR, sur six cents nœuds non plus', () => {
    const { objectifs } = mouvements(g)

    for (let i = 1; i < objectifs.length; i += 1) {
      expect(objectifs[i]!).toBeLessThanOrEqual(objectifs[i - 1]! + 1e-6)
    }
  })

  /* ---------------------------------------------------------------- */
  /* Les trois blocs — déjà posés par le serveur, à ne pas casser       */
  /* ---------------------------------------------------------------- */

  /**
   * **Le serveur sépare déjà l'ascendance paternelle de la maternelle**, et il le
   * fait sans faute : mesuré le 20/09/2026, le côté `p` s'étend de −13 462 à −225,
   * le côté `m` de 684 à 8 006, le tronc de 0 à 455 — **zéro recouvrement**. Il n'y
   * a donc rien à construire ici : il y a à ne rien détruire, et c'est ce que ces
   * trois cas vérifient.
   */
  const cote = (noeud: NoeudGraphe): 'p' | 'm' | null => {
    const branche = (noeud as unknown as { branch?: string | null }).branch
    if (typeof branche !== 'string') return null
    if (branche.startsWith('pp') || branche.startsWith('pm')) return 'p'
    if (branche.startsWith('mp') || branche.startsWith('mm')) return 'm'
    return null
  }

  it('GARDE LES DEUX ASCENDANCES SÉPARÉES — aucun paternel ne passe à droite', () => {
    const paternels = apres.noeuds.filter((n) => cote(n) === 'p').map((n) => n.x)
    const maternels = apres.noeuds.filter((n) => cote(n) === 'm').map((n) => n.x)

    expect(paternels.length).toBeGreaterThan(100)
    expect(maternels.length).toBeGreaterThan(100)
    expect(Math.max(...paternels)).toBeLessThan(Math.min(...maternels))
  })

  it('garde le tronc du lecteur ENTRE les deux ascendances', () => {
    const tronc = apres.noeuds.filter(
      (n) => (n as unknown as { branch?: string | null }).branch === 'tr',
    )
    const paternels = apres.noeuds.filter((n) => cote(n) === 'p').map((n) => n.x)
    const maternels = apres.noeuds.filter((n) => cote(n) === 'm').map((n) => n.x)

    expect(tronc.length).toBeGreaterThan(0)
    for (const noeud of tronc) {
      expect(noeud.x).toBeGreaterThan(Math.max(...paternels))
      expect(noeud.x).toBeLessThan(Math.min(...maternels))
    }
  })

  it('garde les quatre quartiers dans l’ordre pp · pm · mp · mm', () => {
    const moyenne = (quartier: string): number => {
      const xs = apres.noeuds
        .filter((n) => (n as unknown as { branch?: string | null }).branch === quartier)
        .map((n) => n.x)
      return xs.reduce((somme, x) => somme + x, 0) / xs.length
    }

    expect(moyenne('pp')).toBeLessThan(moyenne('pm'))
    expect(moyenne('pm')).toBeLessThan(moyenne('mp'))
    expect(moyenne('mp')).toBeLessThan(moyenne('mm'))
  })

  /* ---------------------------------------------------------------- */
  /* La stabilité à l'ajout — le défaut que /arbre-4 annonce           */
  /* ---------------------------------------------------------------- */

  /**
   * **Ce que coûte une personne de plus.**
   *
   * `/arbre-4` écrit son propre prix : *« ajouter une personne peut renvoyer une
   * branche entière de l'autre côté de l'écran, et deux captures prises à une
   * semaine d'intervalle ne se comparent plus »*. C'est mesurable, et c'est la
   * question que le commanditaire a posée en premier : *mes repères tiennent-ils ?*
   *
   * On retire une feuille — une personne sans union ni enfant —, on replace, et
   * l'on compte **combien de nœuds ont bougé de plus d'une demi-fiche**. Une
   * disposition locale n'en déplace qu'une poignée ; une disposition qui réordonne
   * en déplace des centaines.
   */
  const sansUneFeuille = (): { reduit: Graphe; retiree: string } => {
    const aUneUnion = new Set(g.aretes.filter((a) => a.genre === 'union').map((a) => a.de))
    const feuille = g.noeuds.find(
      (n) =>
        n.type === 'personne' &&
        !aUneUnion.has(n.id) &&
        g.aretes.some((a) => a.genre === 'filiation' && a.vers === n.id),
    )

    if (feuille === undefined) throw new Error('aucune feuille : le cas ne dit rien')

    return {
      reduit: {
        ...g,
        noeuds: g.noeuds.filter((n) => n.id !== feuille.id),
        aretes: g.aretes.filter((a) => a.de !== feuille.id && a.vers !== feuille.id),
      },
      retiree: feuille.id,
    }
  }

  const combienOntBouge = (avant: Graphe, apresAjout: Graphe, ignore: string): number => {
    const x = new Map(apresAjout.noeuds.map((n) => [n.id, n.x]))
    let bouges = 0

    for (const noeud of avant.noeuds) {
      if (noeud.id === ignore) continue
      const nouvelle = x.get(noeud.id)
      if (nouvelle === undefined) continue
      if (Math.abs(nouvelle - noeud.x) > 50) bouges += 1
    }

    return bouges
  }

  it('L’AJOUT D’UNE PERSONNE RESTE LOCAL — et bien plus que sur /arbre-4', () => {
    const { reduit, retiree } = sansUneFeuille()

    const nous = {
      avant: ressortsIsotoniques(reduit, OPTIONS_PAR_DEFAUT),
      apres: ressortsIsotoniques(g, OPTIONS_PAR_DEFAUT),
    }
    const eux = {
      avant: croisementsMinimises(reduit, OPTIONS_PAR_DEFAUT),
      apres: croisementsMinimises(g, OPTIONS_PAR_DEFAUT),
    }

    const nombreNous = combienOntBouge(nous.avant, nous.apres, retiree)
    const nombreEux = combienOntBouge(eux.avant, eux.apres, retiree)

    // Le témoin est `/arbre-4`, pas zéro : une personne de plus déplace
    // forcément quelque chose, et une disposition qui ne bougerait rien ne
    // l'aurait pas placée.
    expect(nombreEux).toBeGreaterThan(0)
    expect(nombreNous).toBeLessThan(nombreEux)
  })

  it('reste déterministe sur six cents nœuds', () => {
    const bis = ressortsIsotoniques(g, OPTIONS_PAR_DEFAUT)
    expect(apres.noeuds.map((n) => [n.id, n.x])).toEqual(bis.noeuds.map((n) => [n.id, n.x]))
  })
})
