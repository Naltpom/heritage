/**
 * **L'ordre des fiches sur chaque rangée** de `/arbre-review` : le moins de croisements
 * possible, chaque famille d'un seul tenant.
 *
 * Le graphe en couches : une rangée par génération ; sur chaque rangée, des ÉLÉMENTS —
 * les foyers (une personne et ses conjoints, qui ne se séparent jamais) et les nœuds
 * FACTICES qui portent un lien sautant une génération. Une famille est un point d'union
 * dans le foyer de ses époux ; ses liens descendent vers ses enfants, une rangée plus bas.
 *
 * 1. **L'ordre de départ, en sablier** — pas un parcours quelconque : la forme d'un arbre
 *    généalogique. Chaque foyer a ses ascendances au-dessus de lui (le côté du mari à
 *    gauche, celui de la femme à droite), ses frères et sœurs du côté EXTÉRIEUR (à gauche
 *    d'un mari, à droite d'une femme), leurs descendants dessous, du même côté. Ce qui ne
 *    peut aller à l'extérieur sans croiser (les cousins par l'autre parent) se range
 *    au-delà de la fratrie, jamais entre deux frères. C'est un ordre presque plan : ses
 *    croisements sont, pour l'essentiel, ceux que la topologie impose (un couple d'ancêtres
 *    ne peut pas être côte à côte ET avoir les cousins de ses deux côtés sous lui).
 * 2. **L'affinage par familles** (`affiner`) : le barycentre, mais par BLOCS (une fratrie,
 *    ou des fratries collées par un couple, se déplace d'un seul tenant), les échanges de
 *    blocs voisins et de frères et sœurs, et le retournement des foyers — chaque
 *    mouvement gardé seulement s'il retire des croisements, jamais hors de la partie (le
 *    rectangle) de l'élément, dont l'ordre est fixé.
 * 3. **Plusieurs départs** (l'ordre servi des fratries, l'inverse) : on garde le meilleur.
 *    Tout est déterministe : aucun hasard.
 *
 * Le barycentre élément par élément et les transpositions libres — le Sugiyama classique —
 * ont été essayés et écartés : sur l'arbre réel, ils coupaient les fratries (un cousin
 * glissé entre deux frères, 49 → 126 fratries coupées) sans croiser moins que l'affinage
 * par familles (852 croisements contre 483, au même stade de la mise au point).
 *
 * **Quels croisements.** Le compte de Sugiyama (des liens obliques « point d'union →
 * enfant » qui s'inversent) n'est pas ce que voit le lecteur : le routage trace une
 * descente, une barre de fratrie sur une piste, des chutes. Deux fratries emboîtées ne
 * s'y croisent qu'une fois (le compte oblique en voyait le produit de leurs liens), et une
 * union qui contourne des cartes coupe chacune de leurs descentes (le compte oblique ne la
 * voyait pas). L'affinage minimise donc le compte « en bus » (`compterBande`), tenu à jour
 * mouvement après mouvement (`CompteEnBus`), et ne garde le compte oblique que pour
 * départager deux ordres aussi croisés. Mesuré sur l'arbre réel : 323 → 262 croisements
 * dessinés, et 110 → 76 sur l'arbre synthétique de 1 000 personnes. (Le compte compare des
 * rangs de deux rangées ; les étaler sur un axe commun, colonne de rectangles par colonne, le
 * rendait plus fidèle au dessin — mais l'affinage trouvait alors des ordres 3 % plus croisés
 * sur les arbres synthétiques : essayé, écarté.)
 *
 * Pur, sans DOM.
 */
import { compterCroisements } from '@/arbre2/agencement'
import { anneeDe } from '@/histoire/lignees'
import type { Structure } from './preparation'

/** Un élément de rangée : un foyer, ou un nœud factice (un lien qui saute une génération). */
export interface Element {
  id: number
  rangee: number
  partie: number
  /** Le foyer, ou −1 pour un factice. */
  foyer: number
  /** Pour un factice : la famille dont il porte le lien, et l'enfant visé. */
  famille: number
  enfant: number
  largeur: number
}

/** Un lien entre deux rangées consécutives. */
export interface Lien {
  /**
   * Le haut : une famille (son point d'union), un factice — ou une PERSONNE : l'époux posé
   * une rangée plus haut que son conjoint (un couple que les rangées n'ont pas su aligner),
   * que le routage relie au flanc du conjoint par le couloir d'entre eux. Ce lien d'union
   * n'a pas d'enfant : il ne sert qu'à ordonner (que le conjoint vienne sous l'époux).
   */
  hautFamille: number
  hautFactice: number
  hautPersonne: number
  /** Le bas : une personne (un enfant) ou un factice. */
  basPersonne: number
  basFactice: number
  /** La rangée du haut. */
  rangee: number
}

export interface Couches {
  elements: Element[]
  /** Par rangée (indice dans `rangees`), les éléments de gauche à droite. */
  ordre: number[][]
  rangees: number[]
  /** Les liens de chaque bande `k → k+1` (indice de la rangée du haut dans `rangees`). */
  liens: Lien[][]
  /** L'élément de chaque foyer. */
  elementDuFoyer: number[]
  /** Les factices d'un lien famille → enfant, de haut en bas. */
  factices: Map<string, number[]>
  /** Membres retournés : le foyer `f` se lit de droite à gauche. */
  retourne: boolean[]
  croisements: number
}

export interface OptionsDOrdre {
  fratriesParNaissance: boolean
  /** Largeur réservée à un factice, pour qu'un trait vertical passe entre deux fiches. */
  largeurFactice: number
}

/* ------------------------------------------------------------------ */
/* Le sablier                                                          */
/* ------------------------------------------------------------------ */

type Rangs = Map<number, number[]>

function joindre(...listes: (Rangs | undefined)[]): Rangs {
  const r: Rangs = new Map()
  for (const l of listes) {
    if (!l) continue
    for (const [k, v] of l) {
      if (!v.length) continue
      const d = r.get(k)
      if (d) for (const x of v) d.push(x)
      else r.set(k, [...v])
    }
  }
  return r
}

function partager(l: Rangs, coupe: number): { avant: Rangs; apres: Rangs } {
  const avant: Rangs = new Map()
  const apres: Rangs = new Map()
  for (const [k, v] of l) (k <= coupe ? avant : apres).set(k, v)
  return { avant, apres }
}

/**
 * L'ordre en sablier : chaque foyer est atteint une fois, depuis l'ego ; ce qui n'est pas
 * relié à lui vient ensuite, composante par composante.
 */
export type VarianteDeSablier = 'servi' | 'naissance' | 'inverse'

export function ordreEnSablier(s: Structure, variante: VarianteDeSablier): Map<number, number[]> {
  const { foyers, foyerDe, familles, enfantDans, rangee } = s
  const place = new Uint8Array(foyers.length)
  const annee = (i: number): number => anneeDe(s.personnes[i]!) ?? 9999

  /**
   * Les foyers des époux d'une famille, de gauche à droite (le mari d'abord) — ceux de la
   * rangée de la famille seulement : un époux d'une autre génération (un couple que les
   * rangées n'ont pas su aligner) se pose à part, dans sa propre rangée.
   */
  const foyersDesEpoux = (f: number): number[] => {
    const rf = Math.max(...familles[f]!.epoux.map((e) => rangee[e]!))
    const ep = [...familles[f]!.epoux]
      .filter((e) => rangee[e] === rf)
      .sort((a, b) => Number(s.personnes[b]!.sex === 'masculin') - Number(s.personnes[a]!.sex === 'masculin') || a - b)
    const l: number[] = []
    for (const e of ep) if (!l.includes(foyerDe[e]!)) l.push(foyerDe[e]!)
    return l
  }

  /** Les enfants d'une famille, dans l'ordre de la fratrie (l'ordre servi, ou les naissances). */
  const enfantsOrdonnes = (f: number): number[] => {
    const e = [...familles[f]!.enfants]
    if (variante === 'naissance') e.sort((a, b) => annee(a) - annee(b))
    else if (variante === 'inverse') e.reverse()
    return e
  }

  /**
   * Le bloc d'une unité (un foyer, ou les foyers des deux époux d'un couple à cheval sur
   * deux rectangles) : ses membres, leurs ascendances au-dessus, leurs collatéraux de
   * part et d'autre, ses descendants dessous.
   *
   * `ancre` : l'enfant par lequel on y arrive en remontant (placé ailleurs, par
   * l'appelant). Tout ce qui est sous l'unité doit alors se ranger d'UN côté de cet
   * enfant — `cote` —, l'autre côté étant celui de son conjoint, qu'on ne sépare pas de
   * lui. Dans l'ordre, en s'éloignant de l'enfant : ses frères et sœurs (la fratrie),
   * puis ce qui ne pouvait pas aller dehors sans croiser (l'intérieur : les cousins par
   * l'autre parent, les demi-frères et sœurs), puis l'extérieur naturel (les cousins par
   * le parent de ce côté, et ainsi de suite en remontant). La fratrie reste d'un seul
   * tenant, sous son bus ; les cousins forment leurs propres grappes.
   *
   * Rend `dessus` (les rangées jusqu'à celle de l'unité) et `dessous` (les suivantes) —
   * tout entier du côté `cote` de l'ancre, s'il y en a une.
   *
   * Par ordre de naissance, l'ancre garde sa place dans sa fratrie : ses aînés à sa gauche,
   * ses cadets à sa droite (comme `/arbre`). Le dessous se coupe alors en deux : `dessous`
   * à gauche de l'ancre (la parenté de gauche, puis les aînés), `droite` à sa droite (les
   * cadets, puis la parenté de droite).
   */
  function bloc(unite: number[], ancre: number, cote: 'G' | 'D'): { dessus: Rangs; dessous: Rangs; droite?: Rangs } {
    for (const fo of unite) place[fo] = 1
    const rang = foyers[unite[0]!]!.rangee
    const membres = unite.flatMap((fo) => foyers[fo]!.membres)

    // Les ascendances : le membre de gauche range sa parenté à gauche, celui de droite à droite.
    const parGauche: Rangs[] = []
    const parDroite: Rangs[] = []
    const dessusAsc: Rangs[] = []
    membres.forEach((m, k) => {
      const pf = enfantDans[m]![0]
      if (pf === undefined) return
      const tous = foyersDesEpoux(pf)
      if (!tous.length || tous.some((fo) => place[fo])) return
      const c: 'G' | 'D' = k === membres.length - 1 && membres.length > 1 ? 'D' : 'G'
      const a = bloc(tous, m, c)
      dessusAsc.push(a.dessus)
      if (a.droite) {
        parGauche.push(a.dessous)
        parDroite.push(a.droite)
      } else (c === 'G' ? parGauche : parDroite).push(a.dessous)
    })

    // Les enfants des familles de l'unité, de gauche à droite ; l'ancre est un trou.
    const dansUnite = new Set(membres)
    const famillesUnite: number[] = []
    for (const m of membres)
      for (const f of s.epouxDans[m]!)
        if (!famillesUnite.includes(f) && familles[f]!.epoux.filter((e) => rangee[e] === rang).every((e) => dansUnite.has(e)))
          famillesUnite.push(f)
    const milieu = (k: number): number => {
      const ep = familles[k]!.epoux.filter((e) => dansUnite.has(e))
      return ep.reduce((t, e) => t + membres.indexOf(e), 0) / Math.max(1, ep.length)
    }
    famillesUnite.sort((a, b) => milieu(a) - milieu(b) || a - b)
    const avant: Rangs[] = []
    const apres: Rangs[] = []
    let apresLAncre = false
    for (const f of famillesUnite) {
      let enfants = enfantsOrdonnes(f)
      if (enfants.includes(ancre) && variante !== 'naissance') {
        enfants = enfants.filter((e) => e !== ancre)
        enfants = cote === 'G' ? [...enfants, ancre] : [ancre, ...enfants]
      }
      for (const c of enfants) {
        if (c === ancre) {
          apresLAncre = true
          continue
        }
        const fo = foyerDe[c]!
        if (place[fo]) continue
        const b = bloc([fo], -1, 'G')
        ;(apresLAncre ? apres : avant).push(joindre(b.dessus, b.dessous))
      }
    }

    // La rangée de l'unité : ses frères et sœurs de part et d'autre, elle au milieu.
    const g = partager(joindre(...parGauche), rang)
    const d = partager(joindre(...parDroite), rang)
    const av = partager(joindre(...avant), rang)
    const ap = partager(joindre(...apres), rang)
    const dessus = joindre(...dessusAsc, g.avant, av.avant, new Map([[rang, [...unite]]]), ap.avant, d.avant)
    let dessous: Rangs
    if (ancre < 0) dessous = joindre(g.apres, av.apres, ap.apres, d.apres)
    else if (variante === 'naissance') return { dessus, dessous: joindre(g.apres, av.apres), droite: joindre(ap.apres, d.apres) }
    // À gauche de l'ancre : [extérieur][intérieur][fratrie] — l'extérieur, c'est la parenté de gauche.
    else if (cote === 'G') dessous = joindre(g.apres, d.apres, ap.apres, av.apres)
    // À droite : [fratrie][intérieur][extérieur].
    else dessous = joindre(ap.apres, av.apres, g.apres, d.apres)
    return { dessus, dessous }
  }

  const tout: Rangs[] = []
  if (s.egoIndex >= 0) {
    const b = bloc([foyerDe[s.egoIndex]!], -1, 'G')
    tout.push(joindre(b.dessus, b.dessous))
  }
  // Le reste, composante par composante : de la plus ancienne rangée à la plus récente.
  const reste = foyers.map((f) => f.id).filter((f) => !place[f])
  reste.sort((a, b) => foyers[a]!.rangee - foyers[b]!.rangee || a - b)
  for (const fo of reste) {
    if (place[fo]) continue
    const b = bloc([fo], -1, 'G')
    tout.push(joindre(b.dessus, b.dessous))
  }
  return joindre(...tout)
}

/* ------------------------------------------------------------------ */
/* Les couches                                                         */
/* ------------------------------------------------------------------ */

/**
 * Construit les éléments (foyers, factices), les liens entre rangées, et l'ordre de départ
 * (le sablier, rangé par parties).
 */
export function construireCouches(s: Structure, depart: Map<number, number[]>, o: OptionsDOrdre): Couches {
  const { foyers, familles, rangee, partieDe, parties } = s
  const rangees = [...new Set(foyers.map((f) => f.rangee))].sort((a, b) => a - b)
  // Les rangées intermédiaires vides (un saut de génération) existent aussi.
  if (rangees.length) {
    const tous: number[] = []
    for (let r = rangees[0]!; r <= rangees[rangees.length - 1]!; r++) tous.push(r)
    rangees.splice(0, rangees.length, ...tous)
  }
  const indiceDeRangee = new Map(rangees.map((r, k) => [r, k]))
  const elements: Element[] = []
  const elementDuFoyer: number[] = new Array(foyers.length).fill(-1)
  for (const fo of foyers) {
    elementDuFoyer[fo.id] = elements.length
    elements.push({ id: elements.length, rangee: fo.rangee, partie: fo.partie, foyer: fo.id, famille: -1, enfant: -1, largeur: fo.largeur })
  }

  // L'ordre de départ : le sablier, chaque rangée rangée par colonne de partie (tri stable).
  const ordre: number[][] = rangees.map(() => [])
  const vus = new Uint8Array(foyers.length)
  for (const [r, l] of depart) {
    for (const fo of l) {
      // Chaque foyer dans SA rangée, quoi qu'en dise le départ.
      if (vus[fo] || foyers[fo]!.rangee !== r) continue
      vus[fo] = 1
      ordre[indiceDeRangee.get(r)!]!.push(elementDuFoyer[fo]!)
    }
  }
  for (const fo of foyers) if (!vus[fo.id]) ordre[indiceDeRangee.get(fo.rangee)!]!.push(elementDuFoyer[fo.id]!)

  // Les liens, bande par bande ; un lien qui saute des rangées passe par des factices.
  const liens: Lien[][] = rangees.map(() => [])
  const factices = new Map<string, number[]>()
  familles.forEach((f, k) => {
    if (!f.epoux.length) return
    const rf = Math.max(...f.epoux.map((e) => rangee[e]!))
    // Les époux d'une rangée plus haute : un lien d'union vers le premier époux de la rangée
    // de la famille, dans le couloir juste au-dessus d'elle (là où le routage le trace).
    const cible = f.epoux.find((e) => rangee[e] === rf)!
    for (const e of f.epoux) {
      if (rangee[e] === rf) continue
      const kr = indiceDeRangee.get(rf - 1)
      if (kr !== undefined) liens[kr]!.push({ hautFamille: -1, hautFactice: -1, hautPersonne: e, basPersonne: cible, basFactice: -1, rangee: rf - 1 })
    }
    for (const c of f.enfants) {
      const rc = rangee[c]!
      if (rc <= rf) continue // un enfant qui n'est pas plus bas que ses parents : rien à ordonner
      const kf = indiceDeRangee.get(rf)!
      if (rc === rf + 1) {
        liens[kf]!.push({ hautFamille: k, hautFactice: -1, hautPersonne: -1, basPersonne: c, basFactice: -1, rangee: rf })
        continue
      }
      const chaine: number[] = []
      for (let r = rf + 1; r < rc; r++) {
        const e: Element = {
          id: elements.length,
          rangee: r,
          // La partie de l'enfant si elle couvre cette rangée, sinon celle des parents.
          partie: parties[partieDe[c]!]!.min <= r ? partieDe[c]! : partieDe[f.epoux[0]!]!,
          foyer: -1,
          famille: k,
          enfant: c,
          largeur: o.largeurFactice,
        }
        elements.push(e)
        chaine.push(e.id)
        ordre[indiceDeRangee.get(r)!]!.push(e.id)
      }
      factices.set(`${k}|${c}`, chaine)
      liens[kf]!.push({ hautFamille: k, hautFactice: -1, hautPersonne: -1, basPersonne: -1, basFactice: chaine[0]!, rangee: rf })
      for (let i = 0; i + 1 < chaine.length; i++)
        liens[indiceDeRangee.get(rf + 1 + i)!]!.push({
          hautFamille: -1,
          hautFactice: chaine[i]!,
          hautPersonne: -1,
          basPersonne: -1,
          basFactice: chaine[i + 1]!,
          rangee: rf + 1 + i,
        })
      liens[indiceDeRangee.get(rc - 1)!]!.push({
        hautFamille: -1,
        hautFactice: chaine[chaine.length - 1]!,
        hautPersonne: -1,
        basPersonne: c,
        basFactice: -1,
        rangee: rc - 1,
      })
    }
  })

  for (const l of ordre) stableParColonne(l, elements, parties)
  return { elements, ordre, rangees, liens, elementDuFoyer, factices, retourne: new Array(foyers.length).fill(false), croisements: 0 }
}

function stableParColonne(l: number[], elements: readonly Element[], parties: Structure['parties']): void {
  const rang = new Map(l.map((e, i) => [e, i]))
  l.sort((a, b) => parties[elements[a]!.partie]!.colonne - parties[elements[b]!.partie]!.colonne || rang.get(a)! - rang.get(b)!)
}

/* ------------------------------------------------------------------ */
/* Les positions ordinales, et les croisements                          */
/* ------------------------------------------------------------------ */

/** Les positions ordinales : `pos[élément]` = son rang dans sa rangée. */
function positions(c: Couches): Float64Array {
  const pos = new Float64Array(c.elements.length)
  for (const l of c.ordre) l.forEach((e, i) => (pos[e] = i))
  return pos
}

/** La position d'une personne : son élément, plus sa place dans le foyer (dans [0, 1)). */
function posPersonne(s: Structure, c: Couches, pos: Float64Array, i: number): number {
  const fo = s.foyerDe[i]!
  const m = s.foyers[fo]!.membres.length
  let k = s.rangDansFoyer[i]!
  if (c.retourne[fo]) k = m - 1 - k
  return pos[c.elementDuFoyer[fo]!]! + (k + 0.5) / m
}

/**
 * Les époux d'une famille posés sur SA rangée (la plus basse de ses époux) : c'est d'eux que
 * descendent les enfants ; un époux d'une rangée plus haute la rejoint par un lien d'union.
 */
function epouxDeLaRangee(s: Structure, f: number): number[] {
  const ep = s.familles[f]!.epoux
  if (ep.length < 2) return ep
  let rf = -Infinity
  for (const e of ep) if (s.rangee[e]! > rf) rf = s.rangee[e]!
  return ep.filter((e) => s.rangee[e] === rf)
}

/** La position d'un point d'union : la moyenne de ses époux de la rangée. */
function posFamille(s: Structure, c: Couches, pos: Float64Array, f: number): number {
  const ep = epouxDeLaRangee(s, f)
  let t = 0
  for (const e of ep) t += posPersonne(s, c, pos, e)
  return t / ep.length
}

function posHaut(s: Structure, c: Couches, pos: Float64Array, l: Lien): number {
  if (l.hautPersonne >= 0) return posPersonne(s, c, pos, l.hautPersonne)
  return l.hautFamille >= 0 ? posFamille(s, c, pos, l.hautFamille) : pos[l.hautFactice]! + 0.5
}

/** La clé de l'article d'un lien : sa famille, son factice, ou son lien d'union (un par paire d'époux). */
function cleDuHaut(l: Lien): number {
  if (l.hautFamille >= 0) return l.hautFamille
  if (l.hautFactice >= 0) return -1 - l.hautFactice
  return 1e12 + l.hautPersonne * 1e6 + l.basPersonne
}
function posBas(s: Structure, c: Couches, pos: Float64Array, l: Lien): number {
  return l.basPersonne >= 0 ? posPersonne(s, c, pos, l.basPersonne) : pos[l.basFactice]! + 0.5
}

/** Une famille (ou un factice) vue d'une bande : sa descente, ses chutes, son étendue. */
interface Article {
  /** La position de la descente (le point d'union, ou le factice). */
  d: number
  /** Les positions des chutes, triées. */
  bas: number[]
  lo: number
  hi: number
  /** La famille, ou −1 pour un factice. */
  famille: number
}

/** Le nombre de valeurs de `tri` (trié) strictement dans `]lo, hi[`. */
function dansOuvert(tri: readonly number[], lo: number, hi: number): number {
  let a = 0
  let b = tri.length
  while (a < b) {
    const m = (a + b) >> 1
    if (tri[m]! <= lo) a = m + 1
    else b = m
  }
  let n = 0
  for (let i = a; i < tri.length && tri[i]! < hi; i++) n++
  return n
}

/**
 * Les croisements d'une bande COMME LES TRACE LE ROUTAGE : une famille n'est pas un faisceau
 * de segments obliques (le compte de Sugiyama), mais une descente, une barre horizontale sur
 * sa piste, et des chutes. Deux familles dont les étendues se recouvrent se croisent alors
 * selon l'ordre de leurs pistes — p au-dessus de q : les chutes de p dans l'étendue de q, et
 * la descente de q dans celle de p — et le routage prend le meilleur des deux (`router`,
 * « l'ordre des pistes »). Deux fratries emboîtées ne se croisent ainsi qu'UNE fois, quand le
 * compte oblique en voyait le produit de leurs liens ; et l'union de deux époux séparés (un
 * détour sous les cartes qui les séparent) coupe chaque descente d'entre eux, ce que le compte
 * oblique ne voyait pas du tout.
 */
function compterBande(s: Structure, c: Couches, pos: Float64Array, k: number): number {
  const parHaut = new Map<number, Article>()
  for (const l of c.liens[k]!) {
    const cle = cleDuHaut(l)
    let a = parHaut.get(cle)
    if (!a) {
      const d = posHaut(s, c, pos, l)
      a = { d, bas: [], lo: d, hi: d, famille: l.hautFamille }
      parHaut.set(cle, a)
    }
    const b = posBas(s, c, pos, l)
    a.bas.push(b)
    if (b < a.lo) a.lo = b
    if (b > a.hi) a.hi = b
  }
  const articles = [...parHaut.values()]
  for (const a of articles) a.bas.sort((x, y) => x - y)
  articles.sort((a, b) => a.lo - b.lo)
  let n = 0
  const actifs: Article[] = []
  for (const q of articles) {
    for (let i = actifs.length - 1; i >= 0; i--) if (actifs[i]!.hi <= q.lo) actifs.splice(i, 1)
    for (const p of actifs) {
      const pq = dansOuvert(p.bas, q.lo, q.hi) + (q.d > p.lo && q.d < p.hi ? 1 : 0)
      const qp = dansOuvert(q.bas, p.lo, p.hi) + (p.d > q.lo && p.d < q.hi ? 1 : 0)
      n += Math.min(pq, qp)
    }
    actifs.push(q)
  }
  // Les détours de la rangée, et les descentes d'entre leurs deux bouts.
  const r = c.rangees[k]!
  const descentes = articles.map((a) => a.d).sort((x, y) => x - y)
  for (const dt of detoursDeRangee(s, c, r)) {
    const [lo, hi] = etendueDuDetour(s, c, pos, dt)
    let dedans = dansOuvert(descentes, lo, hi)
    const propre = parHaut.get(dt.famille)
    if (propre && propre.d > lo && propre.d < hi) dedans--
    n += dedans + dt.poids * entre(lo, hi)
  }
  return n
}

/**
 * Un DÉTOUR : un trait du couloir sous une rangée qui part de deux points de cette rangée,
 * vers le bas, sans rien y chuter — l'union de deux époux que des cartes séparent (elle
 * contourne par le dessous), ou le lien d'un enfant posé sur la rangée même de ses parents
 * (il descend dans le couloir et remonte sous sa carte). Chaque descente d'entre ses deux
 * bouts le coupe (on le met au-dessus des barres : il ne croise alors que les descentes).
 */
interface Detour {
  famille: number
  /** L'enfant remonté, ou −1 pour une union. */
  enfant: number
  /**
   * Ce que coûte chaque élément posé entre les deux bouts, en croisements : 0, sauf pour les
   * couples TÊTES DE QUART (voir `POIDS_TETES`).
   */
  poids: number
}

/**
 * Les couples TÊTES DE QUART — les grands-parents de l'ego (Sosa 4 à 7), les parents et
 * grands-parents du conjoint — sont, par construction des colonnes, à cheval sur deux
 * rectangles voisins, et c'est là que les quarts se rejoignent : leur union doit être droite,
 * les deux époux face à face. Le seul compte de croisements laissait des cousins SANS ENFANTS
 * posés se glisser entre eux (aucune descente à couper — mais l'union contournait 22 cartes
 * par le dessous, relevé sur l'arbre réel). Chaque élément entre eux coûte donc deux
 * croisements : assez pour qu'ils restent face à face, sans les épingler à leur bord (épinglés,
 * ils coûtaient 7 % de croisements sur un arbre synthétique de 10 000 personnes).
 */
export const POIDS_TETES = 2

/** Les éléments posés strictement entre deux positions ordinales (ceux qui séparent les deux bouts). */
function entre(lo: number, hi: number): number {
  return Math.max(0, Math.floor(hi) - Math.floor(lo) - 1)
}

function etendueDuDetour(s: Structure, c: Couches, pos: Float64Array, dt: Detour): [number, number] {
  if (dt.enfant >= 0) {
    const a = posFamille(s, c, pos, dt.famille)
    const b = posPersonne(s, c, pos, dt.enfant)
    return a < b ? [a, b] : [b, a]
  }
  let lo = Infinity
  let hi = -Infinity
  for (const e of epouxDeLaRangee(s, dt.famille)) {
    const p = posPersonne(s, c, pos, e)
    if (p < lo) lo = p
    if (p > hi) hi = p
  }
  return [lo, hi]
}

/** Les détours de la rangée `r`. */
const DETOURS = new WeakMap<Couches, Map<number, Detour[]>>()
function detoursDeRangee(s: Structure, c: Couches, r: number): Detour[] {
  let parRangee = DETOURS.get(c)
  if (!parRangee) {
    const m = new Map<number, Detour[]>()
    const ajouter = (rf: number, dt: Detour): void => {
      const l = m.get(rf)
      if (l) l.push(dt)
      else m.set(rf, [dt])
    }
    const conjointDirect = new Set([...s.sosaConjoint].filter(([n]) => n <= 7).map(([, i]) => i))
    const tete = (i: number): boolean => (s.sosa[i] !== null && s.sosa[i]! <= 7) || conjointDirect.has(i)
    s.familles.forEach((f, k) => {
      const ep = epouxDeLaRangee(s, k)
      if (!ep.length) return
      const rf = s.rangee[ep[0]!]!
      if (ep.length >= 2 && new Set(ep.map((e) => s.foyerDe[e]!)).size >= 2)
        ajouter(rf, { famille: k, enfant: -1, poids: f.epoux.length === ep.length && ep.every(tete) ? POIDS_TETES : 0 })
      for (const x of f.enfants) if (s.rangee[x] === rf) ajouter(rf, { famille: k, enfant: x, poids: 0 })
    })
    DETOURS.set(c, m)
    parRangee = m
  }
  return parRangee.get(r) ?? []
}

/** Les éléments dont la position tend un détour. */
function elementsDuDetour(s: Structure, c: Couches, dt: Detour): number[] {
  const els = new Set(epouxDeLaRangee(s, dt.famille).map((e) => c.elementDuFoyer[s.foyerDe[e]!]!))
  if (dt.enfant >= 0) els.add(c.elementDuFoyer[s.foyerDe[dt.enfant]!]!)
  return [...els]
}

/** Les croisements de toute la disposition, comme les trace le routage (`compterBande`). */
export function compterBus(s: Structure, c: Couches): number {
  const pos = positions(c)
  let n = 0
  for (let k = 0; k < c.liens.length; k++) n += compterBande(s, c, pos, k)
  return n
}

export function compterTout(s: Structure, c: Couches): number {
  const pos = positions(c)
  let n = 0
  for (const bande of c.liens) {
    if (bande.length < 2) continue
    n += compterCroisements(bande.map((l) => ({ haut: posHaut(s, c, pos, l), bas: posBas(s, c, pos, l) })))
  }
  return n
}

/* ------------------------------------------------------------------ */
/* L'amélioration                                                      */
/* ------------------------------------------------------------------ */

/** Pour chaque élément, ses liens vers le haut et vers le bas. */
interface Voisinage {
  haut: Lien[][]
  bas: Lien[][]
}

function voisinage(s: Structure, c: Couches): Voisinage {
  const haut: Lien[][] = c.elements.map(() => [])
  const bas: Lien[][] = c.elements.map(() => [])
  const elementDe = (i: number): number => c.elementDuFoyer[s.foyerDe[i]!]!
  for (const bande of c.liens) {
    for (const l of bande) {
      // Vers le bas : depuis le foyer de chaque époux de la rangée (une union entre deux foyers
      // compte pour les deux), du factice, ou de l'époux d'une rangée plus haute.
      if (l.hautPersonne >= 0) bas[elementDe(l.hautPersonne)]!.push(l)
      else if (l.hautFamille >= 0) {
        const els = new Set(epouxDeLaRangee(s, l.hautFamille).map(elementDe))
        for (const e of els) bas[e]!.push(l)
      } else bas[l.hautFactice]!.push(l)
      if (l.basPersonne >= 0) haut[elementDe(l.basPersonne)]!.push(l)
      else haut[l.basFactice]!.push(l)
    }
  }
  return { haut, bas }
}

/** Le nombre de paires (a dans A, b dans B) avec a > b ; A et B triés. */
function inversions(A: readonly number[], B: readonly number[]): number {
  let n = 0
  let j = 0
  for (const a of A) {
    while (j < B.length && B[j]! < a) j++
    n += j
  }
  return n
}

/* ------------------------------------------------------------------ */
/* Le compte en bus, tenu à jour mouvement après mouvement             */
/* ------------------------------------------------------------------ */

/** La géométrie ordinale d'un article (une famille ou un factice, vu de sa bande). */
interface Geo {
  d: number
  bas: number[]
  lo: number
  hi: number
}

/** Les croisements de deux articles d'une même bande, au meilleur ordre de leurs pistes. */
function paire(p: Geo, q: Geo): number {
  if (p.hi <= q.lo || q.hi <= p.lo) return 0
  const pq = dansOuvert(p.bas, q.lo, q.hi) + (q.d > p.lo && q.d < p.hi ? 1 : 0)
  const qp = dansOuvert(q.bas, p.lo, p.hi) + (p.d > q.lo && p.d < q.hi ? 1 : 0)
  return pq < qp ? pq : qp
}

/**
 * Le compte en bus (`compterBande`), tenu à jour pour des mouvements LOCAUX : un échange
 * de blocs voisins, de deux frères, le retournement d'un foyer. Un tel mouvement ne déplace
 * des éléments que dans un intervalle `R` de leur rangée, et chaque point attaché à un
 * élément déplacé (une descente, une chute, le bout d'un détour) reste dans `R` ; les points
 * de la même rangée attachés aux autres éléments sont hors de `R`. Mais l'étendue d'un
 * article mêle deux rangées (sa descente en haut, ses chutes en bas) : un point de l'AUTRE
 * rangée peut tomber, en position, dans `R`. Ne changent donc que :
 *
 *  - les paires de deux articles qui bougent ;
 *  - les paires d'un article qui bouge et d'un « témoin » — un article immobile dont un point
 *    de l'autre rangée tombe dans `R` (sa descente, pour la bande du dessus ; une chute,
 *    pour celle du dessous) —, ou d'un article « libre », dont la descente est le milieu
 *    d'époux posés dans plusieurs éléments (elle peut tomber dans `R` sans y être
 *    attachée) ; un article libre qui bouge, lui, se recompte contre toute sa bande ;
 *  - les détours tendus par un élément déplacé, contre les descentes qui bougent et celles
 *    des articles libres ; et tous les détours de sa bande, contre un article libre qui bouge.
 *
 * Les témoins se cherchent dans deux index de la rangée en cours (les descentes de la bande
 * du dessus, les chutes de celle du dessous), qu'un mouvement de cette rangée ne change pas.
 *
 * Le compte reste EXACT — `ordre.test.ts` le confronte à `compterBus` après une suite de
 * mouvements tirés au hasard (échanges, retournements, annulations).
 */
export class CompteEnBus {
  /** Les positions ordinales courantes (les mouvements les modifient). */
  readonly pos: Float64Array
  /** Le compte courant. */
  total = 0
  private readonly articles: { bande: number; liens: Lien[]; famille: number; libre: boolean }[] = []
  private readonly parBande: number[][]
  private readonly libresDeBande: number[][]
  private readonly dependances: number[][]
  private readonly geo: Geo[]
  private readonly detours: (Detour & { bande: number })[] = []
  private readonly detoursDeBande: number[][]
  /** Par élément : les détours dont il tend un bout. */
  private readonly detoursDe: number[][]
  /** Par bande : les détours d'un enfant remonté dont la famille est libre (un bout qui n'est attaché à aucun élément). */
  private readonly detoursLibresDeBande: number[][]
  private readonly marque: Int32Array
  private tampon = 0
  private dernier: number[] = []
  private ecart = 0
  private readonly indiceDeRangee: Map<number, number>
  /** La rangée dont les index des témoins sont prêts (−1 : aucune). */
  private rangeeCourante = -1
  /** Les descentes de la bande du dessus, et les chutes de celle du dessous, triées. */
  private indexHaut: { x: number; id: number }[] = []
  private indexBas: { x: number; id: number }[] = []

  constructor(
    private readonly s: Structure,
    private readonly c: Couches,
  ) {
    this.pos = positions(c)
    this.parBande = c.liens.map(() => [])
    const elementDe = (i: number): number => c.elementDuFoyer[s.foyerDe[i]!]!
    c.liens.forEach((bande, k) => {
      const parHaut = new Map<number, number>()
      for (const l of bande) {
        const cle = cleDuHaut(l)
        let id = parHaut.get(cle)
        if (id === undefined) {
          id = this.articles.length
          const libre = l.hautFamille >= 0 && new Set(epouxDeLaRangee(s, l.hautFamille).map(elementDe)).size > 1
          this.articles.push({ bande: k, liens: [], famille: l.hautFamille, libre })
          this.parBande[k]!.push(id)
          parHaut.set(cle, id)
        }
        this.articles[id]!.liens.push(l)
      }
    })
    this.dependances = c.elements.map(() => [])
    this.articles.forEach((a, id) => {
      const els = new Set<number>()
      if (a.famille >= 0) for (const e of epouxDeLaRangee(s, a.famille)) els.add(elementDe(e))
      for (const l of a.liens) {
        if (l.hautFactice >= 0) els.add(l.hautFactice)
        if (l.hautPersonne >= 0) els.add(elementDe(l.hautPersonne))
        els.add(l.basPersonne >= 0 ? elementDe(l.basPersonne) : l.basFactice)
      }
      for (const e of els) this.dependances[e]!.push(id)
    })
    this.libresDeBande = this.parBande.map((l) => l.filter((id) => this.articles[id]!.libre))
    this.detoursDeBande = c.liens.map(() => [])
    this.detoursLibresDeBande = c.liens.map(() => [])
    this.detoursDe = c.elements.map(() => [])
    c.rangees.forEach((r, k) => {
      for (const detour of detoursDeRangee(s, c, r)) {
        const dt = this.detours.length
        this.detoursDeBande[k]!.push(dt)
        this.detours.push({ ...detour, bande: k })
        for (const e of elementsDuDetour(s, c, detour)) this.detoursDe[e]!.push(dt)
        if (detour.enfant >= 0 && new Set(epouxDeLaRangee(s, detour.famille).map(elementDe)).size > 1) this.detoursLibresDeBande[k]!.push(dt)
      }
    })
    this.geo = this.articles.map(() => ({ d: 0, bas: [], lo: 0, hi: 0 }))
    this.marque = new Int32Array(this.articles.length)
    this.indiceDeRangee = new Map(c.rangees.map((r, k) => [r, k]))
    this.recalculer()
  }

  /** Les index des témoins pour les mouvements de la rangée `k` (indice dans `rangees`). */
  private preparerRangee(k: number): void {
    this.rangeeCourante = k
    this.indexHaut = k > 0 ? this.parBande[k - 1]!.map((id) => ({ x: this.geo[id]!.d, id })) : []
    this.indexHaut.sort((a, b) => a.x - b.x)
    this.indexBas = []
    for (const id of this.parBande[k] ?? []) for (const x of this.geo[id]!.bas) this.indexBas.push({ x, id })
    this.indexBas.sort((a, b) => a.x - b.x)
  }

  /** Les articles d'un index dont le point tombe dans `[lo, hi[`. */
  private static dans(index: readonly { x: number; id: number }[], lo: number, hi: number, sortie: number[]): void {
    let a = 0
    let b = index.length
    while (a < b) {
      const m = (a + b) >> 1
      if (index[m]!.x < lo) a = m + 1
      else b = m
    }
    for (let i = a; i < index.length && index[i]!.x < hi; i++) sortie.push(index[i]!.id)
  }

  private calculer(id: number): void {
    const a = this.articles[id]!
    const g = this.geo[id]!
    g.d = posHaut(this.s, this.c, this.pos, a.liens[0]!)
    g.bas = a.liens.map((l) => posBas(this.s, this.c, this.pos, l)).sort((x, y) => x - y)
    g.lo = Math.min(g.d, g.bas[0]!)
    g.hi = Math.max(g.d, g.bas[g.bas.length - 1]!)
  }

  /** Tout recalculer, après un mouvement global (un balayage) qui a refait l'ordre. */
  recalculer(): void {
    this.pos.set(positions(this.c))
    for (let id = 0; id < this.articles.length; id++) this.calculer(id)
    this.total = compterBus(this.s, this.c)
    this.rangeeCourante = -1
  }

  private etendue(detour: number): [number, number] {
    return etendueDuDetour(this.s, this.c, this.pos, this.detours[detour]!)
  }

  /** Une descente dans le détour `dt` (hors celle de la famille du détour) ? */
  private dansLeDetour(dt: number, lo: number, hi: number, article: number): number {
    if (this.articles[article]!.famille === this.detours[dt]!.famille) return 0
    const d = this.geo[article]!.d
    return d > lo && d < hi ? 1 : 0
  }

  /** La part du compte que le mouvement peut changer (voir l'en-tête). */
  private local(aff: readonly number[], temoins: readonly number[], tendus: readonly number[]): number {
    const { articles, geo, marque, tampon } = this
    let n = 0
    for (let i = 0; i < aff.length; i++) {
      const a = articles[aff[i]!]!
      const ga = geo[aff[i]!]!
      for (let j = i + 1; j < aff.length; j++) if (articles[aff[j]!]!.bande === a.bande) n += paire(ga, geo[aff[j]!]!)
      // Contre les articles qui ne bougent pas : toute la bande pour un libre ; sinon, les
      // témoins et les libres (les témoins libres sont marqués `-tampon` : comptés une fois).
      if (a.libre) {
        for (const p of this.parBande[a.bande]!) if (marque[p] !== tampon) n += paire(ga, geo[p]!)
      } else {
        for (const p of temoins) if (articles[p]!.bande === a.bande) n += paire(ga, geo[p]!)
        for (const p of this.libresDeBande[a.bande]!) if (marque[p] !== tampon && marque[p] !== -tampon) n += paire(ga, geo[p]!)
      }
      // Un libre qui bouge, contre tous les détours de sa bande (les tendus se comptent plus bas).
      if (a.libre)
        for (const dt of this.detoursDeBande[a.bande]!) {
          if (tendus.includes(dt)) continue
          const [lo, hi] = this.etendue(dt)
          n += this.dansLeDetour(dt, lo, hi, aff[i]!)
        }
    }
    // Les détours tendus : contre les descentes qui bougent, et celles des libres de la bande ;
    // et les éléments d'entre leurs bouts (un mouvement qui n'en tend aucun bout ne fait pas
    // entrer ni sortir d'élément : l'intervalle déplacé est tout dedans ou tout dehors).
    for (const dt of tendus) {
      const [lo, hi] = this.etendue(dt)
      const { bande: k, poids } = this.detours[dt]!
      for (const a of aff) if (articles[a]!.bande === k) n += this.dansLeDetour(dt, lo, hi, a)
      for (const p of this.libresDeBande[k]!) if (marque[p] !== tampon) n += this.dansLeDetour(dt, lo, hi, p)
      n += poids * entre(lo, hi)
    }
    return n
  }

  /**
   * Essaie un mouvement : `appliquer` déplace les éléments `bouges` (positions, retournement),
   * et l'on rend la variation du compte, le mouvement appliqué. Puis `garder()`, ou
   * `annuler(defaire)`.
   */
  varier(bouges: readonly number[], appliquer: () => void): number {
    const k = this.indiceDeRangee.get(this.c.elements[bouges[0]!]!.rangee)!
    if (k !== this.rangeeCourante) this.preparerRangee(k)
    this.tampon++
    const { marque, tampon } = this
    const aff: number[] = []
    const tendus: number[] = []
    let lo = Infinity
    let hi = -Infinity
    for (const e of bouges) {
      const p = this.pos[e]!
      if (p < lo) lo = p
      if (p + 1 > hi) hi = p + 1
      for (const id of this.dependances[e]!) {
        if (marque[id] === tampon) continue
        marque[id] = tampon
        aff.push(id)
      }
      for (const dt of this.detoursDe[e]!) if (!tendus.includes(dt)) tendus.push(dt)
    }
    // Un détour dont un bout n'est attaché à aucun élément peut tomber dans [lo, hi[ : tendu.
    for (const id of aff) for (const dt of this.detoursLibresDeBande[this.articles[id]!.bande]!) if (!tendus.includes(dt)) tendus.push(dt)
    // Les témoins : immobiles, un point de l'autre rangée dans [lo, hi[.
    const candidats: number[] = []
    CompteEnBus.dans(this.indexHaut, lo, hi, candidats)
    CompteEnBus.dans(this.indexBas, lo, hi, candidats)
    const temoins: number[] = []
    for (const id of candidats) {
      if (marque[id] === tampon || marque[id] === -tampon) continue
      marque[id] = -tampon
      temoins.push(id)
    }
    const avant = this.local(aff, temoins, tendus)
    appliquer()
    for (const id of aff) this.calculer(id)
    this.dernier = aff
    this.ecart = this.local(aff, temoins, tendus) - avant
    return this.ecart
  }

  garder(): void {
    this.total += this.ecart
    this.ecart = 0
  }

  annuler(defaire: () => void): void {
    defaire()
    for (const id of this.dernier) this.calculer(id)
    this.ecart = 0
  }
}

/* ------------------------------------------------------------------ */
/* L'affinage qui garde les familles d'un seul tenant                  */
/* ------------------------------------------------------------------ */

/**
 * Le barycentre et les transpositions libres retirent des croisements, mais au prix de
 * fratries coupées : un cousin glissé entre deux frères, dont le bus doit alors passer
 * par-dessus (mesuré sur l'arbre réel : 822 → 578 croisements, mais 49 → 83 fratries
 * coupées). Ici, des mouvements qui gardent chaque fratrie d'un seul tenant :
 *
 *  - le barycentre par BLOCS — un bloc est une suite d'éléments liés par la fratrie
 *    (frères et sœurs, et les foyers qui collent deux fratries, un couple d'ancêtres),
 *    rangé au milieu de ses liens, son ordre intérieur gardé ;
 *  - échanger deux blocs voisins ;
 *  - échanger deux frères et sœurs voisins, enfants des mêmes parents et de nul autre ;
 *  - retourner un foyer.
 *
 * Ce qu'on minimise : les croisements COMME LES TRACE LE ROUTAGE (`compterBande` — des
 * barres de fratrie sur des pistes, des unions qui contournent), puis, à égalité, le compte
 * oblique de Sugiyama (qui préfère, entre deux dessins aussi croisés, celui dont les liens
 * restent parallèles). Le compte oblique seul se trompait de cible : deux fratries
 * emboîtées lui coûtaient le produit de leurs liens (une seule barre en croise une autre,
 * au dessin), et glisser des cousins entre deux grands-parents ne lui coûtait rien (l'union
 * contournait alors neuf foyers, au dessin, et coupait chacune de leurs descentes).
 * Chaque mouvement n'est gardé que s'il améliore strictement ce couple de comptes ; on
 * recommence jusqu'à ce que plus rien ne s'améliore.
 */
export function affiner(s: Structure, c: Couches, o: OptionsDOrdre, tours = 8): void {
  const v = voisinage(s, c)
  const { elements } = c
  /** Les familles des parents des membres d'un élément (une seule, en général ; deux pour un couple d'ancêtres). */
  const parentsDe: number[][] = elements.map((e) => {
    if (e.foyer < 0) return []
    const l: number[] = []
    for (const m of s.foyers[e.foyer]!.membres) for (const f of s.enfantDans[m]!) if (!l.includes(f)) l.push(f)
    return l
  })
  const lies = (a: number, b: number): boolean => {
    const pa = parentsDe[a]!
    for (const f of parentsDe[b]!) if (pa.includes(f)) return true
    return false
  }
  const freres = (a: number, b: number): boolean => {
    const pa = parentsDe[a]!
    const pb = parentsDe[b]!
    return pa.length === 1 && pb.length === 1 && pa[0] === pb[0]
  }
  const blocsDe = (l: readonly number[]): number[][] => {
    const blocs: number[][] = []
    for (const e of l) {
      const dernier = blocs[blocs.length - 1]
      const prec = dernier?.[dernier.length - 1]
      if (dernier && prec !== undefined && elements[prec]!.partie === elements[e]!.partie && lies(prec, e)) dernier.push(e)
      else blocs.push([e])
    }
    return blocs
  }

  const bus = new CompteEnBus(s, c)
  const { pos } = bus

  /** Le compte oblique entre les liens de deux ensembles d'éléments, le premier à gauche du second. */
  const cout = (A: readonly number[], B: readonly number[]): number => {
    const ha: number[] = []
    const hb: number[] = []
    const ba: number[] = []
    const bb: number[] = []
    for (const e of A) {
      for (const l of v.haut[e]!) ha.push(posHaut(s, c, pos, l))
      for (const l of v.bas[e]!) ba.push(posBas(s, c, pos, l))
    }
    for (const e of B) {
      for (const l of v.haut[e]!) hb.push(posHaut(s, c, pos, l))
      for (const l of v.bas[e]!) bb.push(posBas(s, c, pos, l))
    }
    if ((!ha.length || !hb.length) && (!ba.length || !bb.length)) return 0
    ha.sort((p, q) => p - q)
    hb.sort((p, q) => p - q)
    ba.sort((p, q) => p - q)
    bb.sort((p, q) => p - q)
    return inversions(ha, hb) + inversions(ba, bb)
  }

  /** Échange deux suites voisines `A` puis `B` d'une rangée, si les comptes s'améliorent. */
  const echanger = (A: readonly number[], B: readonly number[]): boolean => {
    const oblique = cout(B, A) - cout(A, B)
    const debut = pos[A[0]!]!
    const ecart = bus.varier([...A, ...B], () => {
      B.forEach((e, j) => (pos[e] = debut + j))
      A.forEach((e, j) => (pos[e] = debut + B.length + j))
    })
    if (ecart < 0 || (ecart === 0 && oblique < 0)) {
      bus.garder()
      return true
    }
    bus.annuler(() => {
      A.forEach((e, j) => (pos[e] = debut + j))
      B.forEach((e, j) => (pos[e] = debut + A.length + j))
    })
    return false
  }

  const echanges = (): boolean => {
    let change = false
    for (let k = 0; k < c.ordre.length; k++) {
      const l = c.ordre[k]!
      for (let tour = 0; tour < 3; tour++) {
        let bouge = false
        const blocs = blocsDe(l)
        // 1. Deux blocs voisins de la même partie.
        for (let i = 0; i + 1 < blocs.length; i++) {
          const A = blocs[i]!
          const B = blocs[i + 1]!
          if (elements[A[0]!]!.partie !== elements[B[0]!]!.partie) continue
          if (!echanger(A, B)) continue
          blocs[i] = B
          blocs[i + 1] = A
          bouge = change = true
        }
        // 2. Deux frères et sœurs voisins (sauf si l'ordre des naissances est demandé).
        if (!o.fratriesParNaissance) {
          for (const bloc of blocs) {
            for (let j = 0; j + 1 < bloc.length; j++) {
              const a = bloc[j]!
              const b = bloc[j + 1]!
              if (!freres(a, b) || !echanger([a], [b])) continue
              bloc[j] = b
              bloc[j + 1] = a
              bouge = change = true
            }
          }
        }
        l.splice(0, l.length, ...blocs.flat())
        if (!bouge) break
      }
    }
    return change
  }

  /*
   * Les époux posés dans deux foyers d'une même rangée (un couple à cheval sur deux
   * rectangles : les grands-parents, un remarié dont l'autre conjoint est d'un autre
   * quart) : leur union n'est une ligne droite que s'ils se font face, chacun au bout de
   * son foyer qui regarde l'autre. À comptes égaux, on préfère donc le foyer retourné qui
   * rapproche l'époux de son conjoint : une union droite plutôt qu'un détour sous une carte.
   */
  const externes: number[][] = s.foyers.map(() => [])
  s.familles.forEach((f, k) => {
    const fos = new Set(f.epoux.map((e) => s.foyerDe[e]!))
    if (fos.size < 2 || new Set(f.epoux.map((e) => s.rangee[e]!)).size > 1) return
    for (const fo of fos) externes[fo]!.push(k)
  })
  /** La distance (en rangs) entre chaque membre du foyer et son époux posé ailleurs. */
  const ecartement = (fo: number): number => {
    let t = 0
    for (const k of externes[fo]!) {
      const ep = s.familles[k]!.epoux
      for (const a of ep)
        for (const b of ep) if (s.foyerDe[a] === fo && s.foyerDe[b] !== fo) t += Math.abs(posPersonne(s, c, pos, a) - posPersonne(s, c, pos, b))
    }
    return t
  }

  const retourner = (): boolean => {
    let change = false
    const entreEux = (liens: readonly Lien[]): number =>
      liens.length < 2 ? 0 : compterCroisements(liens.map((l) => ({ haut: posHaut(s, c, pos, l), bas: posBas(s, c, pos, l) })))
    // Rangée par rangée (le compte en bus prépare ses index une fois par rangée).
    for (const e of c.ordre.flat()) {
      const fo = elements[e]!.foyer
      if (fo < 0 || s.foyers[fo]!.membres.length < 2) continue
      const haut = v.haut[e]!
      const bas = v.bas[e]!
      const ext = externes[fo]!.length > 0
      if (haut.length + bas.length < 2 && !ext) continue
      const obliqueAvant = entreEux(haut) + entreEux(bas)
      const ecartAvant = ext ? ecartement(fo) : 0
      const ecart = bus.varier([e], () => (c.retourne[fo] = !c.retourne[fo]))
      const oblique = entreEux(haut) + entreEux(bas) - obliqueAvant
      const rapproche = ext ? ecartement(fo) - ecartAvant : 0
      // À croisements égaux, l'union droite d'abord (l'époux face à son conjoint), puis le compte oblique.
      if (ecart < 0 || (ecart === 0 && (rapproche < -1e-9 || (Math.abs(rapproche) <= 1e-9 && oblique < 0)))) {
        bus.garder()
        change = true
      } else bus.annuler(() => (c.retourne[fo] = !c.retourne[fo]))
    }
    return change
  }

  /**
   * Le barycentre, mais par BLOCS : chaque bloc (une fratrie, des fratries collées par un
   * couple) se range au milieu de ses liens vers la rangée voisine, d'un seul tenant, son
   * ordre intérieur gardé. En descendant (les blocs sous leurs parents), puis en remontant
   * (sur leurs enfants). Un balayage n'est gardé que s'il améliore les comptes.
   */
  const balayer = (): boolean => {
    const avant = bus.total
    const obliqueAvant = compterTout(s, c)
    const sauvegarde = c.ordre.map((l) => [...l])
    const trierLaRangee = (k: number, sens: 'haut' | 'bas'): void => {
      const p = positions(c)
      const l = c.ordre[k]!
      const blocs = blocsDe(l)
      const cle = new Map<number[], number>()
      let derniere = -1e9
      for (const b of blocs) {
        let t = 0
        let n = 0
        for (const e of b)
          for (const lien of sens === 'haut' ? v.haut[e]! : v.bas[e]!) {
            t += sens === 'haut' ? posHaut(s, c, p, lien) : posBas(s, c, p, lien)
            n++
          }
        // Sans lien de ce côté : il reste derrière son voisin de gauche.
        const k2 = n ? t / n : derniere + 1e-6
        cle.set(b, k2)
        derniere = k2
      }
      const rang = new Map(blocs.map((b, i) => [b, i]))
      blocs.sort(
        (a, b) =>
          s.parties[elements[a[0]!]!.partie]!.colonne - s.parties[elements[b[0]!]!.partie]!.colonne ||
          cle.get(a)! - cle.get(b)! ||
          rang.get(a)! - rang.get(b)!,
      )
      l.splice(0, l.length, ...blocs.flat())
    }
    for (let k = 1; k < c.ordre.length; k++) trierLaRangee(k, 'haut')
    for (let k = c.ordre.length - 2; k >= 0; k--) trierLaRangee(k, 'bas')
    if (o.fratriesParNaissance) remettreLesNaissances(s, c)
    const apres = compterBus(s, c)
    if (apres < avant || (apres === avant && compterTout(s, c) < obliqueAvant)) {
      bus.recalculer()
      return true
    }
    c.ordre = sauvegarde
    return false
  }

  // Jusqu'à ce que plus rien ne s'améliore — ou presque plus : un tour qui ne retire pas un
  // demi-centième des croisements arrête (sur 10 000 personnes, les quatre derniers tours
  // retiraient 0,3 % pour le tiers du temps).
  for (let tour = 0; tour < tours; tour++) {
    const avant = bus.total
    const z = balayer()
    const a = echanges()
    const b = retourner()
    if (!a && !b && !z) break
    if (tour > 0 && avant - bus.total < avant * 0.005) break
  }
  if (o.fratriesParNaissance) remettreLesNaissances(s, c)
  c.croisements = compterBus(s, c)
}

/**
 * L'ordre des naissances, quand il est demandé, ne se négocie pas contre un croisement.
 * Les mouvements par blocs gardent l'ordre intérieur de chaque bloc, mais deux morceaux
 * d'une même fratrie (que sépare le conjoint d'un frère posé dans l'autre rectangle, par
 * exemple) peuvent s'échanger : on remet chaque fratrie dans l'ordre, sur les places
 * qu'elle occupe déjà dans la rangée — le reste ne bouge pas. Partie par partie : un frère
 * posé dans le tronc et ses frères dans le quart de leur parent ne se comparent pas.
 */
function remettreLesNaissances(s: Structure, c: Couches): void {
  for (const l of c.ordre) {
    const fratries = new Map<string, { element: number; annee: number }[]>()
    for (const e of l) {
      const el = c.elements[e]!
      if (el.foyer < 0) continue
      for (const m of s.foyers[el.foyer]!.membres) {
        const f = s.enfantDans[m]![0]
        if (f === undefined) continue
        const cle = `${f}|${el.partie}`
        const annee = anneeDe(s.personnes[m]!) ?? 9999
        const liste = fratries.get(cle)
        const deja = liste?.find((x) => x.element === e)
        if (deja) deja.annee = Math.min(deja.annee, annee)
        else if (liste) liste.push({ element: e, annee })
        else fratries.set(cle, [{ element: e, annee }])
      }
    }
    const place = new Map(l.map((e, i) => [e, i]))
    for (const liste of fratries.values()) {
      if (liste.length < 2) continue
      const places = liste.map((x) => place.get(x.element)!).sort((a, b) => a - b)
      const tri = [...liste].sort((a, b) => a.annee - b.annee || place.get(a.element)! - place.get(b.element)!)
      tri.forEach((x, k) => {
        l[places[k]!] = x.element
        place.set(x.element, places[k]!)
      })
    }
  }
}
