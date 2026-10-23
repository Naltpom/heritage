/**
 * **Mesurer la lisibilité d'une disposition** — celle de `/arbre` comme celle de
 * `/arbre-review`, avec la même règle, sur le DESSIN réellement produit.
 *
 * Pourquoi sur le dessin et pas sur l'ordre : le compte « bande par bande » de
 * `arbre2/groupes.ts` (des paires milieu du couple → enfant qui s'inversent) ne voit ni
 * une barre de fratrie qui passe sur la chute d'une autre famille, ni une union qui
 * contourne dix fiches par le dessous, ni un trait qui file d'un bout à l'autre d'un
 * rectangle. Le lecteur, lui, les voit. On relit donc les segments des traits
 * (`traces.familles[].d`, des `M x y L x y`), famille par famille, et on compte :
 *
 *  - **les croisements** : deux segments de familles différentes qui se coupent
 *    franchement (pas à une extrémité) ;
 *  - **les chevauchements** : deux segments de familles différentes posés l'un sur
 *    l'autre (même ligne, plus d'un pixel en commun) — on ne sait plus qui va où ;
 *  - **les contacts** : l'extrémité d'un segment qui tombe à l'intérieur du segment
 *    d'une autre famille — un « T » qui fait croire à une jonction ;
 *  - **les traits sous une carte** : un segment qui passe à l'intérieur d'une fiche ;
 *  - **les traits qui traversent un groupe** : un segment qui entre d'un côté d'un
 *    rectangle de famille et ressort de l'autre (« tout un groupe ») ; et ceux qui
 *    entrent dans le rectangle d'un groupe où la famille n'a personne ;
 *  - **la longueur des liens** : le décalage horizontal entre le milieu d'un couple et
 *    chacun de ses enfants (ce qui rend un lien long ; la hauteur d'une génération, elle,
 *    ne dépend que de l'interligne), et la longueur totale tracée ;
 *  - **les espacements** : le plus petit écart entre deux fiches d'une rangée, et entre
 *    deux rangées ;
 *  - **les couples séparés** : deux époux d'une même rangée entre lesquels une autre
 *    fiche s'intercale ;
 *  - **la fratrie sous ses parents** : l'écart horizontal entre le centre de chaque
 *    fratrie (conjoints compris) et le centre de ses parents.
 *
 * Pur, sans DOM : `mesures.test.ts` le vérifie sur des dessins faits à la main, et le
 * banc (`revue.banc.ts`) l'applique aux deux moteurs.
 */
import type { DispositionHistoire, FicheHistoire } from '@/histoire/lignees'

/** Un rectangle du monde (coin haut-gauche, taille). */
export interface Boite {
  x: number
  y: number
  w: number
  h: number
}

export interface Mesures {
  personnes: number
  croisements: number
  chevauchements: number
  contacts: number
  /** Paires (segment, fiche) où le segment passe à l'intérieur de la fiche. */
  sousUneCarte: number
  /** Segments qui traversent un rectangle de famille de part en part. */
  traverseUnGroupe: number
  /** Segments qui entrent dans le rectangle d'un groupe où leur famille n'a personne. */
  dansUnGroupeEtranger: number
  /** Somme des décalages horizontaux « milieu du couple → enfant », en pixels du monde. */
  decalageTotal: number
  /** Le plus grand de ces décalages. */
  decalageMax: number
  /** Longueur totale tracée (unions, descentes, barres, chutes), en pixels du monde. */
  longueurTracee: number
  /** Le plus petit écart horizontal entre deux fiches voisines d'une rangée. */
  ecartMinFiches: number
  /** L'écart horizontal médian entre deux fiches voisines. */
  ecartMedianFiches: number
  /** Le plus petit écart vertical entre deux rangées (bas de l'une, haut de la suivante). */
  ecartMinRangees: number
  couplesSepares: number
  /** Les fratries qu'une fiche étrangère (ni frère ou sœur, ni conjoint de l'un d'eux) coupe en deux. */
  fratriesCoupees: number
  /**
   * L'écart horizontal entre le centre de chaque fratrie — ses conjoints posés à côté
   * (`CONJOINT_A_COTE`) compris — et le centre de ses parents (demande du 22/10/2026 :
   * « des enfants loin de leurs parents alors que la place est libre juste dessous »). En
   * pixels du monde : la somme, le plus grand, les centiles ; le nombre de familles
   * mesurées, et celles dont la fratrie est centrée à moins d'un pixel. Les écarts
   * ÉVITABLES : ceux d'une fratrie dont la place, juste sous ses parents, est libre de toute
   * autre fiche ; « seules », celles qui ne partagent aucun couple avec une autre fratrie (un
   * couple dont les deux époux ont leurs parents posés va à mi-chemin, et c'est voulu). Un
   * couple à cheval sur deux rectangles a une fratrie par rectangle, mesurée sous le parent
   * de ce rectangle (retours du 23/10/2026). L'ÉTALEMENT : ce qu'une fratrie s'étire au-delà
   * de sa largeur serrée (entre deux fiches voisines de la fratrie, conjoints compris, tout
   * écart de plus de `CONJOINT_A_COTE`) — une fratrie éclatée a une barre immense, même si son
   * milieu reste sous ses parents ; la somme, et le nombre de fratries étalées.
   */
  fratrieSousParents: {
    total: number
    max: number
    p50: number
    p90: number
    p99: number
    familles: number
    centrees: number
    evitables: number
    evitablesTotal: number
    evitablesSeules: number
    evitablesSeulesTotal: number
    etalementTotal: number
    etalees: number
  }
  largeur: number
  hauteur: number
  /** Surface du monde, en mégapixels — pour information, pas un objectif. */
  surface: number
}

/** Un segment, rangé : `x1 ≤ x2` pour un horizontal, `y1 ≤ y2` pour un vertical. */
interface Segment {
  x1: number
  y1: number
  x2: number
  y2: number
  famille: number
}

const EPS = 0.5
/**
 * La marge autour de la place d'une fratrie centrée, pour la dire libre : l'écart entre deux
 * familles de `/arbre-review` (`ECART_FAMILLE`), que l'alignement ne rogne jamais — on voit
 * où une famille finit et où l'autre commence.
 */
const MARGE_LIBRE = 176
/**
 * Un conjoint compte dans l'étendue d'une fratrie s'il est posé À CÔTÉ : voisin dans la
 * rangée, et plus près qu'un écart de deux familles. Un grand-père, au bord de son quart, et
 * sa femme dans le quart voisin se suivent dans la rangée, à des milliers de pixels l'un de
 * l'autre : elle n'est pas de sa fratrie.
 */
const CONJOINT_A_COTE = 176

/** Les segments d'un chemin `M x y L x y L …` (seules commandes que les traits emploient). */
export function segmentsDe(d: string): [number, number, number, number][] {
  const sortie: [number, number, number, number][] = []
  const jetons = d.match(/[ML]|-?\d+(?:\.\d+)?(?:e-?\d+)?/g) ?? []
  let x = 0
  let y = 0
  let commande = ''
  for (let i = 0; i < jetons.length; ) {
    const t = jetons[i]!
    if (t === 'M' || t === 'L') {
      commande = t
      i++
      continue
    }
    const nx = Number(t)
    const ny = Number(jetons[i + 1])
    i += 2
    if (commande === 'L') sortie.push([x, y, nx, ny])
    x = nx
    y = ny
  }
  return sortie
}

function ranger(s: [number, number, number, number], famille: number): Segment {
  const [x1, y1, x2, y2] = s
  if (Math.abs(y1 - y2) < EPS) return { x1: Math.min(x1, x2), y1, x2: Math.max(x1, x2), y2: y1, famille }
  if (Math.abs(x1 - x2) < EPS) return { x1, y1: Math.min(y1, y2), x2: x1, y2: Math.max(y1, y2), famille }
  return { x1, y1, x2, y2, famille }
}

/** Le premier indice de `tri` (trié sur `cle`) dont la clé est ≥ `v`. */
function premierAuMoins<T>(tri: readonly T[], cle: (t: T) => number, v: number): number {
  let lo = 0
  let hi = tri.length
  while (lo < hi) {
    const m = (lo + hi) >> 1
    if (cle(tri[m]!) < v) lo = m + 1
    else hi = m
  }
  return lo
}

/** Deux segments quelconques se coupent-ils franchement (pas à une extrémité) ? */
function seCoupent(a: Segment, b: Segment): boolean {
  const d1x = a.x2 - a.x1
  const d1y = a.y2 - a.y1
  const d2x = b.x2 - b.x1
  const d2y = b.y2 - b.y1
  const den = d1x * d2y - d1y * d2x
  if (Math.abs(den) < 1e-9) return false
  const t = ((b.x1 - a.x1) * d2y - (b.y1 - a.y1) * d2x) / den
  const u = ((b.x1 - a.x1) * d1y - (b.y1 - a.y1) * d1x) / den
  const m = 1e-6
  return t > m && t < 1 - m && u > m && u < 1 - m
}

/** Un segment passe-t-il à l'intérieur (strict, à `EPS` près) d'une boîte ? Liang–Barsky. */
function entreDans(s: Segment, b: Boite): boolean {
  const x0 = b.x + EPS
  const x1 = b.x + b.w - EPS
  const y0 = b.y + EPS
  const y1 = b.y + b.h - EPS
  if (x1 <= x0 || y1 <= y0) return false
  const dx = s.x2 - s.x1
  const dy = s.y2 - s.y1
  let t0 = 0
  let t1 = 1
  for (const [p, q] of [
    [-dx, s.x1 - x0],
    [dx, x1 - s.x1],
    [-dy, s.y1 - y0],
    [dy, y1 - s.y1],
  ] as const) {
    if (Math.abs(p) < 1e-12) {
      if (q <= 0) return false
      continue
    }
    const r = q / p
    if (p < 0) t0 = Math.max(t0, r)
    else t1 = Math.min(t1, r)
    if (t0 >= t1) return false
  }
  return t1 - t0 > 1e-9
}

/** Le segment entre-t-il d'un côté de la boîte pour ressortir du côté opposé ? */
function traverse(s: Segment, b: Boite): boolean {
  const horizontal = Math.abs(s.y1 - s.y2) < EPS
  const vertical = Math.abs(s.x1 - s.x2) < EPS
  if (horizontal) return s.y1 > b.y + EPS && s.y1 < b.y + b.h - EPS && s.x1 < b.x - EPS && s.x2 > b.x + b.w + EPS
  if (vertical) return s.x1 > b.x + EPS && s.x1 < b.x + b.w - EPS && s.y1 < b.y - EPS && s.y2 > b.y + b.h + EPS
  return false
}

/**
 * Les traits d'une famille tels que l'œil les voit : deux segments d'une même famille posés
 * sur la même ligne, qui se recouvrent ou se touchent, n'en font qu'un. Sans cela, la barre
 * ordinaire que `/arbre` trace SOUS le tronçon de la lignée directe compterait chaque
 * croisement deux fois, et la longueur tracée aussi.
 */
function fusionner(segments: readonly Segment[]): Segment[] {
  const sortie: Segment[] = []
  const lignes = new Map<string, Segment[]>()
  for (const s of segments) {
    const horizontal = Math.abs(s.y1 - s.y2) < EPS && s.x2 - s.x1 > EPS
    const vertical = Math.abs(s.x1 - s.x2) < EPS && s.y2 - s.y1 > EPS
    if (!horizontal && !vertical) {
      sortie.push(s)
      continue
    }
    const cle = horizontal ? `h|${s.famille}|${Math.round(s.y1 * 2) / 2}` : `v|${s.famille}|${Math.round(s.x1 * 2) / 2}`
    const l = lignes.get(cle)
    if (l) l.push(s)
    else lignes.set(cle, [s])
  }
  for (const [cle, l] of lignes) {
    const horizontal = cle.startsWith('h')
    const debut = (s: Segment): number => (horizontal ? s.x1 : s.y1)
    const fin = (s: Segment): number => (horizontal ? s.x2 : s.y2)
    l.sort((a, b) => debut(a) - debut(b))
    let courant = { ...l[0]! }
    for (let i = 1; i < l.length; i++) {
      const s = l[i]!
      if (debut(s) <= fin(courant) + EPS) {
        if (horizontal) courant.x2 = Math.max(courant.x2, s.x2)
        else courant.y2 = Math.max(courant.y2, s.y2)
      } else {
        sortie.push(courant)
        courant = { ...s }
      }
    }
    sortie.push(courant)
  }
  return sortie
}

export function mesurer(d: DispositionHistoire, rectangles: readonly Boite[] = []): Mesures {
  /* Les segments, famille par famille, fusionnés quand ils se recouvrent. */
  const bruts: Segment[] = []
  d.traces.familles.forEach((f, i) => {
    for (const s of segmentsDe(f.d)) bruts.push(ranger(s, i))
  })
  const segments = fusionner(bruts)
  let longueurTracee = 0
  for (const s of segments) longueurTracee += Math.hypot(s.x2 - s.x1, s.y2 - s.y1)
  const horizontaux = segments.filter((s) => s.y1 === s.y2 && s.x2 - s.x1 > EPS)
  const verticaux = segments.filter((s) => s.x1 === s.x2 && s.y2 - s.y1 > EPS)
  const obliques = segments.filter((s) => s.x1 !== s.x2 && s.y1 !== s.y2)

  /* Croisements et contacts horizontal × vertical : les verticaux triés sur x. */
  verticaux.sort((a, b) => a.x1 - b.x1)
  let croisements = 0
  let contacts = 0
  for (const h of horizontaux) {
    for (let i = premierAuMoins(verticaux, (v) => v.x1, h.x1 - EPS); i < verticaux.length; i++) {
      const v = verticaux[i]!
      if (v.x1 > h.x2 + EPS) break
      if (v.famille === h.famille) continue
      const dedansX = v.x1 > h.x1 + EPS && v.x1 < h.x2 - EPS
      const dedansY = h.y1 > v.y1 + EPS && h.y1 < v.y2 - EPS
      if (dedansX && dedansY) croisements++
      else {
        // Un « T » : l'extrémité de l'un tombe sur l'intérieur de l'autre.
        const surV = (Math.abs(h.y1 - v.y1) < EPS || Math.abs(h.y1 - v.y2) < EPS) && dedansX
        const surH = (Math.abs(v.x1 - h.x1) < EPS || Math.abs(v.x1 - h.x2) < EPS) && dedansY
        if (surV || surH) contacts++
      }
    }
  }
  /* Les obliques (rares) : contre tout le reste, sur leur boîte englobante — une paire
     d'obliques n'est comptée qu'une fois. */
  const estOblique = new Set(obliques)
  obliques.forEach((o, io) => {
    const bx0 = Math.min(o.x1, o.x2)
    const bx1 = Math.max(o.x1, o.x2)
    const by0 = Math.min(o.y1, o.y2)
    const by1 = Math.max(o.y1, o.y2)
    for (const s of segments) {
      if (s === o || s.famille === o.famille) continue
      if (estOblique.has(s) && obliques.indexOf(s) < io) continue
      if (Math.max(s.x1, s.x2) < bx0 || Math.min(s.x1, s.x2) > bx1 || Math.max(s.y1, s.y2) < by0 || Math.min(s.y1, s.y2) > by1) continue
      if (seCoupent(o, s)) croisements++
    }
  })

  /* Chevauchements : même ligne, intervalles qui se recouvrent de plus d'un pixel. */
  let chevauchements = 0
  const parLigne = <T extends Segment>(liste: T[], cle: (s: T) => number): Map<number, T[]> => {
    const m = new Map<number, T[]>()
    for (const s of liste) {
      const k = Math.round(cle(s) * 2) / 2
      const l = m.get(k)
      if (l) l.push(s)
      else m.set(k, [s])
    }
    return m
  }
  const compterRecouvrements = (liste: Segment[], debut: (s: Segment) => number, fin: (s: Segment) => number): void => {
    const tri = [...liste].sort((a, b) => debut(a) - debut(b))
    for (let i = 0; i < tri.length; i++) {
      for (let j = i + 1; j < tri.length && debut(tri[j]!) < fin(tri[i]!) - 1; j++) {
        if (tri[j]!.famille !== tri[i]!.famille && Math.min(fin(tri[i]!), fin(tri[j]!)) - debut(tri[j]!) > 1) chevauchements++
      }
    }
  }
  for (const l of parLigne(horizontaux, (s) => s.y1).values()) compterRecouvrements(l, (s) => s.x1, (s) => s.x2)
  for (const l of parLigne(verticaux, (s) => s.x1).values()) compterRecouvrements(l, (s) => s.y1, (s) => s.y2)

  /* Les fiches, par rangée (même `y`), triées sur x. */
  const rangees = new Map<number, FicheHistoire[]>()
  for (const f of d.fiches) {
    const l = rangees.get(f.y)
    if (l) l.push(f)
    else rangees.set(f.y, [f])
  }
  const lignesTriees = [...rangees.entries()].sort((a, b) => a[0] - b[0]).map(([, l]) => l.sort((a, b) => a.x - b.x))

  /* Les traits sous une carte : chaque segment contre les fiches des rangées qu'il touche. */
  const etendues = lignesTriees.map((l) => ({ haut: l[0]!.y, bas: Math.max(...l.map((f) => f.y + f.h)), large: Math.max(...l.map((f) => f.w)) }))
  let sousUneCarte = 0
  for (const s of segments) {
    const sy0 = Math.min(s.y1, s.y2)
    const sy1 = Math.max(s.y1, s.y2)
    const sx0 = Math.min(s.x1, s.x2)
    const sx1 = Math.max(s.x1, s.x2)
    lignesTriees.forEach((ligne, k) => {
      const e = etendues[k]!
      if (e.bas <= sy0 || e.haut >= sy1) return
      for (let i = premierAuMoins(ligne, (f) => f.x, sx0 - e.large); i < ligne.length; i++) {
        const f = ligne[i]!
        if (f.x > sx1) break
        if (entreDans(s, f)) sousUneCarte++
      }
    })
  }

  /* Les rectangles : qui y est, et les traits qui les traversent. */
  let traverseUnGroupe = 0
  let dansUnGroupeEtranger = 0
  if (rectangles.length) {
    const rectDe = (f: FicheHistoire): number =>
      rectangles.findIndex((r) => f.cx >= r.x && f.cx <= r.x + r.w && f.y + f.h / 2 >= r.y && f.y + f.h / 2 <= r.y + r.h)
    const rectsDeFamille = d.traces.familles.map(
      (fam) => new Set(fam.personnes.map((id) => d.parId.get(id)).filter((f): f is FicheHistoire => !!f).map(rectDe)),
    )
    for (const s of segments) {
      for (let r = 0; r < rectangles.length; r++) {
        const b = rectangles[r]!
        if (traverse(s, b)) traverseUnGroupe++
        else if (!rectsDeFamille[s.famille]!.has(r) && entreDans(s, b)) dansUnGroupeEtranger++
      }
    }
  }

  /* Longueur des liens : le milieu du couple, puis chaque enfant. */
  let decalageTotal = 0
  let decalageMax = 0
  let couplesSepares = 0
  let fratriesCoupees = 0
  const ecartsFratries: number[] = []
  let evitables = 0
  let evitablesTotal = 0
  let evitablesSeules = 0
  let evitablesSeulesTotal = 0
  let etalementTotal = 0
  let etalees = 0
  /** Les conjoints de chacun : les époux d'une même famille, sur la rangée la plus haute. */
  const conjoints = new Map<string, string[]>()
  for (const fam of d.traces.familles) {
    const membres = fam.personnes.map((id) => d.parId.get(id)).filter((f): f is FicheHistoire => !!f)
    if (!membres.length) continue
    const haut = Math.min(...membres.map((f) => f.rangee))
    const ep = membres.filter((f) => f.rangee === haut).map((f) => f.id)
    for (const a of ep) for (const b of ep) if (a !== b) conjoints.set(a, [...(conjoints.get(a) ?? []), b])
  }
  const indexDans = new Map<string, number>()
  for (const ligne of lignesTriees) ligne.forEach((f, i) => indexDans.set(f.id, i))
  /** Le rectangle d'une fiche (−1 hors de tout rectangle, ou sans rectangles). */
  const rectangleDe = (f: FicheHistoire): number =>
    rectangles.findIndex((r) => f.cx >= r.x && f.cx <= r.x + r.w && f.y >= r.y && f.y + f.h <= r.y + r.h)
  /** Les familles dont chaque personne est un enfant posé une rangée sous ses parents. */
  const enfantDe = new Map<string, string>()
  for (const fam of d.traces.familles) {
    const membres = fam.personnes.map((id) => d.parId.get(id)).filter((f): f is FicheHistoire => !!f)
    if (!membres.length) continue
    const haut = Math.min(...membres.map((f) => f.rangee))
    for (const f of membres) if (f.rangee === haut + 1) enfantDe.set(f.id, fam.id)
  }
  for (const fam of d.traces.familles) {
    const membres = fam.personnes.map((id) => d.parId.get(id)).filter((f): f is FicheHistoire => !!f)
    // `personnes` : les époux d'abord, puis les enfants (une rangée plus bas).
    const rangeeEpoux = membres.length ? Math.min(...membres.map((f) => f.rangee)) : 0
    const epoux = membres.filter((f) => f.rangee === rangeeEpoux)
    const enfants = membres.filter((f) => f.rangee !== rangeeEpoux)
    if (!epoux.length) continue
    const milieu = epoux.reduce((s, f) => s + f.cx, 0) / epoux.length
    for (const e of enfants) {
      const dx = Math.abs(e.cx - milieu)
      decalageTotal += dx
      decalageMax = Math.max(decalageMax, dx)
    }
    if (epoux.length >= 2) {
      const idx = epoux.map((f) => indexDans.get(f.id)!).sort((a, b) => a - b)
      if (idx[idx.length - 1]! - idx[0]! > epoux.length - 1) couplesSepares++
    }
    // La fratrie d'un seul tenant ? Entre le premier et le dernier enfant d'une rangée, rien
    // que des frères et sœurs et leurs conjoints.
    const parRangeeEnfants = new Map<number, FicheHistoire[]>()
    for (const e of enfants) {
      const l = parRangeeEnfants.get(e.rangee)
      if (l) l.push(e)
      else parRangeeEnfants.set(e.rangee, [e])
    }
    // L'écart de la fratrie (ses conjoints posés à côté compris) à ses parents, la rangée
    // sous la leur seulement (un enfant d'une autre génération, un implexe, n'a pas de place
    // « sous » ses parents). Un couple à cheval sur deux rectangles (le grand-père dans pp, la
    // grand-mère dans pm) : chaque rectangle a sa fratrie, mesurée sous le parent de ce
    // rectangle — un enfant posé dans un troisième (le père, dans le tronc) l'est sous les deux.
    const parRectangle = new Map<number, FicheHistoire[]>()
    for (const e of parRangeeEnfants.get(rangeeEpoux + 1) ?? []) {
      const r = rectangleDe(e)
      const l = parRectangle.get(r)
      if (l) l.push(e)
      else parRectangle.set(r, [e])
    }
    for (const [r, fratrie] of parRectangle) {
      const ici = r >= 0 ? epoux.filter((p) => rectangleDe(p) === r) : []
      const sousQui = ici.length ? ici : epoux
      const centreDesParents = sousQui.reduce((s, f) => s + f.cx, 0) / sousQui.length
      const ligne = lignesTriees.find((l) => l[0]!.rangee === rangeeEpoux + 1)
      let lo = Infinity
      let hi = -Infinity
      const etendre = (f: FicheHistoire): void => {
        lo = Math.min(lo, f.x)
        hi = Math.max(hi, f.x + f.w)
      }
      const groupe = new Set<string>()
      let partagee = false
      for (const e of fratrie) {
        etendre(e)
        groupe.add(e.id)
        const i = indexDans.get(e.id)!
        for (const c of conjoints.get(e.id) ?? []) {
          const fc = d.parId.get(c)
          const aCote = fc && Math.max(fc.x - (e.x + e.w), e.x - (fc.x + fc.w)) < CONJOINT_A_COTE
          if (fc && aCote && fc.rangee === e.rangee && ligne && Math.abs(indexDans.get(c)! - i) <= 2) {
            etendre(fc)
            groupe.add(fc.id)
            // Un conjoint qui a, lui aussi, ses parents posés : le couple va à mi-chemin.
            if (enfantDe.has(c) && enfantDe.get(c) !== fam.id) partagee = true
          }
        }
      }
      const ecart = Math.abs((lo + hi) / 2 - centreDesParents)
      ecartsFratries.push(ecart)
      // L'étalement : les trous de plus d'un écart de familles entre deux fiches de la fratrie.
      const cartes = [...groupe].map((id) => d.parId.get(id)!).sort((u, v) => u.x - v.x)
      let etalement = 0
      for (let i = 1; i < cartes.length; i++) etalement += Math.max(0, cartes[i]!.x - (cartes[i - 1]!.x + cartes[i - 1]!.w) - CONJOINT_A_COTE)
      if (etalement > 0) {
        etalementTotal += etalement
        etalees++
      }
      // Évitable ? La place sous les parents — la même fratrie, centrée — est-elle libre ?
      if (ecart >= 1 && ligne) {
        const a = centreDesParents - (hi - lo) / 2 - MARGE_LIBRE
        const b = centreDesParents + (hi - lo) / 2 + MARGE_LIBRE
        if (!ligne.some((f) => !groupe.has(f.id) && f.x < b && f.x + f.w > a)) {
          evitables++
          evitablesTotal += ecart
          if (!partagee) {
            evitablesSeules++
            evitablesSeulesTotal += ecart
          }
        }
      }
    }
    for (const groupe of parRangeeEnfants.values()) {
      if (groupe.length < 2) continue
      const ligne = lignesTriees.find((l) => l[0]!.rangee === groupe[0]!.rangee)
      if (!ligne) continue
      const ids = new Set(groupe.map((e) => e.id))
      const conjointsDesEnfants = new Set<string>()
      for (const e of groupe) for (const c of conjoints.get(e.id) ?? []) conjointsDesEnfants.add(c)
      const idx = groupe.map((e) => indexDans.get(e.id)!).sort((a, b) => a - b)
      for (let k = idx[0]! + 1; k < idx[idx.length - 1]!; k++) {
        const f = ligne[k]!
        if (!ids.has(f.id) && !conjointsDesEnfants.has(f.id)) {
          fratriesCoupees++
          break
        }
      }
    }
  }

  /* Espacements. */
  const ecarts: number[] = []
  let ecartMinFiches = Infinity
  for (const ligne of lignesTriees) {
    for (let i = 1; i < ligne.length; i++) {
      const e = ligne[i]!.x - (ligne[i - 1]!.x + ligne[i - 1]!.w)
      ecarts.push(e)
      ecartMinFiches = Math.min(ecartMinFiches, e)
    }
  }
  ecarts.sort((a, b) => a - b)
  ecartsFratries.sort((a, b) => a - b)
  const centile = (q: number): number => (ecartsFratries.length ? Math.round(ecartsFratries[Math.min(ecartsFratries.length - 1, Math.floor(q * ecartsFratries.length))]!) : 0)
  let ecartMinRangees = Infinity
  for (let i = 1; i < lignesTriees.length; i++) {
    const haut = lignesTriees[i - 1]!
    const bas = lignesTriees[i]!
    ecartMinRangees = Math.min(ecartMinRangees, bas[0]!.y - Math.max(...haut.map((f) => f.y + f.h)))
  }

  return {
    personnes: d.fiches.length,
    croisements,
    chevauchements,
    contacts,
    sousUneCarte,
    traverseUnGroupe,
    dansUnGroupeEtranger,
    decalageTotal: Math.round(decalageTotal),
    decalageMax: Math.round(decalageMax),
    longueurTracee: Math.round(longueurTracee),
    ecartMinFiches: Number.isFinite(ecartMinFiches) ? Math.round(ecartMinFiches * 10) / 10 : 0,
    ecartMedianFiches: ecarts.length ? ecarts[ecarts.length >> 1]! : 0,
    ecartMinRangees: Number.isFinite(ecartMinRangees) ? Math.round(ecartMinRangees) : 0,
    couplesSepares,
    fratriesCoupees,
    fratrieSousParents: {
      total: Math.round(ecartsFratries.reduce((t, x) => t + x, 0)),
      max: ecartsFratries.length ? Math.round(ecartsFratries[ecartsFratries.length - 1]!) : 0,
      p50: centile(0.5),
      p90: centile(0.9),
      p99: centile(0.99),
      familles: ecartsFratries.length,
      centrees: ecartsFratries.filter((x) => x < 1).length,
      evitables,
      evitablesTotal: Math.round(evitablesTotal),
      evitablesSeules,
      evitablesSeulesTotal: Math.round(evitablesSeulesTotal),
      etalementTotal: Math.round(etalementTotal),
      etalees,
    },
    largeur: Math.round(d.largeur),
    hauteur: Math.round(d.hauteur),
    surface: Math.round((d.largeur * d.hauteur) / 1e5) / 10,
  }
}
