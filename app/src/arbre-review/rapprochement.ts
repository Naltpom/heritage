/**
 * **Le rapprochement de `/arbre-review` : une fratrie loin de ses parents va SOUS eux, même au
 * prix d'un croisement** (retours du 23/10/2026 : « si un trait doit couper certains c'est ok,
 * genre entre 2 parents et leur enfant si c'est direct et qu'il y a personne au-dessus [...] et
 * qu'il y a la place »).
 *
 * L'ordre des rangées (`ordre.ts`) minimise les croisements ; la distance n'y fait que
 * départager des égalités. Les abscisses (`abscisses.ts`) centrent ensuite chaque fratrie sous
 * ses parents, mais SANS changer l'ordre : une fratrie que l'ordre a rangée au bout de sa rangée
 * ne peut pas passer par-dessus ses voisines, et reste à 1 700 px de ses parents alors que la
 * place est vide sous eux. Ici, l'ordre change, en deux temps :
 *
 *  1. un parent SANS PERSONNE AU-DESSUS (aucun parent posé : le père d'une belle-fille, rangé
 *     au bout de la rangée) va au-dessus de ses enfants — rien ne le retient ailleurs ;
 *  2. rangée après rangée de haut en bas, un BLOC — une fratrie, conjoints compris, avec les
 *     fratries qui partagent un de ses couples — va sous ses parents (ceux de son rectangle),
 *     avec toute sa descendance du même rectangle. Un couple dont les deux époux ont leurs
 *     parents posés va à mi-chemin de ses deux couples de parents, comme dans l'alignement.
 *
 * LA PLACE : libre, si aucune fiche n'est dans l'écart d'une famille autour, à aucune des
 * rangées déplacées — « la place dessous ». Sinon, les fiches du même rectangle qui l'occupent
 * s'écartent, et leurs voisines si elles sont serrées ; ce qu'elles s'éloignent alors de leurs
 * propres parents se compte contre le gain. Jamais une fiche d'un autre rectangle. Un bloc qui
 * tomberait au milieu d'une fratrie étrangère s'essaie juste à côté d'elle, à gauche et à
 * droite ; en son milieu aussi, si aucun de ses membres n'a d'enfants — une fratrie coupée ne
 * s'aligne plus d'un bloc, et des frères que leurs enfants tirent chacun de son côté
 * s'écarteraient de milliers de pixels.
 *
 * LE PRIX : c'est ici que la distance pèse contre les croisements. Le gain d'un déplacement est
 * ce qu'il retire aux écarts entre fratries et parents (ceux du bloc, de sa descendance, des
 * fiches écartées) — l'écart d'une fratrie : son milieu sous ses parents, et ce qu'elle s'étire
 * au-delà de sa largeur serrée (une fratrie qu'on écarte en deux garde son milieu, mais sa
 * barre s'allonge : ce n'est pas un rapprochement). Chaque croisement ajouté — compté comme
 * l'ordre les compte, sur les bandes touchées — en coûte `prixDUnCroisement` pixels : un
 * enfant rapproché de 1 700 px vaut deux croisements, pas trois. Chaque fratrie qu'une fiche
 * étrangère coupe en deux en coûte `prixDUneFratrieCoupee` de plus (la fiche passe pour un
 * frère ; le trait qui la relie aux siens, lui, est déjà un croisement compté). Un déplacement
 * qui ne coûte rien, ou qui retire des croisements, se fait dès qu'il rapproche.
 *
 * Pur, sans DOM. Modifie l'ordre (`c.ordre`) ; les abscisses se recalculent ensuite
 * (`disposition.ts`, qui ne garde un passage que s'il a réellement rapproché les fratries).
 */
import { decalageDansElement, type Abscisses } from './abscisses'
import { compterLesBandes, type Couches } from './ordre'
import type { Structure } from './preparation'

export interface OptionsDeRapprochement {
  /** Les écarts d'une rangée : entre deux familles, deux frères et sœurs, deux factices, deux rectangles. */
  ecartFamille: number
  ecartFratrie: number
  ecartFactice: number
  ecartParties: number
  /** Ce que doit rapporter chaque croisement ajouté, en pixels d'écarts retirés. */
  prixDUnCroisement: number
  /** Ce que doit rapporter chaque fratrie qu'une fiche étrangère coupe en deux (ses croisements comptés à part). */
  prixDUneFratrieCoupee: number
  /** En deçà, l'alignement suffit : il glisse une fratrie dans la place que l'ordre lui laisse. */
  gainMinimal: number
  /** Un sous-arbre plus grand ne se déplace pas d'un bloc : ce serait refaire l'ordre. */
  elementsMax: number
}

/** Une fratrie : les enfants d'une famille posés dans un même rectangle, sur la rangée sous leurs parents. */
interface Fratrie {
  famille: number
  partie: number
  ligne: number
  elements: number[]
}

/** Ce que le rapprochement et sa mesure partagent : les fratries, et leurs écarts aux parents. */
function contexte(s: Structure, c: Couches, x: Float64Array, ecartFratrie: number) {
  const { elements } = c
  const largeur = (e: number): number => elements[e]!.largeur
  const elementDe = (i: number): number => c.elementDuFoyer[s.foyerDe[i]!]!
  const cx = (i: number): number => x[elementDe(i)]! + decalageDansElement(s, c, i)
  const ligneDe = new Int32Array(elements.length).fill(-1)
  c.ordre.forEach((l, k) => l.forEach((e) => (ligneDe[e] = k)))
  const chaineDe = (f: number, y: number): readonly number[] => c.factices.get(`${f}|${y}`) ?? []
  const elementDeLEnfant = (f: number, y: number): number => chaineDe(f, y)[0] ?? elementDe(y)

  /* Les fratries, rectangle par rectangle (un couple à cheval sur deux en a une de chaque côté). */
  const fratries: Fratrie[] = []
  const fratriesDe: Fratrie[][] = s.familles.map(() => [])
  s.familles.forEach((fam, f) => {
    if (!fam.epoux.length) return
    const rf = Math.max(...fam.epoux.map((u) => s.rangee[u]!))
    const lp = ligneDe[elementDe(fam.epoux.find((u) => s.rangee[u] === rf)!)]!
    const parPartie = new Map<number, number[]>()
    for (const y of fam.enfants) {
      if (s.rangee[y]! <= rf) continue
      const e = elementDeLEnfant(f, y)
      if (ligneDe[e] !== lp + 1) continue
      const p = elements[e]!.partie
      const l = parPartie.get(p) ?? []
      if (!l.includes(e)) l.push(e)
      parPartie.set(p, l)
    }
    for (const [p, els] of parPartie) {
      const fr: Fratrie = { famille: f, partie: p, ligne: lp + 1, elements: els }
      fratries.push(fr)
      fratriesDe[f]!.push(fr)
    }
  })

  /** Le milieu des parents d'une fratrie : ceux de son rectangle, `null` s'il n'y en a aucun. */
  const parentsIci = (fr: Fratrie): number | null => {
    const ep = s.familles[fr.famille]!.epoux
    const rf = Math.max(...ep.map((u) => s.rangee[u]!))
    const ici = ep.filter((u) => s.rangee[u] === rf && s.partieDe[u] === fr.partie)
    return ici.length ? ici.reduce((t, u) => t + cx(u), 0) / ici.length : null
  }
  /** Le milieu de tous ses parents (pour mesurer l'écart d'un enfant posé ailleurs qu'eux). */
  const parentsTous = (fr: Fratrie): number => {
    const ep = s.familles[fr.famille]!.epoux
    const rf = Math.max(...ep.map((u) => s.rangee[u]!))
    const duRang = ep.filter((u) => s.rangee[u] === rf)
    return duRang.reduce((t, u) => t + cx(u), 0) / duRang.length
  }
  const centre = (els: readonly number[]): number => {
    let lo = Infinity
    let hi = -Infinity
    for (const e of els) {
      lo = Math.min(lo, x[e]!)
      hi = Math.max(hi, x[e]! + largeur(e))
    }
    return (lo + hi) / 2
  }
  /**
   * Ce qu'une fratrie déborde de sa largeur serrée : les fiches étrangères qui la coupent
   * l'étirent, et sa barre s'allonge d'autant — même si son milieu reste sous les parents.
   */
  const etalement = (els: readonly number[]): number => {
    if (els.length < 2) return 0
    let lo = Infinity
    let hi = -Infinity
    let w = 0
    for (const e of els) {
      lo = Math.min(lo, x[e]!)
      hi = Math.max(hi, x[e]! + largeur(e))
      w += largeur(e)
    }
    return Math.max(0, hi - lo - w - ecartFratrie * (els.length - 1))
  }
  /** L'écart d'une famille à ses fratries, rectangle par rectangle : leur milieu, et leur étalement. */
  const ecartDeLaFamille = (f: number): number => {
    let t = 0
    for (const fr of fratriesDe[f]!) t += Math.abs(centre(fr.elements) - (parentsIci(fr) ?? parentsTous(fr))) + etalement(fr.elements)
    return t
  }
  return { elements, largeur, elementDe, ligneDe, chaineDe, fratries, fratriesDe, parentsIci, centre, ecartDeLaFamille }
}

/** La somme des écarts entre chaque fratrie et ses parents (ceux de son rectangle), pour juger un passage. */
export function ecartTotal(s: Structure, c: Couches, a: Abscisses, ecartFratrie: number): number {
  const k = contexte(s, c, Float64Array.from(a.x), ecartFratrie)
  let t = 0
  for (let f = 0; f < s.familles.length; f++) t += k.ecartDeLaFamille(f)
  return t
}

/** Rend le nombre de blocs déplacés. */
export function rapprocher(s: Structure, c: Couches, a: Abscisses, o: OptionsDeRapprochement): number {
  /** Le bord gauche de chaque élément, tenu à jour des déplacements gardés. */
  const x = Float64Array.from(a.x)
  const { elements, largeur, elementDe, ligneDe, chaineDe, fratries, fratriesDe, parentsIci, centre, ecartDeLaFamille } = contexte(s, c, x, o.ecartFratrie)

  /** Les familles de parents d'un élément (deux voisins qui en partagent une sont frères et sœurs). */
  const famillesDesParents = (e: number): number[] => {
    const el = elements[e]!
    if (el.foyer < 0) return [el.famille]
    const l: number[] = []
    for (const m of s.foyers[el.foyer]!.membres) for (const f of s.enfantDans[m]!) if (!l.includes(f)) l.push(f)
    return l
  }
  /** L'écart minimal entre deux voisins d'un même rectangle, celui des abscisses. */
  const ecartMinimal = (u: number, v: number): number => {
    if (elements[u]!.foyer < 0 || elements[v]!.foyer < 0) return o.ecartFactice
    const pu = famillesDesParents(u)
    return famillesDesParents(v).some((f) => pu.includes(f)) ? o.ecartFratrie : o.ecartFamille
  }
  /* Les rectangles qui partagent une rangée (ils ne doivent jamais se recouvrir). */
  const nP = s.parties.length
  const partagent = (p: number, q: number): boolean => !(s.parties[p]!.max < s.parties[q]!.min || s.parties[q]!.max < s.parties[p]!.min)
  const membresDeLaPartie: number[][] = s.parties.map(() => [])
  for (const el of elements) if (ligneDe[el.id] >= 0) membresDeLaPartie[el.partie]!.push(el.id)
  const boiteDe = (p: number): [number, number] => {
    let lo = Infinity
    let hi = -Infinity
    for (const e of membresDeLaPartie[p]!) {
      lo = Math.min(lo, x[e]!)
      hi = Math.max(hi, x[e]! + largeur(e))
    }
    return [lo, hi]
  }

  /** La descendance d'un bloc posée dans son rectangle, sur les rangées du dessous (le bloc compris). */
  const sousArbre = (bloc: readonly number[], p: number): Set<number> | null => {
    const S = new Set(bloc)
    const file = [...bloc]
    while (file.length) {
      const e = file.pop()!
      const el = elements[e]!
      if (el.foyer < 0) continue
      for (const m of s.foyers[el.foyer]!.membres)
        for (const f of s.epouxDans[m]!)
          for (const y of s.familles[f]!.enfants) {
            if (s.rangee[y]! <= s.rangee[m]!) continue
            for (const d of [...chaineDe(f, y), elementDe(y)]) {
              if (S.has(d) || elements[d]!.partie !== p || ligneDe[d] <= ligneDe[e]) continue
              S.add(d)
              file.push(d)
              if (S.size > o.elementsMax) return null
            }
          }
    }
    return S
  }
  /** Les familles dont l'écart change quand ces éléments bougent : celles où ils sont parents, ou enfants. */
  const famillesTouchees = (els: Iterable<number>): Set<number> => {
    const touchees = new Set<number>()
    for (const e of els) {
      const el = elements[e]!
      if (el.foyer < 0) {
        touchees.add(el.famille)
        continue
      }
      for (const m of s.foyers[el.foyer]!.membres) {
        for (const f of s.epouxDans[m]!) touchees.add(f)
        for (const f of s.enfantDans[m]!) touchees.add(f)
      }
    }
    return touchees
  }

  /** Les fratries de chaque rangée, et celles qu'une fiche étrangère coupe en deux. */
  const fratriesDeLaLigne = new Map<number, Fratrie[]>()
  for (const fr of fratries) {
    const l = fratriesDeLaLigne.get(fr.ligne) ?? []
    l.push(fr)
    fratriesDeLaLigne.set(fr.ligne, l)
  }
  const fratriesDeLElement = new Map<number, Fratrie[]>()
  for (const fr of fratries)
    for (const e of fr.elements) {
      const l = fratriesDeLElement.get(e)
      if (l) l.push(fr)
      else fratriesDeLElement.set(e, [fr])
    }
  /** Un élément dont un membre a des enfants : une souche, que ses enfants tirent vers eux. */
  const aDesEnfants = (e: number): boolean => {
    const el = elements[e]!
    return el.foyer >= 0 && s.foyers[el.foyer]!.membres.some((m) => s.epouxDans[m]!.some((f) => s.familles[f]!.enfants.length > 0))
  }
  /**
   * La fratrie d'un seul tenant qu'un bloc posé au rang `index` couperait en deux (`null` s'il
   * n'en coupe aucune) : ses rangs, ses bords. Une fiche étrangère entre deux frères passe pour
   * l'un d'eux. `souche` : un de ces frères a ses propres enfants — coupée, la fratrie ne
   * s'aligne plus d'un bloc, et chacun file vers ses enfants (sur l'arbre réel, un frère à
   * 25 000 px de l'autre pour un gain estimé de 49 px) : celle-là ne se coupe jamais.
   */
  const fratrieCoupee = (reste: readonly number[], index: number): { i0: number; i1: number; gauche: number; droite: number; souche: boolean } | null => {
    if (index <= 0 || index >= reste.length) return null
    const avant = reste[index - 1]!
    const apres = reste[index]!
    for (const fr of fratriesDeLElement.get(avant) ?? []) {
      if (!fr.elements.includes(apres)) continue
      const rangs = fr.elements.map((e) => reste.indexOf(e))
      if (rangs.some((q) => q < 0)) continue
      const i0 = Math.min(...rangs)
      const i1 = Math.max(...rangs)
      if (i1 - i0 + 1 !== rangs.length) continue
      return { i0, i1, gauche: x[reste[i0]!]!, droite: x[reste[i1]!]! + largeur(reste[i1]!), souche: fr.elements.some(aDesEnfants) }
    }
    return null
  }
  /** La rangée `r` sans les éléments qui bougent, et le rang où ils se posent (par leur milieu, décalés de `delta`). */
  const placeDans = (r: number, moved: readonly number[], delta: number) => {
    let lo = Infinity
    let hi = -Infinity
    for (const e of moved) {
      lo = Math.min(lo, x[e]! + delta)
      hi = Math.max(hi, x[e]! + largeur(e) + delta)
    }
    const enMouvement = new Set(moved)
    const reste = c.ordre[r]!.filter((e) => !enMouvement.has(e))
    const milieu = (lo + hi) / 2
    let index = 0
    while (index < reste.length && x[reste[index]!]! + largeur(reste[index]!) / 2 < milieu) index++
    return { lo, hi, reste, milieu, index }
  }
  const coupees = (r: number): number => {
    const rang = new Map(c.ordre[r]!.map((e, i) => [e, i]))
    let n = 0
    for (const fr of fratriesDeLaLigne.get(r) ?? []) {
      if (fr.elements.length < 2) continue
      let lo = Infinity
      let hi = -Infinity
      for (const e of fr.elements) {
        const q = rang.get(e)!
        lo = Math.min(lo, q)
        hi = Math.max(hi, q)
      }
      if (hi - lo + 1 > fr.elements.length) n++
    }
    return n
  }

  let deplaces = 0
  /** Les croisements de chaque bande pour l'ordre courant (`compterLesBandes`), à mesure qu'on les compte. */
  const croisementsDeLaBande = new Map<number, number>()

  /** Un déplacement évalué (rien n'en est gardé) : ce qu'il rapporte net, et de quoi l'appliquer. */
  interface Essai {
    /** Le gain moins le prix. */
    net: number
    decalages: Map<number, number>
    ordres: [number, number[]][]
    bandes: number[]
    croisements: number[]
  }

  /**
   * Évalue le déplacement des éléments `S` (un bloc et sa descendance, ou un parent seul) de
   * `delta` dans leurs rangées : les fiches qui occupent la place s'écartent. `auBord` : dans
   * chaque rangée, posé au bord le plus proche d'une fratrie qu'il couperait, plutôt qu'en son
   * milieu. `null` : impossible (un autre rectangle touché), ou pas assez rapproché.
   */
  const evaluer = (S: ReadonlySet<number>, delta: number, p: number, auBord: boolean): Essai | null => {
    const parLigne = new Map<number, number[]>()
    for (const e of S) {
      const l = parLigne.get(ligneDe[e]) ?? []
      l.push(e)
      parLigne.set(ligneDe[e], l)
    }
    // La place, rangée par rangée : les fiches qui y sont s'écartent, de leur côté — et leurs
    // voisines, si elles sont serrées. Jamais une fiche d'un autre rectangle.
    const decalages = new Map<number, number>()
    for (const e of S) decalages.set(e, delta)
    const insertions: { ligne: number; deplaces: number[]; reste: number[]; index: number }[] = []
    for (const [r, moved] of parLigne) {
      const place = placeDans(r, moved, delta)
      const { lo, hi, reste, milieu } = place
      let index = place.index
      const f = auBord ? fratrieCoupee(reste, index) : null
      if (f) index = milieu - f.gauche <= f.droite - milieu ? f.i0 : f.i1 + 1
      // À gauche.
      let borne = lo
      let voisine = -1
      for (let i = index - 1; i >= 0; i--) {
        const n = reste[i]!
        const marge = voisine < 0 ? (elements[n]!.partie === p ? o.ecartFamille : o.ecartParties) : ecartMinimal(n, voisine)
        const depasse = x[n]! + largeur(n) + marge - borne
        if (depasse <= 0) break
        if (elements[n]!.partie !== p) return null
        decalages.set(n, -depasse)
        borne = x[n]! - depasse
        voisine = n
      }
      // À droite.
      borne = hi
      voisine = -1
      for (let i = index; i < reste.length; i++) {
        const n = reste[i]!
        const marge = voisine < 0 ? (elements[n]!.partie === p ? o.ecartFamille : o.ecartParties) : ecartMinimal(voisine, n)
        const depasse = borne + marge - x[n]!
        if (depasse <= 0) break
        if (elements[n]!.partie !== p) return null
        decalages.set(n, depasse)
        borne = x[n]! + depasse + largeur(n)
        voisine = n
      }
      insertions.push({ ligne: r, deplaces: [...moved].sort((u, v) => x[u]! - x[v]!), reste, index })
    }
    // Aucun autre rectangle qui partage une rangée ne doit être touché.
    for (let q = 0; q < nP; q++) {
      if (q === p || !partagent(p, q)) continue
      const [qlo, qhi] = boiteDe(q)
      if (qlo === Infinity) continue
      for (const [e, d] of decalages) if (x[e]! + d - o.ecartParties < qhi && x[e]! + d + largeur(e) + o.ecartParties > qlo) return null
    }
    // Le gain : les écarts des familles touchées, avant et après.
    const touchees = famillesTouchees(decalages.keys())
    let avant = 0
    for (const f of touchees) avant += ecartDeLaFamille(f)
    for (const [e, d] of decalages) x[e] = x[e]! + d
    let apres = 0
    for (const f of touchees) apres += ecartDeLaFamille(f)
    for (const [e, d] of decalages) x[e] = x[e]! - d
    const gain = avant - apres
    if (gain < o.gainMinimal) return null
    // Le prix : chaque croisement ajouté, et chaque fratrie qu'une fiche étrangère coupe en
    // deux (la fiche passe pour un frère).
    const sauvegarde = insertions.map(({ ligne }) => [ligne, c.ordre[ligne]!] as const)
    const bandes = [...new Set(insertions.flatMap(({ ligne }) => [ligne - 1, ligne]))]
    // Le compte d'avant, gardé d'un essai à l'autre tant que la bande n'a pas changé.
    const manquantes = bandes.filter((k) => !croisementsDeLaBande.has(k))
    compterLesBandes(s, c, manquantes).forEach((n, i) => croisementsDeLaBande.set(manquantes[i]!, n))
    const croisementsAvant = bandes.reduce((t, k) => t + croisementsDeLaBande.get(k)!, 0)
    let coupesAvant = 0
    for (const { ligne } of insertions) coupesAvant += coupees(ligne)
    const ordres: [number, number[]][] = insertions.map(({ ligne, deplaces: moved, reste, index }) => [ligne, [...reste.slice(0, index), ...moved, ...reste.slice(index)]])
    for (const [ligne, l] of ordres) c.ordre[ligne] = l
    let coupesApres = 0
    for (const { ligne } of insertions) coupesApres += coupees(ligne)
    const croisements = compterLesBandes(s, c, bandes)
    for (const [ligne, l] of sauvegarde) c.ordre[ligne] = l
    const croisementsApres = croisements.reduce((t, n) => t + n, 0)
    const net = gain - o.prixDUnCroisement * Math.max(0, croisementsApres - croisementsAvant) - o.prixDUneFratrieCoupee * Math.max(0, coupesApres - coupesAvant)
    return { net, decalages, ordres, bandes, croisements }
  }

  /**
   * Essaie un déplacement. S'il tombe, dans la rangée du bloc, au milieu d'une fratrie d'un seul
   * tenant, il s'essaie juste à côté d'elle, à gauche et à droite (le bloc s'arrête à un écart de
   * familles de ses bords, au lieu de la pousser tout entière) — et en son milieu si elle n'est
   * pas une souche : le prix départage (une fratrie coupée coûte en plus
   * `prixDUneFratrieCoupee`). Le meilleur est gardé s'il rapproche plus qu'il ne coûte. Rend
   * `true` s'il l'est.
   */
  const essayer = (S: ReadonlySet<number>, delta: number, p: number): boolean => {
    const haut = Math.min(...[...S].map((e) => ligneDe[e]))
    const moved = [...S].filter((e) => ligneDe[e] === haut)
    const { lo, hi, reste, index } = placeDans(haut, moved, delta)
    const f = fratrieCoupee(reste, index)
    const essais = f
      ? [
          evaluer(S, delta + (f.gauche - o.ecartFamille - hi), p, true),
          evaluer(S, delta + (f.droite + o.ecartFamille - lo), p, true),
          f.souche ? null : evaluer(S, delta, p, false),
        ]
      : [evaluer(S, delta, p, true)]
    let choix: Essai | null = null
    for (const essai of essais) if (essai && (!choix || essai.net > choix.net)) choix = essai
    if (!choix || choix.net <= 0) return false
    const { decalages, ordres, bandes, croisements } = choix
    for (const [e, d] of decalages) x[e] = x[e]! + d
    for (const [ligne, l] of ordres) c.ordre[ligne] = l
    croisements.forEach((n, i) => croisementsDeLaBande.set(bandes[i]!, n))
    deplaces++
    return true
  }

  /*
   * 1. LES PARENTS SANS PERSONNE AU-DESSUS (le père d'une belle-fille, posé avec elle mais au
   *    bout de la rangée : personne de son côté dans l'arbre) : rien ne les retient là où ils
   *    sont, ils vont au-dessus de leurs enfants. Un parent qui a lui-même des parents posés
   *    reste où l'ordre l'a mis : ce sont ses enfants qui viennent à lui (2).
   */
  for (const l of c.ordre)
    for (const e of [...l]) {
      const el = elements[e]!
      if (el.foyer < 0) continue
      const membres = s.foyers[el.foyer]!.membres
      if (membres.some((m) => s.enfantDans[m]!.length > 0)) continue
      const familles = [...new Set(membres.flatMap((m) => s.epouxDans[m]!))]
      if (!familles.length || familles.some((f) => s.familles[f]!.epoux.some((u) => elementDe(u) !== e))) continue
      const enfants = familles.flatMap((f) => fratriesDe[f]!.filter((fr) => fr.partie === el.partie))
      if (!enfants.length) continue
      let t = 0
      for (const fr of enfants) t += centre(fr.elements)
      // Le point d'union des époux (pas le milieu de la boîte) au-dessus du milieu des enfants.
      let union = 0
      for (const f of familles) union += s.familles[f]!.epoux.reduce((u, m) => u + decalageDansElement(s, c, m), 0) / s.familles[f]!.epoux.length
      const delta = t / enfants.length - (x[e]! + union / familles.length)
      if (Math.abs(delta) >= o.gainMinimal) essayer(new Set([e]), delta, el.partie)
    }

  /* 2. Les fratries, de haut en bas : chacune, avec sa descendance, sous ses parents. */
  for (let k = 0; k < c.ordre.length; k++) {
    // Les blocs de la rangée : des fratries qui partagent un élément (un couple dont chaque
    // époux a ses parents posés) se déplacent ensemble.
    const ici = fratries.filter((fr) => fr.ligne === k)
    if (!ici.length) continue
    const chef = ici.map((_, i) => i)
    const trouver = (i: number): number => (chef[i] === i ? i : (chef[i] = trouver(chef[i]!)))
    const proprietaire = new Map<number, number>()
    ici.forEach((fr, i) => {
      for (const e of fr.elements) {
        const j = proprietaire.get(e)
        if (j === undefined) proprietaire.set(e, i)
        else chef[trouver(i)] = trouver(j)
      }
    })
    const parChef = new Map<number, Fratrie[]>()
    ici.forEach((fr, i) => {
      const r = trouver(i)
      const l = parChef.get(r) ?? []
      l.push(fr)
      parChef.set(r, l)
    })
    /**
     * Les parents d'une fratrie qui SUIVENT le bloc : tous sans personne au-dessus, dans un même
     * élément du rectangle, et sans autre enfant que ceux du bloc (le père d'une belle-fille).
     * Ils se déplacent avec elle, et leur fratrie ne pèse pas dans la place visée.
     */
    const parentsQuiSuivent = (fr: Fratrie, bloc: ReadonlySet<number>): number => {
      const ep = s.familles[fr.famille]!.epoux
      const e = elementDe(ep[0]!)
      if (elements[e]!.partie !== fr.partie || ep.some((u) => elementDe(u) !== e)) return -1
      const membres = s.foyers[elements[e]!.foyer]!.membres
      if (membres.some((m) => s.enfantDans[m]!.length > 0)) return -1
      for (const m of membres) for (const f of s.epouxDans[m]!) for (const autre of fratriesDe[f]!) if (autre.elements.some((x) => !bloc.has(x))) return -1
      return e
    }
    /** Le déplacement voulu d'un bloc : la moyenne de ce que veut chacune de ses fratries (sauf celles dont les parents suivent). */
    const voulu = (membres: readonly Fratrie[]): { delta: number; suivent: number[] } | null => {
      const bloc = new Set(membres.flatMap((fr) => fr.elements))
      const suivent: number[] = []
      let t = 0
      let n = 0
      for (const fr of membres) {
        const qui = membres.length > 1 ? parentsQuiSuivent(fr, bloc) : -1
        if (qui >= 0) {
          if (!suivent.includes(qui)) suivent.push(qui)
          continue
        }
        const cible = parentsIci(fr)
        if (cible === null) return null
        t += cible - centre(fr.elements)
        n++
      }
      return n ? { delta: t / n, suivent } : null
    }
    // Les plus loin d'abord.
    const blocs = [...parChef.values()]
      .map((membres) => ({ membres, v: voulu(membres) }))
      .filter((b) => b.v !== null && Math.abs(b.v.delta) >= o.gainMinimal)
      .sort((p, q) => Math.abs(q.v!.delta) - Math.abs(p.v!.delta))
    for (const { membres } of blocs) {
      const v = voulu(membres)
      if (v === null || Math.abs(v.delta) < o.gainMinimal) continue
      const bloc = [...new Set(membres.flatMap((fr) => fr.elements))]
      const p = elements[bloc[0]!]!.partie
      if (bloc.some((e) => elements[e]!.partie !== p)) continue
      // D'un seul tenant dans sa rangée (une fratrie coupée par une autre famille ne se déplace pas).
      const rang = new Map(c.ordre[k]!.map((e, i) => [e, i]))
      const rangs = bloc.map((e) => rang.get(e)!).sort((u, v) => u - v)
      if (rangs[rangs.length - 1]! - rangs[0]! + 1 !== rangs.length) continue
      const S = sousArbre(bloc, p)
      if (!S) continue
      for (const e of v.suivent) S.add(e)
      essayer(S, v.delta, p)
    }
  }
  return deplaces
}
