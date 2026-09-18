/**
 * ════════════════════════════════════════════════════════════════════
 * LA DUPLICATION ÉCHANGE UN TRAIT LONG CONTRE UN DOUBLON ASSUMÉ.
 *
 * Ce qu'il faut tenir tient en deux points, et le second est le piège :
 *
 * 1. le trait d'union part bien de l'**écho**, sinon on a ajouté une fiche sans
 *    rien supprimer — le pire des deux mondes ;
 * 2. l'écho porte le **même `personId`** que son original. Sans cela, l'arbre
 *    compte deux personnes là où il y en a une, et cliquer sur l'écho ouvrirait
 *    une fiche vide.
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
 * `a1` (enfant, a des parents) est mariée à `x1` (rapportée), et trois personnes
 * s'intercalent entre elles.
 */
const SEPARE: Graphe = {
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
  aretes: [
    u('pa', 'fParents'),
    f('fParents', 'a1'),
    u('a1', 'fCouple'),
    u('x1', 'fCouple'),
  ],
} as unknown as Graphe

const apres = (): Graphe => duplication(SEPARE, OPTIONS_PAR_DEFAUT)

describe('l’écho remplace le trait long', () => {
  it('pose un écho, et un seul', () => {
    expect(compterLesEchos(apres())).toBe(1)
  })

  it('fait partir l’union de l’écho, et non de l’original', () => {
    /*
     * LE POINT CENTRAL. Si l'arête d'origine restait, on aurait ajouté une fiche
     * sans supprimer le trait — le pire des deux mondes : un doublon **et** un
     * croisement.
     */
    const g = apres()
    const versLeCouple = g.aretes.filter((a) => a.genre === 'union' && a.vers === 'fCouple')

    expect(versLeCouple.map((a) => a.de).sort()).toEqual(['a1', 'x1#echo'])
    expect(versLeCouple.some((a) => a.de === 'x1')).toBe(false)
  })

  it('pose l’écho à côté de son partenaire', () => {
    const g = apres()
    const parId = new Map(g.noeuds.map((n) => [n.id, n]))

    const a1 = parId.get('a1')!
    const echo = parId.get('x1#echo')!
    const intercales = g.noeuds.filter(
      (n) => n.type === 'personne' && n.y === 0 && n.x > a1.x && n.x < echo.x,
    )

    expect(intercales).toEqual([])
  })

  it('garde l’original dans la rangée, poussé par l’écho inséré', () => {
    /*
     * L'écho s'insère dans la rangée : tout ce qui était à sa droite recule d'une
     * fiche, l'original compris. C'est le coût assumé de la duplication — la
     * rangée s'élargit —, et non un déplacement décidé quelque part.
     */
    const g = apres()
    const original = g.noeuds.find((n) => n.id === 'x1')!

    expect(original.x).toBeGreaterThan(360)
    expect(original.y).toBe(0)
  })
})

describe('un écho n’est pas une personne de plus', () => {
  it('porte le même `personId` que son original', () => {
    /* Sans cela, l'arbre compte deux personnes là où il y en a une, et cliquer
       sur l'écho ouvrirait une fiche vide. */
    const g = apres()
    const echo = g.noeuds.find((n) => n.id === 'x1#echo')!
    const original = g.noeuds.find((n) => n.id === 'x1')!

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
    const g = apres()
    const echo = g.noeuds.find((n) => n.id === 'x1#echo')!

    expect(echo.y).toBe(0)
  })
})

describe('ce qu’elle ne duplique pas', () => {
  it('laisse tranquille un couple déjà accolé', () => {
    const accole = {
      ...SEPARE,
      noeuds: [
        personne('a1', 0, 0, 1),
        personne('x1', 120, 0, 4),
        famille('fCouple', 60, 46),
      ],
      aretes: [u('a1', 'fCouple'), u('x1', 'fCouple')],
    } as unknown as Graphe

    expect(compterLesEchos(duplication(accole, OPTIONS_PAR_DEFAUT))).toBe(0)
  })

  it('ne duplique pas un couple dont les deux époux ont des parents', () => {
    /*
     * Leur écho porterait **deux** traits — sa filiation et son union — et l'on
     * n'aurait rien simplifié : un doublon de plus, un croisement toujours là.
     */
    const deuxEnfants = {
      ...SEPARE,
      noeuds: [
        personne('pa', 0, -150, 90),
        personne('pb', 400, -150, 91),
        famille('fA', 0, -104),
        famille('fB', 400, -104),
        personne('a1', 0, 0, 1),
        personne('t1', 120, 0, 2),
        personne('t2', 240, 0, 3),
        personne('b1', 360, 0, 4),
        famille('fCouple', 180, 46),
      ],
      aretes: [
        u('pa', 'fA'),
        f('fA', 'a1'),
        u('pb', 'fB'),
        f('fB', 'b1'),
        u('a1', 'fCouple'),
        u('b1', 'fCouple'),
      ],
    } as unknown as Graphe

    expect(compterLesEchos(duplication(deuxEnfants, OPTIONS_PAR_DEFAUT))).toBe(0)
  })

  it('rend le graphe inchangé quand il n’y a rien à dupliquer', () => {
    const rien = { ...SEPARE, aretes: [] } as unknown as Graphe

    expect(duplication(rien, OPTIONS_PAR_DEFAUT)).toBe(rien)
  })
})

describe('la cohérence du résultat', () => {
  it('ne laisse aucune arête pointer dans le vide', () => {
    const g = apres()
    const presents = new Set(g.noeuds.map((n) => n.id))

    for (const arete of g.aretes) {
      expect(presents.has(arete.de), `${arete.de} → ${arete.vers}`).toBe(true)
      expect(presents.has(arete.vers), `${arete.de} → ${arete.vers}`).toBe(true)
    }
  })

  it('replace le losange sur le couple qu’il joint réellement', () => {
    const g = apres()
    const parId = new Map(g.noeuds.map((n) => [n.id, n]))

    const a1 = parId.get('a1')!
    const echo = parId.get('x1#echo')!
    const losange = parId.get('fCouple')!

    expect(losange.x).toBeCloseTo((a1.x + echo.x) / 2, 6)
  })

  it('recalcule la boîte pour contenir les échos', () => {
    const g = apres()

    for (const noeud of g.noeuds) {
      expect(noeud.x - noeud.w / 2).toBeGreaterThanOrEqual(g.boite.x)
      expect(noeud.x + noeud.w / 2).toBeLessThanOrEqual(g.boite.x + g.boite.w)
    }
  })
})
