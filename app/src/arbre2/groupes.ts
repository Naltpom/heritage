/**
 * **`/arbre-2` : l'arbre en rectangles de familles.**
 *
 * Demande du commanditaire, le 09/10/2026 : sur `/arbre`, les familles se mêlent pour ne
 * laisser aucun vide. Ici, chaque famille a son RECTANGLE, qui prend exactement la place
 * de ses fiches, et deux rectangles sont séparés d'au moins deux largeurs de carte.
 *
 * Les groupes, jusqu'à onze :
 *  - le TRONC — le lecteur, ses parents, sa fratrie, ses conjoints, ses descendants ;
 *  - les quatre quarts de ses grands-parents : `pp`, `pm`, `mp`, `mm` ;
 *  - les quatre quarts de son conjoint : `cpp`, `cpm`, `cmp`, `cmm` ;
 *  - « Familles par alliance », quand elles sont affichées À PART (le 10ᵉ) ;
 *  - « Sans lien connu », quand on les affiche (le 11ᵉ).
 * Le partage est celui du serveur (`branch`, `CalculAscendance::branches()`) : rien n'est
 * reclassé ici. Une famille par alliance rangée « dans mon arbre » rejoint le groupe de
 * la personne qui la relie (`brancheDeRattachement`).
 *
 * **Une génération = une rangée pour tout l'arbre** (choix du commanditaire) : un cousin
 * est à la hauteur du lecteur, même dans un autre rectangle. Les rectangles sont donc
 * posés côte à côte, de gauche à droite :
 *
 *     pp │ pm │ TRONC │ mp │ mm │ cpm │ cpp │ cmp │ cmm │ alliances │ sans lien
 *
 * Deux époux de groupes différents sont toujours dans des groupes VOISINS — les
 * grands-parents (`pp`/`pm`, `mp`/`mm`), les beaux-parents (`cpp`/`cmp`), les
 * grands-parents du conjoint (`cpm`/`cpp`, `cmp`/`cmm`) — : leur union traverse le seul
 * couloir qui les sépare. Le côté maternel et le côté du conjoint se disputent la droite
 * du tronc ; on essaie les deux ordres et on garde celui qui croise le moins et tire les
 * liens les plus courts.
 *
 * Dans chaque rectangle, l'ordre et les abscisses viennent de `agencement.ts`. Entre les
 * rectangles, l'écart minimal est de deux cartes ; on l'élargit quand cela raccourcit
 * les liens qui le traversent (« on accorde la place qu'il faut »).
 *
 * Pur, sans DOM : tout se vérifie dans `groupes.test.ts`. `/arbre` (`histoire/lignees.ts`)
 * n'en dépend pas et n'en sait rien.
 */
import { indexer } from '@/components/arbre/graphe'
import type { Branche, Graphe, IdentifiantNoeud, NoeudFamille, NoeudPersonne } from '@/lib/api'
import { classerParente } from '@/histoire/alliances'
import { brancheDeRattachement } from '@/histoire/branches'
import { noeudsSansLien } from '@/histoire/sansLien'
import {
  LIBELLES_QUART,
  anneeDe,
  libelleGeneration,
  nomDeFamilleDe,
  type BlocAlliance,
  type BrancheDeQuart,
  type DispositionHistoire,
  type FicheHistoire,
  type GenerationHistoire,
  type Quart,
} from '@/histoire/lignees'
import { agencer, compterCroisements, type Agencement } from './agencement'
import { tracerLesLiens } from './traces'

/* ------------------------------------------------------------------ */
/* Constantes                                                          */
/* ------------------------------------------------------------------ */

/** Espace vertical entre deux rangées, en plus de la hauteur d'une fiche (celui de `/arbre`). */
const INTERLIGNE = 60
const MARGE_X = 60
const MARGE_Y = 40
/** Marge intérieure d'un rectangle, autour de ses fiches. */
export const MARGE_RECTANGLE = 16
/** Le bandeau du titre, en haut du rectangle (au-dessus de la marge). */
export const BANDEAU_RECTANGLE = 20
/** Combien de largeurs de carte entre deux rectangles. */
export const CARTES_ENTRE_GROUPES = 2
/** Ce que pèse un croisement face à un pixel de lien, pour choisir l'ordre des groupes. */
const POIDS_CROISEMENT = 400

/* ------------------------------------------------------------------ */
/* Ce que la disposition rend                                          */
/* ------------------------------------------------------------------ */

export type CleDeGroupe = Exclude<Branche, 'tr'> | 'tr' | 'alliances' | 'sansLien'

export const LIBELLES_GROUPE: Record<CleDeGroupe, string> = {
  tr: 'Tronc',
  ...LIBELLES_QUART,
  alliances: 'Familles par alliance',
  sansLien: 'Sans lien connu',
}

export interface RectangleDeGroupe {
  cle: CleDeGroupe
  libelle: string
  /** Nom de famille du grand-parent de tête d'un quart ; vide sinon. */
  nom: string
  effectif: number
  /** Coin haut-gauche et taille, dans le monde. */
  x: number
  y: number
  w: number
  h: number
}

export interface DispositionGroupes extends DispositionHistoire {
  groupes: RectangleDeGroupe[]
  /** Croisements de liens, bande par bande (mesure des tests et du banc). */
  croisements: number
  /** Longueur horizontale totale des liens, en pixels du monde. */
  longueurDesLiens: number
  /** L'écart minimal entre deux rectangles. */
  ecartGroupes: number
}

export interface OptionsGroupes {
  /** Les familles par alliance : à part, dans leur rectangle (défaut) ; ou dans le groupe de leur pont. */
  alliances?: 'a-part' | 'dans-l-arbre'
  fratriesParNaissance?: boolean
}

const QUARTS = new Set<string>(['pp', 'pm', 'mp', 'mm', 'cpp', 'cpm', 'cmp', 'cmm'])
const SOSA_DU_QUART: Record<'pp' | 'pm' | 'mp' | 'mm', number> = { pp: 4, pm: 5, mp: 6, mm: 7 }

/* ------------------------------------------------------------------ */
/* La disposition                                                      */
/* ------------------------------------------------------------------ */

export function disposerEnGroupes(graphe: Graphe, options: OptionsGroupes = {}): DispositionGroupes {
  const index = indexer(graphe)
  const personnes = new Map<string, NoeudPersonne>()
  for (const p of index.personnes) personnes.set(p.id, p)
  const familles = new Map<string, NoeudFamille>()
  for (const f of index.familles) familles.set(f.id, f)

  const enfantsParFamille = new Map<string, string[]>()
  const epouxParFamille = new Map<string, string[]>()
  const famillesEpoux = new Map<string, string[]>()
  const famillesEnfant = new Map<string, string[]>()
  const ajouter = (m: Map<string, string[]>, k: string, v: string): void => {
    const l = m.get(k)
    if (l) l.push(v)
    else m.set(k, [v])
  }
  for (const f of familles.keys()) {
    const enfants = (index.enfantsParFamille.get(f) ?? []).filter((id) => personnes.has(id))
    const deja = new Set(enfants)
    const epoux = (index.membresParFamille.get(f) ?? []).filter((id) => !deja.has(id) && personnes.has(id))
    enfantsParFamille.set(f, enfants)
    epouxParFamille.set(f, epoux)
    for (const e of enfants) ajouter(famillesEnfant, e, f)
    for (const e of epoux) ajouter(famillesEpoux, e, f)
  }
  const RIEN: readonly string[] = []
  const epouxDe = (f: string): readonly string[] => epouxParFamille.get(f) ?? RIEN
  const enfantsDe = (f: string): readonly string[] => enfantsParFamille.get(f) ?? RIEN
  const epouxDans = (id: string): readonly string[] => famillesEpoux.get(id) ?? RIEN
  const enfantDans = (id: string): readonly string[] => famillesEnfant.get(id) ?? RIEN
  const largeur = (id: string): number => personnes.get(id)?.w || 132
  const hauteurFiche = Math.max(48, ...index.personnes.map((p) => p.h || 0))

  const ego = personnes.has(graphe.ego) ? graphe.ego : (index.personnes[0]?.id ?? graphe.ego)
  const alliancesDansLArbre = options.alliances === 'dans-l-arbre'

  /* 1. Le groupe de chacun. */
  const isoles = personnes.has(graphe.ego) ? noeudsSansLien(graphe) : new Set<string>()
  const classes = classerParente(graphe)
  const rattachement = brancheDeRattachement(graphe)
  const versGroupe = (b: Branche | null | undefined): CleDeGroupe => (b && QUARTS.has(b) ? (b as CleDeGroupe) : 'tr')
  const groupeDe = new Map<string, CleDeGroupe>()
  for (const id of personnes.keys()) {
    if (id === ego) groupeDe.set(id, 'tr')
    else if (isoles.has(id)) groupeDe.set(id, 'sansLien')
    else if (classes.get(id)?.parente === 'alliance')
      groupeDe.set(id, alliancesDansLArbre ? versGroupe(rattachement(id)) : 'alliances')
    else groupeDe.set(id, versGroupe(personnes.get(id)!.branch))
  }

  /* 2. Les rangées : une génération par rangée, depuis l'ego (0 ; −1 ses parents). */
  const rangeeDe = new Map<string, number>()
  const parcourir = (depart: string, g0: number, garder: (id: string) => boolean): string[] => {
    const atteints = [depart]
    rangeeDe.set(depart, g0)
    for (let i = 0; i < atteints.length; i++) {
      const id = atteints[i]!
      const g = rangeeDe.get(id)!
      const voisins: [string, number][] = []
      for (const f of epouxDans(id)) {
        for (const c of epouxDe(f)) voisins.push([c, g])
        for (const c of enfantsDe(f)) voisins.push([c, g + 1])
      }
      for (const f of enfantDans(id)) for (const c of epouxDe(f)) voisins.push([c, g - 1])
      for (const [v, gv] of voisins) {
        if (rangeeDe.has(v) || !garder(v)) continue
        rangeeDe.set(v, gv)
        atteints.push(v)
      }
    }
    return atteints
  }
  if (personnes.has(ego)) parcourir(ego, 0, (id) => !isoles.has(id))
  // Sans ego dans le graphe : chaque composante part de la génération servie.
  for (const p of index.personnes) if (!rangeeDe.has(p.id) && !isoles.has(p.id)) parcourir(p.id, p.generation, (id) => !isoles.has(id))
  estimerLesIsoles()

  /**
   * Les personnes SANS LIEN : leur génération servie ne dit rien de leur place. On
   * l'estime d'après leurs dates, sur la droite « année ↔ rangée » tirée des personnes
   * reliées — la règle de `/arbre`.
   */
  function estimerLesIsoles(): void {
    if (!isoles.size) return
    const points: [number, number][] = []
    for (const [id, g] of rangeeDe) {
      const an = anneeDe(personnes.get(id)!)
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
    for (const p of index.personnes) {
      if (rangeeDe.has(p.id) || !isoles.has(p.id)) continue
      const ilot = parcourir(p.id, 0, (id) => isoles.has(id))
      const decalages = ilot
        .map((id) => {
          const an = anneeDe(personnes.get(id)!)
          return an === null ? null : (an - a) / b - rangeeDe.get(id)!
        })
        .filter((d): d is number => d !== null)
        .sort((x, y) => x - y)
      const decalage = decalages.length ? Math.round(decalages[decalages.length >> 1]!) : p.generation
      for (const id of ilot) rangeeDe.set(id, rangeeDe.get(id)! + decalage)
    }
  }

  /* 3. La lignée directe (Sosa), pour les traits dorés et le quart de tête. */
  const pereEtMere = (f: string): [string | null, string | null] => {
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
  const sosaDepuis = (racine: string, deja: ReadonlySet<string>): { parNumero: Map<number, string>; couples: Set<string> } => {
    const parNumero = new Map<number, string>([[1, racine]])
    const vus = new Set([racine, ...deja])
    const couples = new Set<string>()
    const file = [1]
    while (file.length) {
      const n = file.shift()!
      const f = enfantDans(parNumero.get(n)!)[0]
      if (f === undefined) continue
      couples.add(f)
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
  const { parNumero: sosa, couples: famillesDirectes } = personnes.has(ego)
    ? sosaDepuis(ego, new Set())
    : { parNumero: new Map<number, string>(), couples: new Set<string>() }
  const numeroDe = new Map<string, number>()
  for (const [n, id] of sosa) numeroDe.set(id, n)
  /** L'ascendance du conjoint dont le serveur a découpé les quarts, pour nommer `cpp`… */
  let sosaConjoint = new Map<number, string>()
  for (const f of epouxDans(ego)) {
    for (const c of epouxDe(f)) {
      if (c === ego || sosaConjoint.size) continue
      const [p, m] = pereEtMere(enfantDans(c)[0] ?? '')
      const quart = (p !== null ? groupeDe.get(p) : undefined) ?? (m !== null ? groupeDe.get(m) : undefined)
      if (quart === 'cpp' || quart === 'cmp') sosaConjoint = sosaDepuis(c, new Set(numeroDe.keys())).parNumero
    }
  }

  /* 4. Les membres de chaque groupe, dans l'ordre du graphe. */
  const membres = new Map<CleDeGroupe, string[]>()
  for (const p of index.personnes) ajouter(membres as Map<string, string[]>, groupeDe.get(p.id)!, p.id)

  const largeurDeCarte = mediane(index.personnes.map((p) => p.w || 132))
  const ecartGroupes = CARTES_ENTRE_GROUPES * largeurDeCarte

  /*
   * 5. Les ordres de groupes à essayer. Le côté paternel reste à gauche du tronc, le
   * maternel et celui du conjoint à droite ; mais DANS chaque côté, le sens de la paire
   * n'est pas neutre : le lien du parent vers le couple de ses parents traverse le quart
   * qui est entre les deux, et ce quart peut être vide à cette hauteur, ou plein. On
   * essaie les deux sens de chaque paire, et l'ordre des deux côtés de droite (au plus
   * seize ordres ; l'agencement d'un groupe ne dépend que des groupes qu'il a à sa
   * gauche, il est gardé d'un essai à l'autre).
   */
  const present = (c: CleDeGroupe): boolean => (membres.get(c)?.length ?? 0) > 0
  const sens = (l: CleDeGroupe[]): CleDeGroupe[][] => {
    const p = l.filter(present)
    return p.length > 1 ? [p, [...p].reverse()] : [p]
  }
  const fin: CleDeGroupe[] = (['alliances', 'sansLien'] as const).filter(present)
  const candidats: CleDeGroupe[][] = []
  for (const paternel of sens(['pp', 'pm']))
    for (const maternel of sens(['mp', 'mm']))
      for (const conjoint of sens(['cpm', 'cpp', 'cmp', 'cmm'])) {
        const tronc: CleDeGroupe[] = present('tr') ? ['tr'] : []
        candidats.push([...paternel, ...tronc, ...maternel, ...conjoint, ...fin])
        if (maternel.length && conjoint.length) candidats.push([...paternel, ...tronc, ...conjoint, ...maternel, ...fin])
      }

  /** Les groupes auxquels chaque groupe est lié (un conjoint, un parent, un enfant). */
  const voisinsDe = new Map<CleDeGroupe, Set<CleDeGroupe>>()
  for (const f of familles.keys()) {
    const touches = new Set<CleDeGroupe>()
    for (const p of epouxDe(f)) touches.add(groupeDe.get(p)!)
    for (const c of enfantsDe(f)) touches.add(groupeDe.get(c)!)
    if (touches.size < 2) continue
    for (const a of touches) {
      let v = voisinsDe.get(a)
      if (!v) voisinsDe.set(a, (v = new Set()))
      for (const b of touches) if (b !== a && b !== 'alliances' && b !== 'sansLien') v.add(b)
    }
  }
  const agencementsGardes = new Map<string, Agencement>()
  const essais = candidats.map((ordre) => essayer(ordre))
  const retenu = essais.reduce((m, e) => (e.score < m.score ? e : m))

  /** Un ordre de groupes : chaque groupe agencé, puis les rectangles posés de gauche à droite. */
  function essayer(ordre: CleDeGroupe[]): {
    score: number
    croisements: number
    longueur: number
    x: Map<string, number>
    blocs: { cle: CleDeGroupe; x0: number; largeur: number }[]
  } {
    const rang = new Map(ordre.map((c, i) => [c, i]))
    const agencements = ordre.map((cle) => {
      const r = rang.get(cle)!
      // L'agencement d'un groupe ne dépend que du CÔTÉ des groupes auxquels il est lié.
      const signature = `${cle}|${[...(voisinsDe.get(cle) ?? [])]
        .sort()
        .map((v) => `${v}${rang.get(v)! < r ? '<' : '>'}`)
        .join(',')}`
      const garde = agencementsGardes.get(signature)
      if (garde) return garde
      const agencement = agencer({
        membres: membres.get(cle)!,
        rangee: (id) => rangeeDe.get(id) ?? 0,
        largeur,
        masculin: (id) => personnes.get(id)?.sex === 'masculin',
        annee: (id) => anneeDe(personnes.get(id)!),
        cote: (id) => {
          const autre = groupeDe.get(id)
          // Les rectangles du bout (alliances, sans lien) ne tirent personne vers eux.
          if (autre === undefined || autre === 'alliances' || autre === 'sansLien') return 0
          if (cle === 'alliances' || cle === 'sansLien') return 0
          return rang.get(autre)! < r ? -1 : 1
        },
        famillesEpoux: epouxDans,
        famillesEnfant: enfantDans,
        epouxDe,
        enfantsDe,
        fratriesParNaissance: options.fratriesParNaissance === true,
      })
      agencementsGardes.set(signature, agencement)
      return agencement
    })

    // Les rectangles, de gauche à droite, au plus serré.
    const largeurs = agencements.map((a) => a.largeur + 2 * MARGE_RECTANGLE)
    const X: number[] = []
    ordre.forEach((_, i) => X.push(i ? X[i - 1]! + largeurs[i - 1]! + ecartGroupes : 0))
    const xDe = (id: string): number => {
      const i = rang.get(groupeDe.get(id)!)!
      return X[i]! + MARGE_RECTANGLE + agencements[i]!.centres.get(id)!
    }

    // Les liens entre groupes : un enfant sous le milieu de ses parents, les époux proches.
    interface LienExterne {
      a: number
      b: number
      /** Ce que vaut l'écart idéal x(b) − x(a), hors position des groupes. */
      ideal: () => number
    }
    const liens: LienExterne[] = []
    for (const f of familles.keys()) {
      const ep = epouxDe(f)
      if (!ep.length) continue
      for (const c of enfantsDe(f)) {
        const gc = rang.get(groupeDe.get(c)!)!
        const gs = ep.map((p) => rang.get(groupeDe.get(p)!)!)
        if (gs.every((g) => g === gc)) continue
        if (ordre[gc] === 'alliances' || ordre[gc] === 'sansLien') continue
        if (gs.some((g) => ordre[g] === 'alliances' || ordre[g] === 'sansLien')) continue
        // L'enfant voudrait être sous le milieu du couple : x(c) = moyenne x(p).
        const a = gs[0]!
        liens.push({
          a,
          b: gc,
          ideal: () => ep.reduce((s, p) => s + xDe(p), 0) / ep.length - X[a]! - (xDe(c) - X[gc]!),
        })
      }
    }
    // Élargir un couloir quand ça rapproche un enfant de ses parents : quelques passes.
    for (let passe = 0; passe < 12 && liens.length; passe++) {
      let bouge = false
      for (let i = 1; i < ordre.length; i++) {
        let somme = 0
        let n = 0
        for (const l of liens) {
          // L'écart idéal entre les deux groupes : X(b) − X(a) = ideal().
          if (l.b === i && l.a < i) {
            somme += X[l.a]! + l.ideal() - X[i]!
            n++
          } else if (l.a === i && l.b < i) {
            somme += X[l.b]! - l.ideal() - X[i]!
            n++
          }
        }
        if (!n) continue
        const voulu = somme / n
        const mini = X[i - 1]! + largeurs[i - 1]! + ecartGroupes
        const nouveau = Math.max(mini, X[i]! + voulu)
        if (Math.abs(nouveau - X[i]!) < 0.5) continue
        const dx = nouveau - X[i]!
        for (let j = i; j < ordre.length; j++) X[j]! += dx
        bouge = true
      }
      if (!bouge) break
    }

    const x = new Map<string, number>()
    for (const id of personnes.keys()) x.set(id, xDe(id))
    const { croisements, longueur } = mesurer(x)
    return {
      score: croisements * POIDS_CROISEMENT + longueur,
      croisements,
      longueur,
      x,
      blocs: ordre.map((cle, i) => ({ cle, x0: X[i]!, largeur: largeurs[i]! })),
    }
  }

  /** Croisements (bande par bande) et longueur horizontale des liens, pour des centres donnés. */
  function mesurer(x: ReadonlyMap<string, number>): { croisements: number; longueur: number } {
    const parBande = new Map<number, { haut: number; bas: number }[]>()
    let longueur = 0
    for (const f of familles.keys()) {
      const ep = epouxDe(f)
      if (!ep.length) continue
      const haut = ep.reduce((s, p) => s + x.get(p)!, 0) / ep.length
      if (ep.length >= 2) longueur += Math.max(...ep.map((p) => x.get(p)!)) - Math.min(...ep.map((p) => x.get(p)!))
      const g = Math.max(...ep.map((p) => rangeeDe.get(p)!))
      for (const c of enfantsDe(f)) {
        longueur += Math.abs(x.get(c)! - haut)
        if (rangeeDe.get(c) !== g + 1) continue
        const lien = { haut, bas: x.get(c)! }
        const l = parBande.get(g)
        if (l) l.push(lien)
        else parBande.set(g, [lien])
      }
    }
    let croisements = 0
    for (const l of parBande.values()) croisements += compterCroisements(l)
    return { croisements, longueur }
  }

  /* 6. Le passage au monde. */
  let minG = Infinity
  let maxG = -Infinity
  for (const g of rangeeDe.values()) {
    minG = Math.min(minG, g)
    maxG = Math.max(maxG, g)
  }
  if (!rangeeDe.size) minG = maxG = 0
  const pasRangee = hauteurFiche + INTERLIGNE
  const hautRangee = (g: number): number => (g - minG) * pasRangee + MARGE_Y + BANDEAU_RECTANGLE + MARGE_RECTANGLE
  const milieuRangee = (g: number): number => hautRangee(g) + hauteurFiche / 2

  const fiches: FicheHistoire[] = []
  const parId = new Map<IdentifiantNoeud, FicheHistoire>()
  const parPersonId = new Map<number, FicheHistoire>()
  for (const p of index.personnes) {
    const w = largeur(p.id)
    const cx = retenu.x.get(p.id)! + MARGE_X
    const g = rangeeDe.get(p.id) ?? 0
    const numero = numeroDe.get(p.id) ?? null
    const classe = classes.get(p.id)
    const groupe = groupeDe.get(p.id)!
    const fiche: FicheHistoire = {
      id: p.id,
      noeud: p,
      x: cx - w / 2,
      y: hautRangee(g),
      w,
      h: p.h || hauteurFiche,
      cx,
      rangee: g,
      directe: numero !== null,
      sosa: numero,
      detache: groupe === 'sansLien',
      parente: classe?.parente ?? 'famille',
      pont: classe?.parente === 'alliance' ? classe.pont : null,
      branche: alliancesDansLArbre ? (rattachement(p.id) ?? p.branch) : p.branch,
    }
    fiches.push(fiche)
    parId.set(p.id, fiche)
    if (p.personId !== null) parPersonId.set(p.personId, fiche)
  }

  /* 7. Les rectangles. */
  const groupes: RectangleDeGroupe[] = []
  for (const bloc of retenu.blocs) {
    const ids = membres.get(bloc.cle)!
    let haut = Infinity
    let bas = -Infinity
    for (const id of ids) {
      const f = parId.get(id)!
      haut = Math.min(haut, f.y)
      bas = Math.max(bas, f.y + f.h)
    }
    const y = haut - MARGE_RECTANGLE - BANDEAU_RECTANGLE
    groupes.push({
      cle: bloc.cle,
      libelle: LIBELLES_GROUPE[bloc.cle],
      nom: nomDuQuart(bloc.cle),
      effectif: ids.length,
      x: bloc.x0 + MARGE_X,
      y,
      w: bloc.largeur,
      h: bas + MARGE_RECTANGLE - y,
    })
  }
  function nomDuQuart(cle: CleDeGroupe): string {
    if (!QUARTS.has(cle)) return ''
    const duConjoint = cle.startsWith('c')
    const q = (duConjoint ? cle.slice(1) : cle) as 'pp' | 'pm' | 'mp' | 'mm'
    const tete = (duConjoint ? sosaConjoint : sosa).get(SOSA_DU_QUART[q])
    const noeud = tete !== undefined ? personnes.get(tete) : undefined
    return noeud ? nomDeFamilleDe(noeud) : ''
  }

  /* 8. Les traits. */
  const aretesEnAttente = new Set<string>()
  for (const a of graphe.aretes) if (a.enAttente) aretesEnAttente.add(`${a.de}|${a.vers}`)
  const { traits, foyers } = tracerLesLiens({
    fiches,
    parId,
    familles,
    epouxDe,
    enfantsDe,
    famillesDirectes,
    aretesEnAttente,
    hautRangee,
    hauteurFiche,
  })

  /* 9. Ce que l'écran lit encore de `/arbre` : quarts, alliances, zone détachée, générations. */
  const quarts: Quart[] = []
  for (const r of groupes) {
    if (!QUARTS.has(r.cle)) continue
    const membresDuQuart = membres.get(r.cle)!.map((id) => parId.get(id)!)
    quarts.push({
      branche: r.cle as BrancheDeQuart,
      libelle: r.libelle,
      nom: r.nom,
      effectif: r.effectif,
      cx: membresDuQuart.reduce((s, f) => s + f.cx, 0) / membresDuQuart.length,
      x0: r.x,
      x1: r.x + r.w,
    })
  }
  const alliances: BlocAlliance[] = []
  {
    const parPont = new Map<string, FicheHistoire[]>()
    for (const id of membres.get('alliances') ?? []) {
      const pont = classes.get(id)?.pont ?? null
      if (pont === null) continue
      const l = parPont.get(pont)
      if (l) l.push(parId.get(id)!)
      else parPont.set(pont, [parId.get(id)!])
    }
    for (const [pont, l] of parPont) {
      const x0 = Math.min(...l.map((f) => f.x))
      const x1 = Math.max(...l.map((f) => f.x + f.w))
      const noeud = personnes.get(pont)
      alliances.push({ pont, nom: noeud ? nomDeFamilleDe(noeud) : '', effectif: l.length, cx: (x0 + x1) / 2, x0, x1 })
    }
    alliances.sort((a, b) => a.x0 - b.x0)
  }
  const rectangleIsoles = groupes.find((r) => r.cle === 'sansLien')
  const zoneDetachee = rectangleIsoles
    ? { x0: rectangleIsoles.x, x1: rectangleIsoles.x + rectangleIsoles.w, cx: rectangleIsoles.x + rectangleIsoles.w / 2, effectif: rectangleIsoles.effectif }
    : null

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

  const dernier = retenu.blocs[retenu.blocs.length - 1]
  const largeurMonde = dernier ? dernier.x0 + dernier.largeur + 2 * MARGE_X : 2 * MARGE_X
  return {
    ego,
    fiches,
    parId,
    parPersonId,
    traces: traits,
    foyers,
    quarts,
    alliances,
    alliancesDansLArbre,
    zoneDetachee,
    generations,
    largeur: largeurMonde,
    hauteur: hautRangee(maxG) + hauteurFiche + MARGE_RECTANGLE + MARGE_Y + 20,
    pasRangee,
    groupes,
    croisements: retenu.croisements,
    longueurDesLiens: Math.round(retenu.longueur),
    ecartGroupes,
  }
}

function mediane(l: number[]): number {
  if (!l.length) return 132
  const t = [...l].sort((a, b) => a - b)
  return t[t.length >> 1]!
}
