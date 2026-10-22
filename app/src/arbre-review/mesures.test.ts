import { describe, expect, it } from 'vitest'
import type { NoeudPersonne } from '@/lib/api'
import type { DispositionHistoire, FicheHistoire } from '@/histoire/lignees'
import { mesurer, segmentsDe } from './mesures'

/**
 * Ce que ces tests protègent : la règle qui compte, la même pour `/arbre` et pour
 * `/arbre-review`. Un banc qui compterait faux comparerait deux moteurs sur un chiffre sans
 * objet : on vérifie chaque mesure sur un dessin fait à la main, dont on connaît la réponse.
 */

function fiche(id: string, x: number, y: number, rangee: number, w = 100, h = 50): FicheHistoire {
  const noeud = { id, type: 'personne', personId: null, displayName: id, etiquetteNom: id, sex: 'inconnu', w, h } as unknown as NoeudPersonne
  return { id, noeud, x, y, w, h, cx: x + w / 2, rangee, directe: false, sosa: null, detache: false, parente: 'famille', pont: null, branche: 'tr' }
}

function disposition(fiches: FicheHistoire[], familles: { id: string; d: string; personnes: string[] }[]): DispositionHistoire {
  return {
    ego: fiches[0]!.id,
    fiches,
    parId: new Map(fiches.map((f) => [f.id, f])),
    parPersonId: new Map(),
    traces: { ordinaires: familles.map((f) => f.d).join(''), directs: '', divorces: '', attente: '', familles },
    foyers: [],
    quarts: [],
    alliances: [],
    alliancesDansLArbre: false,
    zoneDetachee: null,
    generations: [],
    largeur: 1000,
    hauteur: 500,
    pasRangee: 150,
  }
}

describe('la lecture des traits', () => {
  it('relit des segments `M x y L x y`, et des lignes brisées', () => {
    expect(segmentsDe('M0 0L10 0M5 5L5 20')).toEqual([
      [0, 0, 10, 0],
      [5, 5, 5, 20],
    ])
    expect(segmentsDe('M0 0L10 0L10 10')).toEqual([
      [0, 0, 10, 0],
      [10, 0, 10, 10],
    ])
  })
})

describe('les mesures du dessin', () => {
  // Deux couples sur la rangée 0, leurs enfants sur la rangée 1.
  const a = fiche('a', 0, 0, 0)
  const b = fiche('b', 140, 0, 0)
  const c = fiche('c', 400, 0, 0)
  const d = fiche('d', 540, 0, 0)
  const e1 = fiche('e1', 0, 200, 1)
  const e2 = fiche('e2', 540, 200, 1)

  it('compte un croisement franc entre deux familles, et aucun dans une même famille', () => {
    // La famille ab descend vers e2 (à droite), la famille cd vers e1 (à gauche) : leurs bus se croisent.
    const fab = { id: 'fab', d: 'M120 25L120 120M120 120L590 120M590 120L590 200', personnes: ['a', 'b', 'e2'] }
    const fcd = { id: 'fcd', d: 'M520 25L520 140M520 140L50 140M50 140L50 200', personnes: ['c', 'd', 'e1'] }
    const m = mesurer(disposition([a, b, c, d, e1, e2], [fab, fcd]))
    // La descente de cd (x = 520) croise le bus de ab (y = 120) ; la chute de ab (x = 590) ne croise rien.
    expect(m.croisements).toBe(1)
    expect(m.sousUneCarte).toBe(0)
  })

  it('ne compte qu’une fois deux traits d’une même famille posés l’un sur l’autre', () => {
    // La barre ordinaire et, par-dessus, le tronçon de la lignée directe (ce que trace `/arbre`) :
    // à l'œil, un seul trait — que la chute d'une autre famille croise une fois.
    const fab = { id: 'fab', d: 'M50 120L590 120M300 120L590 120', personnes: ['a', 'b'] }
    const fcd = { id: 'fcd', d: 'M400 60L400 200', personnes: ['c'] }
    const m = mesurer(disposition([a, b, c, d], [fab, fcd]))
    expect(m.croisements).toBe(1)
    expect(m.longueurTracee).toBe(540 + 140)
  })

  it('voit un trait qui passe sous une carte, et pas celui qui s’arrête à son bord', () => {
    const traverse = { id: 'f1', d: 'M50 -40L50 100', personnes: ['a'] }
    const bord = { id: 'f2', d: 'M190 -40L190 0', personnes: ['b'] }
    const m = mesurer(disposition([a, b], [traverse, bord]))
    expect(m.sousUneCarte).toBe(1)
  })

  it('voit deux traits posés l’un sur l’autre, et un « T » trompeur', () => {
    const f1 = { id: 'f1', d: 'M0 120L300 120', personnes: ['a'] }
    const f2 = { id: 'f2', d: 'M200 120L500 120', personnes: ['c'] }
    const f3 = { id: 'f3', d: 'M250 60L250 120', personnes: ['b'] }
    const m = mesurer(disposition([a, b, c], [f1, f2, f3]))
    expect(m.chevauchements).toBe(1)
    // Le bout de f3 tombe sur l'intérieur de f1 et de f2 : deux « T ».
    expect(m.contacts).toBe(2)
  })

  it('voit un trait qui traverse tout un rectangle, et celui qui y entre sans y avoir personne', () => {
    const rectangle = { x: 300, y: -30, w: 400, h: 120 }
    const deBoutEnBout = { id: 'f1', d: 'M120 25L900 25', personnes: ['a', 'b'] }
    const quiEntre = { id: 'f2', d: 'M120 60L350 60', personnes: ['a'] }
    const m = mesurer(disposition([a, b, c, d], [deBoutEnBout, quiEntre]), [rectangle])
    expect(m.traverseUnGroupe).toBe(1)
    expect(m.dansUnGroupeEtranger).toBe(1)
  })

  it('mesure les écarts, les couples séparés et les fratries coupées', () => {
    // a et c mariés, séparés par b ; e1 et e2 enfants de ab, séparés par x (étrangère).
    const x = fiche('x', 200, 200, 1)
    const fac = { id: 'fac', d: 'M50 50L50 100L450 100L450 50', personnes: ['a', 'c'] }
    const fab = { id: 'fab', d: 'M120 25L120 150L50 150M50 150L50 200M120 150L590 150M590 150L590 200', personnes: ['a', 'b', 'e1', 'e2'] }
    const m = mesurer(disposition([a, b, c, d, e1, x, e2], [fac, fab]))
    expect(m.ecartMinFiches).toBe(40)
    expect(m.ecartMinRangees).toBe(150)
    expect(m.couplesSepares).toBe(1)
    expect(m.fratriesCoupees).toBe(1)
  })
})
