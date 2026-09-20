/**
 * ════════════════════════════════════════════════════════════════════
 * **CE QUI EST ÉPROUVÉ ICI, C'EST UNE GÉOMÉTRIE — PAS UN RÉSULTAT MOYEN.**
 *
 * Les dispositions qui replacent rangée par rangée promettent d'améliorer un
 * nombre. Celle-ci promet quelque chose de plus fort et de plus vérifiable :
 * **certains dessins deviennent impossibles**. Trois gardes en découlent, et
 * chacune tombe si la rigidité est perdue :
 *
 * 1. **deux blocs frères ne se recouvrent jamais**, même quand l'un est large en
 *    haut et l'autre large en bas — c'est la propriété que `Σ des max` tient et
 *    que `max des Σ` perd, et c'est exactement l'erreur qui a fait annoncer une
 *    largeur de 29 033 px là où la maquette en pose 51 448 ;
 * 2. **l'ordre des enfants est CONSTRUIT** : il sort dans l'ordre reçu, sans
 *    passe de correction, et aucun réglage ne peut l'inverser ;
 * 3. **les conjoints restent accolés** — rien ne s'insère entre eux, jamais.
 *
 * Les noms sont inventés — aucune personne réelle n'entre dans le dépôt (ADR 0006).
 * ════════════════════════════════════════════════════════════════════
 */
import { describe, expect, it } from 'vitest'
import type { AreteGraphe, Graphe, NoeudGraphe } from '@/lib/api'
import {
  absorbes,
  blocsEnfants,
  blocsRigides,
  degagerLesRangees,
  groupes,
  largeurPropre,
  personnesNonPlacees,
  racines,
  topologie,
  unionsInterBlocs,
} from './blocs'
import { ESPACE, mesurerLisibilite, OPTIONS_PAR_DEFAUT } from './socle'

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

function grapheDe(noeuds: NoeudGraphe[], aretes: AreteGraphe[], ego = noeuds[0]?.id ?? ''): Graphe {
  return {
    empreinte: 'essai',
    ego,
    boite: { x: 0, y: 0, w: 0, h: 0 },
    calque: { actif: false, demandes: [], proposes: 0, places: 0, nonPlaces: [] },
    noeuds,
    aretes,
  } as unknown as Graphe
}

const x = (graphe: Graphe, id: string): number => graphe.noeuds.find((n) => n.id === id)?.x ?? Number.NaN

/** Les fiches d'une rangée, de gauche à droite. */
const rangee = (graphe: Graphe, y: number): string[] =>
  graphe.noeuds
    .filter((n) => n.type === 'personne' && n.y === y)
    .sort((a, b) => a.x - b.x)
    .map((n) => n.id)

/* ------------------------------------------------------------------ */

/**
 * Un couple, deux enfants, et les abscisses reçues **dans le désordre** : le
 * cadet est posé à gauche de l'aîné. La maquette doit rendre l'ordre reçu, pas
 * l'ordre des identifiants.
 */
function coupleEtDeuxEnfants(): Graphe {
  return grapheDe(
    [
      personne('PERE', 0, 0),
      personne('MERE', 120, 0),
      famille('f1', 60, 26),
      personne('AINE', 300, 150),
      personne('CADET', 60, 150),
    ],
    [u('PERE', 'f1'), u('MERE', 'f1'), f('f1', 'AINE'), f('f1', 'CADET')],
    'PERE',
  )
}

describe('les blocs rigides', () => {
  it('posent un enfant unique exactement sous le losange de ses parents', () => {
    const g = grapheDe(
      [
        personne('PERE', 0, 0),
        personne('MERE', 120, 0),
        famille('f1', 60, 26),
        personne('FILS', 900, 150),
      ],
      [u('PERE', 'f1'), u('MERE', 'f1'), f('f1', 'FILS')],
      'PERE',
    )

    const b = blocsRigides(g, OPTIONS_PAR_DEFAUT)

    expect(x(b, 'FILS')).toBeCloseTo(x(b, 'f1'), 6)
  })

  it('rendent l’ordre REÇU des enfants, et non celui des identifiants', () => {
    const b = blocsRigides(coupleEtDeuxEnfants(), OPTIONS_PAR_DEFAUT)

    expect(rangee(b, 150)).toEqual(['CADET', 'AINE'])
  })

  it('gardent les conjoints accolés : rien ne s’insère entre eux', () => {
    const g = grapheDe(
      [
        personne('PERE', 0, 0),
        personne('MERE', 120, 0),
        famille('f1', 60, 26),
        // Deux enfants mariés : quatre fiches sur la rangée du bas, deux couples.
        personne('FILLE', 0, 150),
        personne('GENDRE', 120, 150),
        famille('f2', 60, 176),
        personne('FILS', 240, 150),
        personne('BRU', 360, 150),
        famille('f3', 300, 176),
      ],
      [
        u('PERE', 'f1'),
        u('MERE', 'f1'),
        f('f1', 'FILLE'),
        f('f1', 'FILS'),
        u('FILLE', 'f2'),
        u('GENDRE', 'f2'),
        u('FILS', 'f3'),
        u('BRU', 'f3'),
      ],
      'PERE',
    )

    const b = blocsRigides(g, OPTIONS_PAR_DEFAUT)

    expect(rangee(b, 150)).toEqual(['FILLE', 'GENDRE', 'FILS', 'BRU'])
    expect(mesurerLisibilite(b).separes).toBe(0)
  })

  /**
   * **La garde qui distingue « rigide » de « à peu près ».**
   *
   * Le premier bloc est étroit en haut et large en bas, le second l'inverse. Une
   * largeur calculée comme `max(Σ des étages du haut, Σ des étages du bas)` —
   * la formule qui a servi à annoncer 29 033 px — laisse les deux se recouvrir ;
   * `Σ des max` ne le peut pas. Le test échoue exactement sur cette confusion.
   */
  it('ne laissent JAMAIS deux blocs frères se recouvrir', () => {
    const g = grapheDe(
      [
        personne('AIEUL', 0, 0),
        personne('AIEULE', 120, 0),
        famille('f0', 60, 26),
        // Bloc A : une seule fiche en haut, trois larges en dessous.
        personne('A', 0, 150),
        famille('fa', 0, 176),
        personne('A1', -200, 300, 300),
        personne('A2', 100, 300, 300),
        personne('A3', 400, 300, 300),
        // Bloc B : trois larges en haut (B et ses deux conjoints), rien dessous.
        personne('B', 700, 150, 300),
        personne('B1', 1000, 150, 300),
        personne('B2', 1300, 150, 300),
        famille('fb1', 850, 176),
        famille('fb2', 1150, 176),
      ],
      [
        u('AIEUL', 'f0'),
        u('AIEULE', 'f0'),
        f('f0', 'A'),
        f('f0', 'B'),
        u('A', 'fa'),
        f('fa', 'A1'),
        f('fa', 'A2'),
        f('fa', 'A3'),
        u('B', 'fb1'),
        u('B1', 'fb1'),
        u('B', 'fb2'),
        u('B2', 'fb2'),
      ],
      'AIEUL',
    )

    const b = blocsRigides(g, OPTIONS_PAR_DEFAUT)

    // Le bloc A occupe [gauche(A1), droite(A3)] ; B commence après.
    const droiteDeA = Math.max(x(b, 'A1'), x(b, 'A2'), x(b, 'A3')) + 150
    const gaucheDeB = Math.min(x(b, 'B'), x(b, 'B1'), x(b, 'B2')) - 150

    expect(gaucheDeB).toBeGreaterThanOrEqual(droiteDeA + ESPACE - 0.001)
  })

  it('n’oublient personne, et supportent une charge vide', () => {
    expect(personnesNonPlacees(coupleEtDeuxEnfants())).toEqual([])
    expect(blocsRigides(grapheDe([], []), OPTIONS_PAR_DEFAUT).noeuds).toEqual([])
  })

  it('laissent le lecteur à l’origine', () => {
    const b = blocsRigides(coupleEtDeuxEnfants(), OPTIONS_PAR_DEFAUT)

    expect(x(b, 'PERE')).toBe(0)
  })

  it('recalent le losange sur ses époux', () => {
    const b = blocsRigides(coupleEtDeuxEnfants(), OPTIONS_PAR_DEFAUT)

    expect(x(b, 'f1')).toBeCloseTo((x(b, 'PERE') + x(b, 'MERE')) / 2, 6)
  })
})

/* ------------------------------------------------------------------ */

/**
 * **Quand les deux époux sont enfants de l'arbre.** C'est le cas qui décide de
 * tout le reste : le couple ne peut pas être dans les deux fratries à la fois.
 */
describe('l’arbitrage du conjoint entrant', () => {
  function deuxFratries(petiteDroite: boolean): Graphe {
    const noeuds = [
      personne('P1', 0, 0),
      famille('g1', 0, 26),
      personne('P2', 600, 0),
      famille('g2', 600, 26),
      // fratrie de gauche : trois enfants
      personne('L1', -200, 150),
      personne('L2', -80, 150),
      personne('EPOUX', 40, 150),
      // fratrie de droite : un seul enfant
      personne('EPOUSE', 600, 150),
      famille('m', 320, 176),
    ]
    const aretes = [
      u('P1', 'g1'),
      f('g1', 'L1'),
      f('g1', 'L2'),
      f('g1', 'EPOUX'),
      u('P2', 'g2'),
      f('g2', 'EPOUSE'),
      u('EPOUX', 'm'),
      u('EPOUSE', 'm'),
    ]
    if (!petiteDroite) aretes.push(f('g2', 'AUTRE1'), f('g2', 'AUTRE2'), f('g2', 'AUTRE3'))
    if (!petiteDroite) {
      noeuds.push(personne('AUTRE1', 720, 150), personne('AUTRE2', 840, 150), personne('AUTRE3', 960, 150))
    }

    return grapheDe(noeuds, aretes, 'P1')
  }

  it('fait rejoindre la PLUS NOMBREUSE des deux fratries — celle de gauche', () => {
    const g = deuxFratries(true)
    const topo = topologie(g)
    const absorbe = absorbes(topo, new Map(g.noeuds.map((n) => [n.id, n.x])))

    // La fratrie de gauche compte trois enfants, celle de droite un : c'est
    // l'épouse qui quitte la sienne.
    expect([...absorbe]).toEqual(['EPOUSE'])
  })

  it('bascule quand c’est l’autre fratrie qui est la plus nombreuse', () => {
    const g = deuxFratries(false)
    const topo = topologie(g)
    const absorbe = absorbes(topo, new Map(g.noeuds.map((n) => [n.id, n.x])))

    expect([...absorbe]).toEqual(['EPOUX'])
  })

  it('compte l’union inter-blocs et mesure le trait qu’elle coûte', () => {
    const g = deuxFratries(true)
    const b = blocsRigides(g, OPTIONS_PAR_DEFAUT)
    const topo = topologie(g)
    const absorbe = absorbes(topo, new Map(g.noeuds.map((n) => [n.id, n.x])))

    const inter = unionsInterBlocs(b, topo, absorbe)

    expect(inter).toHaveLength(1)
    expect(inter[0]!.personne).toBe('EPOUSE')
    // L'épouse est restée collée à son époux ; son trait de filiation traverse.
    expect(inter[0]!.trait).toBeGreaterThan(100)
  })
})

/* ------------------------------------------------------------------ */

describe('les pièces du modèle', () => {
  it('groupent les deux époux ensemble, et le remarié avec ses deux conjoints', () => {
    const g = grapheDe(
      [
        personne('LUI', 0, 0),
        personne('ELLE', 120, 0),
        personne('AUTRE', 240, 0),
        famille('f1', 60, 26),
        famille('f2', 180, 26),
      ],
      [u('LUI', 'f1'), u('ELLE', 'f1'), u('LUI', 'f2'), u('AUTRE', 'f2')],
      'LUI',
    )
    const topo = topologie(g)
    const { groupes: liste, groupeDe } = groupes(g.noeuds, topo, new Set())

    expect(liste).toHaveLength(1)
    expect(liste[0]!.membres).toEqual(['LUI', 'ELLE', 'AUTRE'])
    expect(groupeDe.get('AUTRE')).toBe(0)
    expect(liste[0]!.ancre).toBeNull()
  })

  it('n’attachent un bloc qu’à SON ancre — jamais deux fois', () => {
    const g = coupleEtDeuxEnfants()
    const topo = topologie(g)
    const absorbe = absorbes(topo, new Map(g.noeuds.map((n) => [n.id, n.x])))
    const { groupes: liste, groupeDe } = groupes(g.noeuds, topo, absorbe)

    const parents = new Map<number, number>()
    for (let i = 0; i < liste.length; i += 1) {
      for (const fils of blocsEnfants(i, { groupes: liste, groupeDe, topo, absorbe })) {
        parents.set(fils, (parents.get(fils) ?? 0) + 1)
      }
    }

    expect([...parents.values()].every((n) => n === 1)).toBe(true)
    expect(racines(liste, new Map(g.noeuds.map((n) => [n.id, n.x])))).toHaveLength(1)
  })

  it('mesurent la largeur propre d’un groupe, marge comprise', () => {
    const w = new Map([
      ['A', 100],
      ['B', 60],
    ])

    expect(largeurPropre([], w)).toBe(0)
    expect(largeurPropre(['A'], w)).toBe(100)
    expect(largeurPropre(['A', 'B'], w)).toBe(100 + ESPACE + 60)
  })

  /**
   * **Le cas que la géométrie seule ne couvre pas**, et qui existe dans l'arbre
   * réel : deux époux que le moteur place sur des rangées différentes. Le bloc
   * réserve alors une place sur la rangée du haut pour quelqu'un qui est en bas,
   * et un descendant peut retomber dessus. La garde de fin le dégage.
   */
  it('dégagent une rangée où deux blocs se télescopent, sans réordonner', () => {
    const g = grapheDe(
      [personne('A', 0, 0), personne('B', 10, 0), personne('C', 20, 0)],
      [],
      'A',
    )
    const serrees = new Map([
      ['A', 0],
      ['B', 10],
      ['C', 20],
    ])

    const degage = degagerLesRangees(g, serrees)

    expect(degage.get('A')).toBe(0)
    expect(degage.get('B')).toBe(100 + ESPACE)
    expect(degage.get('C')).toBe(2 * (100 + ESPACE))
  })
})
