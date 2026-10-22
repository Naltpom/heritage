/**
 * **`/arbre-review` : l'arbre en rectangles, redessiné comme un graphe en couches.**
 *
 * Demande du commanditaire, le 22/10/2026 : `/arbre` n'est pas lisible — cartes tassées,
 * liens qui traversent tout un groupe. Cette page est EXACTEMENT `/arbre` (mêmes données,
 * mêmes cartes, mêmes interactions, même écran autour : `ArbreGroupesView.vue`, à qui
 * l'on passe ce moteur) ; seules changent la disposition des cartes et les lignes.
 *
 * Le moteur, étape par étape :
 *
 *  1. `preparation.ts` — les groupes, les rangées, la lignée directe (les règles de
 *     `/arbre`), puis les PARTIES — un rectangle par quart, comme sur `/arbre` — et les
 *     FOYERS.
 *  2. `ordre.ts` — l'ordre de chaque rangée : un départ en sablier (la forme d'un arbre
 *     généalogique), puis barycentres, transpositions et retournements de foyers, en
 *     gardant le meilleur — le moins de croisements (comptés comme le routage les trace :
 *     des barres de fratrie sur des pistes), chaque famille d'un seul tenant.
 *  3. `abscisses.ts` — les abscisses : des liens courts et droits (chaque fratrie centrée
 *     sous ses parents), sous des écarts généreux, et des rectangles qui ne se
 *     chevauchent jamais.
 *  4. `routage.ts` — les traits, orthogonaux, dans des couloirs dont la hauteur suit le
 *     nombre de pistes : aucun sous une carte.
 *
 * La surface n'est pas un objectif (précision du commanditaire) : l'arbre est plus grand
 * que `/arbre`, et c'est voulu. Le zoom, le cadrage et la mini-carte suivent la taille du
 * monde (`histoire/vue.ts › echelleMin`, `MiniCarte.vue`).
 *
 * Pur, sans DOM : tout se vérifie dans `disposition.test.ts`, et se mesure contre `/arbre`
 * dans `revue.banc.ts`.
 */
import type { Graphe, IdentifiantNoeud } from '@/lib/api'
import {
  LIBELLES_GROUPE,
  type CleDeGroupe,
  type DispositionGroupes,
  type OptionsGroupes,
  type RectangleDeGroupe,
} from '@/arbre2/groupes'
import {
  anneeDe,
  libelleGeneration,
  nomDeFamilleDe,
  type BlocAlliance,
  type BrancheDeQuart,
  type FicheHistoire,
  type GenerationHistoire,
  type Quart,
} from '@/histoire/lignees'
import { preparer } from './preparation'
import { affiner, compterBus, construireCouches, ordreEnSablier, type VarianteDeSablier } from './ordre'
import { placer } from './abscisses'
import { router } from './routage'

/* ------------------------------------------------------------------ */
/* Les écarts : de l'air                                               */
/* ------------------------------------------------------------------ */

/** Entre deux époux : la place de l'union et de la descente. */
export const ECART_COUPLE = 40
/** Entre deux frères et sœurs. */
export const ECART_FRATRIE = 56
/** Entre deux familles : on voit où l'une finit et où l'autre commence. */
export const ECART_FAMILLE = 176
/** La marge intérieure d'un rectangle, et le bandeau de son titre. */
export const MARGE_RECTANGLE = 22
export const BANDEAU_RECTANGLE = 22
/** Combien de largeurs de carte entre deux rectangles (comme `/arbre`). */
export const CARTES_ENTRE_RECTANGLES = 2
/** Le couloir le plus étroit entre deux rangées (60 sur `/arbre`). */
export const COULOIR_MIN = 116
/** La place réservée, entre deux fiches, au trait d'un lien qui saute une génération. */
const LARGEUR_FACTICE = 16
const MARGE_X = 60
const MARGE_Y = 40

const QUARTS = new Set<string>(['pp', 'pm', 'mp', 'mm', 'cpp', 'cpm', 'cmp', 'cmm'])
const SOSA_DU_QUART: Record<'pp' | 'pm' | 'mp' | 'mm', number> = { pp: 4, pm: 5, mp: 6, mm: 7 }

/** Ce que le banc peut régler pour comparer (l'écran ne passe rien : les défauts). */
export interface ReglagesDuMoteur {
  /** Les ordres de départ des fratries essayés ; le meilleur est gardé. */
  departs?: VarianteDeSablier[]
  /** `false` : le sablier seul, sans affinage (pour mesurer ce que l'affinage apporte). */
  affiner?: boolean
  /** `false` : sans l'alignement final des fratries (pour mesurer ce qu'il apporte). */
  aligner?: boolean
  /** L'ordre des rectangles (`COLONNES_PAR_DEFAUT` sinon). */
  colonnes?: readonly CleDeGroupe[]
}

/** Jusqu'à combien de personnes on essaie les ordres de rectangles (au-delà, celui de `/arbre`). */
const PERSONNES_POUR_ESSAYER_LES_COLONNES = 6000

/** Les ordres de rectangles possibles : chaque paire de quarts dans les deux sens, le conjoint avant ou après la mère. */
function ordresDesColonnes(s: ReturnType<typeof preparer>): CleDeGroupe[][] {
  const presents = new Set(s.parties.map((p) => p.groupe))
  const sens = (paire: CleDeGroupe[]): CleDeGroupe[][] => {
    const l = paire.filter((g) => presents.has(g))
    return l.length > 1 ? [l, [...l].reverse()] : [l]
  }
  const conjoint = (['cpm', 'cpp', 'cmp', 'cmm'] as CleDeGroupe[]).filter((g) => presents.has(g))
  const fin = (['alliances', 'sansLien'] as CleDeGroupe[]).filter((g) => presents.has(g))
  const tronc: CleDeGroupe[] = presents.has('tr') ? ['tr'] : []
  const ordres: CleDeGroupe[][] = []
  for (const paternel of sens(['pp', 'pm']))
    for (const maternel of sens(['mp', 'mm'])) {
      ordres.push([...paternel, ...tronc, ...maternel, ...conjoint, ...fin])
      if (maternel.length && conjoint.length) ordres.push([...paternel, ...tronc, ...conjoint, ...maternel, ...fin])
    }
  return ordres
}

/** Pose l'ordre des rectangles : la colonne de chaque partie. */
function poserLesColonnes(s: ReturnType<typeof preparer>, ordre: readonly CleDeGroupe[]): void {
  for (const p of s.parties) p.colonne = Math.max(0, ordre.indexOf(p.groupe))
}

export function disposerEnRevue(graphe: Graphe, options: OptionsGroupes = {}, reglages: ReglagesDuMoteur = {}): DispositionGroupes {
  /* 1. La structure. */
  const s = preparer(graphe, { alliances: options.alliances, ecartCouple: ECART_COUPLE, colonnes: reglages.colonnes })
  const ecartParties = CARTES_ENTRE_RECTANGLES * s.largeurDeCarte + 2 * MARGE_RECTANGLE

  /* 2. L'ordre. */
  const fratriesParNaissance = options.fratriesParNaissance === true
  // Deux départs (l'ordre servi des fratries, et l'inverse) ; on garde celui qui croise le
  // moins. Avec les fratries par ordre de naissance, un seul : celui des naissances.
  const variantes: VarianteDeSablier[] = fratriesParNaissance ? ['naissance'] : (reglages.departs ?? ['servi', 'inverse'])
  const optionsDOrdre = { fratriesParNaissance, largeurFactice: LARGEUR_FACTICE }
  /*
   * L'ordre des rectangles, comme `/arbre` (`groupes.ts`, étape 5) : le côté du père reste à
   * gauche du tronc, celui de la mère et celui du conjoint à droite, mais le sens de chaque
   * paire de quarts n'est pas neutre — le lien du père vers le couple de ses parents traverse
   * le quart qui est entre eux et le tronc, plein ou vide à cette hauteur. Chaque ordre
   * possible s'estime par un tour d'affinage (mesuré : il classe les ordres comme l'affinage
   * entier ; de 1 264 à 1 083 croisements sur l'arbre synthétique de 4 000 personnes) ; le
   * meilleur est gardé. Au-delà de quelques milliers de personnes, l'ordre de `/arbre` seul.
   */
  if (!reglages.colonnes && s.personnes.length <= PERSONNES_POUR_ESSAYER_LES_COLONNES) {
    let meilleur: { ordre: readonly CleDeGroupe[]; n: number } | null = null
    for (const ordre of ordresDesColonnes(s)) {
      poserLesColonnes(s, ordre)
      const essai = construireCouches(s, ordreEnSablier(s, variantes[0]!), optionsDOrdre)
      affiner(s, essai, optionsDOrdre, 1)
      if (!meilleur || essai.croisements < meilleur.n) meilleur = { ordre, n: essai.croisements }
    }
    if (meilleur) poserLesColonnes(s, meilleur.ordre)
  }
  let couches = construireCouches(s, ordreEnSablier(s, variantes[0]!), optionsDOrdre)
  variantes.forEach((variante, k) => {
    const essai = k === 0 ? couches : construireCouches(s, ordreEnSablier(s, variante), optionsDOrdre)
    if (reglages.affiner === false) essai.croisements = compterBus(s, essai)
    else affiner(s, essai, optionsDOrdre)
    if (k === 0 || essai.croisements < couches.croisements) couches = essai
  })

  /* 3. Les abscisses. */
  const abscisses = placer(s, couches, {
    ecartCouple: ECART_COUPLE,
    ecartFratrie: ECART_FRATRIE,
    ecartFamille: ECART_FAMILLE,
    ecartFactice: 28,
    ecartParties,
    tours: 3,
    passes: 24,
    aligner: reglages.aligner ?? true,
  })

  /* 4. Les traits. */
  const haut = MARGE_Y + BANDEAU_RECTANGLE + MARGE_RECTANGLE
  const routage = router(s, couches, abscisses, {
    couloirMin: COULOIR_MIN,
    piste: 14,
    margePiste: 22,
    haut,
    ecartVertical: 9,
    retraitChute: 16,
  })

  /* 5. Le monde : tout décalé pour que le rectangle le plus à gauche commence à la marge. */
  const { personnes } = s
  const indiceDe = new Map(couches.rangees.map((r, k) => [r, k]))
  let minX = Infinity
  let maxX = -Infinity
  for (let i = 0; i < personnes.length; i++) {
    const w = personnes[i]!.w || 132
    minX = Math.min(minX, abscisses.cx[i]! - w / 2)
    maxX = Math.max(maxX, abscisses.cx[i]! + w / 2)
  }
  if (!personnes.length) minX = maxX = 0
  const dx = MARGE_X + MARGE_RECTANGLE - minX
  const decaler = (d: string): string =>
    d.replace(/M(-?[\d.]+) (-?[\d.]+)L(-?[\d.]+) (-?[\d.]+)/g, (_, x1, y1, x2, y2) =>
      `M${(Number(x1) + dx).toFixed(1)} ${y1}L${(Number(x2) + dx).toFixed(1)} ${y2}`,
    )
  const traces = {
    ordinaires: decaler(routage.traits.ordinaires),
    directs: decaler(routage.traits.directs),
    divorces: decaler(routage.traits.divorces),
    attente: decaler(routage.traits.attente),
    familles: routage.traits.familles.map((f) => ({ ...f, d: decaler(f.d) })),
  }
  const foyers = routage.foyers.map((e) => ({ ...e, x: e.x + dx }))

  /* Les fiches. */
  const fiches: FicheHistoire[] = []
  const parId = new Map<IdentifiantNoeud, FicheHistoire>()
  const parPersonId = new Map<number, FicheHistoire>()
  personnes.forEach((p, i) => {
    const w = p.w || 132
    const cx = abscisses.cx[i]! + dx
    const classe = s.classes.get(p.id)
    const fiche: FicheHistoire = {
      id: p.id,
      noeud: p,
      x: cx - w / 2,
      y: routage.hautRangee[indiceDe.get(s.rangee[i]!)!]!,
      w,
      h: p.h || s.hauteurFiche,
      cx,
      rangee: s.rangee[i]!,
      directe: s.sosa[i] !== null,
      sosa: s.sosa[i]!,
      detache: s.groupe[i] === 'sansLien',
      parente: classe?.parente ?? 'famille',
      pont: classe?.parente === 'alliance' ? classe.pont : null,
      branche: s.alliancesDansLArbre ? (s.rattachement(p.id) ?? p.branch) : p.branch,
    }
    fiches.push(fiche)
    parId.set(p.id, fiche)
    if (p.personId !== null) parPersonId.set(p.personId, fiche)
  })

  /* Les rectangles : un par partie. */
  const nomDuQuart = (cle: CleDeGroupe): string => {
    if (!QUARTS.has(cle)) return ''
    const duConjoint = cle.startsWith('c')
    const q = (duConjoint ? cle.slice(1) : cle) as 'pp' | 'pm' | 'mp' | 'mm'
    const tete = (duConjoint ? s.sosaConjoint : s.sosaEgo).get(SOSA_DU_QUART[q])
    return tete !== undefined ? nomDeFamilleDe(personnes[tete]!) : ''
  }
  const groupes: RectangleDeGroupe[] = []
  const ordreDesParties = s.parties.map((_, k) => k).sort((p, q) => s.parties[p]!.colonne - s.parties[q]!.colonne || s.parties[p]!.min - s.parties[q]!.min)
  for (const k of ordreDesParties) {
    const partie = s.parties[k]!
    let x0 = Infinity
    let x1 = -Infinity
    let y0 = Infinity
    let y1 = -Infinity
    for (const i of partie.membres) {
      const f = fiches[i]!
      x0 = Math.min(x0, f.x)
      x1 = Math.max(x1, f.x + f.w)
      y0 = Math.min(y0, f.y)
      y1 = Math.max(y1, f.y + f.h)
    }
    const y = y0 - MARGE_RECTANGLE - BANDEAU_RECTANGLE
    groupes.push({
      cle: partie.groupe,
      libelle: LIBELLES_GROUPE[partie.groupe],
      nom: nomDuQuart(partie.groupe),
      effectif: partie.membres.length,
      x: x0 - MARGE_RECTANGLE,
      y,
      w: x1 - x0 + 2 * MARGE_RECTANGLE,
      h: y1 + MARGE_RECTANGLE - y,
    })
  }

  /* Les quarts (bandeau, mini-carte, filtres) : l'étendue de leur rectangle. */
  const quarts: Quart[] = []
  for (const cle of ['pp', 'pm', 'mp', 'mm', 'cpp', 'cpm', 'cmp', 'cmm'] as const) {
    const rects = groupes.filter((g) => g.cle === cle)
    if (!rects.length) continue
    const membres = fiches.filter((_, i) => s.groupe[i] === cle)
    const tous = membres.length ? membres : fiches.filter((_, i) => s.groupeDePose[i] === cle)
    quarts.push({
      branche: cle as BrancheDeQuart,
      libelle: LIBELLES_GROUPE[cle],
      nom: nomDuQuart(cle),
      effectif: membres.length,
      cx: tous.reduce((t, f) => t + f.cx, 0) / Math.max(1, tous.length),
      x0: Math.min(...rects.map((r) => r.x)),
      x1: Math.max(...rects.map((r) => r.x + r.w)),
    })
  }

  /* Les familles par alliance, « à part » : leur étendue, auprès de leur pont. */
  const alliances: BlocAlliance[] = []
  if (!s.alliancesDansLArbre) {
    const parPont = new Map<string, FicheHistoire[]>()
    personnes.forEach((p, i) => {
      if (s.groupe[i] !== 'alliances') return
      const pont = s.classes.get(p.id)?.pont ?? null
      if (pont === null) return
      const l = parPont.get(pont)
      if (l) l.push(fiches[i]!)
      else parPont.set(pont, [fiches[i]!])
    })
    for (const [pont, l] of parPont) {
      const x0 = Math.min(...l.map((f) => f.x))
      const x1 = Math.max(...l.map((f) => f.x + f.w))
      const noeud = personnes[s.indexDe.get(pont) ?? -1]
      alliances.push({ pont, nom: noeud ? nomDeFamilleDe(noeud) : '', effectif: l.length, cx: (x0 + x1) / 2, x0, x1 })
    }
    alliances.sort((p, q) => p.x0 - q.x0)
  }
  const rectangleIsoles = groupes.find((r) => r.cle === 'sansLien')
  const zoneDetachee = rectangleIsoles
    ? { x0: rectangleIsoles.x, x1: rectangleIsoles.x + rectangleIsoles.w, cx: rectangleIsoles.x + rectangleIsoles.w / 2, effectif: rectangleIsoles.effectif }
    : null

  /* Les générations (gouttière). */
  const generations: GenerationHistoire[] = []
  couches.rangees.forEach((g, k) => {
    const rangee = fiches.filter((f) => f.rangee === g)
    if (!rangee.length) return
    const directs = rangee.filter((f) => f.directe)
    const annees = (directs.length ? directs : rangee).map((f) => anneeDe(f.noeud)).filter((a): a is number => a !== null)
    const moyenne = annees.length ? Math.round(annees.reduce((t, a) => t + a, 0) / annees.length / 10) * 10 : null
    generations.push({
      rangee: g,
      y: routage.hautRangee[k]! + routage.hauteurRangee[k]! / 2,
      libelle: libelleGeneration(g),
      annees: moyenne === null ? null : `vers ${moyenne}`,
      effectif: rangee.length,
    })
  })

  let pasRangee = Infinity
  for (let k = 1; k < routage.hautRangee.length; k++) pasRangee = Math.min(pasRangee, routage.hautRangee[k]! - routage.hautRangee[k - 1]!)
  if (!Number.isFinite(pasRangee)) pasRangee = s.hauteurFiche + COULOIR_MIN

  let longueur = 0
  for (const fam of s.familles) {
    if (!fam.epoux.length) continue
    const u = fam.epoux.reduce((t, e) => t + abscisses.cx[e]!, 0) / fam.epoux.length
    for (const e of fam.enfants) longueur += Math.abs(abscisses.cx[e]! - u)
  }

  const droiteMonde = Math.max(maxX + dx, ...groupes.map((g) => g.x + g.w))
  return {
    ego: s.ego,
    fiches,
    parId,
    parPersonId,
    traces,
    foyers,
    quarts,
    alliances,
    alliancesDansLArbre: s.alliancesDansLArbre,
    zoneDetachee,
    generations,
    largeur: droiteMonde + MARGE_X,
    hauteur: routage.bas + MARGE_RECTANGLE + MARGE_Y + 20,
    pasRangee,
    groupes,
    croisements: couches.croisements,
    longueurDesLiens: Math.round(longueur),
    ecartGroupes: ecartParties - 2 * MARGE_RECTANGLE,
  }
}
