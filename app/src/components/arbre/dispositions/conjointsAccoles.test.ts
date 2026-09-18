/**
 * ════════════════════════════════════════════════════════════════════
 * LA DISPOSITION « CONJOINTS ACCOLÉS » AMÉLIORE, ET NE DÉGRADE RIEN.
 *
 * Ce que ces cas tiennent tient en une phrase : **le nombre de couples séparés
 * baisse, et aucun couple déjà accolé ne le devient.** C'est la propriété qui
 * distingue une correction d'un déplacement du problème — et c'est exactement
 * le piège de cette disposition, où l'on peut très bien rapprocher un couple en
 * en séparant un autre, à somme nulle, sans que l'œil le voie sur 377 nœuds.
 *
 * La seconde règle est la limite assumée : **on ne déplace qu'un conjoint sans
 * parents dans l'arbre**. Déplacer un enfant ne supprimerait pas le trait long,
 * il le transférerait de son trait d'union vers son trait de filiation.
 * ════════════════════════════════════════════════════════════════════
 */
import { describe, expect, it } from 'vitest'
import type { AreteGraphe, Graphe, NoeudGraphe } from '@/lib/api'
import { conjointsAccoles } from './conjointsAccoles'
import { mesurerLisibilite } from './socle'

/* ------------------------------------------------------------------ */
/* Fabrique : un arbre écrit à la main, rangée par rangée              */
/* ------------------------------------------------------------------ */

function personne(id: string, x: number, y = 0, w = 100): NoeudGraphe {
  return {
    type: 'personne',
    id,
    personId: Number(id.replace(/\D/g, '')) || null,
    displayName: id,
    etiquetteNom: id,
    ligneDates: '',
    sexe: 'inconnu',
    vivant: false,
    branche: null,
    x,
    y,
    w,
    h: 40,
  } as unknown as NoeudGraphe
}

function famille(id: string, x: number, y = 46): NoeudGraphe {
  return { type: 'famille', id, familyId: null, divorced: false, x, y, w: 22, h: 14 } as NoeudGraphe
}

function union(de: string, vers: string): AreteGraphe {
  return { de, vers, genre: 'union' } as AreteGraphe
}

function filiation(de: string, vers: string): AreteGraphe {
  return { de, vers, genre: 'filiation' } as AreteGraphe
}

function graphe(noeuds: NoeudGraphe[], aretes: AreteGraphe[]): Graphe {
  return {
    empreinte: 'a'.repeat(64),
    ego: noeuds[0]?.id ?? 'p1',
    boite: { x: -500, y: -500, w: 2000, h: 1000 },
    calque: { actif: false, demandes: [], proposes: 0, places: 0, nonPlaces: [] },
    noeuds,
    aretes,
  } as unknown as Graphe
}

/** L'ordre des personnes d'une rangée, de gauche à droite. */
function ordre(g: Graphe, y = 0): string[] {
  return g.noeuds
    .filter((n) => n.type === 'personne' && n.y === y)
    .sort((a, b) => a.x - b.x)
    .map((n) => n.id)
}

/* ------------------------------------------------------------------ */

describe('un conjoint rapporté rejoint son partenaire', () => {
  /**
   * Le cas relevé sur l'arbre réel, en réduction.
   *
   * Deux fratries voisines — les A et les B —, chacune avec une personne mariée à
   * quelqu'un de rapporté. Les deux conjoints rapportés atterrissent après les
   * deux fratries, et les couples s'entrelacent :
   *
   * ```
   *   a1   b1   x1   x2        au lieu de   a1   x1   b1   x2
   *    └── couple ──┘                        └couple┘   └couple┘
   *         └── couple ──┘
   * ```
   */
  const CAS = graphe(
    [
      personne('a1', 0),
      personne('b1', 120),
      personne('x1', 240),
      personne('x2', 360),
      famille('fA', 60),
      famille('fB', 180),
      personne('pa', 0, -150),
      personne('pb', 120, -150),
      famille('fParentsA', 0, -104),
      famille('fParentsB', 120, -104),
    ],
    [
      // a1 épouse x1 ; b1 épouse x2.
      union('a1', 'fA'),
      union('x1', 'fA'),
      union('b1', 'fB'),
      union('x2', 'fB'),
      // a1 et b1 ont des parents ; x1 et x2 sont rapportés.
      union('pa', 'fParentsA'),
      filiation('fParentsA', 'a1'),
      union('pb', 'fParentsB'),
      filiation('fParentsB', 'b1'),
    ],
  )

  it('désentrelace les deux couples', () => {
    expect(ordre(CAS)).toEqual(['a1', 'b1', 'x1', 'x2'])
    expect(ordre(conjointsAccoles(CAS))).toEqual(['a1', 'x1', 'b1', 'x2'])
  })

  it('fait baisser le nombre de couples séparés, et n’en sépare aucun', () => {
    const avant = mesurerLisibilite(CAS)
    const apres = mesurerLisibilite(conjointsAccoles(CAS))

    expect(avant.separes).toBe(2)
    expect(apres.separes).toBe(0)
    // La garde qui compte : le total de couples ne bouge pas, donc rien n'a été
    // « gagné » en cassant un couple ailleurs.
    expect(apres.couples).toBe(avant.couples)
    expect(apres.accoles).toBeGreaterThan(avant.accoles)
  })

  it('emmène le losange avec le couple', () => {
    /* Un losange qui ne suit pas ses époux pointe à côté du couple qu'il joint,
       et les deux traits d'union partent de travers — ce serait remplacer un
       défaut visible par un autre. */
    const apres = conjointsAccoles(CAS)
    const parId = new Map(apres.noeuds.map((n) => [n.id, n]))

    const a1 = parId.get('a1')!
    const x1 = parId.get('x1')!
    const fA = parId.get('fA')!

    expect(fA.x).toBeCloseTo((a1.x + x1.x) / 2, 6)
  })
})

describe('ce qu’elle refuse de déplacer', () => {
  /**
   * **Deux enfants mariés l'un à l'autre.** Aucun échange local ne peut les
   * rapprocher : celui qu'on déplacerait quitterait sa propre fratrie, et son
   * trait de filiation traverserait la rangée à la place du trait d'union. On
   * échangerait un trait long contre un autre.
   */
  const DEUX_ENFANTS = graphe(
    [
      personne('a1', 0),
      personne('b1', 120),
      personne('a2', 240),
      personne('b2', 360),
      famille('fMixte', 180),
      personne('pa', 0, -150),
      personne('pb', 200, -150),
      famille('fA', 0, -104),
      famille('fB', 200, -104),
    ],
    [
      // a1 épouse b2 : les deux ont des parents.
      union('a1', 'fMixte'),
      union('b2', 'fMixte'),
      union('pa', 'fA'),
      filiation('fA', 'a1'),
      filiation('fA', 'a2'),
      union('pb', 'fB'),
      filiation('fB', 'b1'),
      filiation('fB', 'b2'),
    ],
  )

  it('laisse la rangée intacte', () => {
    expect(ordre(conjointsAccoles(DEUX_ENFANTS))).toEqual(ordre(DEUX_ENFANTS))
  })

  it('le dit dans la mesure : le couple reste séparé', () => {
    const apres = mesurerLisibilite(conjointsAccoles(DEUX_ENFANTS))

    expect(apres.separes).toBe(1)
  })
})

describe('les garde-fous', () => {
  /**
   * **On n'insère pas entre deux époux déjà accolés.**
   *
   * `b1` et `x2` forment un couple voisin. En posant `x1` juste après `b1`, on
   * les séparerait — un couple gagné, un couple perdu, à somme nulle. `x1` doit
   * donc se poser **après** `x2`.
   */
  const COUPLE_EN_PLACE = graphe(
    [
      personne('b1', 0),
      personne('x2', 120),
      personne('a1', 240),
      personne('x1', 360),
      famille('fB', 60),
      famille('fA', 300),
      personne('pa', 240, -150),
      famille('fParentsA', 240, -104),
    ],
    [
      union('b1', 'fB'),
      union('x2', 'fB'),
      union('a1', 'fA'),
      union('x1', 'fA'),
      union('pa', 'fParentsA'),
      filiation('fParentsA', 'a1'),
      filiation('fParentsA', 'b1'),
    ],
  )

  it('ne casse pas un couple déjà voisin', () => {
    const avant = mesurerLisibilite(COUPLE_EN_PLACE)
    const apres = mesurerLisibilite(conjointsAccoles(COUPLE_EN_PLACE))

    expect(apres.accoles).toBeGreaterThanOrEqual(avant.accoles)
    expect(apres.separes).toBeLessThanOrEqual(avant.separes)
  })

  it('est idempotente : la rejouer ne déplace plus rien', () => {
    /* Une disposition qui bouge encore au second passage n'a pas de point fixe,
       et deux affichages du même arbre donneraient deux images. */
    const une = conjointsAccoles(COUPLE_EN_PLACE)
    const deux = conjointsAccoles(une)

    expect(ordre(deux)).toEqual(ordre(une))
    expect(deux.noeuds.map((n) => [n.id, n.x])).toEqual(une.noeuds.map((n) => [n.id, n.x]))
  })

  it('ne touche à aucune ordonnée : les générations sont une propriété de la topologie', () => {
    const avant = new Map(COUPLE_EN_PLACE.noeuds.map((n) => [n.id, n.y]))

    for (const noeud of conjointsAccoles(COUPLE_EN_PLACE).noeuds) {
      expect(noeud.y, noeud.id).toBe(avant.get(noeud.id))
    }
  })

  it('n’ajoute ni ne retire aucun nœud ni aucune arête', () => {
    const apres = conjointsAccoles(COUPLE_EN_PLACE)

    expect(apres.noeuds).toHaveLength(COUPLE_EN_PLACE.noeuds.length)
    expect(apres.aretes).toEqual(COUPLE_EN_PLACE.aretes)
  })

  it('recalcule la boîte englobante', () => {
    /* Elle alimente le `viewBox` initial : la laisser telle quelle ouvrirait la
       vue sur un cadre qui ne contient plus tout l'arbre, et le défaut ne se
       verrait qu'au bord. */
    const apres = conjointsAccoles(COUPLE_EN_PLACE)

    for (const noeud of apres.noeuds) {
      expect(noeud.x - noeud.w / 2).toBeGreaterThanOrEqual(apres.boite.x)
      expect(noeud.x + noeud.w / 2).toBeLessThanOrEqual(apres.boite.x + apres.boite.w)
    }
  })
})

describe('un graphe sans rien à faire', () => {
  it('traverse sans dommage un arbre vide', () => {
    const vide = graphe([], [])

    expect(conjointsAccoles(vide).noeuds).toEqual([])
  })

  it('laisse un couple déjà accolé exactement où il est', () => {
    const bon = graphe(
      [personne('a1', 0), personne('x1', 120), famille('f', 60)],
      [union('a1', 'f'), union('x1', 'f')],
    )

    const apres = conjointsAccoles(bon)

    expect(apres.noeuds.map((n) => n.x)).toEqual(bon.noeuds.map((n) => n.x))
  })
})
