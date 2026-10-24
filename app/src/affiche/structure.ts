/**
 * Les deux arbres d'une affiche : l'ascendance du couple et sa descendance — portage de
 * `structure.py`.
 *
 * Pur, sans DOM : il ne mesure rien et ne place rien, il dit **qui va où** — quel ancêtre
 * occupe quelle place (sosa), quel quartier il colore, lequel est un implexe, et comment
 * chaque union de descendant a fini.
 */
import type { DateAffiche } from './dates'
import type { ArbreAffiche, Personne, Union } from './donnees'
import type { Quartier } from './palette'
import type { Bloc } from './boites'

/** Une place dans l'ascendance. Un même ancêtre peut en occuper plusieurs (implexe). */
export class Anc {
  pere: Anc | null = null
  mere: Anc | null = null
  enfant: Anc | null = null
  /** Le numéro d'implexe, partagé par les deux époux d'un couple présent deux fois. */
  implexe: number | null = null
  /** Une place secondaire d'un implexe : montrée sans ses ascendants. */
  tronque = false
  // Le placement, posé par l'éventail ou le sablier.
  a1 = 0
  a2 = 0
  minimum = 0
  x = 0
  y = 0
  w = 0
  h = 0
  bloc: Bloc | null = null

  constructor(
    readonly p: Personne,
    readonly niveau: number,
    readonly sosa: number,
    readonly quartier: Quartier | null,
  ) {}

  get parents(): Anc[] {
    return [this.pere, this.mere].filter((x): x is Anc => x !== null)
  }
}

export interface Ascendance {
  racineH: Anc
  racineF: Anc
  noeuds: Anc[]
}

const cle = (niveau: number, sosa: number): string => `${niveau}:${sosa}`
const avant = (a: readonly [number, number], b: readonly [number, number]): boolean => a[0] < b[0] || (a[0] === b[0] && a[1] < b[1])

/**
 * Les deux racines (mari, femme) au niveau 0, sosa 2 et 3 comme pour l'enfant fictif 1.
 *
 * Un ancêtre présent plusieurs fois n'est développé qu'à sa première place (niveau le plus
 * bas, puis plus petit sosa) ; les autres places le montrent sans ses ascendants.
 *
 * `profondeur` : le nombre de générations d'ascendants dessinées (1 = les parents) ; par
 * défaut, tout ce que l'arbre connaît. L'affiche d'origine n'avait pas ce réglage : la
 * coupe se fait avant tout calcul, les implexes se comptent donc sur ce qui est dessiné.
 */
export function ascendance(arbre: ArbreAffiche, h: Personne, f: Personne, profondeur = Infinity): Ascendance {
  const principale = new Map<number, readonly [number, number]>()

  const parcourir = (p: Personne, niveau: number, sosa: number): void => {
    const actuelle = principale.get(p.id)
    if (actuelle === undefined || avant([niveau, sosa], actuelle)) principale.set(p.id, [niveau, sosa])
    if (niveau >= profondeur) return
    const [pere, mere] = arbre.parents(p.id)
    if (pere) parcourir(pere, niveau + 1, 2 * sosa)
    if (mere) parcourir(mere, niveau + 1, 2 * sosa + 1)
  }
  parcourir(h, 0, 2)
  parcourir(f, 0, 3)

  const noeuds: Anc[] = []
  const construire = (p: Personne, niveau: number, sosa: number, quartier: Quartier | null, enfant: Anc | null): Anc => {
    const n = new Anc(p, niveau, sosa, quartier)
    n.enfant = enfant
    noeuds.push(n)
    if (cle(...principale.get(p.id)!) !== cle(niveau, sosa)) {
      n.tronque = true
      return n
    }
    if (niveau >= profondeur) return n
    const [pere, mere] = arbre.parents(p.id)
    if (pere) n.pere = construire(pere, niveau + 1, 2 * sosa, quartier ?? (sosa === 2 ? 'HP' : 'FP'), n)
    if (mere) n.mere = construire(mere, niveau + 1, 2 * sosa + 1, quartier ?? (sosa === 2 ? 'HM' : 'FM'), n)
    return n
  }
  const racineH = construire(h, 0, 2, null, null)
  const racineF = construire(f, 0, 3, null, null)

  // Les implexes visibles : une personne à plusieurs places dans l'arbre construit.
  const compte = new Map<number, Anc[]>()
  for (const n of noeuds) compte.set(n.p.id, [...(compte.get(n.p.id) ?? []), n])
  const premiere = (v: Anc[]): readonly [number, number] =>
    v.reduce<readonly [number, number]>((m, x) => (avant([x.niveau, x.sosa], m) ? [x.niveau, x.sosa] : m), [Infinity, Infinity])
  const multiples = [...compte.values()]
    .filter((v) => v.length > 1)
    .sort((a, b) => {
      const [pa, pb] = [premiere(a), premiere(b)]
      return pa[0] - pb[0] || pa[1] - pb[1]
    })
  const numero = new Map<number, number>()
  multiples.forEach((v, i) => numero.set(v[0]!.p.id, i + 1))
  // Les deux époux d'un couple présent deux fois partagent un seul numéro.
  for (const n of noeuds) {
    if (n.pere && n.mere && numero.has(n.pere.p.id) && numero.has(n.mere.p.id)) {
      const m = Math.min(numero.get(n.pere.p.id)!, numero.get(n.mere.p.id)!)
      numero.set(n.pere.p.id, m)
      numero.set(n.mere.p.id, m)
    }
  }
  const rangs = new Map<number, number>()
  ;[...new Set(numero.values())].sort((a, b) => a - b).forEach((v, i) => rangs.set(v, i + 1))
  for (const n of noeuds) {
    const v = numero.get(n.p.id)
    if (v !== undefined) n.implexe = rangs.get(v)!
  }
  return { racineH, racineF, noeuds }
}

/* ------------------------------------------------------------------ */
/* La descendance                                                      */
/* ------------------------------------------------------------------ */

/**
 * Comment une union a fini — ce que le trait d'union écrit au-dessus de son signe :
 * `div` (divorcés), `veuf` / `veuve` (le conjoint est mort pendant l'union et la personne
 * s'est remariée ensuite), ou rien.
 */
export type StatutDUnion = 'div' | 'veuf' | 'veuve' | null

/** Ce que le trait d'union doit dire : marié ou non, rang parmi les unions, issue. */
export interface InfoUnion {
  date: DateAffiche
  lieu: string | null
  marie: boolean
  statut: StatutDUnion
  /** Le rang de l'union parmi celles de la personne (1, 2, 3…), et leur nombre. */
  rang: number
  total: number
}

export class Desc {
  unions: [Desc | null, InfoUnion, Desc[]][] = []
  // Le placement, posé par `mesurerDesc` et `placerDesc`.
  x = 0
  y = 0
  w = 0
  h = 0
  largeurSousArbre = 0
  bloc: Bloc | null = null

  constructor(
    readonly p: Personne,
    readonly niveau: number,
  ) {}
}

/**
 * L'issue d'une union (`statut_union`, version du 22/10/2026) :
 *
 * - **divorcés** si la famille porte le divorce — un divorce suppose un mariage, il n'y a
 *   pas de « séparés » : la base ne sait pas représenter la séparation d'un couple non marié ;
 * - **veuf / veuve** (selon le sexe de la personne) si le conjoint est mort et qu'une union
 *   suit — le veuvage n'est écrit que devant un remariage, là où la succession des conjoints
 *   devient ambiguë ; et seulement si le conjoint est mort avant la personne et avant le
 *   remariage suivant, quand ces dates sont connues.
 *
 * Sur les **vraies** dates (`decesReel`, `mariageReel`) : le masque des vivants ne change
 * pas l'histoire, seulement ce qu'on en écrit.
 */
export function statutUnion(p: Personne, u: Union, suivantes: Union[]): StatutDUnion {
  if (u.divorce) return 'div'
  const c = u.conjoint
  if (!(c && suivantes.length > 0 && c.decesReel.connue)) return null
  const fin = c.decesReel.tri
  const propre = p.decesReel
  if (propre.connue && propre.tri && fin && fin >= propre.tri) return null
  const suivant = suivantes[0]!.mariageReel
  if (suivant.connue && suivant.tri && fin && fin > suivant.tri) return null
  return p.sexe === 'feminin' ? 'veuve' : 'veuf'
}

/** Les enfants du couple, puis leurs unions et leurs enfants, sur `niveaux` générations. */
export function descendance(arbre: ArbreAffiche, h: Personne, f: Personne, niveaux = 6): { fam: number | null; enfants: Desc[] } {
  const fam = arbre.familleDuCouple(h.id, f.id)
  return { fam, enfants: fam !== null ? descendanceDeFamille(arbre, fam, niveaux) : [] }
}

/** Les enfants de la famille `fam` et leur descendance — les frères et sœurs d'un époux, pour le soleil des quatre parents. */
export function descendanceDeFamille(arbre: ArbreAffiche, fam: number, niveaux = 6): Desc[] {
  const construire = (p: Personne, niveau: number): Desc => {
    const d = new Desc(p, niveau)
    if (niveau >= niveaux) return d
    const us = arbre.unions(p.id).filter((u) => u.conjoint !== null || u.enfants.length > 0)
    us.forEach((u, i) => {
      const conj = u.conjoint ? new Desc(u.conjoint, niveau) : null
      const info: InfoUnion = {
        date: u.mariage[0],
        lieu: u.mariage[1],
        marie: u.marie,
        statut: statutUnion(p, u, us.slice(i + 1)),
        rang: i + 1,
        total: us.length,
      }
      d.unions.push([conj, info, u.enfants.map((e) => construire(e, niveau + 1))])
    })
    return d
  }

  return arbre.enfants(fam).map((e) => construire(e, 1))
}

/**
 * Les descendants des quatre parents du couple, pour le soleil et l'éventail des quatre parents :
 * le couple (le mari, sa femme parmi ses conjoints, leurs enfants), les frères et sœurs du mari
 * à gauche, ceux de la femme à droite. La femme quitte sa fratrie, où elle figurerait une seconde
 * fois.
 */
export interface QuatreParents {
  couple: Desc
  /** La femme, parmi les conjoints du mari. */
  epouse: Desc | null
  gauche: Desc[]
  droite: Desc[]
}

/** Les descendants des quatre parents ; `null` quand la famille d'origine de l'un des époux manque. */
export function quatreParents(arbre: ArbreAffiche, h: Personne, f: Personne, niveaux = 6): QuatreParents | null {
  const familleH = arbre.familleDOrigine(h.id)
  const familleF = arbre.familleDOrigine(f.id)
  if (familleH === null || familleF === null) return null
  const fratrieH = descendanceDeFamille(arbre, familleH, niveaux)
  const couple = fratrieH.find((d) => d.p.id === h.id)
  if (!couple) return null
  return {
    couple,
    epouse: couple.unions.find(([c]) => c?.p.id === f.id)?.[0] ?? null,
    gauche: fratrieH.filter((d) => d !== couple),
    droite: descendanceDeFamille(arbre, familleF, niveaux).filter((d) => d.p.id !== f.id),
  }
}

/** Le nombre de descendants des quatre parents par génération — la femme comprise, posée en conjointe du mari. */
export function compterQuatreParents(q: QuatreParents): Map<number, number> {
  const parNiveau = compter([q.couple, ...q.gauche, ...q.droite])
  parNiveau.set(1, (parNiveau.get(1) ?? 0) + 1)
  return parNiveau
}

/** Le nombre de descendants par génération (1 = enfants). */
export function compter(enfants: Desc[]): Map<number, number> {
  const parNiveau = new Map<number, number>()
  const visiter = (d: Desc): void => {
    parNiveau.set(d.niveau, (parNiveau.get(d.niveau) ?? 0) + 1)
    for (const [, , enf] of d.unions) for (const e of enf) visiter(e)
  }
  for (const e of enfants) visiter(e)
  return parNiveau
}
