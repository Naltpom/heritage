/**
 * **Ce que `/arbre-review` sait de l'arbre avant d'en poser une seule fiche** : le
 * groupe de chacun, sa rangée, la lignée directe — exactement les règles de `/arbre`
 * (`arbre2/groupes.ts`, étapes 1 à 3, reprises ligne à ligne : « mêmes données ») —, puis
 * ce qui est propre à cette page : les PARTIES (les rectangles), les FOYERS (les unités
 * d'une rangée) et les familles qui les relient.
 *
 * ── Les parties : pourquoi un quart se coupe en deux ────────────────────────────
 *
 * Sur `/arbre`, chaque quart (pp, pm, mp, mm) est UNE bande verticale, de son ancêtre le
 * plus haut jusqu'aux enfants de ses cousins. Le tronc est entre pm et mp. Or le père,
 * dans le tronc, est l'enfant du couple pp–pm, posé à la frontière des deux bandes : le
 * lien qui descend vers lui doit traverser toute la bande pm, à la hauteur des parents
 * (mesuré sur l'arbre réel : un trait de ~40 000 px de long au travers d'un rectangle).
 * Ce n'est pas un défaut de réglage, c'est de la topologie : le couple des grands-parents
 * paternels et celui des maternels sont séparés par la largeur de deux ascendances, et le
 * père et la mère, eux, sont côte à côte.
 *
 * Ici chaque quart se coupe à la rangée de sa tête (les grands-parents) : au-dessus, son
 * ASCENDANCE ; en dessous, sa DESCENDANCE (oncles, cousins, leurs enfants). Entre les deux
 * passe un couloir libre, sur toute la largeur de l'arbre : c'est là que le père rejoint
 * ses parents, la mère les siens, sans entrer dans aucun rectangle. Le tronc s'insère dans
 * la bande du bas. Les quarts du conjoint se coupent de même, une rangée plus bas (ses
 * parents sont dans ses quarts, à lui) ; le tronc se met alors contre eux, pour que le
 * conjoint rejoigne ses parents par leur couloir.
 *
 * Deux parties qui partagent une rangée sont posées l'une à côté de l'autre, dans l'ordre
 * des COLONNES ; deux parties sans rangée commune (l'ascendance et la descendance d'un même
 * quart) peuvent se superposer : la descendance est sous son ascendance.
 *
 * ── Les familles par alliance ───────────────────────────────────────────────────
 *
 * Rangées à part dans un rectangle au bout de l'arbre, elles tirent un lien vers leur pont
 * au travers de tout ce qui les en sépare. Ici, elles sont TOUJOURS posées auprès de leur
 * pont (au-dessus de lui, comme une ascendance) ; « à part » ne change que leur teinte (le
 * gris des fiches hors branche) et le bandeau, comme sur `/arbre`.
 *
 * Pur, sans DOM.
 */
import { indexer } from '@/components/arbre/graphe'
import type { Branche, Graphe, NoeudFamille, NoeudPersonne } from '@/lib/api'
import { classerParente, type ClasseDeParente } from '@/histoire/alliances'
import { brancheDeRattachement } from '@/histoire/branches'
import { noeudsSansLien } from '@/histoire/sansLien'
import { anneeDe } from '@/histoire/lignees'
import type { CleDeGroupe } from '@/arbre2/groupes'

/* ------------------------------------------------------------------ */
/* Ce que la préparation rend                                          */
/* ------------------------------------------------------------------ */

export interface FamilleR {
  id: string
  noeud: NoeudFamille
  /** Les époux (indices de personnes), dans l'ordre du graphe. */
  epoux: number[]
  enfants: number[]
}

/** Une partie : un rectangle de la page, d'un groupe et d'une tranche de rangées. */
export interface Partie {
  cle: string
  groupe: CleDeGroupe
  /** `haut` : l'ascendance d'un quart ; `bas` : sa descendance ; `entier` sinon. */
  tranche: 'haut' | 'bas' | 'entier'
  /**
   * La bande où la partie se range, d'après ses rangées et la coupe de son groupe :
   * au-dessus du couloir des grands-parents (`haut`), en dessous (`bas`), ou des deux
   * côtés (`pleine` — un quart sans descendance récente, les personnes sans lien).
   */
  bande: 'haut' | 'bas' | 'pleine'
  /** L'ordre de gauche à droite parmi les parties qui partagent une rangée. */
  colonne: number
  /** Rangées extrêmes occupées. */
  min: number
  max: number
  membres: number[]
}

export interface Foyer {
  /** Indice du foyer. */
  id: number
  rangee: number
  partie: number
  /** Les personnes, de gauche à droite. */
  membres: number[]
  /** Largeur totale, écarts internes compris. */
  largeur: number
  /** Le centre de chaque membre, depuis le bord gauche du foyer. */
  decal: number[]
  /** Les familles dont le point d'union est dans ce foyer (tous les époux dedans). */
  familles: number[]
}

export interface Structure {
  graphe: Graphe
  ego: string
  egoIndex: number
  personnes: NoeudPersonne[]
  indexDe: Map<string, number>
  familles: FamilleR[]
  /** Par personne : les familles où elle est époux, où elle est enfant. */
  epouxDans: number[][]
  enfantDans: number[][]
  /** Le groupe affiché (celui de `/arbre`, alliances « à part » comprises). */
  groupe: CleDeGroupe[]
  /** Le groupe où la personne se POSE (une alliance : celui de son pont). */
  groupeDePose: CleDeGroupe[]
  rangee: number[]
  /** Numéro de Sosa (lignée directe de l'ego), ou `null`. */
  sosa: (number | null)[]
  famillesDirectes: Set<number>
  /** Sosa → personne, pour l'ascendance du conjoint dont le serveur a découpé les quarts. */
  sosaConjoint: Map<number, number>
  /** Sosa → personne, pour l'ego. */
  sosaEgo: Map<number, number>
  classes: Map<string, ClasseDeParente>
  rattachement: (id: string) => Branche | null
  isoles: Set<string>
  alliancesDansLArbre: boolean
  hauteurFiche: number
  largeurDeCarte: number
  parties: Partie[]
  partieDe: number[]
  foyers: Foyer[]
  foyerDe: number[]
  /** Position du membre dans son foyer. */
  rangDansFoyer: number[]
}

export interface OptionsDePreparation {
  alliances?: 'a-part' | 'dans-l-arbre' | undefined
  /** L'écart entre deux époux d'un même foyer. */
  ecartCouple: number
}

const QUARTS_EGO = new Set<string>(['pp', 'pm', 'mp', 'mm'])
const QUARTS_CONJOINT = new Set<string>(['cpp', 'cpm', 'cmp', 'cmm'])
const QUARTS = new Set<string>([...QUARTS_EGO, ...QUARTS_CONJOINT])

/* ------------------------------------------------------------------ */
/* La préparation                                                      */
/* ------------------------------------------------------------------ */

export function preparer(graphe: Graphe, options: OptionsDePreparation): Structure {
  const index = indexer(graphe)
  const personnes = index.personnes as NoeudPersonne[]
  const indexDe = new Map<string, number>()
  personnes.forEach((p, i) => indexDe.set(p.id, i))
  const n = personnes.length

  /* Les familles, et qui y est époux ou enfant (comme `disposerEnGroupes`). */
  const familles: FamilleR[] = []
  const epouxDans: number[][] = personnes.map(() => [])
  const enfantDans: number[][] = personnes.map(() => [])
  for (const f of index.familles) {
    const enfantsIds = (index.enfantsParFamille.get(f.id) ?? []).filter((id) => indexDe.has(id))
    const deja = new Set(enfantsIds)
    const epouxIds = (index.membresParFamille.get(f.id) ?? []).filter((id) => !deja.has(id) && indexDe.has(id))
    const k = familles.length
    const fr: FamilleR = { id: f.id, noeud: f, epoux: epouxIds.map((id) => indexDe.get(id)!), enfants: enfantsIds.map((id) => indexDe.get(id)!) }
    familles.push(fr)
    for (const e of fr.enfants) enfantDans[e]!.push(k)
    for (const e of fr.epoux) epouxDans[e]!.push(k)
  }
  const epouxDe = (f: number): readonly number[] => familles[f]!.epoux
  const enfantsDe = (f: number): readonly number[] => familles[f]!.enfants

  const ego = indexDe.has(graphe.ego) ? graphe.ego : (personnes[0]?.id ?? graphe.ego)
  const egoIndex = indexDe.get(ego) ?? -1
  const alliancesDansLArbre = options.alliances === 'dans-l-arbre'

  /* 1. Le groupe de chacun — la règle de `arbre2/groupes.ts`, à l'identique. */
  const isoles = indexDe.has(graphe.ego) ? noeudsSansLien(graphe) : new Set<string>()
  const classes = classerParente(graphe)
  const rattachement = brancheDeRattachement(graphe)
  const versGroupe = (b: Branche | null | undefined): CleDeGroupe | null =>
    b === 'tr' ? 'tr' : b && QUARTS.has(b) ? (b as CleDeGroupe) : null
  const calculerGroupes = (alliancesAuPont: boolean): CleDeGroupe[] => {
    const groupe: (CleDeGroupe | null)[] = new Array(n).fill(null)
    const sansGroupe: number[] = []
    for (let i = 0; i < n; i++) {
      const id = personnes[i]!.id
      let g: CleDeGroupe | null
      if (id === ego) g = 'tr'
      else if (isoles.has(id)) g = 'sansLien'
      else if (classes.get(id)?.parente === 'alliance') g = alliancesAuPont ? versGroupe(rattachement(id)) : 'alliances'
      else g = versGroupe(personnes[i]!.branch)
      if (g === null) sansGroupe.push(i)
      else groupe[i] = g
    }
    // Une personne sans branche (une proposée) prend le groupe de qui la rattache :
    // le conjoint d'abord, puis les parents et les enfants, de proche en proche.
    for (let passe = 0, bouge = true; bouge && sansGroupe.length && passe <= n; passe++) {
      bouge = false
      const rattacher = (voisins: (i: number) => number[]): void => {
        for (let k = sansGroupe.length - 1; k >= 0; k--) {
          const i = sansGroupe[k]!
          const g = voisins(i)
            .map((v) => groupe[v])
            .find((x): x is CleDeGroupe => x !== null && x !== undefined && x !== 'sansLien' && x !== 'alliances')
          if (g === undefined) continue
          groupe[i] = g
          sansGroupe.splice(k, 1)
          bouge = true
        }
      }
      rattacher((i) => epouxDans[i]!.flatMap((f) => epouxDe(f)).filter((c) => c !== i))
      rattacher((i) => [...enfantDans[i]!.flatMap((f) => epouxDe(f)), ...epouxDans[i]!.flatMap((f) => enfantsDe(f))])
    }
    for (const i of sansGroupe) groupe[i] = 'tr'
    return groupe as CleDeGroupe[]
  }
  const groupe = calculerGroupes(alliancesDansLArbre)
  // Pour POSER : une alliance va dans le groupe de son pont, même « à part ».
  const groupeDePose = alliancesDansLArbre ? groupe : calculerGroupes(true)

  /* 2. Les rangées : une génération par rangée, depuis l'ego (0 ; −1 ses parents). */
  const rangeeDe = new Map<string, number>()
  const parcourir = (depart: string, g0: number, garder: (id: string) => boolean): string[] => {
    const atteints = [depart]
    rangeeDe.set(depart, g0)
    for (let k = 0; k < atteints.length; k++) {
      const id = atteints[k]!
      const i = indexDe.get(id)!
      const g = rangeeDe.get(id)!
      const voisins: [string, number][] = []
      for (const f of epouxDans[i]!) {
        for (const c of epouxDe(f)) voisins.push([personnes[c]!.id, g])
        for (const c of enfantsDe(f)) voisins.push([personnes[c]!.id, g + 1])
      }
      for (const f of enfantDans[i]!) for (const c of epouxDe(f)) voisins.push([personnes[c]!.id, g - 1])
      for (const [v, gv] of voisins) {
        if (rangeeDe.has(v) || !garder(v)) continue
        rangeeDe.set(v, gv)
        atteints.push(v)
      }
    }
    return atteints
  }
  if (indexDe.has(ego)) parcourir(ego, 0, (id) => !isoles.has(id))
  for (const p of personnes) if (!rangeeDe.has(p.id) && !isoles.has(p.id)) parcourir(p.id, p.generation, (id) => !isoles.has(id))
  // Les personnes sans lien : leur rangée s'estime d'après leurs dates (la règle de `/arbre`).
  if (isoles.size) {
    const points: [number, number][] = []
    for (const [id, g] of rangeeDe) {
      const an = anneeDe(personnes[indexDe.get(id)!]!)
      if (an !== null) points.push([g, an])
    }
    let a = 1990
    let b = 28
    if (points.length) {
      const mx = points.reduce((t, p) => t + p[0], 0) / points.length
      const my = points.reduce((t, p) => t + p[1], 0) / points.length
      const sxx = points.reduce((t, p) => t + (p[0] - mx) ** 2, 0)
      const sxy = points.reduce((t, p) => t + (p[0] - mx) * (p[1] - my), 0)
      if (sxx > 0 && sxy / sxx > 10) b = sxy / sxx
      a = my - b * mx
    }
    for (const p of personnes) {
      if (rangeeDe.has(p.id) || !isoles.has(p.id)) continue
      const ilot = parcourir(p.id, 0, (id) => isoles.has(id))
      const decalages = ilot
        .map((id) => {
          const an = anneeDe(personnes[indexDe.get(id)!]!)
          return an === null ? null : (an - a) / b - rangeeDe.get(id)!
        })
        .filter((d): d is number => d !== null)
        .sort((x, y) => x - y)
      const decalage = decalages.length ? Math.round(decalages[decalages.length >> 1]!) : p.generation
      for (const id of ilot) rangeeDe.set(id, rangeeDe.get(id)! + decalage)
    }
  }
  const rangee = personnes.map((p) => rangeeDe.get(p.id) ?? 0)

  /* 3. La lignée directe (Sosa). */
  // (Calculée avant la réparation des rangées, qui ne touche jamais un ancêtre direct.)
  const pereEtMere = (f: number): [number | null, number | null] => {
    const ep = epouxDe(f)
    let pere = ep.find((i) => personnes[i]!.sex === 'masculin') ?? null
    let mere = ep.find((i) => personnes[i]!.sex === 'feminin' && i !== pere) ?? null
    for (const i of ep) {
      if (i === pere || i === mere) continue
      if (pere === null) pere = i
      else if (mere === null) mere = i
    }
    return [pere, mere]
  }
  const sosaDepuis = (racine: number, deja: ReadonlySet<number>): { parNumero: Map<number, number>; couples: Set<number> } => {
    const parNumero = new Map<number, number>([[1, racine]])
    const vus = new Set([racine, ...deja])
    const couples = new Set<number>()
    const file = [1]
    while (file.length) {
      const k = file.shift()!
      const f = enfantDans[parNumero.get(k)!]![0]
      if (f === undefined) continue
      couples.add(f)
      const [pere, mere] = pereEtMere(f)
      for (const [i, s] of [
        [pere, 2 * k],
        [mere, 2 * k + 1],
      ] as const) {
        if (i === null || vus.has(i)) continue
        vus.add(i)
        parNumero.set(s, i)
        file.push(s)
      }
    }
    return { parNumero, couples }
  }
  const { parNumero: sosaEgo, couples: famillesDirectes } =
    egoIndex >= 0 && indexDe.has(graphe.ego) ? sosaDepuis(egoIndex, new Set()) : { parNumero: new Map<number, number>(), couples: new Set<number>() }
  const sosa: (number | null)[] = new Array(n).fill(null)
  for (const [k, i] of sosaEgo) sosa[i] = k
  let sosaConjoint = new Map<number, number>()
  if (egoIndex >= 0) {
    for (const f of epouxDans[egoIndex]!) {
      for (const c of epouxDe(f)) {
        if (c === egoIndex || sosaConjoint.size) continue
        const fp = enfantDans[c]![0]
        const [p, m] = fp === undefined ? [null, null] : pereEtMere(fp)
        const quart = (p !== null ? groupe[p] : undefined) ?? (m !== null ? groupe[m] : undefined)
        if (quart === 'cpp' || quart === 'cmp') sosaConjoint = sosaDepuis(c, new Set(sosaEgo.values())).parNumero
      }
    }
  }

  /*
   * 3 bis. LES RANGÉES D'UN IMPLEXE, RÉPARÉES. Le parcours en largeur depuis l'ego donne à
   * chacun la génération du premier chemin qui l'atteint ; dans un implexe (deux cousins qui
   * s'épousent, un ancêtre atteint par deux chemins), deux chemins disent deux générations,
   * et le premier gagne : un époux une rangée au-dessus de l'autre, un enfant sur la rangée de
   * ses parents. Sur l'arbre réel : 5 couples et 7 enfants — et à eux seuls le quart des
   * croisements du dessin, chacun de leurs liens traversant toute une rangée. On déplace
   * donc, d'une rangée à la fois, la personne dont le déplacement retire le plus de ces
   * désaccords (époux sur des rangées différentes, enfant pas juste sous ses parents), sans
   * jamais toucher l'ego, sa lignée directe, ni les personnes sans lien (leur rangée est
   * estimée à part). C'est la même génération d'un autre chemin, pas une invention.
   */
  {
    const desaccords = (i: number, r: number): number => {
      let n = 0
      for (const f of epouxDans[i]!) {
        for (const q of familles[f]!.epoux) if (q !== i && rangee[q] !== r) n++
        for (const c of familles[f]!.enfants) if (rangee[c] !== r + 1) n++
      }
      for (const f of enfantDans[i]!) for (const q of familles[f]!.epoux) if (rangee[q] !== r - 1) n++
      return n
    }
    // Un ancêtre direct est à la génération de son numéro de Sosa — le seul chemin qui
    // compte pour lui (sur l'arbre réel, le couple Sosa 522 × 523 était à cheval sur deux
    // rangées : le mari atteint d'abord par les enfants d'un second mariage).
    if (egoIndex >= 0) for (const [k, i] of sosaEgo) rangee[i] = rangee[egoIndex]! - Math.floor(Math.log2(k))
    const fixe = (i: number): boolean => i === egoIndex || sosa[i] !== null || isoles.has(personnes[i]!.id)
    for (let passe = 0; passe < 12; passe++) {
      let bouge = false
      familles.forEach((f) => {
        if (!f.epoux.length) return
        const rf = Math.max(...f.epoux.map((e) => rangee[e]!))
        const enDesaccord = f.epoux.some((e) => rangee[e] !== rf) || f.enfants.some((c) => rangee[c] !== rf + 1)
        if (!enDesaccord) return
        for (const i of [...f.epoux, ...f.enfants]) {
          if (fixe(i)) continue
          const avant = desaccords(i, rangee[i]!)
          let mieux = avant
          let rangMieux = rangee[i]!
          for (const r of [rangee[i]! - 1, rangee[i]! + 1]) {
            const d = desaccords(i, r)
            if (d < mieux) {
              mieux = d
              rangMieux = r
            }
          }
          if (rangMieux !== rangee[i]) {
            rangee[i] = rangMieux
            bouge = true
          }
        }
      })
      if (!bouge) break
    }
  }

  /* Les tailles. */
  const hauteurFiche = Math.max(48, ...personnes.map((p) => p.h || 0))
  const largeurs = personnes.map((p) => p.w || 132).sort((x, y) => x - y)
  const largeurDeCarte = largeurs.length ? largeurs[largeurs.length >> 1]! : 132

  /* 4. Les parties. */
  const rangeeEgo = egoIndex >= 0 ? rangee[egoIndex]! : 0
  // La tête de chaque quart : le grand-parent (Sosa 4 à 7), ou le grand-parent du conjoint.
  const coupeEgo = rangeeEgo - 2
  const conjointPose = [...sosaConjoint.values()][0]
  const coupeConjoint = (conjointPose !== undefined ? rangee[conjointPose]! : rangeeEgo) - 1
  const parties: Partie[] = []
  const partieParCle = new Map<string, number>()
  const avecConjoint = groupeDePose.some((g) => QUARTS_CONJOINT.has(g))
  // Les colonnes. Sans famille du conjoint, le tronc est au milieu (père et mère à égale
  // distance de leurs parents) ; avec, il se met contre les quarts du conjoint, pour que
  // le conjoint rejoigne ses parents par leur couloir.
  const COLONNES: CleDeGroupe[] = avecConjoint
    ? ['pp', 'pm', 'mp', 'mm', 'tr', 'cpm', 'cpp', 'cmp', 'cmm', 'alliances', 'sansLien']
    : ['pp', 'pm', 'tr', 'mp', 'mm', 'alliances', 'sansLien']
  const partieDe: number[] = new Array(n).fill(0)
  for (let i = 0; i < n; i++) {
    const g = groupeDePose[i]!
    const r = rangee[i]!
    const tranche: Partie['tranche'] = QUARTS_EGO.has(g)
      ? r <= coupeEgo
        ? 'haut'
        : 'bas'
      : QUARTS_CONJOINT.has(g)
        ? r <= coupeConjoint
          ? 'haut'
          : 'bas'
        : 'entier'
    const cle = tranche === 'entier' ? g : `${g}-${tranche}`
    let k = partieParCle.get(cle)
    if (k === undefined) {
      k = parties.length
      partieParCle.set(cle, k)
      parties.push({ cle, groupe: g, tranche, bande: 'pleine', colonne: Math.max(0, COLONNES.indexOf(g)), min: r, max: r, membres: [] })
    }
    const p = parties[k]!
    p.min = Math.min(p.min, r)
    p.max = Math.max(p.max, r)
    p.membres.push(i)
    partieDe[i] = k
  }
  // La bande de chaque partie, d'après la coupe de son groupe.
  for (const p of parties) {
    const coupe = QUARTS_CONJOINT.has(p.groupe) ? coupeConjoint : coupeEgo
    p.bande = p.max <= coupe ? 'haut' : p.min > coupe ? 'bas' : 'pleine'
  }
  // Un quart qui n'a qu'une moitié s'appelle par son nom entier.
  for (const p of parties) {
    if (p.tranche === 'entier') continue
    const autre = parties.find((q) => q !== p && q.groupe === p.groupe)
    if (!autre) p.tranche = 'entier'
  }

  /* 5. Les foyers : une personne et ses conjoints de la même rangée et de la même partie. */
  const chef = new Int32Array(n)
  for (let i = 0; i < n; i++) chef[i] = i
  const trouver = (i: number): number => {
    let r = i
    while (chef[r] !== r) r = chef[r]!
    while (chef[i] !== r) {
      const s = chef[i]!
      chef[i] = r
      i = s
    }
    return r
  }
  const conjointsDe: number[][] = personnes.map(() => [])
  for (const f of familles) {
    for (const a of f.epoux)
      for (const b of f.epoux) {
        if (a >= b || rangee[a] !== rangee[b] || partieDe[a] !== partieDe[b]) continue
        if (!conjointsDe[a]!.includes(b)) conjointsDe[a]!.push(b)
        if (!conjointsDe[b]!.includes(a)) conjointsDe[b]!.push(a)
        chef[trouver(b)] = trouver(a)
      }
  }
  const parChef = new Map<number, number[]>()
  for (let i = 0; i < n; i++) {
    const r = trouver(i)
    const l = parChef.get(r)
    if (l) l.push(i)
    else parChef.set(r, [i])
  }
  const foyers: Foyer[] = []
  const foyerDe: number[] = new Array(n).fill(-1)
  const rangDansFoyer: number[] = new Array(n).fill(0)
  /*
   * Un membre marié aussi HORS du foyer, sur la même rangée, dans une partie voisine (le
   * grand-père Sosa 4, remarié deux fois dans pp, et dont l'épouse Sosa 5 est dans pm) : il se
   * met au bout du foyer qui regarde cette partie, pour que leur union soit droite. Au milieu
   * de ses deux autres épouses, l'union de la lignée directe contournait l'une d'elles.
   */
  const coteExterieur = (i: number): number => {
    let cote = 0
    for (const f of epouxDans[i]!)
      for (const e of familles[f]!.epoux) {
        if (e === i || rangee[e] !== rangee[i] || partieDe[e] === partieDe[i]) continue
        const p = parties[partieDe[i]!]!
        const q = parties[partieDe[e]!]!
        if (Math.abs(p.colonne - q.colonne) !== 1) continue
        const c = q.colonne < p.colonne ? -1 : 1
        if (cote && cote !== c) return 0
        cote = c
      }
    return cote
  }
  for (const groupeDuFoyer of parChef.values()) {
    let membres = ordonnerLeFoyer(groupeDuFoyer, conjointsDe, personnes)
    if (membres.length > 2) {
      const cotes = membres.map(coteExterieur)
      const k = cotes.findIndex((c) => c !== 0)
      if (k >= 0 && cotes.filter((c) => c !== 0).length === 1) {
        const m = membres[k]!
        const autres = membres.filter((x) => x !== m)
        membres = cotes[k]! > 0 ? [...autres, m] : [m, ...autres]
      }
    }
    const decal: number[] = []
    let w = 0
    membres.forEach((i, k) => {
      if (k) w += options.ecartCouple
      decal.push(w + (personnes[i]!.w || 132) / 2)
      w += personnes[i]!.w || 132
    })
    const id = foyers.length
    foyers.push({ id, rangee: rangee[membres[0]!]!, partie: partieDe[membres[0]!]!, membres, largeur: w, decal, familles: [] })
    membres.forEach((i, k) => {
      foyerDe[i] = id
      rangDansFoyer[i] = k
    })
  }
  familles.forEach((f, k) => {
    if (!f.epoux.length) return
    const fo = foyerDe[f.epoux[0]!]!
    if (f.epoux.every((e) => foyerDe[e] === fo)) foyers[fo]!.familles.push(k)
  })
  // Les familles d'un foyer, de gauche à droite (le milieu de leurs époux).
  for (const fo of foyers) {
    const milieu = (k: number): number => {
      const ep = familles[k]!.epoux
      return ep.reduce((s, e) => s + rangDansFoyer[e]!, 0) / ep.length
    }
    fo.familles.sort((a, b) => milieu(a) - milieu(b) || a - b)
  }

  return {
    graphe,
    ego,
    egoIndex,
    personnes,
    indexDe,
    familles,
    epouxDans,
    enfantDans,
    groupe,
    groupeDePose,
    rangee,
    sosa,
    famillesDirectes,
    sosaConjoint,
    sosaEgo,
    classes,
    rattachement,
    isoles,
    alliancesDansLArbre,
    hauteurFiche,
    largeurDeCarte,
    parties,
    partieDe,
    foyers,
    foyerDe,
    rangDansFoyer,
  }
}

/**
 * Deux parties « voisines » : la même, ou deux colonnes qui se touchent sur une rangée
 * commune — le couple des grands-parents paternels, l'un dans pp, l'autre dans pm. Un
 * couple de parties plus lointaines est un IMPLEXE (deux cousins de quarts différents).
 */
export function partiesVoisines(s: Structure, p: number, q: number): boolean {
  if (p === q) return true
  const P = s.parties[p]!
  const Q = s.parties[q]!
  return Math.abs(P.colonne - Q.colonne) <= 1 && !(P.max < Q.min || Q.max < P.min)
}

/**
 * L'ordre d'un foyer, de gauche à droite : la personne aux plus nombreuses unions au
 * milieu, chaque conjoint du côté le plus proche ; deux époux : l'homme à gauche (la
 * règle de `/arbre` sans ports — l'ordre des rangées retournera le foyer s'il le faut).
 */
function ordonnerLeFoyer(groupe: readonly number[], conjointsDe: readonly number[][], personnes: readonly NoeudPersonne[]): number[] {
  if (groupe.length === 1) return [...groupe]
  const tri = [...groupe].sort((a, b) => a - b)
  const degre = (i: number): number => conjointsDe[i]!.length
  const pivot = [...tri].sort((a, b) => degre(b) - degre(a) || a - b)[0]!
  const ordre = [pivot]
  const vus = new Set([pivot])
  const file = [pivot]
  while (file.length) {
    const i = file.shift()!
    for (const c of [...conjointsDe[i]!].sort((a, b) => a - b)) {
      if (vus.has(c)) continue
      vus.add(c)
      file.push(c)
      const k = ordre.indexOf(i)
      if (ordre.length === 1) {
        if (personnes[i]!.sex === 'masculin' || (personnes[i]!.sex !== 'feminin' && personnes[c]!.sex === 'feminin')) ordre.push(c)
        else ordre.unshift(c)
      } else if (k <= (ordre.length - 1) / 2) ordre.unshift(c)
      else ordre.push(c)
    }
  }
  for (const i of tri) if (!vus.has(i)) ordre.push(i)
  return ordre
}
