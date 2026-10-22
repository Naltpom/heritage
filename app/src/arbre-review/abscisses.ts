/**
 * **Les abscisses de `/arbre-review`** : l'ordre de chaque rangée étant fixé, où poser
 * chaque élément pour que les liens soient courts et droits, sans que rien se tasse.
 *
 * Un problème d'optimisation sous contraintes d'espacement :
 *
 *  - **les ressorts** — chaque enfant voudrait être à sa place idéale sous le point
 *    d'union de ses parents (la fratrie centrée sous le couple, serrée à l'écart des
 *    fratries) ; deux époux de foyers différents (un couple à cheval sur deux rectangles)
 *    voudraient être côte à côte ; la chaîne des factices d'un lien qui saute une
 *    génération voudrait être droite. On minimise Σ poids × (écart à la place idéale)².
 *    Une fratrie d'un seul enfant pèse double : son lien doit tomber droit.
 *  - **les contraintes** — sur une rangée, deux éléments voisins gardent leur écart :
 *    celui d'une fratrie, celui de deux familles (plus grand : on voit où une famille
 *    finit), celui de deux rectangles (le plus grand). Et deux rectangles qui partagent une
 *    rangée ne se recouvrent jamais, sur aucune de leurs rangées.
 *
 * La résolution, par blocs : chaque rangée d'un rectangle, à tour de rôle, prend les
 * abscisses EXACTES qui minimisent ses ressorts sous ses contraintes (une régression
 * isotone pondérée, Pool Adjacent Violators — linéaire), les autres étant tenues ; on
 * descend, on remonte, plusieurs fois (descente par blocs : elle converge vers l'optimum,
 * les contraintes d'une rangée ne touchant qu'elle). Puis les rectangles, rigides, se
 * placent les uns par rapport aux autres : chacun là où ses ressorts vers les autres le
 * tirent, sans en chevaucher aucun. On recommence quelques tours.
 *
 * Pur, sans DOM.
 */
import { isotone } from '@/arbre2/agencement'
import type { Couches, Element } from './ordre'
import { partiesVoisines, type Structure } from './preparation'

export interface OptionsDAbscisses {
  ecartCouple: number
  ecartFratrie: number
  ecartFamille: number
  ecartFactice: number
  /** L'écart minimal entre les fiches de deux rectangles voisins (marges des rectangles comprises). */
  ecartParties: number
  tours: number
  passes: number
}

export interface Abscisses {
  /** Le bord gauche de chaque élément, dans le monde (avant la marge de la page). */
  x: Float64Array
  /** Le centre de chaque personne. */
  cx: Float64Array
  /** Le centre de chaque membre d'un foyer, depuis le bord gauche de l'élément (retournement compris). */
  decalage: (s: Structure, c: Couches, i: number) => number
}

/**
 * Ce que pèse le lien entre deux époux de rectangles différents (les grands-parents
 * paternels, l'un dans pp, l'autre dans pm). Faible, exprès : chacun reste sous ses
 * propres parents — l'arbre de pedigree classique —, et leur union court sur une rangée
 * où rien ne se met entre eux (l'ordre y veille). Un lien fort les collait à la frontière,
 * au prix d'un bus de 20 000 px entre chacun et ses parents, au travers de tout le bas de
 * son rectangle.
 */
const POIDS_UNION_EXTERNE = 0.3

/**
 * Ce que pèse le lien d'un enfant posé dans un AUTRE GROUPE que ses parents : le père (dans
 * le tronc) et ses parents (dans pp et pm), le conjoint (dans le tronc) et les siens (dans les
 * quarts du conjoint). Ce lien file d'un rectangle à l'autre, long de toute façon ; à plein
 * poids, il tirait l'enfant hors de sa famille : l'ego, happé par les parents de son
 * conjoint, s'éloignait des siens de toute la largeur du tronc (relevé sur un arbre
 * synthétique). Allégé, chaque rectangle garde ses propres liens courts, et le rectangle
 * entier se place, lui, entre ceux qui le tirent.
 */
const POIDS_ENTRE_GROUPES = 0.25

interface Ressort {
  a: number
  oa: number
  b: number
  ob: number
  /** On veut (x_b + ob) − (x_a + oa) = delta. */
  delta: number
  w: number
}

/** Le centre du membre `i` dans son élément, le foyer étant peut-être retourné. */
export function decalageDansElement(s: Structure, c: Couches, i: number): number {
  const fo = s.foyers[s.foyerDe[i]!]!
  const k = s.rangDansFoyer[i]!
  return c.retourne[fo.id] ? fo.largeur - fo.decal[k]! : fo.decal[k]!
}

export function placer(s: Structure, c: Couches, o: OptionsDAbscisses): Abscisses {
  const { elements } = c
  const nE = elements.length
  const elementDe = (i: number): number => c.elementDuFoyer[s.foyerDe[i]!]!
  const dec = (i: number): number => decalageDansElement(s, c, i)

  /* La rangée et le rang de chaque élément. */
  const rangDe = new Int32Array(nE)
  const ligneDe = new Int32Array(nE)
  c.ordre.forEach((l, k) => l.forEach((e, i) => ((rangDe[e] = i), (ligneDe[e] = k))))

  /* Les familles des parents de chaque élément : deux voisins qui en partagent une sont une fratrie. */
  const parentsDe: number[][] = elements.map((e) => {
    if (e.foyer < 0) return [e.famille]
    const l: number[] = []
    for (const m of s.foyers[e.foyer]!.membres) for (const f of s.enfantDans[m]!) if (!l.includes(f)) l.push(f)
    return l
  })
  const ecartEntre = (a: Element, b: Element): number => {
    if (a.partie !== b.partie) return o.ecartParties
    if (a.foyer < 0 || b.foyer < 0) return o.ecartFactice
    const pa = parentsDe[a.id]!
    for (const f of parentsDe[b.id]!) if (pa.includes(f)) return o.ecartFratrie
    return o.ecartFamille
  }

  /* Les ressorts. */
  const ressorts: Ressort[] = []
  /** Le point d'union d'une famille dont les époux sont dans un même élément. */
  const unionDans = (f: number): { e: number; o: number } | null => {
    const ep = s.familles[f]!.epoux
    if (!ep.length) return null
    const e = elementDe(ep[0]!)
    if (!ep.every((x) => elementDe(x) === e)) return null
    return { e, o: ep.reduce((t, x) => t + dec(x), 0) / ep.length }
  }
  /**
   * Deux parties « voisines » : la même, ou deux colonnes qui se touchent sur une rangée
   * commune — le couple des grands-parents paternels, l'un dans pp, l'autre dans pm. Un
   * couple de parties plus lointaines est un IMPLEXE (deux cousins de quarts différents) :
   * leur union ne doit pas tirer l'un vers l'autre deux régions de l'arbre, et chaque enfant
   * suit le parent de sa propre partie — sinon il flottait au milieu, à mi-chemin des deux
   * (mesuré sur un arbre synthétique de 10 000 personnes : un tronc étiré sur 100 000 px).
   */
  const partieDeLElement = (e: number): number => elements[e]!.partie
  s.familles.forEach((fam, f) => {
    if (!fam.epoux.length) return
    const rf = Math.max(...fam.epoux.map((e) => s.rangee[e]!))
    const enfants = fam.enfants.filter((x) => s.rangee[x]! > rf)
    const voisines = fam.epoux.every((u) => fam.epoux.every((v) => partiesVoisines(s, s.partieDe[u]!, s.partieDe[v]!)))
    // Les époux de deux éléments (un couple entre deux rectangles voisins) : côte à côte.
    if (fam.epoux.length >= 2 && voisines) {
      const [a, b] = [...fam.epoux].sort((x, y) => ligneDe[elementDe(x)]! - ligneDe[elementDe(y)]! || rangDe[elementDe(x)]! - rangDe[elementDe(y)]!)
      const ea = elementDe(a!)
      const eb = elementDe(b!)
      if (ea !== eb) {
        const memeRangee = s.rangee[a!] === s.rangee[b!]
        const wa = s.personnes[a!]!.w || 132
        const wb = s.personnes[b!]!.w || 132
        ressorts.push({ a: ea, oa: dec(a!), b: eb, ob: dec(b!), delta: memeRangee ? wa / 2 + o.ecartCouple + wb / 2 : 0, w: POIDS_UNION_EXTERNE })
      }
    }
    if (!enfants.length) return
    // La place idéale de chaque enfant : la fratrie serrée, centrée sous l'union.
    const tri = [...enfants].sort((x, y) => {
      const ex = c.factices.get(`${f}|${x}`)?.[0] ?? elementDe(x)
      const ey = c.factices.get(`${f}|${y}`)?.[0] ?? elementDe(y)
      return rangDe[ex]! - rangDe[ey]! || dec(x) - dec(y)
    })
    const centres: number[] = []
    let p = 0
    let precedent = -1
    for (const x of tri) {
      const chaine = c.factices.get(`${f}|${x}`)
      const e = chaine ? chaine[0]! : elementDe(x)
      if (e !== precedent) {
        if (precedent >= 0) p += elements[precedent]!.largeur + o.ecartFratrie
        precedent = e
      }
      centres.push(p + (chaine ? elements[e]!.largeur / 2 : dec(x)))
    }
    const milieu = (centres[0]! + centres[centres.length - 1]!) / 2
    const poidsDeLaFratrie = tri.length === 1 ? 2 : 1
    const union = unionDans(f)
    const groupesDesParents = new Set(fam.epoux.map((u) => s.parties[s.partieDe[u]!]!.groupe))
    tri.forEach((x, j) => {
      const ideal = centres[j]! - milieu
      const chaine = c.factices.get(`${f}|${x}`)
      const cible = chaine ? { e: chaine[0]!, o: elements[chaine[0]!]!.largeur / 2 } : { e: elementDe(x), o: dec(x) }
      const poids = groupesDesParents.has(s.parties[s.partieDe[x]!]!.groupe) ? poidsDeLaFratrie : poidsDeLaFratrie * POIDS_ENTRE_GROUPES
      if (union) ressorts.push({ a: union.e, oa: union.o, b: cible.e, ob: cible.o, delta: ideal, w: poids })
      else if (!voisines) {
        // Un implexe : l'enfant suit le parent de sa partie (ou de la plus proche).
        const pc = partieDeLElement(cible.e)
        const parent = [...fam.epoux].sort(
          (u, v) =>
            Math.abs(s.parties[partieDeLElement(elementDe(u))]!.colonne - s.parties[pc]!.colonne) -
              Math.abs(s.parties[partieDeLElement(elementDe(v))]!.colonne - s.parties[pc]!.colonne) || u - v,
        )[0]!
        ressorts.push({ a: elementDe(parent), oa: dec(parent), b: cible.e, ob: cible.o, delta: ideal, w: poids })
      } else {
        // Union entre deux éléments : chaque époux tire l'enfant, chacun pour sa moitié.
        const ep = [...fam.epoux].sort((u, v) => rangDe[elementDe(u)]! - rangDe[elementDe(v)]!)
        const demi = ep.length > 1 ? (s.personnes[ep[0]!]!.w / 2 + o.ecartCouple / 2) : 0
        ep.forEach((u, k) => {
          const decale = ep.length > 1 ? (k === 0 ? demi : -(s.personnes[ep[ep.length - 1]!]!.w / 2 + o.ecartCouple / 2)) : 0
          ressorts.push({ a: elementDe(u), oa: dec(u), b: cible.e, ob: cible.o, delta: ideal + decale, w: poids / ep.length })
        })
      }
      if (chaine) {
        for (let i = 0; i + 1 < chaine.length; i++)
          ressorts.push({ a: chaine[i]!, oa: elements[chaine[i]!]!.largeur / 2, b: chaine[i + 1]!, ob: elements[chaine[i + 1]!]!.largeur / 2, delta: 0, w: 2 })
        const der = chaine[chaine.length - 1]!
        ressorts.push({ a: der, oa: elements[der]!.largeur / 2, b: elementDe(x), ob: dec(x), delta: 0, w: 2 })
      }
    })
  })

  /* Les ressorts de chaque élément, dans les deux sens. */
  const depuis: { r: Ressort; cote: 'a' | 'b' }[][] = elements.map(() => [])
  for (const r of ressorts) {
    depuis[r.a]!.push({ r, cote: 'a' })
    depuis[r.b]!.push({ r, cote: 'b' })
  }

  /* Les segments (rangée × partie), dans l'ordre. */
  const segments: number[][] = []
  const segmentsDeLaRangee: number[][] = c.ordre.map(() => [])
  c.ordre.forEach((l, k) => {
    let courant: number[] = []
    for (const e of l) {
      if (courant.length && elements[courant[0]!]!.partie !== elements[e]!.partie) {
        segmentsDeLaRangee[k]!.push(segments.length)
        segments.push(courant)
        courant = []
      }
      courant.push(e)
    }
    if (courant.length) {
      segmentsDeLaRangee[k]!.push(segments.length)
      segments.push(courant)
    }
  })

  /* Les positions : un décalage par partie, plus une position interne par élément. */
  const nP = s.parties.length
  const X = new Float64Array(nP)
  const xi = new Float64Array(nE)
  // Départ : chaque segment serré, centré sur 0 dans sa partie.
  for (const seg of segments) {
    let t = 0
    const pos: number[] = []
    seg.forEach((e, i) => {
      if (i) t += ecartEntre(elements[seg[i - 1]!]!, elements[e]!)
      pos.push(t)
      t += elements[e]!.largeur
    })
    seg.forEach((e, i) => (xi[e] = pos[i]! - t / 2))
  }
  const gx = (e: number): number => X[elements[e]!.partie]! + xi[e]!

  /** La rangée de segment `seg` : les abscisses exactes, les autres tenues. */
  const resoudre = (seg: number[]): void => {
    const n = seg.length
    const p = elements[seg[0]!]!.partie
    const S: number[] = new Array(n)
    const cibles: number[] = new Array(n)
    const poids: number[] = new Array(n)
    let cumul = 0
    for (let i = 0; i < n; i++) {
      const e = seg[i]!
      if (i) cumul += elements[seg[i - 1]!]!.largeur + ecartEntre(elements[seg[i - 1]!]!, elements[e]!)
      S[i] = cumul
      let tw = 0
      let tv = 0
      for (const { r, cote } of depuis[e]!) {
        // La position voulue du bord gauche de `e`, d'après l'autre bout du ressort.
        const v = cote === 'a' ? gx(r.b) + r.ob - r.delta - r.oa : gx(r.a) + r.oa + r.delta - r.ob
        tw += r.w
        tv += r.w * v
      }
      if (tw > 0) {
        cibles[i] = tv / tw - X[p]! - S[i]!
        poids[i] = tw
      } else {
        cibles[i] = xi[e]! - S[i]!
        poids[i] = 1e-3
      }
    }
    const y = isotone(cibles, poids)
    for (let i = 0; i < n; i++) xi[seg[i]!] = y[i]! + S[i]!
  }

  /* Les boîtes des parties (en interne), et leurs paires qui partagent une rangée. */
  const boite = (p: number): [number, number] => {
    let a = Infinity
    let b = -Infinity
    for (const m of s.parties[p]!.membres) {
      const e = elementDe(m)
      a = Math.min(a, xi[e]!)
      b = Math.max(b, xi[e]! + elements[e]!.largeur)
    }
    // Les factices de la partie aussi.
    return [a, b]
  }
  const factivesDeLaPartie: number[][] = s.parties.map(() => [])
  for (const e of elements) if (e.foyer < 0) factivesDeLaPartie[e.partie]!.push(e.id)
  const boiteComplete = (p: number): [number, number] => {
    let [a, b] = boite(p)
    for (const e of factivesDeLaPartie[p]!) {
      a = Math.min(a, xi[e]!)
      b = Math.max(b, xi[e]! + elements[e]!.largeur)
    }
    return [a, b]
  }
  const paires: [number, number][] = []
  for (let p = 0; p < nP; p++)
    for (let q = 0; q < nP; q++) {
      const P = s.parties[p]!
      const Q = s.parties[q]!
      if (p === q || P.colonne > Q.colonne || (P.colonne === Q.colonne && p > q)) continue
      if (P.max < Q.min || Q.max < P.min) continue
      paires.push([p, q])
    }

  /* Les ressorts entre deux parties : ce qui place les parties les unes par rapport aux autres. */
  const ressortsExternes = ressorts.filter((r) => elements[r.a]!.partie !== elements[r.b]!.partie)
  const parColonne = (l: number[]): number[] => l.sort((a, b) => s.parties[a]!.colonne - s.parties[b]!.colonne || s.parties[a]!.min - s.parties[b]!.min || a - b)
  const hautes = parColonne(s.parties.map((_, p) => p).filter((p) => s.parties[p]!.bande === 'haut'))
  const autres = parColonne(s.parties.map((_, p) => p).filter((p) => s.parties[p]!.bande !== 'haut'))
  const partagent = (p: number, q: number): boolean => !(s.parties[p]!.max < s.parties[q]!.min || s.parties[q]!.max < s.parties[p]!.min)

  /**
   * Les parties, rigides, les unes par rapport aux autres :
   *  1. les ASCENDANCES (le haut des quarts) se serrent de gauche à droite : un écart de
   *     plus ne ferait qu'allonger l'union des têtes de deux quarts voisins ;
   *  2. les autres (les descendances, le tronc) se posent sous leurs parents — là où leurs
   *     ressorts les tirent —, de gauche à droite sans se chevaucher : une régression
   *     isotone sur les parties, exacte ;
   *  3. un dernier passage, strict, écarte toute paire qui partage une rangée et se
   *     chevaucherait encore.
   */
  const placerLesParties = (): void => {
    const boites = s.parties.map((_, p) => boiteComplete(p))
    // 1. Les ascendances, serrées (la première ne bouge pas : pas de dérive d'un tour à l'autre).
    for (let i = 1; i < hautes.length; i++) {
      const p = hautes[i - 1]!
      const q = hautes[i]!
      X[q] = X[p]! + boites[p]![1] + o.ecartParties - boites[q]![0]
    }
    for (let tour = 0; tour < 3; tour++) {
      // 2. Les autres, sous leurs parents.
      if (autres.length) {
        const cibles: number[] = []
        const poids: number[] = []
        const S: number[] = []
        let cumul = 0
        autres.forEach((p, i) => {
          if (i) {
            const prec = autres[i - 1]!
            // Deux parties sans rangée commune ne se contraignent pas : un écart « infiniment négatif ».
            cumul += partagent(prec, p) ? boites[prec]![1] - boites[prec]![0] + o.ecartParties : -1e7
          }
          S.push(cumul)
          let tw = 0
          let tv = 0
          for (const r of ressortsExternes) {
            const pa = elements[r.a]!.partie
            const pb = elements[r.b]!.partie
            if (pa !== p && pb !== p) continue
            if (pa === p) tv += r.w * (X[pb]! + xi[r.b]! + r.ob - r.delta - r.oa - xi[r.a]!)
            else tv += r.w * (X[pa]! + xi[r.a]! + r.oa + r.delta - r.ob - xi[r.b]!)
            tw += r.w
          }
          // La cible du bord gauche de la partie.
          const cible = tw > 0 ? tv / tw : X[p]!
          cibles.push(cible + boites[p]![0] - S[i]!)
          poids.push(tw > 0 ? tw : 1e-3)
        })
        const y = isotone(cibles, poids)
        autres.forEach((p, i) => (X[p] = y[i]! + S[i]! - boites[p]![0]))
      }
      // 3. Strict : aucune paire qui partage une rangée ne se chevauche.
      for (let k = 0; k < 3; k++)
        for (const [p, q] of paires) {
          const besoin = X[p]! + boites[p]![1] + o.ecartParties - (X[q]! + boites[q]![0])
          if (besoin > 0) X[q] = X[q]! + besoin
        }
    }
  }

  // Les parties côte à côte au départ, puis les tours.
  placerLesParties()
  for (let tour = 0; tour < o.tours; tour++) {
    for (let passe = 0; passe < o.passes; passe++) {
      const lignes = passe % 2 ? [...segmentsDeLaRangee].reverse() : segmentsDeLaRangee
      for (const segs of lignes) for (const k of segs) resoudre(segments[k]!)
    }
    placerLesParties()
  }

  const x = new Float64Array(nE)
  for (let e = 0; e < nE; e++) x[e] = gx(e)
  const cx = new Float64Array(s.personnes.length)
  for (let i = 0; i < s.personnes.length; i++) cx[i] = x[elementDe(i)]! + dec(i)
  return { x, cx, decalage: decalageDansElement }
}
