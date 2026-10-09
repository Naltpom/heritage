/**
 * **L'agencement d'UN groupe de `/arbre-2`** : l'ordre des fiches sur chaque rangée, puis
 * leur abscisse, à l'intérieur du rectangle du groupe.
 *
 * Les rangées sont DONNÉES (une génération = une rangée pour tout l'arbre, choix du
 * commanditaire du 09/10/2026) : on ne décide ici que de l'horizontale. Deux buts, dans
 * cet ordre :
 *
 *  1. **Le moins de croisements possible** — méthode de Sugiyama : on part d'un ordre
 *     en profondeur (les enfants sous leurs parents), puis on trie chaque rangée sur le
 *     barycentre de ses liens, en descendant puis en remontant, et on garde le meilleur
 *     ordre rencontré.
 *  2. **Les liens les plus courts possible** — à ordre fixé, chaque rangée cherche les
 *     abscisses qui minimisent Σ (x de la fiche − x visé)², sans se chevaucher : c'est
 *     une régression isotone (Pool Adjacent Violators), exacte sur une rangée, et
 *     alternée vers le bas et vers le haut.
 *
 * **Les ports.** Un lien qui sort du groupe (le grand-père de `pp` marié à la grand-mère
 * de `pm`, le père du tronc fils du couple de `pp`/`pm`) tire sa fiche vers le bord qui
 * fait face à l'autre groupe : on ne dessine ainsi qu'un lien court, à travers le
 * couloir qui sépare les deux rectangles, au lieu d'un trait qui traverse tout le groupe.
 *
 * **L'unité rangée est le foyer** : une personne et ses conjoints du même groupe, posés
 * côte à côte. Un foyer ne se coupe jamais.
 *
 * Pur, sans DOM : tout se vérifie dans `groupes.test.ts`.
 */
import { ECART_BLOC, ECART_FRATRIE } from '@/histoire/lignees'

/** Écart entre deux îlots d'un même groupe (deux familles par alliance, deux îlots sans lien). */
export const ECART_COMPOSANTES = 56

/** Ce que l'agencement d'un groupe a besoin de savoir du graphe. */
export interface EntreeAgencement {
  /** Les personnes du groupe, dans l'ordre du graphe. */
  membres: readonly string[]
  rangee: (id: string) => number
  largeur: (id: string) => number
  masculin: (id: string) => boolean
  annee: (id: string) => number | null
  /**
   * Pour une personne HORS du groupe : de quel côté est son groupe (−1 à gauche, +1 à
   * droite), ou 0 pour ne pas en tenir compte (familles par alliance, sans lien).
   */
  cote: (id: string) => -1 | 0 | 1
  famillesEpoux: (id: string) => readonly string[]
  famillesEnfant: (id: string) => readonly string[]
  epouxDe: (famille: string) => readonly string[]
  enfantsDe: (famille: string) => readonly string[]
  fratriesParNaissance: boolean
}

export interface Agencement {
  /** Le centre horizontal de chaque fiche, le bord gauche du groupe à 0. */
  centres: Map<string, number>
  /** La largeur occupée par les fiches. */
  largeur: number
}

/* ------------------------------------------------------------------ */
/* Outils                                                              */
/* ------------------------------------------------------------------ */

/**
 * La régression isotone pondérée : les `y` non décroissants les plus proches des cibles,
 * au sens des moindres carrés. Pool Adjacent Violators, linéaire.
 */
export function isotone(cibles: readonly number[], poids: readonly number[]): number[] {
  const moyennes: number[] = []
  const masses: number[] = []
  const tailles: number[] = []
  for (let i = 0; i < cibles.length; i++) {
    moyennes.push(cibles[i]!)
    masses.push(poids[i]!)
    tailles.push(1)
    while (moyennes.length > 1 && moyennes[moyennes.length - 2]! > moyennes[moyennes.length - 1]!) {
      const m2 = masses.pop()!
      const v2 = moyennes.pop()!
      const t2 = tailles.pop()!
      const k = moyennes.length - 1
      const m = masses[k]! + m2
      moyennes[k] = (moyennes[k]! * masses[k]! + v2 * m2) / m
      masses[k] = m
      tailles[k]! += t2
    }
  }
  const sortie: number[] = []
  for (let k = 0; k < moyennes.length; k++) for (let t = 0; t < tailles[k]!; t++) sortie.push(moyennes[k]!)
  return sortie
}

/**
 * Le nombre de croisements entre des liens d'une même bande (d'une rangée à la
 * suivante) : les paires dont l'ordre en haut et l'ordre en bas s'inversent. Deux liens
 * qui partent du même point (une fratrie) ne se croisent pas.
 */
export function compterCroisements(liens: { haut: number; bas: number }[]): number {
  if (liens.length < 2) return 0
  const tries = [...liens].sort((a, b) => a.haut - b.haut || a.bas - b.bas)
  const bas = tries.map((l) => l.bas)
  const tampon = new Array<number>(bas.length)
  // Tri fusion qui compte les inversions strictes.
  const fusion = (lo: number, hi: number): number => {
    if (hi - lo < 2) return 0
    const mi = (lo + hi) >> 1
    let n = fusion(lo, mi) + fusion(mi, hi)
    let i = lo
    let j = mi
    let k = lo
    while (i < mi && j < hi) {
      if (bas[i]! <= bas[j]!) tampon[k++] = bas[i++]!
      else {
        n += mi - i
        tampon[k++] = bas[j++]!
      }
    }
    while (i < mi) tampon[k++] = bas[i++]!
    while (j < hi) tampon[k++] = bas[j++]!
    for (let t = lo; t < hi; t++) bas[t] = tampon[t]!
    return n
  }
  return fusion(0, bas.length)
}

/* ------------------------------------------------------------------ */
/* Les foyers                                                          */
/* ------------------------------------------------------------------ */

interface Foyer {
  personnes: string[]
  rangee: number
  /** Bord gauche. */
  x: number
  w: number
  /** Centre de chaque personne, depuis le bord gauche du foyer. */
  decalage: Map<string, number>
  /** Vers quel bord il est tiré : −1 gauche, 0 aucun, +1 droite. */
  port: number
  /** Les familles dont un membre est enfant (pour l'écart des fratries). */
  fratries: Set<string>
  /** Rang d'origine, pour un tri stable. */
  rang: number
}

/* ------------------------------------------------------------------ */
/* L'agencement                                                        */
/* ------------------------------------------------------------------ */

const PASSES_ORDRE = 6
const PASSES_PLACEMENT = 8

export function agencer(e: EntreeAgencement): Agencement {
  const dedans = new Set(e.membres)
  const parents = (id: string): string[] => {
    const f = e.famillesEnfant(id)[0]
    return f === undefined ? [] : [...e.epouxDe(f)]
  }

  // Les îlots du groupe : ce que les liens INTERNES relient.
  const ilot = new Map<string, number>()
  const ilots: string[][] = []
  for (const depart of e.membres) {
    if (ilot.has(depart)) continue
    const n = ilots.length
    const liste: string[] = []
    ilots.push(liste)
    ilot.set(depart, n)
    const pile = [depart]
    while (pile.length) {
      const id = pile.pop()!
      liste.push(id)
      const voisins: string[] = []
      for (const f of e.famillesEpoux(id)) voisins.push(...e.epouxDe(f), ...e.enfantsDe(f))
      for (const f of e.famillesEnfant(id)) voisins.push(...e.epouxDe(f), ...e.enfantsDe(f))
      for (const v of voisins) {
        if (!dedans.has(v) || ilot.has(v)) continue
        ilot.set(v, n)
        pile.push(v)
      }
    }
  }
  const ordreDuGraphe = new Map(e.membres.map((id, i) => [id, i]))
  for (const l of ilots) l.sort((a, b) => ordreDuGraphe.get(a)! - ordreDuGraphe.get(b)!)

  /** Le port d'une personne : la somme des côtés de ses liens qui sortent du groupe. */
  const portDe = new Map<string, number>()
  for (const id of e.membres) {
    let p = 0
    for (const f of e.famillesEpoux(id)) {
      for (const c of e.epouxDe(f)) if (c !== id && !dedans.has(c)) p += 2 * e.cote(c)
      const enfants = e.enfantsDe(f)
      const dehors = enfants.filter((c) => !dedans.has(c))
      if (dehors.length) p += dehors.reduce((s, c) => s + e.cote(c), 0) / dehors.length
    }
    const ps = parents(id)
    if (ps.length && !ps.some((c) => dedans.has(c))) p += ps.reduce((s, c) => s + e.cote(c), 0) / ps.length
    portDe.set(id, p)
  }

  const resultats = ilots.map((l) => agencerUnIlot(l, e, dedans, portDe))
  // Les îlots côte à côte : ceux qui regardent à gauche d'abord, puis l'ordre du graphe.
  const ordre = resultats
    .map((r, i) => ({ r, i, port: r.port }))
    .sort((a, b) => Math.sign(a.port) - Math.sign(b.port) || a.i - b.i)
  const centres = new Map<string, number>()
  let x = 0
  for (const { r } of ordre) {
    for (const [id, c] of r.centres) centres.set(id, c + x)
    x += r.largeur + ECART_COMPOSANTES
  }
  return { centres, largeur: Math.max(0, x - ECART_COMPOSANTES) }
}

interface AgencementDIlot extends Agencement {
  port: number
}

function agencerUnIlot(
  membres: readonly string[],
  e: EntreeAgencement,
  dedans: ReadonlySet<string>,
  portDe: ReadonlyMap<string, number>,
): AgencementDIlot {
  /* Les foyers : les conjoints du groupe sur une même rangée, réunis. */
  const chef = new Map<string, string>(membres.map((id) => [id, id]))
  const trouver = (id: string): string => {
    let r = id
    while (chef.get(r) !== r) r = chef.get(r)!
    chef.set(id, r)
    return r
  }
  const conjointsDe = new Map<string, string[]>()
  for (const id of membres) {
    for (const f of e.famillesEpoux(id)) {
      for (const c of e.epouxDe(f)) {
        if (c === id || !dedans.has(c) || e.rangee(c) !== e.rangee(id)) continue
        const l = conjointsDe.get(id)
        if (l) {
          if (!l.includes(c)) l.push(c)
        } else conjointsDe.set(id, [c])
        chef.set(trouver(c), trouver(id))
      }
    }
  }
  const parChef = new Map<string, string[]>()
  for (const id of membres) {
    const r = trouver(id)
    const l = parChef.get(r)
    if (l) l.push(id)
    else parChef.set(r, [id])
  }

  const foyerDe = new Map<string, Foyer>()
  const foyers: Foyer[] = []
  for (const groupe of parChef.values()) {
    const personnes = ordonnerLeFoyer(groupe, conjointsDe, e, portDe)
    const decalage = new Map<string, number>()
    let w = 0
    personnes.forEach((id, i) => {
      if (i) w += ECART_FRATRIE
      decalage.set(id, w + e.largeur(id) / 2)
      w += e.largeur(id)
    })
    const fratries = new Set<string>()
    for (const id of personnes) {
      const f = e.famillesEnfant(id)[0]
      if (f !== undefined) fratries.add(f)
    }
    const port = Math.sign(personnes.reduce((s, id) => s + portDe.get(id)!, 0))
    const foyer: Foyer = { personnes, rangee: e.rangee(personnes[0]!), x: 0, w, decalage, port, fratries, rang: foyers.length }
    foyers.push(foyer)
    for (const id of personnes) foyerDe.set(id, foyer)
  }

  const centre = (id: string): number => {
    const f = foyerDe.get(id)!
    return f.x + f.decalage.get(id)!
  }
  const numeros = [...new Set(foyers.map((f) => f.rangee))].sort((a, b) => a - b)
  const rangees = new Map<number, Foyer[]>(numeros.map((g) => [g, []]))
  for (const f of foyers) rangees.get(f.rangee)!.push(f)

  /* Les cibles d'un foyer, en bord gauche : vers ses parents, vers ses enfants. */
  const versLeHaut = (f: Foyer): { cible: number; poids: number }[] => {
    const l: { cible: number; poids: number }[] = []
    for (const id of f.personnes) {
      const fam = e.famillesEnfant(id)[0]
      if (fam === undefined) continue
      const ps = e.epouxDe(fam).filter((p) => dedans.has(p) && foyerDe.has(p))
      if (!ps.length) continue
      const t = ps.reduce((s, p) => s + centre(p), 0) / ps.length
      l.push({ cible: t - f.decalage.get(id)!, poids: 1 })
    }
    return l
  }
  const versLeBas = (f: Foyer): { cible: number; poids: number }[] => {
    const l: { cible: number; poids: number }[] = []
    const vues = new Set<string>()
    for (const id of f.personnes) {
      for (const fam of e.famillesEpoux(id)) {
        if (vues.has(fam)) continue
        vues.add(fam)
        const enfants = e.enfantsDe(fam).filter((c) => dedans.has(c) && foyerDe.has(c))
        if (!enfants.length) continue
        const t = enfants.reduce((s, c) => s + centre(c), 0) / enfants.length
        const ep = e.epouxDe(fam).filter((p) => f.decalage.has(p))
        const m = ep.reduce((s, p) => s + f.decalage.get(p)!, 0) / ep.length
        // Une fratrie nombreuse tire plus fort, mais pas en proportion : racine.
        l.push({ cible: t - m, poids: Math.sqrt(enfants.length) })
      }
    }
    return l
  }

  /** L'écart avant le foyer `b` quand il suit `a` : une fratrie se serre. */
  const ecart = (a: Foyer, b: Foyer): number => {
    for (const fr of a.fratries) if (b.fratries.has(fr)) return ECART_FRATRIE
    return ECART_BLOC
  }

  /** Les abscisses d'une rangée, ordre fixé, au plus près des cibles. */
  function placer(rangee: Foyer[], cibles: Map<Foyer, { cible: number; poids: number }[]>): void {
    if (!rangee.length) return
    const decal: number[] = []
    let o = 0
    rangee.forEach((f, i) => {
      if (i) o += ecart(rangee[i - 1]!, f)
      decal.push(o)
      o += f.w
    })
    const y: number[] = []
    const p: number[] = []
    rangee.forEach((f, i) => {
      const c = cibles.get(f) ?? []
      if (c.length) {
        const poids = c.reduce((s, k) => s + k.poids, 0)
        y.push(c.reduce((s, k) => s + k.cible * k.poids, 0) / poids - decal[i]!)
        p.push(poids)
      } else {
        // Sans lien de ce côté : il reste où il est, sans peser sur les autres.
        y.push(f.x - decal[i]!)
        p.push(0.05)
      }
    })
    const r = isotone(y, p)
    rangee.forEach((f, i) => (f.x = r[i]! + decal[i]!))
  }

  /** Trie une rangée sur ses cibles : les ports aux bords, puis le barycentre. */
  function trier(rangee: Foyer[], cibles: Map<Foyer, { cible: number; poids: number }[]>): void {
    const cle = new Map<Foyer, number>()
    for (const f of rangee) {
      const c = cibles.get(f) ?? []
      const poids = c.reduce((s, k) => s + k.poids, 0)
      cle.set(f, poids ? c.reduce((s, k) => s + (k.cible + f.w / 2) * k.poids, 0) / poids : f.x + f.w / 2)
    }
    const naissance = (f: Foyer): number => {
      let a = Infinity
      for (const id of f.personnes) {
        if (!e.famillesEnfant(id).length) continue
        a = Math.min(a, e.annee(id) ?? Infinity)
      }
      return a
    }
    const position = new Map(rangee.map((f, i) => [f, i]))
    rangee.sort(
      (a, b) =>
        a.port - b.port ||
        cle.get(a)! - cle.get(b)! ||
        (e.fratriesParNaissance ? naissance(a) - naissance(b) || 0 : 0) ||
        position.get(a)! - position.get(b)!,
    )
  }

  const ciblesDe = (rangee: Foyer[], sens: 'haut' | 'bas' | 'deux'): Map<Foyer, { cible: number; poids: number }[]> =>
    new Map(rangee.map((f) => [f, sens === 'haut' ? versLeHaut(f) : sens === 'bas' ? versLeBas(f) : [...versLeHaut(f), ...versLeBas(f)]]))

  /* L'ordre de départ : chaque rangée sous ses parents, de haut en bas. */
  for (const g of numeros) {
    const rangee = rangees.get(g)!
    const cibles = ciblesDe(rangee, 'haut')
    // Les foyers sans parents posés ici gardent l'ordre du graphe, après les autres.
    let fin = 0
    for (const f of rangee) if (!cibles.get(f)!.length) f.x = 1e6 + fin++
    trier(rangee, cibles)
    let x = 0
    rangee.forEach((f, i) => {
      if (i) x += ecart(rangee[i - 1]!, f)
      f.x = x
      x += f.w
    })
    placer(rangee, cibles)
  }

  /*
   * Le nombre de croisements de l'îlot, bande par bande. Les liens « couple → enfant »
   * se relèvent UNE fois (ils ne dépendent que des liens) ; chaque mesure ne relit que
   * les abscisses — c'était le premier poste de l'agencement, refait à chaque passe.
   */
  const bandes = new Map<number, { epoux: string[]; enfants: string[] }[]>()
  {
    const vues = new Set<string>()
    for (const f of foyers) {
      for (const id of f.personnes) {
        for (const fam of e.famillesEpoux(id)) {
          if (vues.has(fam)) continue
          vues.add(fam)
          const epoux = e.epouxDe(fam).filter((p) => foyerDe.has(p))
          if (!epoux.length) continue
          const g = Math.max(...epoux.map((p) => e.rangee(p)))
          const enfants = e.enfantsDe(fam).filter((c) => foyerDe.has(c) && e.rangee(c) === g + 1)
          if (!enfants.length) continue
          const l = bandes.get(g)
          if (l) l.push({ epoux, enfants })
          else bandes.set(g, [{ epoux, enfants }])
        }
      }
    }
  }
  const croisements = (): number => {
    let n = 0
    for (const liste of bandes.values()) {
      const liens: { haut: number; bas: number }[] = []
      for (const { epoux, enfants } of liste) {
        let haut = 0
        for (const p of epoux) haut += centre(p)
        haut /= epoux.length
        for (const c of enfants) liens.push({ haut, bas: centre(c) })
      }
      n += compterCroisements(liens)
    }
    return n
  }

  /*
   * L'ÉCHANGE DE VOISINS (la « transposition » de Sugiyama) : le barycentre range bien en
   * gros, mais laisse des paires voisines croisées qu'un simple échange défait. On échange
   * deux foyers voisins de même port quand cela retire strictement des croisements — avec
   * leurs parents au-dessus et leurs enfants au-dessous —, jusqu'à ce que plus rien ne
   * s'améliore.
   */
  const extremites = (f: Foyer): { haut: number[]; bas: number[] } => {
    const haut: number[] = []
    const bas: number[] = []
    const vues = new Set<string>()
    for (const id of f.personnes) {
      const fam = e.famillesEnfant(id)[0]
      if (fam !== undefined) {
        const ps = e.epouxDe(fam).filter((p) => foyerDe.has(p) && e.rangee(p) < f.rangee)
        if (ps.length) haut.push(ps.reduce((s, p) => s + centre(p), 0) / ps.length)
      }
      for (const fa of e.famillesEpoux(id)) {
        if (vues.has(fa)) continue
        vues.add(fa)
        for (const c of e.enfantsDe(fa)) if (foyerDe.has(c) && e.rangee(c) > f.rangee) bas.push(centre(c))
      }
    }
    return { haut, bas }
  }
  /** Les paires (x de `a`, y de `b`) où `a`, posé à gauche, rejoint plus à droite que `b`. */
  const inversions = (a: number[], b: number[]): number => {
    let n = 0
    for (const x of a) for (const y of b) if (x > y) n++
    return n
  }
  const ECHANGES_MAX = 300
  function transposer(): void {
    for (const g of numeros) {
      const rangee = rangees.get(g)!
      if (rangee.length < 2 || rangee.length > ECHANGES_MAX) continue
      const ext = rangee.map(extremites)
      let bouge = false
      for (let tour = 0, change = true; change && tour < 4; tour++) {
        change = false
        for (let i = 0; i + 1 < rangee.length; i++) {
          if (rangee[i]!.port !== rangee[i + 1]!.port) continue
          const [ea, eb] = [ext[i]!, ext[i + 1]!]
          const avant = inversions(ea.haut, eb.haut) + inversions(ea.bas, eb.bas)
          const apres = inversions(eb.haut, ea.haut) + inversions(eb.bas, ea.bas)
          if (apres >= avant) continue
          ;[rangee[i], rangee[i + 1]] = [rangee[i + 1]!, rangee[i]!]
          ;[ext[i], ext[i + 1]] = [eb, ea]
          change = bouge = true
        }
      }
      if (!bouge) continue
      // L'ordre a changé : les abscisses se refont, sans chevauchement, au plus près.
      let x = rangee[0]!.x
      rangee.forEach((f, i) => {
        if (i) x += ecart(rangee[i - 1]!, f)
        f.x = x
        x += f.w
      })
      placer(rangee, ciblesDe(rangee, 'deux'))
    }
  }

  /* L'ordre : des allers-retours de barycentres, on garde le meilleur. */
  const instantane = (): Map<number, Foyer[]> => new Map([...rangees].map(([g, l]) => [g, [...l]]))
  let meilleur = instantane()
  let moins = croisements()
  for (let passe = 0; passe < PASSES_ORDRE && moins > 0; passe++) {
    for (const g of numeros) {
      const rangee = rangees.get(g)!
      const cibles = ciblesDe(rangee, 'haut')
      trier(rangee, cibles)
      placer(rangee, cibles)
    }
    for (const g of [...numeros].reverse()) {
      const rangee = rangees.get(g)!
      const cibles = ciblesDe(rangee, 'bas')
      trier(rangee, cibles)
      placer(rangee, cibles)
    }
    transposer()
    const n = croisements()
    if (n < moins) {
      moins = n
      meilleur = instantane()
    }
  }
  for (const [g, l] of meilleur) rangees.set(g, l)

  /* Les abscisses : chaque rangée au plus près de ses parents ET de ses enfants. */
  for (const g of numeros) {
    const rangee = rangees.get(g)!
    let x = rangee[0] ? rangee[0].x : 0
    rangee.forEach((f, i) => {
      if (i) x = Math.max(f.x, x + ecart(rangee[i - 1]!, f))
      else x = f.x
      f.x = x
      x += f.w
    })
  }
  for (let passe = 0; passe < PASSES_PLACEMENT; passe++) {
    const sens = passe % 2 ? [...numeros].reverse() : numeros
    for (const g of sens) {
      const rangee = rangees.get(g)!
      placer(rangee, ciblesDe(rangee, 'deux'))
    }
  }

  /* Le bord gauche à 0. */
  let x0 = Infinity
  let x1 = -Infinity
  for (const f of foyers) {
    x0 = Math.min(x0, f.x)
    x1 = Math.max(x1, f.x + f.w)
  }
  if (!foyers.length) x0 = x1 = 0
  const centres = new Map<string, number>()
  for (const f of foyers) for (const id of f.personnes) centres.set(id, f.x - x0 + f.decalage.get(id)!)
  const port = foyers.reduce((s, f) => s + f.port, 0)
  return { centres, largeur: x1 - x0, port }
}

/**
 * L'ordre d'un foyer, de gauche à droite : la personne aux plus nombreuses unions au
 * milieu, chaque conjoint du côté le plus proche de son partenaire ; puis on retourne le
 * foyer si ses ports le demandent (le conjoint dont la famille est à droite, à droite).
 * Sans port, l'homme à gauche.
 */
function ordonnerLeFoyer(
  groupe: readonly string[],
  conjointsDe: ReadonlyMap<string, readonly string[]>,
  e: EntreeAgencement,
  portDe: ReadonlyMap<string, number>,
): string[] {
  if (groupe.length === 1) return [...groupe]
  const degre = (id: string): number => conjointsDe.get(id)?.length ?? 0
  const pivot = [...groupe].sort((a, b) => degre(b) - degre(a))[0]!
  const ordre = [pivot]
  const vus = new Set([pivot])
  const file = [pivot]
  while (file.length) {
    const id = file.shift()!
    for (const c of conjointsDe.get(id) ?? []) {
      if (vus.has(c)) continue
      vus.add(c)
      file.push(c)
      const i = ordre.indexOf(id)
      if (ordre.length === 1) {
        if (e.masculin(id)) ordre.push(c)
        else ordre.unshift(c)
      } else if (i <= (ordre.length - 1) / 2) ordre.unshift(c)
      else ordre.push(c)
    }
  }
  // Ce que le parcours n'a pas atteint (aucun cas connu) : au bout.
  for (const id of groupe) if (!vus.has(id)) ordre.push(id)
  const tirage = ordre.reduce((s, id, i) => s + portDe.get(id)! * (i - (ordre.length - 1) / 2), 0)
  if (tirage < 0) ordre.reverse()
  return ordre
}
