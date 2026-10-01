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
 * **Les familles par alliance ont chacune leur bloc** (décision du 2026-10-01). Ce
 * que `histoire/alliances.ts` classe « par alliance » — l'ascendance du mari d'une
 * tante, vue de la nièce — n'entre pas dans les règles ci-dessus : chaque groupe est
 * mis en page À PART, comme sa propre généalogie (une disposition `lignees` dont le
 * pont est l'ego), puis posé d'un seul tenant à droite des branches du lecteur. Le
 * pont lui-même reste à côté de son conjoint. Rattachée famille par famille dans les
 * creux, cette ascendance s'intercalait dans la branche Voulgre (446 croisements de
 * traits sur l'arbre du diagnostic, `diagnostic-alliance.test.ts`).
 *
 * **La largeur d'une fiche est celle du moteur** (`w`, `h`) : la disposition
 * change la position des boîtes, jamais leur taille.
 *
 * Pur, sans DOM : tout ce fichier se vérifie dans `lignees.test.ts`.
 */
import { indexer } from '@/components/arbre/graphe'
import type { Branche, Graphe, IdentifiantNoeud, NoeudFamille, NoeudPersonne, Parente } from '@/lib/api'
import { NOM_D_UNE_PERSONNE_VIVANTE } from '@/lib/api/arbre'
import { classerParente, familleDesConjoints, groupesParAlliance } from './alliances'

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
  /** Ce qu'elle est pour le lecteur (`histoire/alliances.ts`). */
  parente: Parente
  /** Sur une fiche `alliance` : le conjoint « famille » qui rattache son bloc. */
  pont: IdentifiantNoeud | null
}

export interface EtiquetteFoyer {
  x: number
  y: number
  nom: string
  /** La personne dont le nom est écrit : une seule étiquette par personne et par rangée. */
  personne: IdentifiantNoeud
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

/** Le bloc d'une famille par alliance, à droite des branches du lecteur. */
export interface BlocAlliance {
  /** Le conjoint « famille » qui le rattache — il est posé, lui, dans l'arbre du lecteur. */
  pont: IdentifiantNoeud
  /** Nom de famille du pont : « Provost » ; vide s'il n'est pas lisible. */
  nom: string
  effectif: number
  cx: number
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
  /** Les familles par alliance présentes dans le graphe, de gauche à droite. */
  alliances: BlocAlliance[]
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
 * Le nom qu'une fiche écrit : `etiquetteNom`, sauf sur un nœud RESTREINT, qui n'en a
 * plus (révision du 2026-10-01, ADR 0003) — on y écrit « Personne vivante », sans
 * relire ce que le serveur a envoyé : un serveur antérieur y mettait le vrai nom.
 */
export function nomEcrit(noeud: NoeudPersonne): string {
  if (noeud.visibilite === 'restreinte') return NOM_D_UNE_PERSONNE_VIVANTE
  return typeof noeud.etiquetteNom === 'string' ? noeud.etiquetteNom : ''
}

/**
 * Le prénom dessiné : premier mot de `etiquetteNom`. Le nom de famille peut
 * contenir des espaces, le prénom non — par construction (§ 5.2). Vide sur un nœud
 * restreint : « Personne » seul ne voudrait rien dire.
 */
export function prenomDe(noeud: NoeudPersonne): string {
  if (noeud.visibilite === 'restreinte') return ''
  const nom = typeof noeud.etiquetteNom === 'string' ? noeud.etiquetteNom : ''
  const espace = nom.indexOf(' ')
  return espace < 0 ? nom : nom.slice(0, espace)
}

/**
 * Le nom de famille : ce qui suit le premier mot de `etiquetteNom`. Vide sur un nœud
 * restreint — sans quoi un foyer s'étiquetterait « vivante ».
 */
export function nomDeFamilleDe(noeud: NoeudPersonne): string {
  if (noeud.visibilite === 'restreinte') return ''
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

/**
 * Le graphe sans la famille des conjoints de l'ego — leur ascendance, leurs
 * collatéraux et leurs alliés : ces fiches et les liens qui les touchent sont RETIRÉS
 * avant la disposition, pour que l'arbre se recompacte (les filtres de branche, eux,
 * estompent sans rien déplacer). Les conjoints eux-mêmes et les enfants restent.
 *
 * Le partage se lit sur les LIENS, pas sur les couleurs : le serveur ne découpe en
 * branches « conjoint » que l'ascendance d'un seul conjoint, et un second mariage doit
 * se masquer aussi. Est du côté de l'ego tout ce qu'on atteint depuis lui sans passer
 * par un de ses conjoints ; est de la famille d'un conjoint ce qu'on atteint depuis ses
 * parents sans repasser par le côté de l'ego. Une personne des deux côtés (un arbre
 * endogame) reste.
 */
export function sansFamilleDuConjoint(graphe: Graphe): Graphe {
  // Le parcours des liens vit dans `alliances.ts`, avec la classification qu'il nourrit.
  const retires = familleDesConjoints(graphe)
  if (!retires.size) return graphe
  return {
    ...graphe,
    noeuds: graphe.noeuds.filter((n) => !retires.has(n.id)),
    aretes: graphe.aretes.filter((a) => !retires.has(a.de) && !retires.has(a.vers)),
  }
}

type Cote = 'G' | 'D'

/** Une ascendance numérotée : Sosa → personne, et Sosa pair → famille du couple. */
interface Lignee {
  sosa: Map<number, string>
  couples: Map<number, string>
}

/** Au-delà, un bloc rattaché va au bord de l'arbre plutôt que dans un creux. */
const PETIT_BLOC = 12

export interface OptionsDeDisposition {
  /**
   * La mise en page d'UNE famille par alliance, depuis son pont : tout y est « de la
   * famille » de ce pont, on ne reclasse rien (sinon chaque groupe se viderait).
   */
  interne?: boolean
}

export function disposerLignees(graphe: Graphe, options: OptionsDeDisposition = {}): DispositionHistoire {
  const index = indexer(graphe)
  const personnes = new Map<string, NoeudPersonne>()
  for (const p of index.personnes) personnes.set(p.id, p)
  const familles = new Map<string, NoeudFamille>()
  for (const f of index.familles) familles.set(f.id, f)

  /*
   * Les familles par alliance, par pont. Tant qu'elles ne sont pas posées, elles sont
   * HORS JEU : aucune règle de placement ne les voit — ni la descendance d'un
   * collatéral, ni le rattrapage dans les creux. Elles entrent d'un bloc, après.
   */
  const classes = options.interne ? null : classerParente(graphe)
  const groupes = options.interne ? new Map<string, string[]>() : groupesParAlliance(graphe)
  const horsJeu = new Set([...groupes.values()].flat())
  const enJeu = (id: string): boolean => personnes.has(id) && !horsJeu.has(id)

  const enfantsDe = (f: string): string[] => (index.enfantsParFamille.get(f) ?? []).filter(enJeu)
  const epouxDe = (f: string): string[] => {
    const enfants = new Set(index.enfantsParFamille.get(f) ?? [])
    return (index.membresParFamille.get(f) ?? []).filter((id) => !enfants.has(id) && enJeu(id))
  }
  const famillesEnfant = new Map<string, string[]>()
  const famillesEpoux = new Map<string, string[]>()
  function indexerLesFamilles(): void {
    for (const f of familles.keys()) {
      for (const e of enfantsDe(f)) famillesEnfant.set(e, [...(famillesEnfant.get(e) ?? []), f])
      for (const e of epouxDe(f)) famillesEpoux.set(e, [...(famillesEpoux.get(e) ?? []), f])
    }
  }
  indexerLesFamilles()
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

  /**
   * Numérotation de Sosa depuis une personne. Un ancêtre déjà numéroté (implexe) n'est
   * pas repris — ni un de ceux de `dejaNumerotes` (l'ascendance de l'ego, quand on
   * numérote celle de son conjoint).
   */
  function sosaDepuis(
    racine: string,
    dejaNumerotes: ReadonlySet<string> = new Set(),
  ): { parNumero: Map<number, string>; couples: Map<number, string> } {
    const parNumero = new Map<number, string>([[1, racine]])
    const vus = new Set([racine, ...dejaNumerotes])
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
  const lignee: Lignee = { sosa, couples }

  /*
   * L'ascendance du CONJOINT de l'ego est un arbre à part entière, pas une suite
   * d'alliés : elle se construit comme celle de l'ego (Sosa, fratries) et se pose d'un
   * seul tenant à droite de l'arbre de l'ego. Rattachée famille par famille, elle
   * s'éparpillait dans les creux et s'entremêlait à celle de l'ego (constaté en prod,
   * vue d'un père : l'ascendance de sa femme à gauche de ses Provost, en ordre inverse).
   */
  /** Tous les ancêtres structurés — de l'ego et de ses conjoints : ni collatéraux, ni alliés. */
  const ancetres = new Set(numeroDe.keys())
  /*
   * PLUSIEURS CONJOINTS : chacun dont les parents sont connus a son ascendance en arbre,
   * dans l'ordre des unions, un bloc après l'autre. Un aïeul commun (arbre endogame)
   * reste à celui qui l'a numéroté le premier — l'ego d'abord.
   */
  const lignees: { conjoint: string; lignee: Lignee }[] = []
  for (const c of (famillesEpoux.get(ego) ?? []).flatMap((f) => epouxDe(f))) {
    if (c === ego || ancetres.has(c) || lignees.some((l) => l.conjoint === c)) continue
    const { parNumero, couples: cc } = sosaDepuis(c, ancetres)
    if (!cc.size) continue
    for (const [n, id] of parNumero) if (n > 1) ancetres.add(id)
    lignees.push({ conjoint: c, lignee: { sosa: parNumero, couples: cc } })
  }
  /** Celui dont le serveur a découpé l'ascendance en quarts « conjoint » (un seul). */
  const ligneeConjoint =
    lignees.find(({ lignee }) =>
      [2, 3].some((n) => {
        const b = personnes.get(lignee.sosa.get(n) ?? '')?.branch
        return b === 'cpp' || b === 'cmp'
      }),
    )?.lignee ??
    lignees[0]?.lignee ??
    null
  /** Les couples de la lignée directe de l'ego : leurs traits sont ceux de la ligne directe. */
  const famillesDirectes = new Set(couples.values())
  /** Tous les couples structurés : un collatéral ne les reprend pas. */
  const famillesAncestrales = new Set([...couples.values(), ...lignees.flatMap(({ lignee }) => [...lignee.couples.values()])])

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
      const conjoint = epouxDe(fam).find((e) => e !== pivot && !poses.has(e) && !ancetres.has(e))
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
        .filter((e) => !poses.has(e) && !ancetres.has(e))
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

  /**
   * La place d'une fiche DÉJÀ posée ailleurs : sa rangée est réservée, sans fiche.
   * C'est le conjoint de l'ego au pied de sa propre ascendance — il est posé à côté de
   * l'ego, et ses parents se rangent au-dessus de cette place.
   */
  function fantome(id: string, g: number): Forme {
    const w = largeur(id)
    return { poses: [], rangees: new Map([[g, [-w / 2, w / 2]]]), ancre: 0 }
  }

  /**
   * Un ancêtre et ses AUTRES unions : un remariage (le second conjoint du côté
   * extérieur, les demi-frères et sœurs dessous), ou un enfant qu'il ou elle a eu
   * seul·e. Rattachées après coup, ces familles ne trouvaient pas de creux dans une
   * ascendance dense et partaient loin, d'où des barres de fratrie qui traversaient
   * tout le bloc (diagnostic du 01/10 : 413 croisements sur une ascendance de six
   * générations, 0 sans ces familles). Posées ici, elles font partie de la forme et
   * l'empilement par contours leur fait la place. L'union des ancêtres, elle, est
   * ancestrale : `blocDescendance` ne la reprend pas.
   */
  function ancetreEtSesUnions(id: string, n: number, g: number): Forme {
    if (n === 1 || !aLExterieur(n)) return seule(id, g)
    return blocDescendance(id, n % 2 === 0 ? 'G' : 'D', g)
  }

  /**
   * L'ancêtre `n` est-il du côté EXTÉRIEUR de son couple ? Le père d'un couple du côté
   * père de l'arbre (et des parents de l'ego), la mère d'un couple du côté mère. De
   * l'autre côté, l'extérieur du couple, c'est son conjoint : un second conjoint ou des
   * demi-frères posés là s'intercaleraient entre les deux époux.
   */
  function aLExterieur(n: number): boolean {
    const couple = n >> 1
    if (couple === 1) return true
    return n % 2 === 0 ? couple % 2 === 0 : couple % 2 === 1
  }

  /** La forme d'un ancêtre : sa propre ascendance s'il en a une, sinon sa fiche. */
  function formePersonne(L: Lignee, n: number): Forme {
    if (L.couples.has(2 * n)) return formeCouple(L, 2 * n)
    const id = L.sosa.get(n)!
    return poses.has(id) ? fantome(id, -generationSosa(n)) : ancetreEtSesUnions(id, n, -generationSosa(n))
  }

  /** Le couple d'ancêtres de Sosa `n` (pair) et `n + 1`, avec toute leur fratrie. */
  function formeCouple(L: Lignee, n: number): Forme {
    const { sosa, couples } = L
    const m = n >> 1
    const g = -generationSosa(n)
    const cote: 'G' | 'D' | 'C' = m === 1 ? 'C' : m % 2 === 0 ? 'G' : 'D'
    const fam = couples.get(n)!
    const direct = sosa.get(m)!

    const FP = sosa.has(n) ? formePersonne(L, n) : null
    const FM = sosa.has(n + 1) ? formePersonne(L, n + 1) : null

    const fratrie = enfantsDe(fam)
      .filter((e) => e === direct || (!poses.has(e) && !ancetres.has(e)))
      .sort((a, b) => anneeTri(a) - anneeTri(b))
    if (!fratrie.includes(direct)) fratrie.push(direct)
    const autres = fratrie.filter((e) => e !== direct)
    const ordre = cote === 'G' ? [...autres, direct] : cote === 'D' ? [direct, ...autres] : fratrie
    const iDirect = ordre.indexOf(direct)

    const parties = ordre.map((e, i) => {
      if (e === direct) {
        if (poses.has(e)) return fantome(e, g + 1)
        return m === 1 ? blocDescendance(e, 'D', g + 1) : ancetreEtSesUnions(e, m, g + 1)
      }
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
      }
      /*
       * La fratrie se pose ENTRE ce que chaque parent porte sous sa rangée : à droite
       * de ce qui pend sous le père (ses demi-enfants, les enfants de ses frères et
       * sœurs, rangés à sa gauche), à gauche de ce qui pend sous la mère. Contre la
       * forme des deux réunis, elle était repoussée au-delà de TOUT ce qui pend d'un
       * côté — un remariage du père, côté mère de l'arbre, envoyait l'enfant direct
       * loin à gauche de l'union de ses parents. Sans la place, la mère s'écarte.
       */
      const borne = (v: number, defaut: number): number => (Number.isFinite(v) ? v : defaut)
      const lo = FP ? borne(besoinADroite(FP, G, ECART_BLOC), -Infinity) : -Infinity
      let hi = FM ? borne(besoinAGauche(FM, G, ECART_BLOC), Infinity) : Infinity
      if (lo > hi && FM) {
        decaler(FM, lo - hi)
        hi = lo
      }
      fx = FP ? FP.ancre : null
      mx = FM ? FM.ancre : null
      if (FP && FM) forme = fusionner(FP, FM)
      else forme = (FP ?? FM)!
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
      const dx = Math.min(hi, Math.max(lo, cibleX - ancres[iDirect]!))
      decaler(G, dx)
      for (let i = 0; i < ancres.length; i++) ancres[i]! += dx
      fusionner(forme, G)
    }
    /*
     * Les autres unions du parent INTÉRIEUR (le père quand l'enfant direct est une
     * mère, la mère quand c'est un père) : posées à son côté extérieur, elles
     * s'intercaleraient entre lui et son conjoint, et ses demi-enfants entre l'enfant
     * direct et le sien. Elles vont donc au-delà de tout le couple, du côté extérieur
     * de la fratrie : l'union contourne par le dessous, un seul trait la croise (celui
     * qui descend du couple). Rattachées dans les creux, elles en croisaient des dizaines.
     */
    const interieur = cote === 'D' ? sosa.get(n) : cote === 'G' ? sosa.get(n + 1) : undefined
    if (interieur !== undefined && poses.has(interieur)) {
      const autres = autresUnions(interieur, g, cote as Cote)
      if (autres) {
        const d = cote === 'D' ? besoinADroite(forme, autres, ECART_BLOC) : besoinAGauche(forme, autres, ECART_BLOC)
        decaler(autres, Number.isFinite(d) ? d : 0)
        fusionner(forme, autres)
      }
    }
    forme.ancre = ancres[iDirect]!
    return forme
  }

  /**
   * Les unions non ancestrales d'un ancêtre déjà posé, d'un seul bloc : chaque autre
   * conjoint avec sa descendance (`blocDescendance`), ou les enfants qu'il a eus seul.
   */
  function autresUnions(id: string, g: number, sens: Cote): Forme | null {
    const parties: Forme[] = []
    for (const fam of famillesEpoux.get(id) ?? []) {
      if (famillesAncestrales.has(fam)) continue
      const conjoint = epouxDe(fam).find((e) => e !== id && !poses.has(e) && !ancetres.has(e))
      if (conjoint !== undefined) {
        parties.push(blocDescendance(conjoint, sens, g))
        continue
      }
      const enfants = enfantsDe(fam)
        .filter((e) => !poses.has(e) && !ancetres.has(e))
        .sort((a, b) => anneeTri(a) - anneeTri(b))
      if (!enfants.length) continue
      parties.push(empiler(enfants.map((e) => blocDescendance(e, sens, g + 1)), ECART_FRATRIE).forme)
    }
    if (!parties.length) return null
    // Du plus proche au plus lointain : vers l'extérieur, dans l'ordre des unions.
    const ordre = sens === 'D' ? parties : parties.reverse()
    return empiler(ordre, ECART_BLOC).forme
  }

  /* L'arbre principal. */
  let racine: Forme
  if (couples.has(2)) racine = formeCouple(lignee, 2)
  else racine = blocDescendance(ego, 'D', 0)

  /*
   * Les ZONES : 0 pour l'ego, puis une par conjoint. Un allié rattaché plus tard se
   * pose dans la zone de la personne qui le relie, sans empiéter sur les autres —
   * sinon la demi-tante de l'ego se glissait dans le creux le plus proche, chez les
   * beaux-parents.
   */
  const bord = (f: Forme, cote: Cote): number => {
    let v = cote === 'G' ? Infinity : -Infinity
    for (const [a, b] of f.rangees.values()) v = cote === 'G' ? Math.min(v, a) : Math.max(v, b)
    return v
  }
  const zoneDe = new Map<string, number>()
  const etendues: Map<number, [number, number]>[] = []
  const etendre = (zone: number, f: Forme): void => {
    const e = (etendues[zone] ??= new Map())
    for (const [g, [a, b]] of f.rangees) {
      const r = e.get(g)
      if (r) {
        r[0] = Math.min(r[0], a)
        r[1] = Math.max(r[1], b)
      } else e.set(g, [a, b])
    }
    for (const p of f.poses) zoneDe.set(p.id, zone)
  }
  etendre(0, racine)

  /*
   * L'ascendance de chaque conjoint, d'un seul tenant, à droite de ce qui précède —
   * APRÈS les alliés de l'ego, pour que ceux du bord droit gardent leur place au bord
   * de sa zone au lieu de buter sur le mur de la suivante.
   */
  function poserLesConjoints(): void {
    for (const { conjoint, lignee: l } of lignees) {
      if (!poses.has(conjoint) || !l.couples.has(2)) continue
      const bloc = formeCouple(l, 2)
      // Strictement à droite de tout ce qui précède, toutes rangées confondues : une
      // colonne par famille, et des murs nets entre les zones.
      decaler(bloc, bord(racine, 'D') + ECART_ZONE - bord(bloc, 'G'))
      etendre(etendues.length, bloc)
      fusionner(racine, bloc)
    }
  }
  /** Les ancêtres des conjoints : ils attendent leur bloc, aucun allié ne les prend. */
  const ancetresDesConjoints = new Set(
    lignees.flatMap(({ lignee: l }) => [...l.sosa].filter(([n]) => n > 1).map(([, id]) => id)),
  )

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
  function accrocher(bloc: Forme, cote: Cote, cible?: number, zone = 0): void {
    if (cible !== undefined && bloc.poses.length <= PETIT_BLOC) {
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
      // Les autres zones sont des MURS : le bloc ne les traverse pas pour trouver un creux
      // plus proche de l'autre côté. Les zones vont de gauche à droite dans l'ordre.
      let murG = -Infinity
      let murD = Infinity
      etendues.forEach((e, z) => {
        for (const [a, b] of e.values()) {
          if (z < zone) murG = Math.max(murG, b)
          if (z > zone) murD = Math.min(murD, a)
        }
      })
      for (const g of new Set(bloc.poses.map((p) => p.g))) {
        if (murG > -Infinity) prendre(g, -Infinity, murG)
        if (murD < Infinity) prendre(g, murD, Infinity)
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
      const dx = Math.abs(dInt - depart) < Math.abs(dExt - depart) ? dInt : dExt
      // Muré des deux côtés sans creux : le bord de l'arbre, comme un grand bloc.
      if (Number.isFinite(dx)) {
        decaler(bloc, dx)
        etendre(zone, bloc)
        fusionner(racine, bloc)
        return
      }
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
    etendre(zone, bloc)
    fusionner(racine, bloc)
  }

  function accrocherLesProches(exclus: ReadonlySet<string> = new Set()): void {
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
        if ([...epLibres, ...enLibres].some((e) => exclus.has(e))) continue

        const attache = epPoses[0] ?? enPoses[0]!
        const cote: Cote = (x.get(attache) ?? 0) < xEgo ? 'G' : 'D'
        const rangee = rangeeDe.get(attache)! + (epPoses.length ? 0 : -1)

        const xAttache = x.get(attache) ?? 0
        const zone = zoneDe.get(attache) ?? 0
        if (epLibres.length) {
          for (const e of epLibres) {
            if (poses.has(e)) continue
            // À côté du conjoint posé, ou au-dessus de l'enfant posé.
            const pas = epPoses.length ? largeur(attache) / 2 + ECART_FRATRIE + largeur(e) / 2 : 0
            accrocher(blocDescendance(e, cote, rangee), cote, cote === 'G' ? xAttache - pas : xAttache + pas, zone)
          }
        } else {
          // Les époux sont posés, il reste des enfants : une fratrie issue d'une autre union.
          const parties = enLibres
            .sort((a, b) => anneeTri(a) - anneeTri(b))
            .map((e) => blocDescendance(e, cote, rangee + 1))
          const { forme, ancres } = empiler(parties, ECART_FRATRIE)
          const milieu = epPoses.length ? epPoses.reduce((s, e) => s + (x.get(e) ?? 0), 0) / epPoses.length : xAttache
          forme.ancre = (ancres[0]! + ancres[ancres.length - 1]!) / 2
          accrocher(forme, cote, milieu, zone)
        }
        progres = true
        break
      }
    }
  }
  accrocherLesProches(ancetresDesConjoints)
  poserLesConjoints()
  accrocherLesProches()

  /*
   * Les FAMILLES PAR ALLIANCE, une colonne chacune, à droite de tout ce qui précède,
   * dans l'ordre de leurs ponts de gauche à droite. Chacune est mise en page comme sa
   * propre généalogie — une disposition `lignees` dont le pont est l'ego : son
   * ascendance en Sosa, ses fratries, ses remariages —, puis posée d'un seul tenant.
   * Le pont n'y figure qu'en place réservée : il est déjà posé à côté de son conjoint,
   * et ses parents se rangent au-dessus de cette place, comme ceux du conjoint de l'ego.
   */
  const pontDe = new Map<string, string>()
  const blocsAlliance: { pont: string; ids: string[] }[] = []
  function poserLesAlliances(): void {
    const x = xDe()
    const ordre = [...groupes].filter(([pont]) => poses.has(pont)).sort(([a], [b]) => x.get(a)! - x.get(b)!)
    for (const [pont, membres] of ordre) {
      const sous = disposerLignees(sousGraphe(pont, membres), { interne: true })
      const g0 = rangeeDe.get(pont)!
      const bloc = vide()
      const ids: string[] = []
      for (const fi of sous.fiches) {
        const g = fi.rangee + g0
        if (fi.id === pont) {
          // La place du pont : réservée, sans fiche.
          const r = bloc.rangees.get(g)
          const [a, b] = [fi.cx - fi.w / 2, fi.cx + fi.w / 2]
          if (r) {
            r[0] = Math.min(r[0], a)
            r[1] = Math.max(r[1], b)
          } else bloc.rangees.set(g, [a, b])
          continue
        }
        // Ni le pont, ni un membre déjà posé (par un autre chemin) : pas deux fois la même fiche.
        if (poses.has(fi.id)) continue
        poser(bloc, fi.id, fi.cx, g)
        ids.push(fi.id)
        pontDe.set(fi.id, pont)
      }
      if (!ids.length) continue
      decaler(bloc, bord(racine, 'D') + ECART_ZONE - bord(bloc, 'G'))
      etendre(etendues.length, bloc)
      fusionner(racine, bloc)
      blocsAlliance.push({ pont, ids })
    }
    // Ce qui reste (un groupe dont le pont n'est pas dans l'arbre) rentre dans le jeu
    // ordinaire : rattaché comme avant plutôt que perdu.
    horsJeu.clear()
    famillesEnfant.clear()
    famillesEpoux.clear()
    indexerLesFamilles()
    accrocherLesProches()
  }

  /** Le pont, son groupe, et les familles qui touchent le groupe — rien d'autre. */
  function sousGraphe(pont: string, membres: string[]): Graphe {
    const estMembre = new Set(membres)
    const garde = new Set([pont, ...membres])
    for (const a of graphe.aretes) {
      if (estMembre.has(a.de) && familles.has(a.vers)) garde.add(a.vers)
      if (estMembre.has(a.vers) && familles.has(a.de)) garde.add(a.de)
    }
    return {
      ...graphe,
      ego: pont,
      noeuds: graphe.noeuds.filter((n) => garde.has(n.id)),
      aretes: graphe.aretes.filter((a) => garde.has(a.de) && garde.has(a.vers)),
    }
  }
  if (groupes.size) poserLesAlliances()

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
      // Chaque îlot est sa propre zone : ses alliés ne retournent pas dans l'arbre.
      accrocher(blocDescendance(sommet[0], 'D', rangee), 'D', undefined, etendues.length)
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
      parente: pontDe.has(p.id) ? 'alliance' : (classes?.get(p.id)?.parente ?? 'famille'),
      pont: pontDe.get(p.id) ?? null,
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
    const ancestrale = famillesDirectes.has(fam)
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

    if (ep.length && milieuX !== null) {
      // Le mari, sinon l'épouse, sinon qui reste — le premier dont le nom se lit (un
      // nœud restreint n'en a pas).
      const tete = [
        ...ep.filter((e) => e.noeud.sex === 'masculin'),
        ...ep.filter((e) => e.noeud.sex === 'feminin'),
        ...ep.filter((e) => e.noeud.sex !== 'masculin' && e.noeud.sex !== 'feminin'),
      ].find((e) => nomDeFamilleDe(e.noeud) !== '')
      if (tete) {
        const gauche = ep[0]!
        const droite = ep[ep.length - 1]!
        // Une union qui contourne a ses époux loin l'un de l'autre : centrée entre eux,
        // l'étiquette tombait sur les fiches intercalées. Elle se pose sur la tête.
        const [x, envergure] = detour ? [tete.cx, tete.w] : [milieuX, droite.x + droite.w - gauche.x]
        foyers.push({
          x,
          y: hautRangee(tete.rangee) - 8,
          nom: nomDeFamilleDe(tete.noeud),
          personne: tete.id,
          ancetres: ancestrale,
          envergure,
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

  /*
   * UNE étiquette par personne et par rangée. Un homme remarié, ou une mère dont un
   * enfant est né hors union (un foyer à elle seule), écrivait son nom deux fois à une
   * demi-fiche d'écart : « Provos Provost », « Delobe Delobel » (captures du 01/10).
   * On garde le foyer des ancêtres, sinon le plus large.
   */
  const unParPersonne = new Map<string, EtiquetteFoyer>()
  for (const e of foyers) {
    const cle = `${e.personne}|${e.y}`
    const deja = unParPersonne.get(cle)
    if (!deja || Number(e.ancetres) - Number(deja.ancetres) > 0 || (e.ancetres === deja.ancetres && e.envergure > deja.envergure))
      unParPersonne.set(cle, e)
  }
  foyers.splice(0, foyers.length, ...unParPersonne.values())

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
  const sosaConjoint = ligneeConjoint?.sosa ?? new Map<number, string>()
  for (const branche of ['pp', 'pm', 'mp', 'mm', 'cpp', 'cpm', 'cmp', 'cmm'] as const) {
    // Une fiche par alliance n'est d'aucun quart, quelle que soit la branche servie : la
    // bande « Voulgre » s'étirait sur toute l'ascendance du mari de la tante.
    const membres = fiches.filter((f) => f.noeud.branch === branche && f.parente !== 'alliance')
    if (!membres.length) continue
    const duConjoint = branche.startsWith('c')
    const cle = (duConjoint ? branche.slice(1) : branche) as 'pp' | 'pm' | 'mp' | 'mm'
    const tete = (duConjoint ? sosaConjoint : sosa).get(SOSA_DU_QUART[cle])
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

  /* Les blocs des familles par alliance, de gauche à droite. */
  const alliances: BlocAlliance[] = blocsAlliance.map(({ pont, ids }) => {
    const membres = ids.map((id) => parId.get(id)!)
    const x0 = Math.min(...membres.map((f) => f.x))
    const x1 = Math.max(...membres.map((f) => f.x + f.w))
    return { pont, nom: nomDeFamilleDe(personnes.get(pont)!), effectif: membres.length, cx: (x0 + x1) / 2, x0, x1 }
  })

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
    alliances,
    zoneDetachee,
    generations,
    largeur: maxX - minX + 2 * MARGE_X,
    hauteur: hautRangee(maxG) + hauteurFiche + MARGE_Y + 20,
    pasRangee,
  }
}
