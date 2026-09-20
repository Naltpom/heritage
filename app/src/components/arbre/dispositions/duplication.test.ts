/**
 * ════════════════════════════════════════════════════════════════════
 * LA DUPLICATION ÉCHANGE UN TRAIT LONG CONTRE UN DOUBLON ASSUMÉ.
 *
 * **Ce fichier a changé de sujet le 20/09/2026**, et il faut dire pourquoi : la
 * page dupliquait le conjoint **rapporté**, c'est-à-dire celui qui n'a aucun
 * parent dans l'arbre. Mesuré sur la charge réelle, ce choix couvrait **zéro**
 * des 61 unions où les deux époux sont enfants de l'arbre — et ce sont elles qui
 * portent **71 des 79 croisements**. Elle travaillait sur les huit autres.
 *
 * Deux choses sont donc éprouvées ici, et la seconde est le cœur :
 *
 * 1. **le conjoint rapporté se DÉPLACE, il ne se dédouble pas.** L'exemplaire
 *    qu'on laissait derrière lui n'avait aucune arête — pas de parents, l'union
 *    partie à l'écho : une carte pleine reliée à rien, à côté d'une carte pâle
 *    reliée au conjoint. Un exemplaire qui ne porte aucun trait ne dit rien.
 * 2. **quand les deux époux sont enfants de l'arbre, l'écho se pose sous le
 *    losange des parents**, avec la filiation, et l'original reste collé au
 *    conjoint avec l'union. Chaque exemplaire porte **exactement un trait**, et
 *    les deux sont courts.
 *
 * Et le piège d'origine tient toujours : l'écho porte le **même `personId`** que
 * son original, sinon l'arbre compte deux personnes là où il y en a une.
 * ════════════════════════════════════════════════════════════════════
 */
import { describe, expect, it } from 'vitest'
import type { AreteGraphe, Graphe, NoeudGraphe } from '@/lib/api'
import { compterLesEchos, duplication, estUnEcho, originalDeLEcho } from './duplication'
import { OPTIONS_PAR_DEFAUT } from './socle'

function personne(id: string, x: number, y: number, personId: number | null): NoeudGraphe {
  return {
    type: 'personne',
    id,
    personId,
    displayName: id,
    etiquetteNom: id,
    ligneDates: '',
    sexe: 'inconnu',
    vivant: false,
    branche: null,
    x,
    y,
    w: 100,
    h: 40,
  } as unknown as NoeudGraphe
}

function famille(id: string, x: number, y: number): NoeudGraphe {
  return { type: 'famille', id, familyId: null, divorced: false, x, y, w: 22, h: 14 } as NoeudGraphe
}

const u = (de: string, vers: string): AreteGraphe => ({ de, vers, genre: 'union' }) as AreteGraphe
const f = (de: string, vers: string): AreteGraphe =>
  ({ de, vers, genre: 'filiation' }) as AreteGraphe

/**
 * `a1` (enfant, a des parents) est mariée à `x1` (rapportée), et deux personnes
 * s'intercalent entre elles.
 */
const RAPPORTE: Graphe = {
  empreinte: 'a'.repeat(64),
  ego: 'a1',
  boite: { x: -500, y: -500, w: 2000, h: 1000 },
  calque: { actif: false, demandes: [], proposes: 0, places: 0, nonPlaces: [] },
  noeuds: [
    personne('pa', 0, -150, 90),
    famille('fParents', 0, -104),
    personne('a1', 0, 0, 1),
    personne('t1', 120, 0, 2),
    personne('t2', 240, 0, 3),
    personne('x1', 360, 0, 4),
    famille('fCouple', 180, 46),
  ],
  aretes: [u('pa', 'fParents'), f('fParents', 'a1'), u('a1', 'fCouple'), u('x1', 'fCouple')],
} as unknown as Graphe

/**
 * **Le cas qui compte.** `a1` et `b1` sont chacun enfant d'une famille de
 * l'arbre ; le moteur les a gardés accolés, et celui qui a quitté les siens
 * traîne une filiation à travers la rangée. `b2` est la sœur de `b1`, restée
 * de son côté.
 */
const DEUX_ENFANTS: Graphe = {
  empreinte: 'b'.repeat(64),
  ego: 'a1',
  boite: { x: -500, y: -500, w: 2000, h: 1000 },
  calque: { actif: false, demandes: [], proposes: 0, places: 0, nonPlaces: [] },
  noeuds: [
    personne('pa', 0, -150, 90),
    personne('pb', 900, -150, 91),
    famille('fA', 0, -104),
    famille('fB', 900, -104),
    personne('a1', 0, 0, 1),
    personne('b1', 120, 0, 4),
    personne('t1', 400, 0, 2),
    personne('a2', 520, 0, 7),
    personne('a3', 640, 0, 8),
    personne('b2', 900, 0, 5),
    famille('fCouple', 60, 46),
  ],
  aretes: [
    u('pa', 'fA'),
    f('fA', 'a1'),
    f('fA', 'a2'),
    f('fA', 'a3'),
    u('pb', 'fB'),
    f('fB', 'b1'),
    f('fB', 'b2'),
    u('a1', 'fCouple'),
    u('b1', 'fCouple'),
  ],
} as unknown as Graphe

const parId = (g: Graphe): Map<string, NoeudGraphe> => new Map(g.noeuds.map((n) => [n.id, n]))
const aretesDe = (g: Graphe, id: string): AreteGraphe[] =>
  g.aretes.filter((a) => a.de === id || a.vers === id)

/* ------------------------------------------------------------------ */

describe('le conjoint rapporté se déplace, il ne se dédouble pas', () => {
  const g = duplication(RAPPORTE, OPTIONS_PAR_DEFAUT)

  it('ne pose aucun écho', () => {
    /*
     * L'exemplaire laissé derrière n'aurait AUCUNE arête : pas de parents, et
     * l'union partie à l'écho. Mesuré le 20/09/2026 sur l'arbre réel, les six
     * originaux de la version précédente étaient dans ce cas.
     */
    expect(compterLesEchos(g)).toBe(0)
  })

  it('amène le rapporté contre son conjoint', () => {
    const N = parId(g)
    const a1 = N.get('a1')!
    const x1 = N.get('x1')!
    const entre = g.noeuds.filter(
      (n) => n.type === 'personne' && n.y === 0 && n.x > Math.min(a1.x, x1.x) && n.x < Math.max(a1.x, x1.x),
    )

    expect(entre).toEqual([])
  })

  it('garde son union sur lui, et n’ajoute aucune arête', () => {
    expect(aretesDe(g, 'x1').map((a) => a.genre)).toEqual(['union'])
    expect(g.aretes).toHaveLength(RAPPORTE.aretes.length)
  })
})

describe('les deux époux enfants de l’arbre : l’écho rejoint la fratrie', () => {
  const g = duplication(DEUX_ENFANTS, OPTIONS_PAR_DEFAUT)

  it('pose un écho, et un seul', () => {
    // `a1` vient d'une fratrie de trois, `b1` d'une fratrie de deux : le couple
    // rejoint la plus nombreuse, donc c'est `b1` qui quitte les siens.
    expect(compterLesEchos(g)).toBe(1)
    expect(g.noeuds.some((n) => n.id === 'b1#echo')).toBe(true)
  })

  it('fait partir la FILIATION de l’écho, et non de l’original', () => {
    /*
     * LE POINT CENTRAL. Si l'arête d'origine restait, on aurait ajouté une fiche
     * sans supprimer le trait — le pire des deux mondes : un doublon **et** un
     * croisement.
     */
    const depuisLesParents = g.aretes.filter((a) => a.genre === 'filiation' && a.de === 'fB')

    expect(depuisLesParents.map((a) => a.vers).sort()).toEqual(['b1#echo', 'b2'])
    expect(depuisLesParents.some((a) => a.vers === 'b1')).toBe(false)
  })

  it('donne UN trait à chaque exemplaire, et c’est ce qui rend le cas praticable', () => {
    /*
     * La page refusait ce cas en écrivant qu'un tel écho porterait deux traits.
     * C'est vrai de l'autre construction — celle où l'écho prend l'union alors
     * que l'original garde la filiation. Ici les rôles sont répartis.
     */
    expect(aretesDe(g, 'b1').map((a) => a.genre)).toEqual(['union'])
    expect(aretesDe(g, 'b1#echo').map((a) => a.genre)).toEqual(['filiation'])
  })

  it('pose l’écho AU CONTACT de sa fratrie, sous le losange de ses parents', () => {
    /*
     * Il est inséré à l'abscisse du losange, puis la passe d'écartement lui fait
     * la place — il se retrouve donc **collé à sa sœur**, à une fiche du losange
     * et non dessus. C'est la promesse qui compte : la fratrie l'a retrouvé.
     */
    const N = parId(g)
    const echo = N.get('b1#echo')!
    const rangee = g.noeuds
      .filter((n) => n.type === 'personne' && n.y === 0)
      .sort((p, q) => p.x - q.x)
    const ou = rangee.findIndex((n) => n.id === 'b1#echo')
    const voisins = [rangee[ou - 1]?.id, rangee[ou + 1]?.id]

    expect(voisins).toContain('b2')
    expect(Math.abs(echo.x - N.get('fB')!.x)).toBeLessThan(200)
  })

  it('laisse l’original collé à son conjoint', () => {
    const N = parId(g)
    const entre = g.noeuds.filter(
      (n) => n.type === 'personne' && n.y === 0 && n.x > N.get('a1')!.x && n.x < N.get('b1')!.x,
    )

    expect(entre).toEqual([])
  })
})

describe('un écho n’est pas une personne de plus', () => {
  const g = duplication(DEUX_ENFANTS, OPTIONS_PAR_DEFAUT)

  it('porte le même `personId` que son original', () => {
    /* Sans cela, l'arbre compte deux personnes là où il y en a une, et cliquer
       sur l'écho ouvrirait une fiche vide. */
    const N = parId(g)
    const echo = N.get('b1#echo')!
    const original = N.get('b1')!

    expect(echo.type === 'personne' && original.type === 'personne').toBe(true)
    expect((echo as { personId: number | null }).personId).toBe(
      (original as { personId: number | null }).personId,
    )
  })

  it('se reconnaît à son identifiant, et rend celui de son original', () => {
    expect(estUnEcho('x1#echo')).toBe(true)
    expect(estUnEcho('x1')).toBe(false)
    expect(originalDeLEcho('x1#echo')).toBe('x1')
    expect(originalDeLEcho('x1')).toBe('x1')
  })

  it('reste sur la rangée de son original', () => {
    expect(parId(g).get('b1#echo')!.y).toBe(0)
  })
})

describe('ce qu’elle ne touche pas', () => {
  it('laisse tranquille un couple déjà accolé, sans parents des deux côtés', () => {
    const accole = {
      ...RAPPORTE,
      noeuds: [personne('a1', 0, 0, 1), personne('x1', 120, 0, 4), famille('fCouple', 60, 46)],
      aretes: [u('a1', 'fCouple'), u('x1', 'fCouple')],
    } as unknown as Graphe

    expect(duplication(accole, OPTIONS_PAR_DEFAUT)).toBe(accole)
  })

  it('ne duplique pas celui qui touche déjà l’un des siens', () => {
    /*
     * **Condition, pas seuil.** Si la personne est assise à côté d'un frère ou
     * d'une sœur, sa fratrie ne l'a pas perdue : son trait de filiation est court
     * et il n'y a rien à réparer.
     */
    const colle = {
      ...DEUX_ENFANTS,
      noeuds: DEUX_ENFANTS.noeuds.map((n) => (n.id === 'b2' ? { ...n, x: 240 } : n)),
    } as unknown as Graphe

    expect(compterLesEchos(duplication(colle, OPTIONS_PAR_DEFAUT))).toBe(0)
  })

  it('rend le graphe inchangé quand il n’y a rien à faire', () => {
    const rien = { ...RAPPORTE, aretes: [] } as unknown as Graphe

    expect(duplication(rien, OPTIONS_PAR_DEFAUT)).toBe(rien)
  })
})

describe('la cohérence du résultat', () => {
  const g = duplication(DEUX_ENFANTS, OPTIONS_PAR_DEFAUT)

  it('ne laisse aucune arête pointer dans le vide', () => {
    const presents = new Set(g.noeuds.map((n) => n.id))

    for (const arete of g.aretes) {
      expect(presents.has(arete.de), `${arete.de} → ${arete.vers}`).toBe(true)
      expect(presents.has(arete.vers), `${arete.de} → ${arete.vers}`).toBe(true)
    }
  })

  it('replace le losange sur le couple qu’il joint réellement', () => {
    const N = parId(g)
    const losange = N.get('fCouple')!

    expect(losange.x).toBeCloseTo((N.get('a1')!.x + N.get('b1')!.x) / 2, 6)
  })

  it('recalcule la boîte pour contenir les échos', () => {
    for (const noeud of g.noeuds) {
      expect(noeud.x - noeud.w / 2).toBeGreaterThanOrEqual(g.boite.x)
      expect(noeud.x + noeud.w / 2).toBeLessThanOrEqual(g.boite.x + g.boite.w)
    }
  })
})
