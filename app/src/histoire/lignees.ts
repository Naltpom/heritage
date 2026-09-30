/**
 * La disposition `lignees` de `/histoire` : tout l'arbre, une rangée par
 * génération, la lignée directe dégagée au milieu.
 *
 * Transposée de `disposer()` dans la maquette « Arbre complet à niveaux de
 * détail (3a) ». Les règles, dans l'ordre où elles s'appliquent :
 *
 *  1. une rangée par génération ;
 *  2. numérotation de Sosa depuis l'ego (1 = ego, 2n = père, 2n+1 = mère) : côté
 *     père à gauche, côté mère à droite, à tous les niveaux ;
 *  3. les collatéraux d'un ancêtre sont rangés **à l'extérieur** de la lignée
 *     directe — à gauche s'il est père dans son couple, à droite s'il est mère ;
 *  4. pour chaque couple d'ancêtres, on empile `[père] [mère] [enfants]` côté
 *     gauche et l'ordre inverse côté droit ;
 *  5. empilement par contours : chaque bloc est posé au plus près du précédent,
 *     sans chevaucher aucune rangée commune ;
 *  6. la descendance d'un collatéral est un arbre de foyers compact ;
 *  7. aucun trait ne passe derrière une fiche (une union séparée par d'autres
 *     fiches contourne par le dessous), et deux barres de fratrie ne se
 *     superposent jamais (chacune son couloir de hauteur).
 *
 * **Personne n'est perdu.** La maquette ne connaissait que l'ascendance et les
 * collatéraux ; le graphe réel porte aussi les remariages, l'ascendance du
 * conjoint et des îlots. Ce qui n'est pas atteint par les règles ci-dessus est
 * posé ensuite, bloc par bloc, contre le bord extérieur de l'arbre, du côté de
 * la personne déjà placée qui le rattache.
 *
 * **La largeur d'une fiche est celle du moteur** (`w`, `h`) : la disposition
 * change la position des boîtes, jamais leur taille.
 *
 * Pur, sans DOM : tout ce fichier se vérifie dans `lignees.test.ts`.
 */
import { indexer } from '@/components/arbre/graphe'
import type { Branche, Graphe, IdentifiantNoeud, NoeudFamille, NoeudPersonne } from '@/lib/api'

/* ------------------------------------------------------------------ */
/* Constantes de placement (celles de la maquette)                     */
/* ------------------------------------------------------------------ */

/** Écart entre frères et sœurs, et entre conjoints. */
export const ECART_FRATRIE = 12
/** Écart entre deux blocs familiaux. */
export const ECART_BLOC = 28
/** Espace vertical entre deux rangées, en plus de la hauteur d'une fiche. */
const INTERLIGNE = 60
/** Marge du monde autour de l'arbre. */
const MARGE_X = 60
const MARGE_Y = 40
/** Hauteur de base de la barre de fratrie au-dessus de la rangée des enfants
 *  (+ 6 px par couloir quand deux barres se chevaucheraient). */
const BARRE_DIRECTE = 12
const BARRE_ORDINAIRE = 10
/** Écart entre l'arbre et la zone des personnes détachées. */
const ECART_ZONE = 160

/* ------------------------------------------------------------------ */
/* Ce que la disposition rend                                          */
/* ------------------------------------------------------------------ */

export interface FicheHistoire {
  id: IdentifiantNoeud
  noeud: NoeudPersonne
  /** Coin haut-gauche, dans le monde. */
  x: number
  y: number
  w: number
  h: number
  /** Centre horizontal. */
  cx: number
  /** Génération relative à l'ego : 0 = lui, −1 = ses parents. */
  rangee: number
  /** Ancêtre de l'ego, ou l'ego lui-même. */
  directe: boolean
  sosa: number | null
  /** Aucun lien connu avec l'ego : la rangée est estimée d'après les dates. */
  detache: boolean
}

export interface EtiquetteFoyer {
  x: number
  y: number
  nom: string
  /** Un couple d'ancêtres. */
  ancetres: boolean
  /** Largeur du couple dans le monde, pour décider s'il a la place. */
  envergure: number
}

export type BrancheDeQuart = Extract<Branche, 'pp' | 'pm' | 'mp' | 'mm' | 'cpp' | 'cpm' | 'cmp' | 'cmm'>

export interface Quart {
  branche: BrancheDeQuart
  libelle: string
  /** Nom de famille du grand-parent de tête ; vide s'il n'est pas connu. */
  nom: string
  effectif: number
  /** Barycentre horizontal des fiches du quart. */
  cx: number
  /** Étendue horizontale, pour la mini-carte. */
  x0: number
  x1: number
}

export interface GenerationHistoire {
  rangee: number
  /** Milieu de la rangée, dans le monde. */
  y: number
  libelle: string
  /** « vers 1930 », ou `null` quand aucune année n'est lisible. */
  annees: string | null
  effectif: number
}

export interface TracesHistoire {
  ordinaires: string
  directs: string
  divorces: string
  attente: string
}

export interface DispositionHistoire {
  ego: IdentifiantNoeud
  fiches: FicheHistoire[]
  parId: Map<IdentifiantNoeud, FicheHistoire>
  parPersonId: Map<number, FicheHistoire>
  traces: TracesHistoire
  foyers: EtiquetteFoyer[]
  quarts: Quart[]
  /** La zone des personnes détachées, au-delà du bord droit ; `null` s'il n'y en a pas. */
  zoneDetachee: { cx: number; x0: number; x1: number; effectif: number } | null
  generations: GenerationHistoire[]
  largeur: number
  hauteur: number
  /** Pas vertical entre deux rangées. */
  pasRangee: number
}

/* ------------------------------------------------------------------ */
/* Lecture des noms et des années                                      */
/* ------------------------------------------------------------------ */

/**
 * Le prénom dessiné : premier mot de `etiquetteNom`. Le nom de famille peut
 * contenir des espaces, le prénom non — par construction (§ 5.2).
 */
export function prenomDe(noeud: NoeudPersonne): string {
  const nom = typeof noeud.etiquetteNom === 'string' ? noeud.etiquetteNom : ''
  const espace = nom.indexOf(' ')
  return espace < 0 ? nom : nom.slice(0, espace)
}

/** Le nom de famille : ce qui suit le premier mot de `etiquetteNom`. */
export function nomDeFamilleDe(noeud: NoeudPersonne): string {
  const nom = typeof noeud.etiquetteNom === 'string' ? noeud.etiquetteNom : ''
  const espace = nom.indexOf(' ')
  return espace < 0 ? '' : nom.slice(espace + 1).trim()
}

/**
 * Première année lisible de la ligne de dates, pour **ordonner** une fratrie et
 * situer une génération dans la gouttière — jamais pour l'afficher : la ligne
 * de dates s'affiche telle quelle.
 */
export function anneeDe(noeud: NoeudPersonne): number | null {
  const d = noeud.etiquetteDates
  if (typeof d !== 'string') return null
  const m = /\b(1[0-9]{3}|20[0-9]{2})\b/.exec(d)
  return m ? Number(m[1]) : null
}

const LIBELLES_GENERATION: Record<number, string> = {
  [-6]: 'Quintaïeux',
  [-5]: 'Quadrisaïeux',
  [-4]: 'Trisaïeux',
  [-3]: 'Arrière-grands-parents',
  [-2]: 'Grands-parents',
  [-1]: 'Parents',
  0: 'Votre génération',
  1: 'Enfants',
  2: 'Petits-enfants',
  3: 'Arrière-petits-enfants',
}

export function libelleGeneration(rangee: number): string {
  return LIBELLES_GENERATION[rangee] ?? `Génération ${rangee > 0 ? '+' : ''}${rangee}`
}

export const LIBELLES_QUART: Record<BrancheDeQuart, string> = {
  pp: 'Père du père',
  pm: 'Mère du père',
  mp: 'Père de la mère',
  mm: 'Mère de la mère',
  cpp: 'Conjoint · Père du père',
  cpm: 'Conjoint · Mère du père',
  cmp: 'Conjoint · Père de la mère',
  cmm: 'Conjoint · Mère de la mère',
}

/** Sosa du grand-parent de tête de chaque quart. */
const SOSA_DU_QUART: Record<'pp' | 'pm' | 'mp' | 'mm', number> = { pp: 4, pm: 5, mp: 6, mm: 7 }

const generationSosa = (n: number): number => Math.floor(Math.log2(n))

/* ------------------------------------------------------------------ */
/* Formes : un contour par rangée                                      */
/* ------------------------------------------------------------------ */

interface Pose {
  id: IdentifiantNoeud
  x: number
  g: number
}

interface Forme {
  poses: Pose[]
  rangees: Map<number, [number, number]>
  ancre: number
}

const vide = (): Forme => ({ poses: [], rangees: new Map(), ancre: 0 })

function decaler(f: Forme, dx: number): Forme {
  if (!dx || !Number.isFinite(dx)) return f
  for (const p of f.poses) p.x += dx
  for (const r of f.rangees.values()) {
    r[0] += dx
    r[1] += dx
  }
  f.ancre += dx
  return f
}

function fusionner(a: Forme, b: Forme): Forme {
  for (const p of b.poses) a.poses.push(p)
  for (const [g, s] of b.rangees) {
    const r = a.rangees.get(g)
    if (r) {
      r[0] = Math.min(r[0], s[0])
      r[1] = Math.max(r[1], s[1])
    } else a.rangees.set(g, [s[0], s[1]])
  }
  return a
}

/** Décalage minimal pour poser `b` à droite de `a` sans rangée commune qui se chevauche. */
function besoinADroite(a: Forme, b: Forme, ecart: number): number {
  let d = -Infinity
  for (const [g, s] of b.rangees) {
    const r = a.rangees.get(g)
    if (r) d = Math.max(d, r[1] - s[0] + ecart)
  }
  return d
}

function besoinAGauche(a: Forme, b: Forme, ecart: number): number {
  let d = Infinity
  for (const [g, s] of b.rangees) {
    const r = a.rangees.get(g)
    if (r) d = Math.min(d, r[0] - s[1] - ecart)
  }
  return d
}

/** Empile de gauche à droite ; rend la forme fusionnée et l'ancre de chaque partie. */
function empiler(parties: Forme[], ecart: number): { forme: Forme; ancres: number[] } {
  const acc = parties[0]!
  const ancres = [acc.ancre]
  for (let i = 1; i < parties.length; i++) {
    const p = parties[i]!
    const dx = besoinADroite(acc, p, ecart)
    decaler(p, Number.isFinite(dx) ? dx : 0)
    ancres.push(p.ancre)
    fusionner(acc, p)
  }
  acc.ancre = ancres[0]!
  return { forme: acc, ancres }
}

/* ------------------------------------------------------------------ */
/* La disposition                                                      */
/* ------------------------------------------------------------------ */

type Cote = 'G' | 'D'

export function disposerLignees(graphe: Graphe): DispositionHistoire {
  const index = indexer(graphe)
  const personnes = new Map<string, NoeudPersonne>()
  for (const p of index.personnes) personnes.set(p.id, p)
  const familles = new Map<string, NoeudFamille>()
  for (const f of index.familles) familles.set(f.id, f)

  const enfantsDe = (f: string): string[] =>
    (index.enfantsParFamille.get(f) ?? []).filter((id) => personnes.has(id))
  const epouxDe = (f: string): string[] => {
    const enfants = new Set(index.enfantsParFamille.get(f) ?? [])
    return (index.membresParFamille.get(f) ?? []).filter((id) => !enfants.has(id) && personnes.has(id))
  }
  const famillesEnfant = new Map<string, string[]>()
  const famillesEpoux = new Map<string, string[]>()
  for (const f of familles.keys()) {
    for (const e of enfantsDe(f)) famillesEnfant.set(e, [...(famillesEnfant.get(e) ?? []), f])
    for (const e of epouxDe(f)) famillesEpoux.set(e, [...(famillesEpoux.get(e) ?? []), f])
  }
  const largeur = (id: string): number => personnes.get(id)?.w ?? 132
  const hauteurFiche = Math.max(48, ...index.personnes.map((p) => p.h || 0))

  /** Père puis mère d'une famille ; un sexe inconnu prend la place libre. */
  function pereEtMere(f: string): [string | null, string | null] {
    const ep = epouxDe(f)
    let pere = ep.find((id) => personnes.get(id)!.sex === 'masculin') ?? null
    let mere = ep.find((id) => personnes.get(id)!.sex === 'feminin' && id !== pere) ?? null
    for (const id of ep) {
      if (id === pere || id === mere) continue
      if (pere === null) pere = id
      else if (mere === null) mere = id
    }
    return [pere, mere]
  }

  /** Numérotation de Sosa depuis une personne. Un ancêtre déjà numéroté (implexe) n'est pas repris. */
  function sosaDepuis(racine: string): { parNumero: Map<number, string>; couples: Map<number, string> } {
    const parNumero = new Map<number, string>([[1, racine]])
    const vus = new Set([racine])
    const couples = new Map<number, string>()
    const file = [1]
    while (file.length) {
      const n = file.shift()!
      const f = famillesEnfant.get(parNumero.get(n)!)?.[0]
      if (f === undefined) continue
      couples.set(2 * n, f)
      const [pere, mere] = pereEtMere(f)
      for (const [id, k] of [[pere, 2 * n], [mere, 2 * n + 1]] as const) {
        if (id === null || vus.has(id)) continue
        vus.add(id)
        parNumero.set(k, id)
        file.push(k)
      }
    }
    return { parNumero, couples }
  }

  const ego = personnes.has(graphe.ego) ? graphe.ego : (index.personnes[0]?.id ?? graphe.ego)
  const { parNumero: sosa, couples } = sosaDepuis(ego)
  const numeroDe = new Map<string, number>()
  for (const [n, id] of sosa) numeroDe.set(id, n)
  const famillesAncestrales = new Set(couples.values())

  const poses = new Set<string>()
  const rangeeDe = new Map<string, number>()
  const anneeTri = (id: string): number => anneeDe(personnes.get(id)!) ?? Number.POSITIVE_INFINITY

  function poser(f: Forme, id: string, x: number, g: number): void {
    const w = largeur(id)
    f.poses.push({ id, x, g })
    poses.add(id)
    rangeeDe.set(id, g)
    const r = f.rangees.get(g)
    if (r) {
      r[0] = Math.min(r[0], x - w / 2)
      r[1] = Math.max(r[1], x + w / 2)
    } else f.rangees.set(g, [x - w / 2, x + w / 2])
  }

  /**
   * Un collatéral et sa descendance : le conjoint du côté extérieur, les
   * enfants centrés sous le couple.
   */
  function blocDescendance(id: string, cote: Cote, g: number): Forme {
    const f = vide()
    poser(f, id, 0, g)
    // La fiche la plus à l'extérieur sur la rangée : un second conjoint se pose au-delà du premier.
    let exterieur = { id, x: 0 }
    /*
     * Les unions à poser, en FILE : celles de la personne, puis — dès qu'un
     * conjoint est posé — les AUTRES unions de ce conjoint, traitées aussitôt pour
     * que son autre partenaire se pose juste à côté de lui. Sans cela, ce second
     * partenaire n'était posé que par le rattrapage final, au bord extérieur de
     * l'arbre, et le trait d'union traversait toute la rangée (constaté en prod :
     * une belle-sœur remariée, un trait derrière vingt fiches).
     */
    const aTraiter = (famillesEpoux.get(id) ?? []).map((fam) => ({ pivot: id, xPivot: 0, fam }))
    const traitees = new Set<string>()
    while (aTraiter.length) {
      const { pivot, xPivot, fam } = aTraiter.shift()!
      if (traitees.has(fam) || famillesAncestrales.has(fam)) continue
      traitees.add(fam)
      let milieu = xPivot
      const conjoint = epouxDe(fam).find((e) => e !== pivot && !poses.has(e) && !numeroDe.has(e))
      if (conjoint !== undefined) {
        const pas = largeur(exterieur.id) / 2 + ECART_FRATRIE + largeur(conjoint) / 2
        const x = cote === 'G' ? exterieur.x - pas : exterieur.x + pas
        poser(f, conjoint, x, g)
        exterieur = { id: conjoint, x }
        milieu = (xPivot + x) / 2
        // Ses autres unions passent en tête : son autre partenaire se pose à côté de lui.
        const autres = (famillesEpoux.get(conjoint) ?? []).filter((f2) => f2 !== fam)
        aTraiter.unshift(...autres.map((f2) => ({ pivot: conjoint, xPivot: x, fam: f2 })))
      }
      const enfants = enfantsDe(fam)
        .filter((e) => !poses.has(e) && !numeroDe.has(e))
        .sort((a, b) => anneeTri(a) - anneeTri(b))
      if (!enfants.length) continue
      const parties = enfants.map((e) => blocDescendance(e, cote, g + 1))
      const { forme, ancres } = empiler(parties, ECART_FRATRIE)
      const centre = (ancres[0]! + ancres[ancres.length - 1]!) / 2
      decaler(forme, milieu - centre)
      // Les enfants d'une seconde union ne doivent pas recouvrir ceux de la première.
      const besoin = cote === 'G' ? besoinAGauche(f, forme, ECART_BLOC) : besoinADroite(f, forme, ECART_BLOC)
      if (Number.isFinite(besoin)) {
        if (cote === 'G' && besoin < 0) decaler(forme, besoin)
        if (cote === 'D' && besoin > 0) decaler(forme, besoin)
      }
      fusionner(f, forme)
    }
    f.ancre = 0
    return f
  }

  function seule(id: string, g: number): Forme {
    const f = vide()
    poser(f, id, 0, g)
    return f
  }

  /** La forme d'un ancêtre : sa propre ascendance s'il en a une, sinon sa fiche. */
  function formePersonne(n: number): Forme {
    if (couples.has(2 * n)) return formeCouple(2 * n)
    return seule(sosa.get(n)!, -generationSosa(n))
  }

  /** Le couple d'ancêtres de Sosa `n` (pair) et `n + 1`, avec toute leur fratrie. */
  function formeCouple(n: number): Forme {
    const m = n >> 1
    const g = -generationSosa(n)
    const cote: 'G' | 'D' | 'C' = m === 1 ? 'C' : m % 2 === 0 ? 'G' : 'D'
    const fam = couples.get(n)!
    const direct = sosa.get(m)!

    const FP = sosa.has(n) ? formePersonne(n) : null
    const FM = sosa.has(n + 1) ? formePersonne(n + 1) : null

    const fratrie = enfantsDe(fam)
      .filter((e) => e === direct || (!poses.has(e) && !numeroDe.has(e)))
      .sort((a, b) => anneeTri(a) - anneeTri(b))
    if (!fratrie.includes(direct)) fratrie.push(direct)
    const autres = fratrie.filter((e) => e !== direct)
    const ordre = cote === 'G' ? [...autres, direct] : cote === 'D' ? [direct, ...autres] : fratrie
    const iDirect = ordre.indexOf(direct)

    const parties = ordre.map((e, i) => {
      if (e === direct) return m === 1 ? blocDescendance(e, 'D', g + 1) : seule(e, g + 1)
      const sens: Cote = cote === 'C' ? (i < iDirect ? 'G' : 'D') : cote
      return blocDescendance(e, sens, g + 1)
    })
    const { forme: G, ancres } = empiler(parties, ECART_FRATRIE)

    let fx: number | null = null
    let mx: number | null = null
    let forme: Forme
    if (FP === null && FM === null) {
      forme = G
    } else if (cote === 'C' && FP && FM) {
      forme = FP
      fx = FP.ancre
      const pref = fx + (largeur(sosa.get(n)!) + ECART_FRATRIE) / 2 - ancres[iDirect]!
      const besoin = besoinADroite(FP, G, ECART_BLOC)
      const dx = Math.max(Number.isFinite(besoin) ? besoin : -Infinity, pref)
      decaler(G, dx)
      for (let i = 0; i < ancres.length; i++) ancres[i]! += dx
      fusionner(forme, G)
      const dm = besoinADroite(forme, FM, ECART_BLOC)
      decaler(FM, Number.isFinite(dm) ? dm : 0)
      mx = FM.ancre
      fusionner(forme, FM)
    } else {
      if (FP && FM) {
        const dm = besoinADroite(FP, FM, ECART_BLOC)
        decaler(FM, Number.isFinite(dm) ? dm : 0)
        fx = FP.ancre
        mx = FM.ancre
        forme = fusionner(FP, FM)
      } else if (FP) {
        fx = FP.ancre
        forme = FP
      } else {
        mx = FM!.ancre
        forme = FM!
      }
      // L'enfant direct se pose du côté INTÉRIEUR de l'union de ses parents, sous
      // elle : son trait descend tout droit, et son propre couple reste court.
      const milieu = fx !== null && mx !== null ? (fx + mx) / 2 : (fx ?? mx)!
      let cibleX = milieu
      if (fx !== null && mx !== null) {
        cibleX =
          cote === 'D'
            ? Math.min(milieu, fx + largeur(sosa.get(n)!) / 2 + ECART_BLOC)
            : Math.max(milieu, mx - largeur(sosa.get(n + 1)!) / 2 - ECART_BLOC)
      }
      const pref = cibleX - ancres[iDirect]!
      let dx: number
      if (cote === 'D') {
        const besoin = besoinAGauche(forme, G, ECART_BLOC)
        dx = Math.min(Number.isFinite(besoin) ? besoin : Infinity, pref)
      } else {
        const besoin = besoinADroite(forme, G, ECART_BLOC)
        dx = Math.max(Number.isFinite(besoin) ? besoin : -Infinity, pref)
      }
      decaler(G, dx)
      for (let i = 0; i < ancres.length; i++) ancres[i]! += dx
      fusionner(forme, G)
    }
    forme.ancre = ancres[iDirect]!
    return forme
  }

  /* L'arbre principal. */
  let racine: Forme
  if (couples.has(2)) racine = formeCouple(2)
  else racine = blocDescendance(ego, 'D', 0)

  const xDe = (): Map<string, number> => {
    const m = new Map<string, number>()
    for (const p of racine.poses) m.set(p.id, p.x)
    return m
  }
  const xEgo = racine.poses.find((p) => p.id === ego)?.x ?? 0

  /*
   * Ce qui reste : remariages, ascendance du conjoint, îlots.
   *
   * Un bloc RATTACHÉ (le second mari d'une aïeule, les parents d'un allié) se
   * pose au plus près de la personne qui le relie : son ancre sur `cible`, puis
   * poussé vers l'extérieur jusqu'au premier creux libre de SES rangées, fiche par
   * fiche. Le poser au-delà du contour de l'arbre entier l'envoyait au bord
   * extérieur, dans une autre famille (constaté en prod : les parents d'un allié
   * Voulgre posés au-dessus des Figarol, l'ascendance d'un allié Destombes chez
   * les Provost). Sans cible — un îlot détaché —, le bloc va au bord.
   */
  function accrocher(bloc: Forme, cote: Cote, cible?: number): void {
    if (cible !== undefined) {
      // Ce qui est pris, rangée par rangée : chaque fiche, et l'espace entre deux
      // époux posés — un bloc glissé là couperait leur trait d'union.
      const occupe = new Map<number, [number, number][]>()
      const prendre = (g: number, a: number, b: number): void => {
        const l = occupe.get(g)
        if (l) l.push([a, b])
        else occupe.set(g, [[a, b]])
      }
      const xPose = new Map<string, Pose>()
      for (const p of racine.poses) {
        const w = largeur(p.id)
        prendre(p.g, p.x - w / 2, p.x + w / 2)
        xPose.set(p.id, p)
      }
      for (const fam of familles.keys()) {
        const ep = epouxDe(fam)
          .map((e) => xPose.get(e))
          .filter((p): p is Pose => p !== undefined)
        if (ep.length < 2 || ep.some((p) => p.g !== ep[0]!.g)) continue
        prendre(ep[0]!.g, Math.min(...ep.map((p) => p.x)), Math.max(...ep.map((p) => p.x)))
      }
      // Le premier creux libre dans un sens, depuis la cible.
      const chercher = (sens: Cote): number => {
        let dx = cible - bloc.ancre
        for (let tour = 0; tour < 10_000; tour++) {
          let pas = 0
          for (const p of bloc.poses) {
            const w = largeur(p.id)
            const lo = p.x + dx - w / 2
            const hi = p.x + dx + w / 2
            for (const [a, b] of occupe.get(p.g) ?? []) {
              if (lo >= b + ECART_BLOC || hi <= a - ECART_BLOC) continue
              pas = sens === 'D' ? Math.max(pas, b + ECART_BLOC - lo) : Math.min(pas, a - ECART_BLOC - hi)
            }
          }
          if (pas === 0) break
          dx += pas
        }
        return dx
      }
      // Le plus proche des deux ; à égalité, vers l'extérieur.
      const depart = cible - bloc.ancre
      const [dExt, dInt] = cote === 'D' ? [chercher('D'), chercher('G')] : [chercher('G'), chercher('D')]
      decaler(bloc, Math.abs(dInt - depart) < Math.abs(dExt - depart) ? dInt : dExt)
      fusionner(racine, bloc)
      return
    }
    const besoin = cote === 'G' ? besoinAGauche(racine, bloc, ECART_BLOC) : besoinADroite(racine, bloc, ECART_BLOC)
    if (Number.isFinite(besoin)) decaler(bloc, besoin)
    else {
      // Aucune rangée commune : on pose le bloc au-delà du bord de l'arbre.
      let lo = Infinity
      let hi = -Infinity
      for (const r of racine.rangees.values()) {
        lo = Math.min(lo, r[0])
        hi = Math.max(hi, r[1])
      }
      let blo = Infinity
      let bhi = -Infinity
      for (const r of bloc.rangees.values()) {
        blo = Math.min(blo, r[0])
        bhi = Math.max(bhi, r[1])
      }
      if (Number.isFinite(lo)) decaler(bloc, cote === 'G' ? lo - ECART_BLOC - bhi : hi + ECART_BLOC - blo)
    }
    fusionner(racine, bloc)
  }

  function accrocherLesProches(): void {
    let progres = true
    while (progres) {
      progres = false
      const x = xDe()
      for (const fam of familles.keys()) {
        const ep = epouxDe(fam)
        const en = enfantsDe(fam)
        const epPoses = ep.filter((e) => poses.has(e))
        const enPoses = en.filter((e) => poses.has(e))
        if (!epPoses.length && !enPoses.length) continue
        const epLibres = ep.filter((e) => !poses.has(e))
        const enLibres = en.filter((e) => !poses.has(e))
        if (!epLibres.length && !enLibres.length) continue

        const attache = epPoses[0] ?? enPoses[0]!
        const cote: Cote = (x.get(attache) ?? 0) < xEgo ? 'G' : 'D'
        const rangee = rangeeDe.get(attache)! + (epPoses.length ? 0 : -1)

        const xAttache = x.get(attache) ?? 0
        if (epLibres.length) {
          for (const e of epLibres) {
            if (poses.has(e)) continue
            // À côté du conjoint posé, ou au-dessus de l'enfant posé.
            const pas = epPoses.length ? largeur(attache) / 2 + ECART_FRATRIE + largeur(e) / 2 : 0
            accrocher(blocDescendance(e, cote, rangee), cote, cote === 'G' ? xAttache - pas : xAttache + pas)
          }
        } else {
          // Les époux sont posés, il reste des enfants : une fratrie issue d'une autre union.
          const parties = enLibres
            .sort((a, b) => anneeTri(a) - anneeTri(b))
            .map((e) => blocDescendance(e, cote, rangee + 1))
          const { forme, ancres } = empiler(parties, ECART_FRATRIE)
          const milieu = epPoses.length ? epPoses.reduce((s, e) => s + (x.get(e) ?? 0), 0) / epPoses.length : xAttache
          forme.ancre = (ancres[0]! + ancres[ancres.length - 1]!) / 2
          accrocher(forme, cote, milieu)
        }
        progres = true
        break
      }
    }
  }
  accrocherLesProches()

  /*
   * Les personnes DÉTACHÉES : aucun chemin ne les relie à l'ego. Leur génération
   * servie ne dit rien de leur place ; on l'ESTIME d'après leurs dates, sur la
   * droite « année ↔ rangée » tirée de la lignée directe, en attendant le vrai
   * lien. Elles vont dans une zone à part, au-delà du bord droit de l'arbre.
   */
  const detaches = new Set<string>()
  if (poses.size < personnes.size) {
    // La droite année = a + b · rangée, ajustée sur les fiches posées (la lignée directe d'abord).
    const points: [number, number][] = []
    for (const id of poses) {
      const an = numeroDe.has(id) ? anneeDe(personnes.get(id)!) : null
      if (an !== null) points.push([rangeeDe.get(id)!, an])
    }
    if (new Set(points.map((p) => p[0])).size < 2) {
      points.length = 0
      for (const id of poses) {
        const an = anneeDe(personnes.get(id)!)
        if (an !== null) points.push([rangeeDe.get(id)!, an])
      }
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

    // Un mur au-delà du bord droit : la zone détachée ne se glisse pas dans les creux de l'arbre.
    let bord = -Infinity
    for (const r of racine.rangees.values()) bord = Math.max(bord, r[1])
    if (Number.isFinite(bord)) {
      for (let g = -40; g <= 40; g++) {
        const r = racine.rangees.get(g)
        if (r) r[1] = Math.max(r[1], bord + ECART_ZONE - ECART_BLOC)
        else racine.rangees.set(g, [bord + ECART_ZONE - ECART_BLOC - 1, bord + ECART_ZONE - ECART_BLOC])
      }
    }

    for (const depart of index.personnes) {
      if (poses.has(depart.id)) continue
      // La composante, avec la génération relative de chacun (conjoint 0, enfant +1, parent −1).
      const relatif = new Map<string, number>([[depart.id, 0]])
      const file = [depart.id]
      while (file.length) {
        const id = file.shift()!
        const g = relatif.get(id)!
        const voisins: [string, number][] = []
        for (const f of famillesEpoux.get(id) ?? []) {
          for (const e of epouxDe(f)) voisins.push([e, g])
          for (const e of enfantsDe(f)) voisins.push([e, g + 1])
        }
        for (const f of famillesEnfant.get(id) ?? []) for (const e of epouxDe(f)) voisins.push([e, g - 1])
        for (const [v, gv] of voisins) {
          if (relatif.has(v) || poses.has(v)) continue
          relatif.set(v, gv)
          file.push(v)
        }
      }
      const decalages = [...relatif]
        .map(([id, g]) => {
          const an = anneeDe(personnes.get(id)!)
          return an === null ? null : (an - a) / b - g
        })
        .filter((d): d is number => d !== null)
        .sort((x, y) => x - y)
      const sommet = [...relatif].sort((x, y) => x[1] - y[1])[0]!
      const rangee = decalages.length
        ? Math.round(decalages[decalages.length >> 1]! + sommet[1])
        : personnes.get(sommet[0])!.generation
      for (const id of relatif.keys()) detaches.add(id)
      accrocher(blocDescendance(sommet[0], 'D', rangee), 'D')
      accrocherLesProches()
    }
  }

  /* ------------------------------------------------------------------ */
  /* Passage au monde                                                    */
  /* ------------------------------------------------------------------ */

  let minX = Infinity
  let maxX = -Infinity
  let minG = Infinity
  let maxG = -Infinity
  for (const p of racine.poses) {
    const w = largeur(p.id)
    minX = Math.min(minX, p.x - w / 2)
    maxX = Math.max(maxX, p.x + w / 2)
    minG = Math.min(minG, p.g)
    maxG = Math.max(maxG, p.g)
  }
  if (!racine.poses.length) {
    minX = maxX = 0
    minG = maxG = 0
  }
  const pasRangee = hauteurFiche + INTERLIGNE
  const decalage = -minX + MARGE_X
  const hautRangee = (g: number): number => (g - minG) * pasRangee + MARGE_Y
  const milieuRangee = (g: number): number => hautRangee(g) + hauteurFiche / 2

  const fiches: FicheHistoire[] = []
  const parId = new Map<string, FicheHistoire>()
  const parPersonId = new Map<number, FicheHistoire>()
  for (const p of racine.poses) {
    const noeud = personnes.get(p.id)!
    const w = largeur(p.id)
    const cx = p.x + decalage
    const numero = numeroDe.get(p.id) ?? null
    const fiche: FicheHistoire = {
      id: p.id,
      noeud,
      x: cx - w / 2,
      y: hautRangee(p.g),
      w,
      h: noeud.h || hauteurFiche,
      cx,
      rangee: p.g,
      directe: numero !== null,
      sosa: numero,
      detache: detaches.has(p.id),
    }
    fiches.push(fiche)
    parId.set(p.id, fiche)
    if (noeud.personId !== null) parPersonId.set(noeud.personId, fiche)
  }

  /* Les traits, famille par famille. */
  const traits: TracesHistoire = { ordinaires: '', directs: '', divorces: '', attente: '' }
  const seg = (x1: number, y1: number, x2: number, y2: number): string =>
    `M${x1.toFixed(1)} ${y1.toFixed(1)}L${x2.toFixed(1)} ${y2.toFixed(1)}`
  const aretesEnAttente = new Set<string>()
  for (const a of graphe.aretes) if (a.enAttente) aretesEnAttente.add(`${a.de}|${a.vers}`)
  const enAttente = (f: string, p: string): boolean =>
    aretesEnAttente.has(`${f}|${p}`) || aretesEnAttente.has(`${p}|${f}`)

  /*
   * Le tracé se fait en DEUX TEMPS.
   *
   * 1. Famille par famille, la géométrie : l'union (droite, ou qui CONTOURNE par
   *    le dessous quand d'autres fiches séparent les époux — jamais un trait
   *    derrière des cartes), le point d'où descendent les enfants, l'étendue de
   *    la barre de fratrie.
   * 2. Rangée par rangée, des COULOIRS : deux barres de fratrie qui se
   *    chevaucheraient à la même hauteur se confondaient à l'œil (« comme si ces
   *    personnes étaient liées ») ; chacune reçoit sa hauteur. Même chose pour les
   *    unions qui contournent.
   *
   * L'espace entre deux rangées (60 px) est partagé : le HAUT aux unions qui
   * contournent, sous la rangée des parents ; le BAS aux barres, au-dessus de la
   * rangée des enfants. Les deux familles de traits ne peuvent donc pas se croiser.
   */
  interface Intervalle {
    lo: number
    hi: number
    couloir: number
  }
  /** Répartit des intervalles d'une même rangée en couloirs sans chevauchement. */
  const repartir = (liste: Intervalle[], ecart: number): void => {
    const fins: number[] = []
    for (const it of [...liste].sort((a, b) => a.lo - b.lo)) {
      let c = fins.findIndex((fin) => fin + ecart <= it.lo)
      if (c < 0) {
        c = fins.length
        fins.push(it.hi)
      } else fins[c] = it.hi
      it.couloir = c
    }
  }
  const COULOIRS_DETOUR = 4
  const COULOIRS_BARRE = 5
  const yDetour = (rangee: number, couloir: number): number =>
    hautRangee(rangee) + hauteurFiche + 6 + 5 * Math.min(couloir, COULOIRS_DETOUR - 1)
  const yBarre = (rangee: number, couloir: number, directe: boolean): number =>
    hautRangee(rangee) - (directe ? BARRE_DIRECTE : BARRE_ORDINAIRE) - 6 * Math.min(couloir, COULOIRS_BARRE - 1)

  interface Trace {
    fam: string
    ancestrale: boolean
    style: 'attente' | 'divorces' | 'directs' | 'ordinaires'
    ep: FicheHistoire[]
    en: FicheHistoire[]
    /** Union droite (même rangée, rien entre les deux) ou contournement. */
    detour: (Intervalle & { rangee: number }) | null
    milieuX: number | null
    milieuY: number
    portee: [number, number] | null
    barre: (Intervalle & { rangee: number }) | null
    departX: number
    direct: FicheHistoire | undefined
  }

  const fichesParRangee = new Map<number, FicheHistoire[]>()
  for (const f of fiches) fichesParRangee.set(f.rangee, [...(fichesParRangee.get(f.rangee) ?? []), f])
  /** Une fiche, autre que les époux, entre les deux sur leur rangée ? */
  const separes = (a: FicheHistoire, b: FicheHistoire): boolean =>
    (fichesParRangee.get(a.rangee) ?? []).some((f) => f !== a && f !== b && f.x < b.x && f.x + f.w > a.x + a.w)

  const foyers: EtiquetteFoyer[] = []
  const traces: Trace[] = []
  for (const [fam, noeudFamille] of familles) {
    const ancestrale = famillesAncestrales.has(fam)
    const ep = epouxDe(fam)
      .map((e) => parId.get(e))
      .filter((e): e is FicheHistoire => e !== undefined)
      .sort((a, b) => a.cx - b.cx)
    const en = enfantsDe(fam)
      .map((e) => parId.get(e))
      .filter((e): e is FicheHistoire => e !== undefined)
    const style: Trace['style'] = ep.some((e) => enAttente(fam, e.id))
      ? 'attente'
      : noeudFamille.divorced
        ? 'divorces'
        : ancestrale
          ? 'directs'
          : 'ordinaires'

    let milieuX: number | null = null
    let milieuY = 0
    let portee: [number, number] | null = null
    let detour: Trace['detour'] = null
    if (ep.length >= 2) {
      const [a, b] = [ep[0]!, ep[ep.length - 1]!]
      const ya = a.y + a.h / 2
      if (a.rangee === b.rangee && separes(a, b)) {
        // Des fiches entre les époux : l'union contourne par le dessous.
        detour = { lo: a.cx, hi: b.cx, couloir: 0, rangee: a.rangee }
        milieuX = (a.cx + b.cx) / 2
        portee = [a.cx, b.cx]
      } else {
        const s = a.rangee === b.rangee ? seg(a.x + a.w, ya, b.x, ya) : seg(a.cx, a.y + a.h / 2, b.cx, b.y + b.h / 2)
        traits[style] += s
        milieuX = (a.x + a.w + b.x) / 2
        milieuY = ya
        if (a.rangee === b.rangee && b.x - (a.x + a.w) > 16) portee = [a.x + a.w + 8, b.x - 8]
      }
    } else if (ep.length === 1) {
      const p = ep[0]!
      milieuX = p.cx
      milieuY = p.y + p.h
      portee = [p.x + 12, p.x + p.w - 12]
    }

    if (ep.length) {
      const tete =
        ep.find((e) => e.noeud.sex === 'masculin') ?? ep.find((e) => e.noeud.sex === 'feminin') ?? ep[0]!
      const nom = nomDeFamilleDe(tete.noeud)
      if (nom !== '' && milieuX !== null) {
        const gauche = ep[0]!
        const droite = ep[ep.length - 1]!
        foyers.push({
          x: milieuX,
          y: hautRangee(gauche.rangee) - 8,
          nom,
          ancetres: ancestrale,
          envergure: droite.x + droite.w - gauche.x,
        })
      }
    }

    const direct = ancestrale ? en.find((e) => e.directe) : undefined
    let departX = milieuX ?? 0
    let barre: Trace['barre'] = null
    if (en.length && milieuX !== null) {
      const xs = en.map((e) => e.cx)
      const vise = direct ? direct.cx : (Math.min(...xs) + Math.max(...xs)) / 2
      departX = portee ? Math.min(portee[1], Math.max(portee[0], vise)) : milieuX
      const seul = en.length === 1 ? en[0]! : null
      if (!(seul && Math.abs(seul.cx - departX) < 0.5)) {
        barre = {
          lo: Math.min(departX, ...xs),
          hi: Math.max(departX, ...xs),
          couloir: 0,
          rangee: Math.min(...en.map((e) => e.rangee)),
        }
      }
    }
    traces.push({ fam, ancestrale, style, ep, en, detour, milieuX, milieuY, portee, barre, departX, direct })
  }

  // Les couloirs, rangée par rangée.
  const parRangee = <T extends { rangee: number }>(liste: T[]): Map<number, T[]> => {
    const m = new Map<number, T[]>()
    for (const it of liste) m.set(it.rangee, [...(m.get(it.rangee) ?? []), it])
    return m
  }
  for (const liste of parRangee(traces.flatMap((t) => (t.detour ? [t.detour] : []))).values()) repartir(liste, 10)
  for (const liste of parRangee(traces.flatMap((t) => (t.barre ? [t.barre] : []))).values()) repartir(liste, 10)

  // Le dessin.
  for (const t of traces) {
    const { ep, en, detour, milieuX, direct, ancestrale, fam } = t
    let departY = t.milieuY
    if (detour) {
      const [a, b] = [ep[0]!, ep[ep.length - 1]!]
      const y = yDetour(detour.rangee, detour.couloir)
      traits[t.style] += seg(a.cx, a.y + a.h, a.cx, y) + seg(a.cx, y, b.cx, y) + seg(b.cx, y, b.cx, b.y + b.h)
      departY = y
    }
    if (!en.length || milieuX === null) continue
    const cible = (s: string, directe: boolean, attente: boolean): void => {
      if (attente) traits.attente += s
      else if (directe) traits.directs += s
      else traits.ordinaires += s
    }
    if (!t.barre) {
      // Un enfant sous l'union : un trait droit.
      const seul = en[0]!
      cible(seg(t.departX, departY, t.departX, seul.y), ancestrale, enAttente(fam, seul.id))
      continue
    }
    const barre = yBarre(t.barre.rangee, t.barre.couloir, ancestrale)
    cible(seg(t.departX, departY, t.departX, barre), ancestrale, false)
    if (t.barre.hi > t.barre.lo) traits.ordinaires += seg(t.barre.lo, barre, t.barre.hi, barre)
    if (direct && Math.abs(direct.cx - t.departX) >= 0.5)
      traits.directs += seg(Math.min(t.departX, direct.cx), barre, Math.max(t.departX, direct.cx), barre)
    for (const e of en) cible(seg(e.cx, barre, e.cx, e.y), e === direct, enAttente(fam, e.id))
  }

  /* Les quarts : ceux du lecteur, puis ceux de son conjoint. */
  const fichesDetachees = fiches.filter((f) => f.detache)
  const zoneDetachee = fichesDetachees.length
    ? {
        x0: Math.min(...fichesDetachees.map((f) => f.x)),
        x1: Math.max(...fichesDetachees.map((f) => f.x + f.w)),
        cx: 0,
        effectif: fichesDetachees.length,
      }
    : null
  if (zoneDetachee) zoneDetachee.cx = (zoneDetachee.x0 + zoneDetachee.x1) / 2

  const quarts: Quart[] = []
  const conjointEgo = (famillesEpoux.get(ego) ?? []).flatMap((f) => epouxDe(f)).find((e) => e !== ego)
  const sosaConjoint = conjointEgo !== undefined ? sosaDepuis(conjointEgo).parNumero : new Map<number, string>()
  for (const branche of ['pp', 'pm', 'mp', 'mm', 'cpp', 'cpm', 'cmp', 'cmm'] as const) {
    const membres = fiches.filter((f) => f.noeud.branch === branche)
    if (!membres.length) continue
    const conjoint = branche.startsWith('c')
    const cle = (conjoint ? branche.slice(1) : branche) as 'pp' | 'pm' | 'mp' | 'mm'
    const tete = (conjoint ? sosaConjoint : sosa).get(SOSA_DU_QUART[cle])
    const noeudTete = tete !== undefined ? personnes.get(tete) : undefined
    quarts.push({
      branche,
      libelle: LIBELLES_QUART[branche],
      nom: noeudTete ? nomDeFamilleDe(noeudTete) : '',
      effectif: membres.length,
      cx: membres.reduce((s, f) => s + f.cx, 0) / membres.length,
      x0: Math.min(...membres.map((f) => f.x)),
      x1: Math.max(...membres.map((f) => f.x + f.w)),
    })
  }

  /* La gouttière des générations. */
  const generations: GenerationHistoire[] = []
  for (let g = minG; g <= maxG; g++) {
    const rangee = fiches.filter((f) => f.rangee === g)
    if (!rangee.length) continue
    const directs = rangee.filter((f) => f.directe)
    const annees = (directs.length ? directs : rangee).map((f) => anneeDe(f.noeud)).filter((a): a is number => a !== null)
    const moyenne = annees.length ? Math.round(annees.reduce((s, a) => s + a, 0) / annees.length / 10) * 10 : null
    generations.push({
      rangee: g,
      y: milieuRangee(g),
      libelle: libelleGeneration(g),
      annees: moyenne === null ? null : `vers ${moyenne}`,
      effectif: rangee.length,
    })
  }

  return {
    ego,
    fiches,
    parId,
    parPersonId,
    traces: traits,
    foyers,
    quarts,
    zoneDetachee,
    generations,
    largeur: maxX - minX + 2 * MARGE_X,
    hauteur: hautRangee(maxG) + hauteurFiche + MARGE_Y + 20,
    pasRangee,
  }
}
