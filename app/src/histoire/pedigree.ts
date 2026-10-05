/**
 * **La ligne directe seule, en arbre binaire** (05/10/2026).
 *
 * Le mode « Seulement elle » de la ligne directe ne garde que les ancêtres d'une
 * personne et ses descendants (`seulementLaLigne`). Passés au moteur général
 * (`disposerLignees`), ils se rangeaient comme un arbre de famille : couples serrés,
 * fratries absentes, et des écarts qui ne disaient rien. Or une ascendance a une forme
 * à elle, la plus lisible qui soit : **un arbre binaire**, chaque personne centrée sous
 * ses deux parents, le père à gauche, la mère à droite, et le même traitement pour
 * toutes les branches.
 *
 * La règle est stricte, c'est ce qui la rend lisible :
 *
 *  - chaque génération d'ancêtres a `2^g` CASES de même largeur, qu'elles soient
 *    occupées ou non. Le numéro de Sosa dit la case : `n` est en case `n − 2^g` de sa
 *    rangée. Une case vide reste vide — un ancêtre inconnu ne déplace personne, et
 *    le côté du père a exactement la même forme que celui de la mère ;
 *  - chaque enfant est donc pile au milieu de ses deux parents : l'union est un trait
 *    horizontal, et la descente tombe droit sur lui ;
 *  - sous la racine, ses descendants en arbre compact (chaque enfant au milieu de sa
 *    propre descendance), reliés par une barre de fratrie.
 *
 * La disposition rendue a la même forme que celle du moteur général : l'écran la
 * dessine sans rien savoir de plus (fiches, traits, foyers, quarts, gouttière).
 *
 * Pur, sans DOM : `pedigree.test.ts`.
 */
import type { Graphe, IdentifiantNoeud, NoeudPersonne } from '@/lib/api'
import {
  LIBELLES_QUART,
  anneeDe,
  libelleGeneration,
  nomDeFamilleDe,
  type BrancheDeQuart,
  type DispositionHistoire,
  type EtiquetteFoyer,
  type FicheHistoire,
  type GenerationHistoire,
  type Quart,
  type TracesHistoire,
} from './lignees'

/** L'écart entre deux cases de la rangée la plus large : entre les deux époux d'un couple… */
const ECART_CASE = 16
/** … et, en plus, entre deux couples voisins, pour qu'on voie où l'un finit. */
const ECART_COUPLE = 36
/** L'espace vertical entre deux rangées, en plus de la hauteur d'une fiche. */
const INTERLIGNE = 60
const MARGE_X = 60
const MARGE_Y = 40
/** La barre de fratrie des descendants, au-dessus de leur rangée. */
const BARRE = 22

/** Sosa du grand-parent de tête de chaque quart, depuis le lecteur. */
const SOSA_DU_QUART: Record<'pp' | 'pm' | 'mp' | 'mm', number> = { pp: 4, pm: 5, mp: 6, mm: 7 }

const generationSosa = (n: number): number => Math.floor(Math.log2(n))

/**
 * Dispose `graphe` — déjà réduit à la ligne directe de `racine` — en arbre binaire.
 * Les rangées se comptent depuis `lecteur` (celui du serveur, pas l'ego d'un graphe
 * réduit) s'il est de la ligne, comme partout ; sinon depuis la racine.
 */
export function disposerEnPedigree(
  graphe: Graphe,
  racine: IdentifiantNoeud,
  lecteur: IdentifiantNoeud = graphe.ego,
): DispositionHistoire {
  const personnes = new Map<string, NoeudPersonne>()
  for (const n of graphe.noeuds) if (n.type === 'personne') personnes.set(n.id, n)
  const divorcees = new Set<string>()
  for (const n of graphe.noeuds) if (n.type === 'famille' && n.divorced) divorcees.add(n.id)

  const epouxDe = new Map<string, string[]>()
  const enfantsDe = new Map<string, string[]>()
  const famillesDOrigine = new Map<string, string[]>()
  const famillesDUnion = new Map<string, string[]>()
  const enAttente = new Set<string>()
  const ajouter = (m: Map<string, string[]>, k: string, v: string): void => {
    const l = m.get(k)
    if (l) {
      if (!l.includes(v)) l.push(v)
    } else m.set(k, [v])
  }
  for (const a of graphe.aretes) {
    if (a.enAttente) enAttente.add(a.genre === 'union' ? a.vers : a.de)
    if (a.genre === 'union') {
      if (!personnes.has(a.de)) continue
      ajouter(epouxDe, a.vers, a.de)
      ajouter(famillesDUnion, a.de, a.vers)
    } else {
      if (!personnes.has(a.vers)) continue
      ajouter(enfantsDe, a.de, a.vers)
      ajouter(famillesDOrigine, a.vers, a.de)
    }
  }

  /** Le père puis la mère d'une famille ; un sexe inconnu prend la place libre. */
  function pereEtMere(famille: string): [string | null, string | null] {
    const ep = epouxDe.get(famille) ?? []
    let pere = ep.find((id) => personnes.get(id)!.sex === 'masculin') ?? null
    let mere = ep.find((id) => personnes.get(id)!.sex === 'feminin' && id !== pere) ?? null
    for (const id of ep) {
      if (id === pere || id === mere) continue
      if (pere === null) pere = id
      else if (mere === null) mere = id
    }
    return [pere, mere]
  }

  /* ── L'ascendance : les numéros de Sosa depuis la racine ── */
  const sosaDe = new Map<string, number>()
  const parSosa = new Map<number, string>()
  /** La famille d'origine de chaque ancêtre posé, pour tracer son union. */
  const origine = new Map<number, string>()
  if (personnes.has(racine)) {
    sosaDe.set(racine, 1)
    parSosa.set(1, racine)
    const file = [1]
    while (file.length) {
      const n = file.shift()!
      const famille = famillesDOrigine.get(parSosa.get(n)!)?.[0]
      if (famille === undefined) continue
      origine.set(n, famille)
      const [pere, mere] = pereEtMere(famille)
      for (const [id, numero] of [
        [pere, 2 * n],
        [mere, 2 * n + 1],
      ] as const) {
        // Un ancêtre déjà posé (implexe : deux cousins mariés) ne l'est qu'une fois.
        if (id === null || sosaDe.has(id)) continue
        sosaDe.set(id, numero)
        parSosa.set(numero, id)
        file.push(numero)
      }
    }
  }

  const largeurCase = Math.max(132, ...[...personnes.values()].map((p) => p.w || 0)) + ECART_CASE
  const hauteurFiche = Math.max(48, ...[...personnes.values()].map((p) => p.h || 0))
  const largeurDe = (id: string): number => personnes.get(id)?.w || largeurCase - ECART_CASE
  const hauteurDe = (id: string): number => personnes.get(id)?.h || hauteurFiche

  let profondeur = 0
  for (const n of parSosa.keys()) profondeur = Math.max(profondeur, generationSosa(n))
  /**
   * Le centre de la case du Sosa `n`. La rangée la plus haute a ses `2^profondeur` cases
   * côte à côte, un écart de plus entre deux couples ; toute autre case est au milieu
   * des deux cases de ses parents — occupées ou non : c'est ce qui rend l'arbre égal.
   */
  const centres = new Map<number, number>()
  function centre(n: number): number {
    const connu = centres.get(n)
    if (connu !== undefined) return connu
    const g = generationSosa(n)
    const i = n - 2 ** g
    const x =
      g >= profondeur ? i * largeurCase + Math.floor(i / 2) * ECART_COUPLE + largeurCase / 2 : (centre(2 * n) + centre(2 * n + 1)) / 2
    centres.set(n, x)
    return x
  }

  /** Centre et génération (relative à la racine : négative en montant) de chaque fiche posée. */
  const poses = new Map<string, { x: number; g: number }>()
  for (const [n, id] of parSosa) poses.set(id, { x: centre(n), g: -generationSosa(n) })

  /* ── La descendance : un arbre compact sous la racine ── */
  const vus = new Set<string>([racine])
  const enfantsPoses = new Map<string, { famille: string; enfants: string[] }[]>()
  const anneeTri = (id: string): number => anneeDe(personnes.get(id)!) ?? Number.POSITIVE_INFINITY
  function descendre(id: string): number {
    const groupes: { famille: string; enfants: string[] }[] = []
    for (const famille of famillesDUnion.get(id) ?? []) {
      const enfants = (enfantsDe.get(famille) ?? []).filter((e) => !vus.has(e) && !sosaDe.has(e))
      enfants.sort((a, b) => anneeTri(a) - anneeTri(b))
      for (const e of enfants) vus.add(e)
      if (enfants.length) groupes.push({ famille, enfants })
    }
    enfantsPoses.set(id, groupes)
    let total = 0
    for (const gr of groupes) for (const e of gr.enfants) total += descendre(e)
    largeurs.set(id, Math.max(largeurCase, total))
    return largeurs.get(id)!
  }
  const largeurs = new Map<string, number>()
  function placer(id: string, cx: number, g: number): void {
    const groupes = enfantsPoses.get(id) ?? []
    let gauche = cx - (groupes.flatMap((gr) => gr.enfants).reduce((s, e) => s + largeurs.get(e)!, 0)) / 2
    for (const gr of groupes) {
      for (const e of gr.enfants) {
        const l = largeurs.get(e)!
        poses.set(e, { x: gauche + l / 2, g: g + 1 })
        placer(e, gauche + l / 2, g + 1)
        gauche += l
      }
    }
  }
  if (personnes.has(racine)) {
    descendre(racine)
    placer(racine, poses.get(racine)!.x, 0)
  }

  /* ── Les rangées, depuis le lecteur s'il est de la ligne ── */
  const lecteurPresent = poses.has(lecteur)
  const origineDesRangees = lecteurPresent ? lecteur : racine
  const decalageRangee = poses.get(origineDesRangees)?.g ?? 0

  let minX = Infinity
  let maxX = -Infinity
  let minG = Infinity
  let maxG = -Infinity
  for (const [id, p] of poses) {
    const w = largeurDe(id)
    minX = Math.min(minX, p.x - w / 2)
    maxX = Math.max(maxX, p.x + w / 2)
    minG = Math.min(minG, p.g - decalageRangee)
    maxG = Math.max(maxG, p.g - decalageRangee)
  }
  if (!poses.size) minX = maxX = minG = maxG = 0
  const pasRangee = hauteurFiche + INTERLIGNE
  const decalage = MARGE_X - minX
  const hautRangee = (g: number): number => (g - minG) * pasRangee + MARGE_Y

  const fiches: FicheHistoire[] = []
  const parId = new Map<string, FicheHistoire>()
  const parPersonId = new Map<number, FicheHistoire>()
  for (const [id, p] of poses) {
    const noeud = personnes.get(id)!
    const w = largeurDe(id)
    const cx = p.x + decalage
    const rangee = p.g - decalageRangee
    const fiche: FicheHistoire = {
      id,
      noeud,
      x: cx - w / 2,
      y: hautRangee(rangee),
      w,
      h: hauteurDe(id),
      cx,
      rangee,
      directe: sosaDe.has(id),
      sosa: sosaDe.get(id) ?? null,
      detache: false,
      parente: noeud.parente ?? 'famille',
      pont: null,
      branche: noeud.branch,
    }
    fiches.push(fiche)
    parId.set(id, fiche)
    if (noeud.personId !== null) parPersonId.set(noeud.personId, fiche)
  }

  /* ── Les traits ── */
  const traits: TracesHistoire = { ordinaires: '', directs: '', divorces: '', attente: '', familles: [] }
  const seg = (x1: number, y1: number, x2: number, y2: number): string =>
    `M${x1.toFixed(1)} ${y1.toFixed(1)}L${x2.toFixed(1)} ${y2.toFixed(1)}`
  const style = (famille: string): 'directs' | 'divorces' | 'attente' =>
    enAttente.has(famille) ? 'attente' : divorcees.has(famille) ? 'divorces' : 'directs'
  const foyers: EtiquetteFoyer[] = []

  // L'ascendance : l'union de chaque couple, et la descente droit sur l'enfant.
  for (const [n, famille] of origine) {
    const enfant = parId.get(parSosa.get(n)!)
    if (!enfant) continue
    const pere = parSosa.has(2 * n) ? parId.get(parSosa.get(2 * n)!) : undefined
    const mere = parSosa.has(2 * n + 1) ? parId.get(parSosa.get(2 * n + 1)!) : undefined
    let d = ''
    const parents = [pere, mere].filter((p): p is FicheHistoire => p !== undefined)
    if (pere && mere) {
      const y = pere.y + pere.h / 2
      const milieu = (pere.x + pere.w + mere.x) / 2
      d += seg(pere.x + pere.w, y, mere.x, y) + seg(milieu, y, milieu, enfant.y)
    } else {
      // Un seul parent connu : il est dans sa case, au-dessus d'un côté de l'enfant.
      for (const p of parents) {
        const yCoude = enfant.y - BARRE
        d += seg(p.cx, p.y + p.h, p.cx, yCoude) + seg(p.cx, yCoude, enfant.cx, yCoude) + seg(enfant.cx, yCoude, enfant.cx, enfant.y)
      }
    }
    if (!d) continue
    traits[style(famille)] += d
    traits.familles.push({ id: famille, d, personnes: [...parents.map((p) => p.id), enfant.id] })
    const tete = [pere, mere].find((p) => p !== undefined && nomDeFamilleDe(p.noeud) !== '')
    if (tete) {
      const gauche = parents[0]!
      const droite = parents[parents.length - 1]!
      foyers.push({
        x: (gauche.x + droite.x + droite.w) / 2,
        y: tete.y - 8,
        nom: nomDeFamilleDe(tete.noeud),
        personne: tete.id,
        ancetres: true,
        envergure: droite.x + droite.w - gauche.x,
      })
    }
  }

  // La descendance : du parent à la barre de fratrie, puis à chaque enfant.
  for (const [id, groupes] of enfantsPoses) {
    const parent = parId.get(id)
    if (!parent) continue
    for (const { famille, enfants } of groupes) {
      const en = enfants.map((e) => parId.get(e)).filter((f): f is FicheHistoire => f !== undefined)
      if (!en.length) continue
      const yBarre = en[0]!.y - BARRE
      const xs = [parent.cx, ...en.map((e) => e.cx)]
      let d = seg(parent.cx, parent.y + parent.h, parent.cx, yBarre) + seg(Math.min(...xs), yBarre, Math.max(...xs), yBarre)
      for (const e of en) d += seg(e.cx, yBarre, e.cx, e.y)
      traits[style(famille)] += d
      traits.familles.push({ id: famille, d, personnes: [parent.id, ...en.map((e) => e.id)] })
    }
  }

  /* ── Les quarts du lecteur, pour le bandeau et la mini-carte ── */
  const quarts: Quart[] = []
  for (const branche of ['pp', 'pm', 'mp', 'mm'] as const satisfies readonly BrancheDeQuart[]) {
    const membres = fiches.filter((f) => f.branche === branche)
    if (!membres.length) continue
    // La tête du quart : le grand-parent du lecteur quand la racine est le lecteur ; sinon
    // le membre du quart le plus proche du bas de l'arbre.
    const tete =
      racine === lecteur
        ? membres.find((f) => f.sosa === SOSA_DU_QUART[branche])
        : [...membres].sort((a, b) => b.rangee - a.rangee || Number(b.noeud.sex === 'masculin') - Number(a.noeud.sex === 'masculin'))[0]
    quarts.push({
      branche,
      libelle: LIBELLES_QUART[branche],
      nom: tete ? nomDeFamilleDe(tete.noeud) : '',
      effectif: membres.length,
      cx: membres.reduce((s, f) => s + f.cx, 0) / membres.length,
      x0: Math.min(...membres.map((f) => f.x)),
      x1: Math.max(...membres.map((f) => f.x + f.w)),
    })
  }

  /* ── La gouttière des générations ── */
  const generations: GenerationHistoire[] = []
  for (let g = minG; g <= maxG; g++) {
    const rangee = fiches.filter((f) => f.rangee === g)
    if (!rangee.length) continue
    const annees = rangee.map((f) => anneeDe(f.noeud)).filter((a): a is number => a !== null)
    const moyenne = annees.length ? Math.round(annees.reduce((s, a) => s + a, 0) / annees.length / 10) * 10 : null
    generations.push({
      rangee: g,
      y: hautRangee(g) + hauteurFiche / 2,
      // Sans le lecteur dans la ligne, la rangée 0 est celle de la racine, pas la sienne.
      libelle: g === 0 && !lecteurPresent ? 'Sa génération' : libelleGeneration(g),
      annees: moyenne === null ? null : `vers ${moyenne}`,
      effectif: rangee.length,
    })
  }

  return {
    ego: origineDesRangees,
    fiches,
    parId,
    parPersonId,
    traces: traits,
    foyers,
    quarts,
    alliances: [],
    alliancesDansLArbre: false,
    zoneDetachee: null,
    generations,
    largeur: maxX - minX + 2 * MARGE_X,
    hauteur: hautRangee(maxG) + hauteurFiche + MARGE_Y + 20,
    pasRangee,
  }
}
