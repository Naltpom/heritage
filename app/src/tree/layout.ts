/**
 * Placement de l'arbre de `/tree`.
 *
 * L'arbre est « total » — ascendants, descendants, collatéraux, conjoints — mais
 * il est **organisé autour de moi**, comme un arbre de généalogiste :
 *
 * - moi au centre, mes descendants sous moi ;
 * - à chaque couple d'ancêtres, **le côté du père à gauche, celui de la mère à
 *   droite** : toute l'ascendance paternelle est à gauche de toute l'ascendance
 *   maternelle ;
 * - les frères et sœurs d'un ancêtre sont posés **du côté extérieur**, avec
 *   leurs descendants (les cousins) sous eux ;
 * - les parents sont centrés au-dessus de leurs enfants ;
 * - ce qui ne tient à l'arbre que par alliance (parents d'un conjoint…) forme
 *   des blocs posés à droite.
 *
 * Chaque famille est un **bloc** dont on connaît le profil (gauche et droite
 * de chaque rangée). Les blocs sont accolés au plus près sans se chevaucher :
 * une famille ne se mêle jamais à une autre.
 *
 * **Renvois.** Quand une famille devrait se relier à quelqu'un déjà posé
 * ailleurs — la conjointe dont on dessine les parents à part, un ancêtre
 * atteint par deux chemins —, on ne tire pas un trait à travers l'arbre : on
 * pose une fiche de renvoi (`ref`) à l'endroit voulu. Aucun trait ne traverse
 * donc l'arbre ; c'est ce qui garde les croisements au minimum.
 */
import type { Graphe, NoeudPersonne } from '@/lib/api'

export const CARD_W = 172
export const CARD_H = 58
const SPOUSE_GAP = 30
const SIBLING_GAP = 22
const FAMILY_GAP = 48
const LANE_STEP = 12
const GAP_PAD = 26

export interface Family {
  id: string
  parents: string[]
  children: string[]
  divorced: boolean
  pending: boolean
}

export interface PlacedPerson {
  /** Identifiant de la fiche dessinée : celui de la personne, ou d'un renvoi. */
  id: string
  node: NoeudPersonne
  /** Sur une fiche de renvoi : la personne réelle, posée ailleurs. */
  ref?: string
  level: number
  x: number
  y: number
}

export interface Link {
  key: string
  d: string
  kind: 'couple' | 'descent'
  divorced: boolean
  pending: boolean
  /** Fiches que ce lien relie, pour l'éclairer quand l'une est choisie. */
  people: string[]
}

export interface TreeLayout {
  persons: PlacedPerson[]
  byId: Map<string, PlacedPerson>
  links: Link[]
  families: Map<string, Family>
  /** Chaque famille exprimée en fiches dessinées (renvois compris). */
  drawn: { parents: string[]; children: string[] }[]
  asParent: Map<string, string[]>
  asChild: Map<string, string[]>
  bounds: { x: number; y: number; w: number; h: number }
}

type Side = 'L' | 'R'

/** Une famille telle qu'elle est dessinée, en fiches (réelles ou renvois). */
interface Piece {
  f: Family
  parents: string[]
  children: string[]
}

/* ------------------------------------------------------------------ */
/* Blocs à profil                                                      */
/* ------------------------------------------------------------------ */

/** Des fiches posées, et pour chaque rangée l'étendue qu'elles occupent. */
interface Block {
  pos: Map<string, { x: number; row: number }>
  l: Map<number, number>
  r: Map<number, number>
}

const emptyBlock = (): Block => ({ pos: new Map(), l: new Map(), r: new Map() })

function extend(b: Block, row: number, a: number, z: number): void {
  b.l.set(row, Math.min(b.l.get(row) ?? Infinity, a))
  b.r.set(row, Math.max(b.r.get(row) ?? -Infinity, z))
}
function addCard(b: Block, id: string, x: number, row: number): void {
  b.pos.set(id, { x, row })
  extend(b, row, x, x + CARD_W)
}
function card(id: string, row: number): Block {
  const b = emptyBlock()
  addCard(b, id, 0, row)
  return b
}
function merge(into: Block, b: Block, dx: number): void {
  for (const [id, p] of b.pos) into.pos.set(id, { x: p.x + dx, row: p.row })
  for (const [row, a] of b.l) extend(into, row, a + dx, b.r.get(row)! + dx)
}
/** Décalage minimal de `b` pour qu'il passe à droite de `a` sur toutes les rangées communes. */
function sep(a: Block, b: Block, gap: number): number | null {
  let m = -Infinity
  for (const [row, bl] of b.l) {
    const ar = a.r.get(row)
    if (ar !== undefined) m = Math.max(m, ar + gap - bl)
  }
  return m === -Infinity ? null : m
}
function packRight(acc: Block, b: Block, gap: number): number {
  if (!acc.l.size) return 0
  const s = sep(acc, b, gap)
  if (s !== null) return s
  return Math.max(...acc.r.values()) + gap - Math.min(...b.l.values())
}
const centerIn = (b: Block, id: string): number => b.pos.get(id)!.x + CARD_W / 2

const push = <K, V>(m: Map<K, V[]>, k: K, v: V): void => {
  const l = m.get(k)
  if (l) l.push(v)
  else m.set(k, [v])
}
const r1 = (n: number): number => Math.round(n * 10) / 10

/* ------------------------------------------------------------------ */
/* Placement                                                           */
/* ------------------------------------------------------------------ */

export function layoutTree(graph: Graphe): TreeLayout {
  const people = new Map<string, NoeudPersonne>()
  const families = new Map<string, Family>()
  for (const n of graph.noeuds) {
    if (n.type === 'personne') people.set(n.id, n)
    else
      families.set(n.id, {
        id: n.id,
        parents: [],
        children: [],
        divorced: n.divorced,
        pending: n.enAttente === true,
      })
  }
  for (const a of graph.aretes) {
    if (a.genre === 'union') {
      const f = families.get(a.vers)
      if (f && people.has(a.de) && !f.parents.includes(a.de)) f.parents.push(a.de)
    } else {
      const f = families.get(a.de)
      if (f && people.has(a.vers) && !f.children.includes(a.vers)) f.children.push(a.vers)
    }
  }
  const asParent = new Map<string, string[]>()
  const asChild = new Map<string, string[]>()
  for (const f of families.values()) {
    for (const p of f.parents) push(asParent, p, f.id)
    for (const c of f.children) push(asChild, c, f.id)
  }
  const fam = (id: string): Family => families.get(id)!
  const ownFams = (p: string): Family[] => (asParent.get(p) ?? []).map(fam)
  const birthFams = (p: string): Family[] => (asChild.get(p) ?? []).map(fam)

  const year = (p: string): number => {
    const m = /\d{4}/.exec(people.get(p)?.etiquetteDates ?? '')
    return m ? +m[0] : Infinity
  }
  const byBirth = (ids: string[]): string[] =>
    ids.map((id, i) => ({ id, i, y: year(id) })).sort((a, b) => a.y - b.y || a.i - b.i).map((e) => e.id)
  const maleFirst = (ids: string[]): string[] =>
    [...ids].sort((a, b) => sexRank(people.get(a)!) - sexRank(people.get(b)!))

  const placed = new Set<string>()
  /** Renvoi → personne réelle. */
  const refs = new Map<string, string>()
  const makeRef = (p: string): string => {
    const id = `↪${p}#${refs.size}`
    refs.set(id, p)
    return id
  }
  /** Chaque famille telle qu'elle est dessinée : en fiches (réelles ou renvois). */
  const pieces: Piece[] = []
  /** Descendances repoussées hors de l'arbre, pour ne pas croiser un couple. */
  const deferred: { f: Family; row: number; side: Side }[] = []

  /**
   * Les enfants d'une union, chacun avec sa descendance, côte à côte.
   * `depth` borne le nombre de générations dessinées sous chaque enfant.
   */
  function kidsGroup(f: Family, row: number, depth: number, side: Side): { g: Block; ids: string[] } {
    const g = emptyBlock()
    const ids: string[] = []
    for (const k of byBirth(f.children)) {
      const id = placed.has(k) ? makeRef(k) : k
      const kb = id === k ? down(k, row, depth, side) : card(id, row)
      merge(g, kb, packRight(g, kb, SIBLING_GAP))
      ids.push(id)
    }
    return { g, ids }
  }
  const span = (b: Block, ids: string[]): number =>
    (Math.min(...ids.map((id) => centerIn(b, id))) + Math.max(...ids.map((id) => centerIn(b, id)))) / 2

  /**
   * Une personne et sa descendance : elle et ses conjoints sur une rangée,
   * les enfants de chaque union en dessous, centrés sous le couple. Au-delà de
   * `depth` générations, la descendance est repoussée (`deferred`).
   */
  function down(p: string, row: number, depth: number, side: Side): Block {
    placed.add(p)
    const fams = ownFams(p)
    const spouseOf = new Map<Family, string | null>()
    const spouses: string[] = []
    for (const f of fams) {
      const s = f.parents.find((q) => q !== p)
      if (s === undefined) spouseOf.set(f, null)
      else {
        const id = placed.has(s) ? makeRef(s) : s
        placed.add(s)
        spouses.push(id)
        spouseOf.set(f, id)
      }
    }
    // Un seul conjoint : l'homme à gauche. Plusieurs : de part et d'autre.
    let order: string[]
    if (spouses.length === 1) {
      const s = spouses[0]!
      order = sexRank(people.get(refs.get(s) ?? s)!) < sexRank(people.get(p)!) ? [s, p] : [p, s]
    } else {
      order = [p]
      spouses.forEach((s, i) => (i % 2 === 0 ? order.push(s) : order.unshift(s)))
    }
    const b = emptyBlock()
    order.forEach((q, i) => addCard(b, q, i * (CARD_W + SPOUSE_GAP), row))

    const groups = fams
      .map((f) => {
        const s = spouseOf.get(f)
        return { f, s, anchor: s ? (centerIn(b, p) + centerIn(b, s)) / 2 : centerIn(b, p) }
      })
      .sort((a, c) => a.anchor - c.anchor)
    const all = emptyBlock()
    for (const { f, s, anchor } of groups) {
      const parents = s ? [p, s] : [p]
      if (depth <= 0 && f.children.some((k) => !placed.has(k))) {
        deferred.push({ f, row, side })
        pieces.push({ f, parents, children: [] })
        continue
      }
      const { g, ids } = kidsGroup(f, row + 1, depth - 1, side)
      pieces.push({ f, parents, children: ids })
      if (!ids.length) continue
      const want = anchor - span(g, ids)
      const min = all.l.size ? sep(all, g, FAMILY_GAP) : null
      merge(all, g, min === null ? want : Math.max(want, min))
    }
    merge(b, all, 0)
    return b
  }

  /**
   * Remonte depuis `x` : ses frères et sœurs, ses parents, puis
   * récursivement leurs propres familles — le père à gauche, la mère à droite.
   *
   * `outer` est le côté où poser la fratrie ; `branch` le côté de l'arbre
   * (paternel à gauche, maternel à droite). `free` n'est vrai que sur les
   * lignées les plus extérieures (père du père du père…, mère de la mère…).
   * Partout ailleurs, la fratrie est au-dessus de l'espace qui sépare deux
   * conjoints de la lignée : sa descendance s'y glisserait, couperait le trait
   * du couple ou s'y confondrait. Elle reste visible, mais sa descendance est
   * repoussée du côté de la branche, reliée par des renvois.
   */
  function up(
    x: string,
    row: number,
    outer: Side | null,
    branch: Side | null,
    free: boolean,
    core: Block,
    coreId: string,
    bf: Family | undefined,
    lineFam?: Family,
  ): Block {
    const side: Side = branch ?? 'R'
    const depth = free ? Infinity : 0
    type Item = { b: Block; id: string | null; birth: number }
    // Les autres unions d'un ancêtre (demi-frères et sœurs) : contre lui.
    const others: Item[] = []
    if (outer && coreId === x)
      for (const f of ownFams(x)) {
        if (f === lineFam) continue
        const s = f.parents.find((q) => q !== x)
        const sid = s === undefined ? null : placed.has(s) ? makeRef(s) : s
        if (s !== undefined) placed.add(s)
        const b = sid ? card(sid, row) : emptyBlock()
        if (depth <= 0) {
          if (f.children.some((k) => !placed.has(k))) deferred.push({ f, row, side })
          pieces.push({ f, parents: sid ? [x, sid] : [x], children: [] })
          if (b.pos.size) others.push({ b, id: null, birth: 0 })
          continue
        }
        const { g, ids } = kidsGroup(f, row + 1, depth - 1, side)
        pieces.push({ f, parents: sid ? [x, sid] : [x], children: ids })
        if (ids.length) merge(b, g, sid ? centerIn(b, sid) - span(g, ids) : 0)
        if (b.pos.size) others.push({ b, id: null, birth: 0 })
      }
    const sibItems: Item[] = []
    if (bf)
      for (const s of byBirth(bf.children)) {
        if (s === x) continue
        if (placed.has(s)) {
          const id = makeRef(s)
          sibItems.push({ b: card(id, row), id, birth: year(s) })
        } else sibItems.push({ b: down(s, row, depth, side), id: s, birth: year(s) })
      }
    const coreItem: Item = { b: core, id: coreId, birth: year(x) }
    let items: Item[]
    if (outer === 'L') items = [...sibItems, ...others.reverse(), coreItem]
    else if (outer === 'R') items = [coreItem, ...others, ...sibItems]
    else items = [...sibItems, coreItem].sort((a, c) => a.birth - c.birth)

    const g = emptyBlock()
    for (const it of items) merge(g, it.b, packRight(g, it.b, SIBLING_GAP))
    const kids = items.filter((it) => it.id !== null).map((it) => it.id!)
    if (!bf) return g
    if (!bf.parents.length) {
      pieces.push({ f: bf, parents: [], children: kids })
      return g
    }

    const parents = maleFirst(bf.parents)
    const blocks = parents.map((q, i) => {
      if (placed.has(q)) {
        const id = makeRef(q)
        return { id, b: card(id, row - 1) }
      }
      placed.add(q)
      const qOuter: Side = parents.length === 2 ? (i === 0 ? 'L' : 'R') : (outer ?? 'L')
      const qBranch: Side = branch ?? qOuter
      const qFree = free && qOuter === qBranch
      return { id: q, b: up(q, row - 1, qOuter, qBranch, qFree, card(q, row - 1), q, birthFams(q)[0], bf) }
    })
    pieces.push({ f: bf, parents: blocks.map((b) => b.id), children: kids })
    const comb = span(g, kids)
    const ph = blocks[0]!.b
    const hc = centerIn(ph, blocks[0]!.id)
    const gMin = sep(ph, g, FAMILY_GAP) ?? -Infinity
    if (blocks.length === 1) {
      merge(ph, g, Math.max(hc - comb, gMin))
      return ph
    }
    const pw = blocks[1]!.b
    const wc = centerIn(pw, blocks[1]!.id)
    let pwOff = sep(ph, pw, FAMILY_GAP) ?? 0
    let gx = Math.max((hc + wc + pwOff) / 2 - comb, gMin)
    const gw = sep(g, pw, FAMILY_GAP)
    if (gw !== null) pwOff = Math.max(pwOff, gx + gw)
    gx = Math.max((hc + wc + pwOff) / 2 - comb, gMin)
    if (gw !== null) gx = Math.min(gx, pwOff - gw)
    merge(ph, g, gx)
    merge(ph, pw, pwOff)
    return ph
  }

  const main = emptyBlock()

  /**
   * Ce qu'occupe un ensemble de fiches : leurs étendues par rangée, et celles
   * de leurs traits par interstice (sous la rangée des parents).
   */
  type Spans = Map<number, [number, number][]>
  const occupancy = (b: Block, ps: Piece[]): { cards: Spans; lines: Spans } => {
    const cards: Spans = new Map()
    const lines: Spans = new Map()
    for (const p of b.pos.values()) push(cards, p.row, [p.x, p.x + CARD_W])
    for (const d of ps) {
      const members = [...d.parents, ...d.children].filter((id) => b.pos.has(id))
      if (members.length < 2) continue
      const pr = d.parents.filter((id) => b.pos.has(id)).map((id) => b.pos.get(id)!.row)
      const cr = d.children.filter((id) => b.pos.has(id)).map((id) => b.pos.get(id)!.row)
      // Rien ne se glisse entre deux conjoints : l'intervalle du couple est pris.
      const ps = d.parents.filter((id) => b.pos.has(id))
      if (ps.length === 2 && pr[0] === pr[1]) {
        const xs = ps.map((id) => b.pos.get(id)!.x)
        push(cards, pr[0]!, [Math.min(...xs), Math.max(...xs) + CARD_W])
      }
      const gapRow = pr.length ? Math.max(...pr) : Math.min(...cr) - 1
      const xs = members.map((id) => centerIn(b, id))
      push(lines, gapRow, [Math.min(...xs), Math.max(...xs)])
    }
    return { cards, lines }
  }

  /**
   * Pose un bloc à part au plus près de `target`, dans un trou de l'arbre si
   * l'un est assez grand : ni ses fiches ne touchent une fiche, ni ses traits
   * un trait, rangée par rangée et interstice par interstice.
   */
  const bestOffset = (b: Block, own: Piece[], target: number): number => {
    const mine = occupancy(b, own)
    const theirs = occupancy(main, pieces)
    const bad: [number, number][] = []
    const forbid = (a: Spans, m: Spans, margin: number): void => {
      for (const [row, ivs] of a) {
        const other = m.get(row)
        if (!other) continue
        const lo = Math.min(...ivs.map((iv) => iv[0]))
        const hi = Math.max(...ivs.map((iv) => iv[1]))
        for (const [ml, mr] of other) bad.push([ml - hi - margin, mr - lo + margin])
      }
    }
    forbid(mine.cards, theirs.cards, FAMILY_GAP)
    forbid(mine.lines, theirs.lines, FAMILY_GAP / 2)
    bad.sort((u, v) => u[0] - v[0])
    const merged: [number, number][] = []
    for (const iv of bad) {
      const last = merged[merged.length - 1]
      if (last && iv[0] < last[1]) last[1] = Math.max(last[1], iv[1])
      else merged.push([...iv])
    }
    const hit = merged.find(([a, z]) => target > a && target < z)
    if (!hit) return target
    return target - hit[0] <= hit[1] - target ? hit[0] : hit[1]
  }

  /** Construit un bloc à part et le pose près de la personne qu'il prolonge. */
  const attach = (build: () => Block, side: Side): void => {
    const from = pieces.length
    const b = build()
    if (!b.pos.size) return
    if (!main.pos.size) {
      merge(main, b, 0)
      return
    }
    const own = pieces.slice(from)
    const anchor = [...b.pos.keys()].find((id) => refs.has(id) && main.pos.has(refs.get(id)!))
    let target: number
    if (anchor !== undefined) target = main.pos.get(refs.get(anchor)!)!.x - b.pos.get(anchor)!.x
    else if (side === 'R') target = packRight(main, b, FAMILY_GAP * 2)
    else target = Math.min(...main.l.values()) - FAMILY_GAP * 2 - Math.max(...b.r.values())
    merge(main, b, bestOffset(b, own, target))
  }
  const rooted = (r: string, row: number): Block =>
    up(r, row, null, null, true, down(r, row, Infinity, 'R'), r, birthFams(r)[0])

  if (people.has(graph.ego)) attach(() => rooted(graph.ego, 0), 'R')
  const egoX = main.pos.get(graph.ego)?.x ?? 0
  const sideOf = (id: string): Side => (main.pos.get(id)!.x < egoX ? 'L' : 'R')

  /** Un couple en renvois, et ses enfants dessous. */
  const refFamily = (parents: string[], f: Family, row: number, side: Side): Block => {
    const b = emptyBlock()
    const ids = parents.map((q) => makeRef(q))
    ids.forEach((id, i) => addCard(b, id, i * (CARD_W + SPOUSE_GAP), row))
    const { g, ids: kids } = kidsGroup(f, row + 1, Infinity, side)
    pieces.push({ f, parents: ids, children: kids })
    if (kids.length) merge(b, g, span(b, ids) - span(g, kids))
    return b
  }

  for (let i = 0; i < deferred.length || placed.size < people.size; ) {
    // Les descendances repoussées, chacune de son côté.
    for (; i < deferred.length; i++) {
      const { f, row, side } = deferred[i]!
      if (!f.children.some((k) => !placed.has(k))) continue
      attach(() => refFamily(f.parents.filter((q) => placed.has(q)), f, row, side), side)
    }
    if (placed.size >= people.size) break
    // Ce qui ne tient que par alliance, ou pas du tout : un bloc à part,
    // relié par un renvoi.
    let progress = false
    for (const y of [...main.pos.keys()]) {
      if (refs.has(y)) continue
      const row = main.pos.get(y)!.row
      const side = sideOf(y)
      for (const f of birthFams(y)) {
        if (![...f.parents, ...f.children].some((q) => !placed.has(q))) continue
        attach(() => {
          const id = makeRef(y)
          return up(y, row, side, side, true, card(id, row), id, f)
        }, side)
        progress = true
      }
      for (const f of ownFams(y)) {
        const s = f.parents.find((q) => q !== y)
        if (s && !placed.has(s)) {
          attach(() => down(s, row, Infinity, side), side)
          progress = true
        } else if (f.children.some((k) => !placed.has(k))) {
          attach(() => refFamily(f.parents, f, row, side), side)
          progress = true
        }
      }
    }
    if (!progress && i >= deferred.length) {
      const r = [...people.keys()].find((q) => !placed.has(q))!
      attach(() => rooted(r, 0), 'R')
    }
  }

  return finish(main, people, families, refs, pieces, asParent, asChild)
}

function sexRank(n: NoeudPersonne): number {
  return n.sex === 'masculin' ? 0 : n.sex === 'feminin' ? 2 : 1
}

/* ------------------------------------------------------------------ */
/* Ordonnées et traits                                                 */
/* ------------------------------------------------------------------ */

function finish(
  main: Block,
  people: Map<string, NoeudPersonne>,
  families: Map<string, Family>,
  refs: Map<string, string>,
  pieces: Piece[],
  asParent: Map<string, string[]>,
  asChild: Map<string, string[]>,
): TreeLayout {
  const pos = main.pos
  const rowOf = (p: string): number => pos.get(p)!.row
  const center = (p: string): number => pos.get(p)!.x + CARD_W / 2
  const levels = [...new Set([...pos.values()].map((p) => p.row))].sort((a, b) => a - b)


  // Fiches de chaque rangée, dans l'ordre : pour savoir si deux conjoints se touchent.
  const rowCards = new Map<number, string[]>()
  for (const [id, p] of pos) push(rowCards, p.row, id)
  for (const ids of rowCards.values()) ids.sort((a, b) => pos.get(a)!.x - pos.get(b)!.x)
  const adjacent = (a: string, b: string): boolean => {
    if (rowOf(a) !== rowOf(b)) return false
    const ids = rowCards.get(rowOf(a))!
    return Math.abs(ids.indexOf(a) - ids.indexOf(b)) === 1
  }
  const parentLevel = (d: { parents: string[]; children: string[] }): number | null => {
    if (d.parents.length) return Math.max(...d.parents.map(rowOf))
    return d.children.length ? Math.min(...d.children.map(rowOf)) - 1 : null
  }

  // Un couloir par peigne d'enfants, pour qu'aucun ne se confonde avec un autre.
  // Une fiche posée deux fois n'existe pas ; une personne sans fiche, si.
  const drawn = pieces.map((d) => ({
    f: d.f,
    parents: d.parents.filter((p) => pos.has(p)),
    children: d.children.filter((c) => pos.has(c)),
  }))
  type Comb = { f: number; from: number; to: number; lane: number }
  const combs = new Map<number, Comb[]>()
  for (const [fid, d] of drawn.entries()) {
    const pl = parentLevel(d)
    if (!d.children.length || pl === null) continue
    const xs = [...d.parents, ...d.children].map(center)
    push(combs, pl, { f: fid, from: Math.min(...xs) - 8, to: Math.max(...xs) + 8, lane: 0 })
  }
  const laneCount = new Map<number, number>()
  for (const [l, list] of combs) {
    list.sort((a, b) => a.from - b.from || a.to - b.to)
    const ends: number[] = []
    for (const c of list) {
      let lane = ends.findIndex((e) => e < c.from)
      if (lane === -1) lane = ends.length
      ends[lane] = c.to
      c.lane = lane
    }
    laneCount.set(l, ends.length)
  }
  const top = new Map<number, number>()
  let y = 0
  for (const l of levels) {
    top.set(l, y)
    const lanes = laneCount.get(l) ?? 0
    y += CARD_H + Math.max(72, GAP_PAD * 2 + Math.max(0, lanes - 1) * LANE_STEP)
  }
  const yOf = (p: string): number => top.get(rowOf(p))!

  const links: Link[] = []
  for (const [fid, d] of drawn.entries()) {
    const f = d.f
    const ps = d.parents
    let px: number | null = null
    let py = 0
    if (ps.length === 1) {
      px = center(ps[0]!)
      py = yOf(ps[0]!) + CARD_H
    } else if (ps.length >= 2) {
      const [a, b] = [...ps].sort((p, q) => center(p) - center(q)) as [string, string]
      px = (center(a) + center(b)) / 2
      if (adjacent(a, b)) {
        py = yOf(a) + CARD_H / 2
        links.push({
          key: `${f.id}.${fid}:c`,
          d: `M${r1(center(a) + CARD_W / 2)} ${r1(py)}H${r1(center(b) - CARD_W / 2)}`,
          kind: 'couple',
          divorced: f.divorced,
          pending: f.pending,
          people: [a, b],
        })
      } else {
        py = Math.max(yOf(a), yOf(b)) + CARD_H + 10
        links.push({
          key: `${f.id}.${fid}:c`,
          d:
            `M${r1(center(a))} ${r1(yOf(a) + CARD_H)}V${r1(py)}H${r1(center(b))}` +
            `V${r1(yOf(b) + CARD_H)}`,
          kind: 'couple',
          divorced: f.divorced,
          pending: f.pending,
          people: [a, b],
        })
      }
    }
    const cs = d.children
    const pl = parentLevel(d)
    if (!cs.length || pl === null) continue
    const comb = combs.get(pl)!.find((c) => c.f === fid)!
    const busY = top.get(pl)! + CARD_H + GAP_PAD + comb.lane * LANE_STEP
    const xs = cs.map(center)
    const x0 = px ?? xs[0]!
    let path = px !== null ? `M${r1(px)} ${r1(py)}V${r1(busY)}` : ''
    const lo = Math.min(x0, ...xs)
    const hi = Math.max(x0, ...xs)
    if (hi - lo > 0.5) path += `M${r1(lo)} ${r1(busY)}H${r1(hi)}`
    for (const c of cs) path += `M${r1(center(c))} ${r1(busY)}V${r1(yOf(c))}`
    links.push({ key: `${f.id}.${fid}:d`, d: path, kind: 'descent', divorced: false, pending: f.pending, people: [...ps, ...cs] })
  }

  const persons: PlacedPerson[] = [...pos].map(([id, p]) => {
    const target = refs.get(id)
    const placed: PlacedPerson = { id, node: people.get(target ?? id)!, level: p.row, x: p.x, y: top.get(p.row)! }
    if (target !== undefined) placed.ref = target
    return placed
  })
  const byId = new Map(persons.map((p) => [p.id, p]))
  let minX = Infinity
  let minY = Infinity
  let maxX = -Infinity
  let maxY = -Infinity
  for (const p of persons) {
    minX = Math.min(minX, p.x)
    minY = Math.min(minY, p.y)
    maxX = Math.max(maxX, p.x + CARD_W)
    maxY = Math.max(maxY, p.y + CARD_H)
  }
  if (!persons.length) minX = minY = maxX = maxY = 0
  return {
    persons,
    byId,
    links,
    families,
    drawn: drawn.map(({ parents, children }) => ({ parents, children })),
    asParent,
    asChild,
    bounds: { x: minX, y: minY, w: maxX - minX, h: maxY - minY },
  }
}

/**
 * Nombre de croisements entre traits de descendance d'une rangée à la
 * suivante — la mesure de lisibilité que les tests surveillent.
 */
export function countCrossings(layout: TreeLayout): number {
  const segs: { row: number; a: number; b: number }[] = []
  for (const d of layout.drawn) {
    const ps = d.parents.map((p) => layout.byId.get(p)!)
    if (!ps.length) continue
    const fx = ps.reduce((s, p) => s + p.x + CARD_W / 2, 0) / ps.length
    const row = Math.max(...ps.map((p) => p.level))
    for (const c of d.children) segs.push({ row, a: fx, b: layout.byId.get(c)!.x + CARD_W / 2 })
  }
  let n = 0
  for (let i = 0; i < segs.length; i++)
    for (let j = i + 1; j < segs.length; j++) {
      const s = segs[i]!
      const t = segs[j]!
      if (s.row === t.row && (s.a - t.a) * (s.b - t.b) < 0) n++
    }
  return n
}
