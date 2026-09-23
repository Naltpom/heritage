/**
 * Placement de l'arbre de `/tree` — écrit de zéro, sans rien reprendre des
 * positions calculées par le serveur.
 *
 * L'arbre est « total » : ascendants, descendants, collatéraux, conjoints et
 * leurs familles. Ce n'est donc pas un arbre binaire mais un graphe en couches :
 *
 * 1. chaque personne reçoit une **génération** (moi = 0, parents = -1…) ;
 * 2. dans une génération, les conjoints forment une **unité** posée d'un bloc ;
 * 3. l'**ordre** des unités d'une rangée est cherché par balayages
 *    barycentriques, en gardant celui qui croise le moins de liens ;
 * 4. les **abscisses** sont tirées vers les parents et les enfants, puis
 *    rendues sans chevauchement par régression isotone (on bouge le moins
 *    possible en respectant l'ordre et les écarts) ;
 * 5. les **liens** : trait de couple entre conjoints, puis un « peigne » vers
 *    les enfants, chaque peigne sur son propre couloir pour ne jamais se
 *    confondre avec celui d'une autre famille.
 */
import type { Graphe, NoeudPersonne } from '@/lib/api'

export const CARD_W = 172
export const CARD_H = 58
const SPOUSE_GAP = 30
const SIBLING_GAP = 22
const GROUP_GAP = 64
const COMPONENT_GAP = 160
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
  id: string
  node: NoeudPersonne
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
  /** Personnes que ce lien relie, pour l'éclairer quand l'une est choisie. */
  people: string[]
}

export interface TreeLayout {
  persons: PlacedPerson[]
  byId: Map<string, PlacedPerson>
  links: Link[]
  families: Map<string, Family>
  asParent: Map<string, string[]>
  asChild: Map<string, string[]>
  bounds: { x: number; y: number; w: number; h: number }
}

interface Unit {
  id: number
  level: number
  members: string[]
  x: number
}

const push = <K, V>(m: Map<K, V[]>, k: K, v: V): void => {
  const l = m.get(k)
  if (l) l.push(v)
  else m.set(k, [v])
}

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

  // Composantes connexes, celle de l'ego en premier.
  const seen = new Set<string>()
  const components: string[][] = []
  const starts = [graph.ego, ...people.keys()].filter((id) => people.has(id))
  for (const start of starts) {
    if (seen.has(start)) continue
    const comp: string[] = []
    const stack = [start]
    seen.add(start)
    while (stack.length) {
      const p = stack.pop()!
      comp.push(p)
      for (const fid of [...(asParent.get(p) ?? []), ...(asChild.get(p) ?? [])]) {
        const f = families.get(fid)!
        for (const q of [...f.parents, ...f.children])
          if (!seen.has(q)) {
            seen.add(q)
            stack.push(q)
          }
      }
    }
    components.push(comp)
  }

  const ctx: Ctx = { people, families, asParent, asChild }
  const placed: PlacedPerson[] = []
  const links: Link[] = []
  let offsetX = 0
  for (const comp of components) {
    const r = layoutComponent(ctx, comp[0]!)
    const dx = offsetX - r.minX
    for (const p of r.persons) p.x += dx
    for (const l of r.links) l.d = shiftPath(l.d, dx)
    placed.push(...r.persons)
    links.push(...r.links)
    offsetX += r.maxX - r.minX + COMPONENT_GAP
  }

  const byId = new Map(placed.map((p) => [p.id, p]))
  let minX = Infinity,
    minY = Infinity,
    maxX = -Infinity,
    maxY = -Infinity
  for (const p of placed) {
    minX = Math.min(minX, p.x)
    minY = Math.min(minY, p.y)
    maxX = Math.max(maxX, p.x + CARD_W)
    maxY = Math.max(maxY, p.y + CARD_H)
  }
  if (!placed.length) minX = minY = maxX = maxY = 0
  return {
    persons: placed,
    byId,
    links,
    families,
    asParent,
    asChild,
    bounds: { x: minX, y: minY, w: maxX - minX, h: maxY - minY },
  }
}

interface Ctx {
  people: Map<string, NoeudPersonne>
  families: Map<string, Family>
  asParent: Map<string, string[]>
  asChild: Map<string, string[]>
}

function shiftPath(d: string, dx: number): string {
  // Les chemins n'utilisent que M/L/H/V en absolu : décaler les X suffit.
  return d.replace(/([MLHV])([-\d.]+)(?: ([-\d.]+))?/g, (_, c: string, a: string, b?: string) => {
    if (c === 'V') return `V${a}`
    if (c === 'H') return `H${r1(+a + dx)}`
    return `${c}${r1(+a + dx)} ${b}`
  })
}

const r1 = (n: number): number => Math.round(n * 10) / 10

function layoutComponent(
  ctx: Ctx,
  root: string,
): { persons: PlacedPerson[]; links: Link[]; minX: number; maxX: number } {
  const { families, asParent, asChild } = ctx
  const fam = (id: string): Family => families.get(id)!
  const parentFams = (p: string): Family[] => (asChild.get(p) ?? []).map(fam)
  const ownFams = (p: string): Family[] => (asParent.get(p) ?? []).map(fam)

  /* 1. Générations ------------------------------------------------------ */
  const level = new Map<string, number>([[root, 0]])
  const queue = [root]
  while (queue.length) {
    const p = queue.shift()!
    const l = level.get(p)!
    const set = (q: string, v: number): void => {
      if (!level.has(q)) {
        level.set(q, v)
        queue.push(q)
      }
    }
    for (const f of ownFams(p)) {
      f.parents.forEach((q) => set(q, l))
      f.children.forEach((c) => set(c, l + 1))
    }
    for (const f of parentFams(p)) {
      f.parents.forEach((q) => set(q, l - 1))
      f.children.forEach((s) => set(s, l))
    }
  }
  const members = [...level.keys()]
  const compFams = new Set<string>()
  for (const p of members) for (const f of [...ownFams(p), ...parentFams(p)]) compFams.add(f.id)

  /* 2. Unités de conjoints ------------------------------------------- */
  const spouses = new Map<string, string[]>()
  for (const fid of compFams) {
    const f = fam(fid)
    if (f.parents.length === 2) {
      const [a, b] = f.parents as [string, string]
      if (level.get(a) === level.get(b)) {
        push(spouses, a, b)
        push(spouses, b, a)
      }
    }
  }
  const units: Unit[] = []
  const unitOf = new Map<string, Unit>()
  for (const p of members) {
    if (unitOf.has(p)) continue
    // Composante de conjoints, puis mise en ligne autour du plus « marié ».
    const group: string[] = []
    const st = [p]
    const g = new Set([p])
    while (st.length) {
      const q = st.pop()!
      group.push(q)
      for (const s of spouses.get(q) ?? []) if (!g.has(s)) (g.add(s), st.push(s))
    }
    const hub = group.reduce((a, b) =>
      (spouses.get(b)?.length ?? 0) > (spouses.get(a)?.length ?? 0) ? b : a,
    )
    const used = new Set([hub])
    const chain = (from: string): string[] => {
      const out: string[] = []
      let cur: string | undefined = from
      while (cur) {
        used.add(cur)
        out.push(cur)
        cur = (spouses.get(cur) ?? []).find((s) => !used.has(s))
      }
      return out
    }
    const left: string[] = []
    const right: string[] = []
    ;(spouses.get(hub) ?? []).forEach((s, i) => {
      if (used.has(s)) return
      const c = chain(s)
      if (i % 2 === 0) right.push(...c)
      else left.unshift(...c.reverse())
    })
    const ordered = [...left, hub, ...right]
    for (const q of group) if (!ordered.includes(q)) ordered.push(q) // cycles
    const u: Unit = { id: units.length, level: level.get(p)!, members: ordered, x: 0 }
    units.push(u)
    for (const q of ordered) unitOf.set(q, u)
  }
  const unitW = (u: Unit): number => u.members.length * CARD_W + (u.members.length - 1) * SPOUSE_GAP
  const memberOff = (u: Unit, p: string): number => u.members.indexOf(p) * (CARD_W + SPOUSE_GAP)

  const levels = [...new Set(units.map((u) => u.level))].sort((a, b) => a - b)
  const rows = new Map<number, Unit[]>(levels.map((l) => [l, []]))

  /* 3. Ordre ---------------------------------------------------------- */
  // Ordre initial : parcours en profondeur depuis moi (conjoints, enfants,
  // parents, fratrie), qui garde déjà les familles groupées.
  const firstSeen = new Map<number, number>()
  {
    const st = [root]
    const vis = new Set<string>()
    let k = 0
    while (st.length) {
      const p = st.pop()!
      if (vis.has(p)) continue
      vis.add(p)
      const u = unitOf.get(p)!
      if (!firstSeen.has(u.id)) firstSeen.set(u.id, k++)
      const next: string[] = []
      next.push(...u.members)
      for (const f of ownFams(p)) next.push(...f.children)
      for (const f of parentFams(p)) next.push(...f.parents, ...f.children)
      for (let i = next.length - 1; i >= 0; i--) if (!vis.has(next[i]!)) st.push(next[i]!)
    }
  }
  for (const u of units) rows.get(u.level)!.push(u)
  for (const row of rows.values()) row.sort((a, b) => firstSeen.get(a.id)! - firstSeen.get(b.id)!)

  const slot = new Map<string, number>()
  const refreshSlots = (row: Unit[]): void => {
    let s = 0
    for (const u of row) for (const m of u.members) slot.set(m, s++)
  }
  const famSlot = (f: Family): number | null => {
    const ps = f.parents.filter((p) => slot.has(p))
    return ps.length ? ps.reduce((a, p) => a + slot.get(p)!, 0) / ps.length : null
  }
  levels.forEach((l) => refreshSlots(rows.get(l)!))

  const orient = (u: Unit, score: (p: string) => number | null): void => {
    if (u.members.length < 2) return
    const a = score(u.members[0]!)
    const b = score(u.members[u.members.length - 1]!)
    if (a !== null && b !== null && a > b) u.members.reverse()
  }
  const upScore = (p: string): number | null => {
    const s = parentFams(p)
      .map(famSlot)
      .filter((v): v is number => v !== null)
    return s.length ? s.reduce((a, b) => a + b, 0) / s.length : null
  }
  const downScore = (p: string): number | null => {
    const s = ownFams(p).flatMap((f) => f.children.map((c) => slot.get(c)!))
    return s.length ? s.reduce((a, b) => a + b, 0) / s.length : null
  }
  const sortRow = (l: number, score: (p: string) => number | null): void => {
    const row = rows.get(l)!
    const keyed = row.map((u, i) => {
      orient(u, score)
      const vals = u.members.map(score).filter((v): v is number => v !== null)
      const cur = slot.get(u.members[0]!)!
      return { u, k: vals.length ? vals.reduce((a, b) => a + b, 0) / vals.length : cur, i }
    })
    keyed.sort((a, b) => a.k - b.k || a.i - b.i)
    rows.set(
      l,
      keyed.map((e) => e.u),
    )
    refreshSlots(rows.get(l)!)
  }
  const crossings = (): number => {
    let total = 0
    for (let i = 0; i + 1 < levels.length; i++) {
      const edges: [number, number][] = []
      for (const u of rows.get(levels[i + 1]!)!)
        for (const m of u.members)
          for (const f of parentFams(m)) {
            const s = famSlot(f)
            if (s !== null && f.parents.some((p) => level.get(p) === levels[i])) edges.push([s, slot.get(m)!])
          }
      for (let a = 0; a < edges.length; a++)
        for (let b = a + 1; b < edges.length; b++) {
          const [a1, a2] = edges[a]!
          const [b1, b2] = edges[b]!
          if ((a1 - b1) * (a2 - b2) < 0) total++
        }
    }
    return total
  }
  const snapshot = (): Map<number, [Unit, string[]][]> =>
    new Map(levels.map((l) => [l, rows.get(l)!.map((u) => [u, [...u.members]] as [Unit, string[]])]))
  let best = snapshot()
  let bestC = crossings()
  for (let it = 0; it < 24 && bestC > 0; it++) {
    for (const l of levels.slice(1)) sortRow(l, upScore)
    for (const l of levels.slice(0, -1).reverse()) sortRow(l, downScore)
    const c = crossings()
    if (c < bestC) {
      bestC = c
      best = snapshot()
    }
  }
  for (const [l, row] of best) {
    rows.set(
      l,
      row.map(([u, m]) => ((u.members = m), u)),
    )
    refreshSlots(rows.get(l)!)
  }

  /* 4. Abscisses ------------------------------------------------------ */
  const siblingsOf = (u: Unit): Set<string> =>
    new Set(u.members.flatMap((m) => asChild.get(m) ?? []))
  const gapBetween = (a: Unit, b: Unit): number => {
    const sa = siblingsOf(a)
    for (const f of siblingsOf(b)) if (sa.has(f)) return SIBLING_GAP
    return GROUP_GAP
  }
  for (const row of rows.values()) {
    let x = 0
    row.forEach((u, i) => {
      if (i) x += gapBetween(row[i - 1]!, u)
      u.x = x
      x += unitW(u)
    })
    const shift = -x / 2
    for (const u of row) u.x += shift
  }
  const center = (p: string): number => {
    const u = unitOf.get(p)!
    return u.x + memberOff(u, p) + CARD_W / 2
  }
  const famX = (f: Family): number | null => {
    const ps = f.parents.filter((p) => unitOf.has(p))
    return ps.length ? ps.reduce((a, p) => a + center(p), 0) / ps.length : null
  }
  const placeRow = (l: number, useUp: boolean, useDown: boolean): void => {
    const row = rows.get(l)!
    const desired: number[] = []
    const weight: number[] = []
    for (const u of row) {
      let sum = 0
      let n = 0
      for (const m of u.members) {
        const off = memberOff(u, m) + CARD_W / 2
        if (useUp)
          for (const f of parentFams(m)) {
            const fx = famX(f)
            if (fx !== null) (sum += 2 * (fx - off), (n += 2))
          }
        if (useDown)
          for (const f of ownFams(m)) {
            const own = f.parents.filter((p) => unitOf.get(p) === u)
            if (!own.includes(m) || own[0] !== m) continue // une fois par famille
            const fo = own.reduce((a, p) => a + memberOff(u, p) + CARD_W / 2, 0) / own.length
            const cs = f.children.filter((c) => unitOf.has(c))
            if (!cs.length) continue
            const cx = cs.reduce((a, c) => a + center(c), 0) / cs.length
            sum += 2 * (cx - fo)
            n += 2
          }
      }
      desired.push(n ? sum / n : u.x)
      weight.push(Math.max(1, n))
    }
    const gaps = row.slice(1).map((u, i) => gapBetween(row[i]!, u))
    const xs = isotonic(
      desired,
      row.map(unitW),
      gaps,
      weight,
    )
    row.forEach((u, i) => (u.x = xs[i]!))
  }
  for (let it = 0; it < 40; it++) {
    for (const l of levels.slice(1)) placeRow(l, true, it > 4)
    for (const l of levels.slice(0, -1).reverse()) placeRow(l, it > 4, true)
  }
  // Dernière passe descendante : les enfants se centrent sous leurs parents.
  for (const l of levels) placeRow(l, true, true)

  /* 5. Ordonnées et liens ---------------------------------------------- */
  // Couloirs des peignes, par intervalle occupé, pour chaque interstice.
  type Comb = { f: Family; fx: number; from: number; to: number; lane: number; start: number }
  const combsByLevel = new Map<number, Comb[]>()
  const parentLevel = (f: Family): number | null => {
    const ls = f.parents.filter((p) => level.has(p)).map((p) => level.get(p)!)
    if (ls.length) return Math.max(...ls)
    const cs = f.children.filter((c) => level.has(c)).map((c) => level.get(c)!)
    return cs.length ? Math.min(...cs) - 1 : null
  }
  for (const fid of compFams) {
    const f = fam(fid)
    const cs = f.children.filter((c) => unitOf.has(c))
    if (!cs.length) continue
    const pl = parentLevel(f)
    if (pl === null) continue
    const fx = famX(f) ?? cs.reduce((a, c) => a + center(c), 0) / cs.length
    const xs = [fx, ...cs.map(center)]
    push(combsByLevel, pl, { f, fx, from: Math.min(...xs) - 8, to: Math.max(...xs) + 8, lane: 0, start: 0 })
  }
  const laneCount = new Map<number, number>()
  for (const [l, combs] of combsByLevel) {
    combs.sort((a, b) => a.from - b.from || a.to - b.to)
    const ends: number[] = []
    for (const c of combs) {
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
  const yOf = (p: string): number => top.get(level.get(p)!)!

  const persons: PlacedPerson[] = members.map((p) => ({
    id: p,
    node: ctx.people.get(p)!,
    level: level.get(p)!,
    x: center(p) - CARD_W / 2,
    y: yOf(p),
  }))

  const links: Link[] = []
  for (const fid of compFams) {
    const f = fam(fid)
    const ps = f.parents.filter((p) => unitOf.has(p))
    let px: number | null = null
    let py = 0
    if (ps.length === 1) {
      px = center(ps[0]!)
      py = yOf(ps[0]!) + CARD_H
    } else if (ps.length === 2) {
      const [a, b] = [...ps].sort((p, q) => center(p) - center(q)) as [string, string]
      const ua = unitOf.get(a)!
      const adjacent =
        ua === unitOf.get(b) && Math.abs(ua.members.indexOf(a) - ua.members.indexOf(b)) === 1
      if (adjacent) {
        py = yOf(a) + CARD_H / 2
        px = (center(a) + center(b)) / 2
        links.push({
          key: `${fid}:c`,
          d: `M${r1(center(a) + CARD_W / 2)} ${r1(py)}H${r1(center(b) - CARD_W / 2)}`,
          kind: 'couple',
          divorced: f.divorced,
          pending: f.pending,
          people: [a, b],
        })
      } else {
        // Conjoints non voisins : chacun descend, on les relie sous la rangée.
        py = Math.max(yOf(a), yOf(b)) + CARD_H + 10
        px = (center(a) + center(b)) / 2
        links.push({
          key: `${fid}:c`,
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
    const cs = f.children.filter((c) => unitOf.has(c))
    if (!cs.length) continue
    const comb = combsByLevel.get(parentLevel(f)!)!.find((c) => c.f === f)!
    const pl = parentLevel(f)!
    const busY = top.get(pl)! + CARD_H + GAP_PAD + comb.lane * LANE_STEP
    const xs = cs.map(center)
    let d = ''
    const x0 = px ?? comb.fx
    if (px !== null) d += `M${r1(px)} ${r1(py)}V${r1(busY)}`
    const lo = Math.min(x0, ...xs)
    const hi = Math.max(x0, ...xs)
    if (hi - lo > 0.5) d += `M${r1(lo)} ${r1(busY)}H${r1(hi)}`
    for (const c of cs) d += `M${r1(center(c))} ${r1(busY)}V${r1(yOf(c))}`
    links.push({
      key: `${fid}:d`,
      d,
      kind: 'descent',
      divorced: false,
      pending: f.pending,
      people: [...ps, ...cs],
    })
  }

  let minX = Infinity
  let maxX = -Infinity
  for (const p of persons) {
    minX = Math.min(minX, p.x)
    maxX = Math.max(maxX, p.x + CARD_W)
  }
  return { persons, links, minX, maxX }
}

/**
 * Place une rangée au plus près des abscisses voulues, sans chevauchement et
 * sans changer l'ordre : régression isotone pondérée (pool adjacent violators).
 */
export function isotonic(desired: number[], widths: number[], gaps: number[], weights: number[]): number[] {
  const n = desired.length
  const off: number[] = []
  let acc = 0
  for (let i = 0; i < n; i++) {
    off.push(acc)
    acc += widths[i]! + (gaps[i] ?? 0)
  }
  const blocks: { sum: number; w: number; len: number }[] = []
  for (let i = 0; i < n; i++) {
    const w = weights[i]!
    blocks.push({ sum: (desired[i]! - off[i]!) * w, w, len: 1 })
    while (blocks.length > 1) {
      const b = blocks[blocks.length - 1]!
      const a = blocks[blocks.length - 2]!
      if (a.sum / a.w <= b.sum / b.w) break
      blocks.pop()
      a.sum += b.sum
      a.w += b.w
      a.len += b.len
    }
  }
  const out: number[] = []
  for (const b of blocks) for (let k = 0; k < b.len; k++) out.push(b.sum / b.w + off[out.length]!)
  return out
}
