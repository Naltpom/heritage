import { describe, expect, it } from 'vitest'
import { sansFamillesParAlliance } from '@/histoire/alliances'
import { genealogieSynthetique } from '@/histoire/banc/genealogie'
import { noeudsSansLien, sansPersonnesSansLien } from '@/histoire/sansLien'
import { brancherCommeLeServeur } from './banc/branches'
import { ECART_COUPLE } from './disposition'
import { CompteEnBus, POIDS_TETES, compterBus, compterTout, construireCouches, ordreEnSablier, type Couches, type Lien } from './ordre'
import { preparer, type Structure } from './preparation'

/**
 * Ce que ces tests protègent : le compte que l'affinage minimise. Il doit dire les
 * croisements que le routage tracera (des barres de fratrie sur des pistes), et le compte
 * tenu à jour mouvement après mouvement doit rester exactement celui qu'on recompterait.
 */

function preparerSynthetique(personnes: number, graine: number): { s: Structure; c: Couches } {
  const graphe = brancherCommeLeServeur(genealogieSynthetique({ personnes, graine }))
  const g = sansPersonnesSansLien(sansFamillesParAlliance(graphe), noeudsSansLien(graphe))
  const s = preparer(g, { ecartCouple: ECART_COUPLE })
  const c = construireCouches(s, ordreEnSablier(s, 'servi'), { fratriesParNaissance: false, largeurFactice: 16 })
  return { s, c }
}

/** Un générateur pseudo-aléatoire à graine (le test doit être reproductible). */
function hasard(graine: number): () => number {
  let x = graine >>> 0
  return () => {
    x = (x * 1664525 + 1013904223) >>> 0
    return x / 2 ** 32
  }
}

/**
 * Un modèle fait à la main : des rangées de foyers (`'a'`, ou `'a+b'` pour un couple), dans
 * l'ordre où on les veut, et des familles. Juste ce que lisent les comptes.
 */
function modele(
  rangees: string[][],
  familles: { epoux: string[]; enfants: string[] }[],
  sosa: Record<string, number> = {},
): { s: Structure; c: Couches } {
  const indexDe = new Map<string, number>()
  const rangee: number[] = []
  const foyerDe: number[] = []
  const rangDansFoyer: number[] = []
  const foyers: { membres: number[] }[] = []
  const ordre: number[][] = []
  rangees.forEach((l, r) => {
    ordre.push([])
    for (const foyer of l) {
      const fo = foyers.length
      const membres = foyer.split('+').map((nom, k) => {
        const i = indexDe.size
        indexDe.set(nom, i)
        rangee.push(r)
        foyerDe.push(fo)
        rangDansFoyer.push(k)
        return i
      })
      foyers.push({ membres })
      ordre[r]!.push(fo)
    }
  })
  const fam = familles.map((f) => ({ epoux: f.epoux.map((n) => indexDe.get(n)!), enfants: f.enfants.map((n) => indexDe.get(n)!) }))
  const liens: Lien[][] = rangees.map(() => [])
  fam.forEach((f, k) => {
    const rf = Math.max(...f.epoux.map((e) => rangee[e]!))
    for (const x of f.enfants) liens[rf]!.push({ hautFamille: k, hautFactice: -1, hautPersonne: -1, basPersonne: x, basFactice: -1, rangee: rf })
  })
  const s = {
    familles: fam,
    rangee,
    foyerDe,
    rangDansFoyer,
    foyers,
    sosa: rangee.map((_, i) => sosa[[...indexDe].find(([, j]) => j === i)![0]] ?? null),
    sosaConjoint: new Map(),
  } as unknown as Structure
  const c = {
    elements: foyers.map((f, id) => ({ id, rangee: rangee[f.membres[0]!]!, partie: 0, foyer: id, famille: -1, enfant: -1, largeur: 1 })),
    ordre,
    rangees: rangees.map((_, r) => r),
    liens,
    elementDuFoyer: foyers.map((_, id) => id),
    factices: new Map(),
    retourne: foyers.map(() => false),
    croisements: 0,
  } as Couches
  return { s, c }
}

describe('le compte en bus, sur des cas d’école', () => {
  it('deux fratries emboîtées : une barre en croise une autre une fois, pas quatre', () => {
    // P a quatre enfants, Q deux, posés au milieu de ceux de P.
    const { s, c } = modele(
      [['p1+p2', 'q1+q2'], ['a1', 'a2', 'b1', 'b2', 'a3', 'a4']],
      [
        { epoux: ['p1', 'p2'], enfants: ['a1', 'a2', 'a3', 'a4'] },
        { epoux: ['q1', 'q2'], enfants: ['b1', 'b2'] },
      ],
    )
    expect(compterTout(s, c)).toBe(4)
    // La barre de Q passe sous celle de P : seule sa descente coupe la barre de P.
    expect(compterBus(s, c)).toBe(1)
  })

  it('deux fratries côte à côte ne se croisent pas ; inversées, une fois', () => {
    // (Des enfants mariés : leurs positions ne tombent pas pile sous les points d'union.)
    const familles = [
      { epoux: ['p1', 'p2'], enfants: ['a'] },
      { epoux: ['q1', 'q2'], enfants: ['b'] },
    ]
    const droit = modele([['p1+p2', 'q1+q2'], ['a+a2', 'b+b2']], familles)
    expect(compterBus(droit.s, droit.c)).toBe(0)
    const croise = modele([['p1+p2', 'q1+q2'], ['b+b2', 'a+a2']], familles)
    expect(compterBus(croise.s, croise.c)).toBe(1)
    expect(compterTout(croise.s, croise.c)).toBe(1)
  })

  it('une union qui contourne : chaque descente d’entre les époux la coupe ; une carte sans enfants, non', () => {
    // x et y mariés, séparés par le couple m (qui a un enfant) et par z (qui n'en a pas).
    const { s, c } = modele(
      [['x', 'm1+m2', 'z', 'y'], ['e']],
      [
        { epoux: ['x', 'y'], enfants: [] },
        { epoux: ['m1', 'm2'], enfants: ['e'] },
      ],
    )
    expect(compterTout(s, c)).toBe(0)
    expect(compterBus(s, c)).toBe(1)
  })

  it('les grands-parents (Sosa 4 et 5) séparés : chaque carte d’entre eux compte', () => {
    const { s, c } = modele(
      [['gp', 'z1', 'z2', 'gm'], ['pere']],
      [
        { epoux: ['gp', 'gm'], enfants: ['pere'] },
        { epoux: ['z1'], enfants: [] },
      ],
      { gp: 4, gm: 5, pere: 2 },
    )
    // Deux cartes sans enfants entre eux : aucune descente coupée, mais deux fois le poids.
    expect(compterBus(s, c)).toBe(2 * POIDS_TETES)
  })
})

describe('le compte en bus', () => {
  it('reste exact, mouvement après mouvement (échanges, retournements, annulations)', () => {
    const { s, c } = preparerSynthetique(1000, 7)
    const bus = new CompteEnBus(s, c)
    expect(bus.total).toBe(compterBus(s, c))
    const tirer = hasard(42)
    const { pos } = bus
    let essais = 0
    for (let n = 0; n < 1500; n++) {
      const k = Math.floor(tirer() * c.ordre.length)
      const l = c.ordre[k]!
      if (tirer() < 0.25) {
        // Retourner un foyer de plusieurs membres.
        const e = l[Math.floor(tirer() * l.length)]
        const fo = e === undefined ? -1 : c.elements[e]!.foyer
        if (fo < 0 || s.foyers[fo]!.membres.length < 2) continue
        bus.varier([e!], () => (c.retourne[fo] = !c.retourne[fo]))
        if (tirer() < 0.5) bus.garder()
        else bus.annuler(() => (c.retourne[fo] = !c.retourne[fo]))
      } else {
        // Échanger deux suites voisines de la même partie (un ou deux éléments chacune).
        if (l.length < 3) continue
        const i = Math.floor(tirer() * (l.length - 2))
        const la = 1 + Math.floor(tirer() * 2)
        const lb = 1 + Math.floor(tirer() * 2)
        if (i + la + lb > l.length) continue
        const A = l.slice(i, i + la)
        const B = l.slice(i + la, i + la + lb)
        if (new Set([...A, ...B].map((e) => c.elements[e]!.partie)).size > 1) continue
        const debut = pos[A[0]!]!
        bus.varier([...A, ...B], () => {
          B.forEach((e, j) => (pos[e] = debut + j))
          A.forEach((e, j) => (pos[e] = debut + B.length + j))
        })
        if (tirer() < 0.5) {
          bus.garder()
          l.splice(i, la + lb, ...B, ...A)
        } else
          bus.annuler(() => {
            A.forEach((e, j) => (pos[e] = debut + j))
            B.forEach((e, j) => (pos[e] = debut + A.length + j))
          })
      }
      essais++
      expect(bus.total, `après le mouvement ${n}`).toBe(compterBus(s, c))
    }
    expect(essais).toBeGreaterThan(500)
  })
})
