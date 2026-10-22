/**
 * **Les abscisses de `/arbre-review`** : l'ordre de chaque rangée étant fixé, où poser
 * chaque élément pour que les liens soient courts et droits, sans que rien se tasse.
 *
 * Un problème d'optimisation sous contraintes d'espacement :
 *
 *  - **les ressorts** — chaque enfant voudrait être à sa place idéale sous le point
 *    d'union de ses parents (la fratrie, ses conjoints compris, serrée à l'écart des
 *    fratries et centrée sous le couple) ; deux époux de foyers différents (un couple à
 *    cheval sur deux rectangles) voudraient être côte à côte ; la chaîne des factices d'un
 *    lien qui saute une génération voudrait être droite. On minimise Σ poids × (écart à la
 *    place idéale)². Une fratrie d'un seul enfant pèse double : son lien doit tomber droit.
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
 * Enfin l'ALIGNEMENT, de haut en bas : les moindres carrés laissent presque chaque fratrie un
 * peu de travers (tirée par ses propres enfants, par la famille d'un conjoint) ; chacune glisse
 * alors sous ses parents, aussi loin que la place libre le permet (`aligner`).
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
  /** L'alignement final des fratries sous leurs parents, de haut en bas (`aligner`). */
  aligner: boolean
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

/*
 * Essayés pour recentrer les fratries, et écartés après mesure sur l'arbre réel (22/10/2026) :
 * la somme des écarts plutôt que celle de leurs carrés (l'arbre plus large, les fratries plus
 * loin de leurs parents : la descente par blocs s'y arrête en chemin) ; des parents qui ne
 * suivent pas leurs enfants (l'ascendance redressée, mais l'ensemble des fratries éloigné de
 * 10 %) ; un même poids par famille, partagé entre ses enfants (plus de croisements, sans
 * mieux centrer). L'alignement final (`aligner`) fait mieux, sans ces défauts.
 */

/** De combien l'alignement final peut élargir un rectangle sur l'écart qui le sépare de son voisin. */
const DEBORD = 60

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
  /** Chaque fratrie : sa famille, et ses éléments de la rangée sous les parents (pour l'alignement final). */
  const fratries: { famille: number; elements: number[] }[] = []
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
    {
      const els: number[] = []
      for (const x of tri) {
        const e = c.factices.get(`${f}|${x}`)?.[0] ?? elementDe(x)
        if (ligneDe[e] === ligneDe[elementDe(fam.epoux.find((u) => s.rangee[u] === rf)!)]! + 1 && !els.includes(e)) els.push(e)
      }
      if (els.length) fratries.push({ famille: f, elements: els })
    }
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
    // Le milieu de la fratrie AVEC ses conjoints : de bord à bord de ses foyers (demande du
    // 22/10/2026 — Jérôme et sa femme, centrés ensemble sous les parents de Jérôme).
    const milieu = (p + (precedent >= 0 ? elements[precedent]!.largeur : 0)) / 2
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
  const autres = s.parties
    .map((_, p) => p)
    .sort((a, b) => s.parties[a]!.colonne - s.parties[b]!.colonne || s.parties[a]!.min - s.parties[b]!.min || a - b)
  const partagent = (p: number, q: number): boolean => !(s.parties[p]!.max < s.parties[q]!.min || s.parties[q]!.max < s.parties[p]!.min)

  /**
   * Les parties, rigides, les unes par rapport aux autres :
   *  1. chacune se pose là où ses ressorts vers les autres la tirent, de gauche à droite
   *     sans se chevaucher : une régression isotone sur les parties, exacte ;
   *  2. un dernier passage, strict, écarte toute paire qui partage une rangée et se
   *     chevaucherait encore.
   */
  const placerLesParties = (): void => {
    const boites = s.parties.map((_, p) => boiteComplete(p))
    for (let tour = 0; tour < 3; tour++) {
      // 1. Toutes, là où leurs liens les tirent.
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
            // La position voulue de la partie, d'après ce ressort.
            const v = pa === p ? X[pb]! + xi[r.b]! + r.ob - r.delta - r.oa - xi[r.a]! : X[pa]! + xi[r.a]! + r.oa + r.delta - r.ob - xi[r.b]!
            tv += r.w * v
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
      // 2. Strict : aucune paire qui partage une rangée ne se chevauche.
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
  // Deux fois : le passage strict de la fin peut écarter des rectangles, et libérer ainsi de la
  // place qu'une fratrie déjà traitée n'avait pas.
  if (o.aligner) for (let fois = 0; fois < 2; fois++) aligner()

  /**
   * L'ALIGNEMENT DES FRATRIES, de haut en bas (demande du 22/10/2026 : « pourquoi les lignes
   * sont si grandes alors que dessous des parents il y a la place juste dessous pour les
   * enfants et même conjoint »). Les moindres carrés partagent chaque conflit entre tous les
   * liens qui y prennent part : une fratrie un peu tirée par ses propres enfants, un couple
   * tiré par ceux de son conjoint, et presque plus aucune fratrie n'est droite sous ses
   * parents (5 % sur l'arbre réel). Ici, rangée après rangée, chaque fratrie — conjoints
   * compris, d'un bloc — glisse sous ses parents, aussi loin que la place libre le permet
   * (ses voisines ne bougent pas). Une fratrie qui partage un foyer avec une autre (un couple
   * dont chaque époux a ses parents posés : le couple de la génération suivante, dans
   * l'ascendance) glisse avec elle, d'un même bloc, vers le milieu de leurs deux couples de
   * parents : le couple se pose ENTRE eux. Leurs enfants suivent, à la rangée d'après.
   */
  function aligner(): void {
    const parLigne = new Map<number, typeof fratries>()
    for (const fr of fratries) {
      const k = ligneDe[fr.elements[0]!]!
      const l = parLigne.get(k)
      if (l) l.push(fr)
      else parLigne.set(k, [fr])
    }
    const largeur = (e: number): number => elements[e]!.largeur
    // Les cadres des rectangles (en interne), tenus à jour, et leurs voisins de rangée.
    const cadres = s.parties.map((_, p) => boiteComplete(p))
    const voisinsGauche: number[][] = s.parties.map(() => [])
    const voisinsDroite: number[][] = s.parties.map(() => [])
    for (const [p, q] of paires) {
      voisinsDroite[p]!.push(q)
      voisinsGauche[q]!.push(p)
    }
    for (let k = 0; k < c.ordre.length; k++) {
      const liste = parLigne.get(k)
      if (!liste?.length) continue
      const ligne = c.ordre[k]!
      // Les blocs : des fratries qui partagent un élément glissent ensemble.
      const chef = liste.map((_, i) => i)
      const trouver = (i: number): number => (chef[i] === i ? i : (chef[i] = trouver(chef[i]!)))
      const proprietaire = new Map<number, number>()
      liste.forEach((fr, i) => {
        for (const e of fr.elements) {
          const j = proprietaire.get(e)
          if (j === undefined) proprietaire.set(e, i)
          else chef[trouver(i)] = trouver(j)
        }
      })
      const blocs = new Map<number, number[]>()
      liste.forEach((_, i) => {
        const r = trouver(i)
        const b = blocs.get(r)
        if (b) b.push(i)
        else blocs.set(r, [i])
      })
      // Chaque bloc glisse entre ses voisines telles qu'elles sont ; trois balayages, dans un
      // sens puis dans l'autre, pour qu'un bloc profite de la place qu'une voisine libère.
      const ordonnes = [...blocs.values()].map((membres) => {
        const els = [...new Set(membres.flatMap((i) => liste[i]!.elements))].sort((a, b) => rangDe[a]! - rangDe[b]!)
        return { membres, els }
      })
      ordonnes.sort((p, q) => rangDe[p.els[0]!]! - rangDe[q.els[0]!]!)
      for (let balayage = 0; balayage < 3; balayage++) {
        for (const { membres, els } of balayage % 2 ? [...ordonnes].reverse() : ordonnes) {
          const premier = rangDe[els[0]!]!
          const dernier = rangDe[els[els.length - 1]!]!
          // D'un seul tenant, et d'un seul rectangle — celui d'un des parents au moins : un enfant
          // posé dans un autre rectangle que ses deux parents (la mère, dans le tronc, et ses
          // parents dans mp et mm) suit la place des rectangles, pas la sienne. Sinon, on n'y
          // touche pas.
          if (dernier - premier + 1 !== els.length) continue
          const partie = elements[els[0]!]!.partie
          if (els.some((e) => elements[e]!.partie !== partie)) continue
          if (membres.some((i) => !s.familles[liste[i]!.famille]!.epoux.some((u) => s.partieDe[u] === partie))) continue
          // Le milieu des parents de chaque fratrie du bloc.
          const unions = membres.map((i) => {
            const ep = s.familles[liste[i]!.famille]!.epoux
            const rf = Math.max(...ep.map((u) => s.rangee[u]!))
            const duRang = ep.filter((u) => s.rangee[u] === rf)
            return duRang.reduce((t, u) => t + gx(elementDe(u)) + dec(u), 0) / duRang.length
          })
          // La place libre, entre les voisines (et leurs écarts) — et, au bord du rectangle,
          // jusqu'aux rectangles voisins (et leur écart), avec un débord de quelques dizaines de
          // pixels : le passage strict, à la fin, écartera d'autant les rectangles de droite (un
          // rectangle serré contre son voisin ne laissait pas une fratrie se recentrer de 8 px).
          const p = partie
          const g = premier > 0 ? ligne[premier - 1]! : -1
          const d = dernier + 1 < ligne.length ? ligne[dernier + 1]! : -1
          let gauche = -Infinity
          let droite = Infinity
          // (Au bord, le cadre EXACT du rectangle voisin : l'alignement a pu le resserrer.)
          if (g >= 0 && elements[g]!.partie === p) gauche = gx(g) + largeur(g) + ecartEntre(elements[g]!, elements[els[0]!]!)
          else for (const q of voisinsGauche[p]!) gauche = Math.max(gauche, X[q]! + (cadres[q] = boiteComplete(q))[1] + o.ecartParties - DEBORD)
          if (d >= 0 && elements[d]!.partie === p) droite = gx(d) - ecartEntre(elements[els[els.length - 1]!]!, elements[d]!)
          else for (const q of voisinsDroite[p]!) droite = Math.min(droite, X[q]! + (cadres[q] = boiteComplete(q))[0] - o.ecartParties + DEBORD)
          // Les nouvelles positions (bords gauches) des éléments du bloc.
          let nouvelles: number[]
          if (membres.length === 1) {
            // Une fratrie seule : SERRÉE (ses écarts au plus court) et centrée sous ses parents,
            // conjoints compris — les moindres carrés l'avaient parfois étirée, chaque enfant
            // tiré vers sa propre famille.
            const decalages: number[] = []
            let t = 0
            els.forEach((e, j) => {
              if (j) t += ecartEntre(elements[els[j - 1]!]!, elements[e]!)
              decalages.push(t)
              t += largeur(e)
            })
            const voulu = unions[0]! - t / 2
            const bord = droite - t < gauche ? gx(els[0]!) : Math.min(droite - t, Math.max(gauche, voulu))
            nouvelles = decalages.map((dx) => bord + dx)
          } else {
            // Des fratries qui partagent un couple : d'un bloc, vers la moyenne de ce que chacune
            // voudrait — le couple ENTRE ses deux couples de parents.
            let voulu = 0
            membres.forEach((i, j) => {
              const tri = [...liste[i]!.elements].sort((a, b) => rangDe[a]! - rangDe[b]!)
              voulu += unions[j]! - (gx(tri[0]!) + gx(tri[tri.length - 1]!) + largeur(tri[tri.length - 1]!)) / 2
            })
            voulu /= membres.length
            const min = gauche - gx(els[0]!)
            const max = droite - (gx(els[els.length - 1]!) + largeur(els[els.length - 1]!))
            const glisse = min > max ? 0 : Math.min(max, Math.max(min, voulu))
            nouvelles = els.map((e) => gx(e) + glisse)
          }
          if (els.every((e, j) => Math.abs(nouvelles[j]! - gx(e)) < 0.5)) continue
          els.forEach((e, j) => {
            xi[e] = nouvelles[j]! - X[p]!
            cadres[p]![0] = Math.min(cadres[p]![0], xi[e]!)
            cadres[p]![1] = Math.max(cadres[p]![1], xi[e]! + largeur(e))
          })
        }
      }
    }
    // Un rectangle élargi ne doit chevaucher aucun voisin : le passage strict.
    const boites = s.parties.map((_, p) => boiteComplete(p))
    for (let k = 0; k < 3; k++)
      for (const [p, q] of paires) {
        const besoin = X[p]! + boites[p]![1] + o.ecartParties - (X[q]! + boites[q]![0])
        if (besoin > 0) X[q] = X[q]! + besoin
      }
  }

  const x = new Float64Array(nE)
  for (let e = 0; e < nE; e++) x[e] = gx(e)
  const cx = new Float64Array(s.personnes.length)
  for (let i = 0; i < s.personnes.length; i++) cx[i] = x[elementDe(i)]! + dec(i)
  return { x, cx, decalage: decalageDansElement }
}
