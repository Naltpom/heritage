/**
 * Les descendants autrement qu'en arbre de boîtes — les deux pistes de la maquette « Options
 * centre » du commanditaire (24/10/2026) :
 *
 * - le **soleil intérieur** : la descendance en anneaux, une famille par secteur, dans le même
 *   langage que l'éventail — la tête de famille au premier anneau (le couple en haut), ses enfants
 *   au deuxième, ses petits-enfants au troisième ; chaque famille prend un angle à la mesure de sa
 *   descendance, et ses teintes pâlissent d'anneau en anneau ;
 * - la **rosace de médaillons** : une famille par médaillon, autour d'un médaillon central —
 *   la tête et son conjoint, un ornement, ses enfants (et leur conjoint), ses petits-enfants en
 *   italique.
 *
 * Les deux servent le soleil (le cercle entier, dans le disque central) et l'éventail (le
 * demi-cercle sous sa base). Vert-bleu du côté du mari, terre cuite du côté de la femme, sable pour
 * le couple. Le texte se règle sur la place : il rapetisse jusqu'à un plancher, et c'est ensuite
 * seulement le cercle qui grandit — l'appelant en décide, à partir de `rayonRequis()`.
 */
import { titre } from './dates'
import type { Personne } from './donnees'
import { ENCRE, ENCRE_DOUCE, melange, PAPIER, TRAIT, type Rgb } from './palette'
import { Style } from './polices'
import { f3, polaire, secteur, type Scene } from './scene'
import type { Desc } from './structure'

export type Cote = 'n' | 'p' | 'v'

/**
 * Les bornes du corps (pt) du nom d'une tête de famille. Le centre montre la famille vivante,
 * celle qui regarde l'affiche : il se lit au moins aussi bien que les ancêtres (7,2 pt), et c'est
 * le cercle qui grandit pour cela.
 */
export const CORPS_MIN = 10
export const CORPS_MAX = 18

/** La teinte d'un côté, au premier anneau ; les suivants pâlissent vers le papier. */
const TEINTES_DES_COTES: Record<Cote, Rgb> = { n: [229, 218, 196], p: [208, 223, 219], v: [239, 220, 197] }
const PALIERS = [1, 0.62, 0.36, 0.22]
const FONDS_DES_MEDAILLONS: Record<Cote, { fond: string; trait: string }> = {
  n: { fond: '#EFE6D6', trait: 'rgba(142,124,108,.6)' },
  p: { fond: '#E3EAE5', trait: 'rgba(78,114,112,.55)' },
  v: { fond: '#F4E9D9', trait: 'rgba(162,85,59,.5)' },
}
const PETITS_ENFANTS = '#6E5940'
/** Une case trop étroite pour le corps de son anneau rapetisse son texte, jusqu'à cette part. */
const PLANCHER_D_UNE_CASE = 0.75

/** Une famille du centre : la tête et ses unions (pour le couple des quatre parents, le mari). */
export interface FamilleDuCentre {
  cote: Cote
  /** Le couple de l'affiche, quand il est l'une des familles (soleil des quatre parents). */
  couple: [Personne, Personne] | null
  tete: Desc
}

/** Ce que porte le cœur : les deux noms du titre, le couple et ses enfants s'il y est, les chiffres. */
export interface Coeur {
  noms: [string, string]
  couple: [Personne, Personne] | null
  enfants: Personne[]
  chiffres: string[]
}

/* ------------------------------------------------------------------ */
/* Les lignes : des morceaux de styles différents, bout à bout          */
/* ------------------------------------------------------------------ */

type Morceau = readonly [string, Style]
type Ligne = Morceau[]

const largeur = (l: Ligne): number => l.reduce((w, [t, s]) => w + s.largeur(t), 0)
const hauteur = (l: Ligne): number => l.reduce((h, [, s]) => Math.max(h, s.interligne), 0)
const echelle = (l: Ligne, k: number): Ligne => l.map(([t, s]) => [t, s.echelle(k)] as const)

/** « Prénoms NOM » : le prénom au corps `c`, le nom en capitales grasses un peu plus petites. */
function nomComplet(p: Personne, c: number, couleur = ENCRE, prefixe = '', italique = false): Ligne {
  const l: Ligne = []
  const prenoms = `${prefixe}${p.prenoms}`.trim()
  if (prenoms) l.push([p.nom ? `${prenoms} ` : prenoms, new Style('SS', c, couleur, 0, italique ? 'Ital' : 'Reg')])
  if (p.nom) l.push([p.nom, new Style('SS', c * 0.84, couleur, 0.05, 'Semi')])
  return l
}

/** Le même sur deux lignes : les prénoms, puis le NOM. */
function nomCoupe(p: Personne, c: number, couleur = ENCRE, prefixe = '', italique = false): Ligne[] {
  const lignes: Ligne[] = []
  const prenoms = `${prefixe}${p.prenoms}`.trim()
  if (prenoms) lignes.push([[prenoms, new Style('SS', c, couleur, 0, italique ? 'Ital' : 'Reg')]])
  if (p.nom) lignes.push([[p.nom, new Style('SS', c * 0.84, couleur, 0.05, 'Semi')]])
  return lignes
}

const conjoints = (d: Desc): Personne[] => d.unions.map(([c]) => c?.p).filter((p): p is Personne => p !== undefined)
const enfants = (d: Desc): Desc[] => d.unions.flatMap(([, , e]) => e)
const signe = (i: number): string => ['∞ ', '∞² ', '∞³ '][i] ?? `∞${i + 1} `

/** « A, B et C » */
function enumerer(mots: string[]): string {
  return mots.length > 1 ? `${mots.slice(0, -1).join(', ')} et ${mots[mots.length - 1]}` : (mots[0] ?? '')
}

/** Pose une ligne centrée sur (x, y) — y la ligne de base —, éventuellement dans un repère tourné. */
function poserLigne(scene: Scene, l: Ligne, x: number, y: number, transform?: string, dispo = Infinity): void {
  const w = largeur(l)
  // Comme `ajuster()` : le corps jusqu'à 84 %, puis la chasse.
  const k = w > dispo && dispo > 0 ? dispo / w : 1
  const corps = Math.max(k, 0.84)
  const chasse = k < 0.84 ? k / 0.84 : 1
  let pos = x - (w * corps * chasse) / 2
  for (const [t, s] of l) {
    const st = s.echelle(corps)
    const lw = st.largeur(t) * chasse
    scene.texte(pos, y, t, st, 'start', transform, chasse < 1 ? lw : null)
    pos += lw
  }
}

/** L'ornement des maquettes : un trait, un losange, un trait. */
function ornement(scene: Scene, x: number, y: number, l: number): void {
  scene.ligneDroite(x - l * 0.62, y, x - l * 0.18, y, TRAIT, l * 0.025)
  scene.ligneDroite(x + l * 0.18, y, x + l * 0.62, y, TRAIT, l * 0.025)
  const d = l * 0.07
  scene.chemin(`M${f3(x)},${f3(y - d)} L${f3(x + d)},${f3(y)} L${f3(x)},${f3(y + d)} L${f3(x - d)},${f3(y)} Z`, TRAIT)
}

/** Un bloc de lignes (`null` : l'ornement) empilé et centré : rend les lignes de base, relatives au centre. */
function empiler(lignes: (Ligne | null)[], ornementH: number): { bases: number[]; hauteur: number } {
  const hs = lignes.map((l) => (l ? hauteur(l) : ornementH))
  const total = hs.reduce((a, b) => a + b, 0)
  let y = -total / 2
  const bases = hs.map((h, i) => {
    const base = lignes[i] ? y + h * 0.78 : y + h / 2
    y += h
    return base
  })
  return { bases, hauteur: total }
}

/** Vrai si le bloc tient dans un cercle de rayon `r` : chaque ligne dans sa corde. */
function tientDansLeCercle(lignes: (Ligne | null)[], bases: number[], r: number): boolean {
  return lignes.every((l, i) => {
    if (!l) return true
    const h = hauteur(l)
    const loin = Math.max(Math.abs(bases[i]! - h * 0.78), Math.abs(bases[i]! + h * 0.22))
    return loin < r && largeur(l) / 2 <= Math.sqrt(r * r - loin * loin)
  })
}

/** Le plus grand `x` de [lo, hi] pour lequel `ok(x)` (supposé vrai en `lo`, et monotone). */
function plusGrand(lo: number, hi: number, ok: (x: number) => boolean): number {
  if (ok(hi)) return hi
  for (let i = 0; i < 22; i++) {
    const mid = (lo + hi) / 2
    if (ok(mid)) lo = mid
    else hi = mid
  }
  return lo
}

/** Le titre du cœur : DESCENDANCE, les deux noms, l'ornement, les chiffres — au corps `u`. */
function lignesDuTitre(coeur: Coeur, u: number, empiles: boolean): (Ligne | null)[] {
  const nom = (t: string): Ligne => [[titre(t), new Style('CG', 30 * u, ENCRE, 0.01, 'Bold')]]
  const lignes: (Ligne | null)[] = [[['DESCENDANCE', new Style('SS', 8 * u, ENCRE_DOUCE, 0.14, 'Reg')]]]
  if (empiles) lignes.push(nom(coeur.noms[0]), null, nom(coeur.noms[1]))
  else lignes.push([[`${titre(coeur.noms[0])} · ${titre(coeur.noms[1])}`, new Style('CG', 22 * u, ENCRE, 0.01, 'Bold')]], null)
  if (coeur.couple) {
    lignes.push(nomComplet(coeur.couple[0], 11.5 * u), [['∞', new Style('SS', 13 * u, TRAIT, 0, 'Reg')]], nomComplet(coeur.couple[1], 11.5 * u))
    if (coeur.enfants.length > 0) lignes.push([[enumerer(coeur.enfants.map((p) => p.prenoms.split(' ')[0]!)), new Style('SS', 10 * u, ENCRE_DOUCE, 0, 'Ital')]])
  } else {
    for (const c of coeur.chiffres) lignes.push([[c, new Style('SS', 8 * u, ENCRE_DOUCE, 0, 'Reg')]])
  }
  return lignes
}

/** Le titre posé au plus grand corps qui tient dans le cercle (cx, cy, r). */
function poserLeTitre(scene: Scene, coeur: Coeur, cx: number, cy: number, r: number, empiles: boolean): void {
  const ornementH = (u: number): number => 9 * u * 0.3528
  const ok = (u: number): boolean => {
    const ls = lignesDuTitre(coeur, u, empiles)
    return tientDansLeCercle(ls, empiler(ls, ornementH(u)).bases, r)
  }
  const u = plusGrand(0.05, 6, ok)
  const ls = lignesDuTitre(coeur, u, empiles)
  const { bases } = empiler(ls, ornementH(u))
  ls.forEach((l, i) => {
    if (l) poserLigne(scene, l, cx, cy + bases[i]!)
    else ornement(scene, cx, cy + bases[i]!, 30 * u * 0.3528 * 1.6)
  })
}

/* ------------------------------------------------------------------ */
/* Le soleil intérieur                                                  */
/* ------------------------------------------------------------------ */

/** Une case du soleil intérieur : ses lignes, en une forme et (au besoin) coupée en deux. */
interface CaseDuSoleil {
  lignes: Ligne[]
  coupees: Ligne[]
  /** Le couple, dont les noms suivent l'arc de préférence. */
  couple: boolean
  enfants: CaseDuSoleil[]
  poids: number
  cote: Cote
}

/** Une case posée : son anneau, ses angles (degrés), et sa forme. */
interface CasePosee {
  c: CaseDuSoleil
  anneau: number
  a1: number
  a2: number
}

export interface PoseDuSoleil {
  /** Le premier secteur commence ici (degrés, sens trigonométrique)… */
  depart: number
  /** … et les familles se suivent dans ce sens (−1 : celui des aiguilles d'une montre). */
  sens: 1 | -1
  /** L'ouverture : 360 pour le soleil, 180 pour l'éventail. */
  ouverture: number
}

export class SoleilInterieur {
  /** Le nombre d'anneaux : la tête, puis jusqu'à trois générations. */
  readonly anneaux: number
  /** Le rayon du cœur (le titre), en part du rayon. */
  readonly coeurRelatif: number

  constructor(
    readonly familles: FamilleDuCentre[],
    readonly coeur: Coeur | null,
    readonly plein: boolean,
  ) {
    const profondeur = (d: Desc): number => 1 + Math.max(0, ...enfants(d).map(profondeur))
    this.anneaux = Math.min(4, Math.max(1, ...familles.map((f) => profondeur(f.tete))))
    this.coeurRelatif = coeur ? 0.275 : 0.16
  }

  /** Les bornes des anneaux, en part du rayon : le cœur, puis des épaisseurs à la maquette. */
  private bornes(): number[] {
    const poids = [1, 1.26, 1.02, 1].slice(0, this.anneaux)
    const total = poids.reduce((a, b) => a + b, 0)
    const sortie = [this.coeurRelatif]
    for (const p of poids) sortie.push(sortie[sortie.length - 1]! + ((1 - this.coeurRelatif) * p) / total)
    return sortie
  }

  /** Les cases, chaque anneau à son corps `corps[anneau − 1]` (pour le premier, le nom d'une tête de famille, en points). */
  private cases(corps: number[]): CaseDuSoleil[] {
    const construire = (d: Desc, anneau: number, cote: Cote, couple: [Personne, Personne] | null): CaseDuSoleil => {
      const dernier = anneau === this.anneaux
      const b = corps[anneau - 1]!
      const c = anneau === 1 ? b : b * 0.833
      const cc = anneau === 1 ? b * 0.833 : b * 0.767
      let lignes: Ligne[]
      let coupees: Ligne[]
      if (couple) {
        lignes = [nomComplet(couple[0], b * 0.93), [['∞', new Style('SS', b * 0.93, TRAIT, 0, 'Reg')]], nomComplet(couple[1], b * 0.93)]
        coupees = [...nomCoupe(couple[0], b * 0.85), [['∞', new Style('SS', b * 0.85, TRAIT, 0, 'Reg')]], ...nomCoupe(couple[1], b * 0.85)]
      } else {
        const epoux = conjoints(d)
        lignes = [nomComplet(d.p, c), ...epoux.map((p, i) => nomComplet(p, cc, ENCRE_DOUCE, signe(i), true))]
        coupees = [...nomCoupe(d.p, c), ...epoux.flatMap((p, i) => nomCoupe(p, cc * 0.95, ENCRE_DOUCE, signe(i), true))]
        if (dernier) lignes = coupees
      }
      // Pour le couple, seuls ses enfants communs : les autres unions du mari sont sa fratrie à lui.
      const sous = dernier ? [] : couple ? (d.unions.find(([cj]) => cj?.p.id === couple[1].id)?.[2] ?? []) : enfants(d)
      const fils = sous.map((e) => construire(e, anneau + 1, cote, null))
      const poids = fils.length > 0 ? Math.max(1, fils.reduce((s, f) => s + f.poids, 0)) : 1
      return { lignes, coupees, couple: couple !== null, enfants: fils, poids: couple ? poids * 1.4 : poids, cote }
    }
    return this.familles.map((f) => construire(f.tete, 1, f.cote, f.couple))
  }

  /** Les cases posées, d'angle en angle, à partir de `pose`. */
  private poser(cases: CaseDuSoleil[], pose: PoseDuSoleil): { posees: CasePosee[]; limites: number[] } {
    const total = cases.reduce((s, c) => s + c.poids, 0) || 1
    const unite = pose.ouverture / total
    const posees: CasePosee[] = []
    const limites: number[] = []
    const descendre = (c: CaseDuSoleil, anneau: number, a: number, b: number): void => {
      posees.push({ c, anneau, a1: Math.min(a, b), a2: Math.max(a, b) })
      // Les enfants se partagent tout l'angle du parent, au prorata de leur poids — celui du
      // couple compris, plus large que leur somme : chaque enfant y prenait sa seule mesure, et
      // laissait un trou derrière lui (relevé du 24/10/2026).
      const somme = c.enfants.reduce((s, e) => s + e.poids, 0)
      let x = a
      for (const e of c.enfants) {
        const y = x + ((b - a) * e.poids) / (somme || 1)
        descendre(e, anneau + 1, x, y)
        x = y
      }
    }
    // Le demi-cercle des quatre parents : le couple au milieu, sous le titre, et chaque fratrie
    // se partage la moitié qui reste de son côté, si nombreuse que soit l'autre.
    const unites = cases.map(() => unite)
    const i = cases.findIndex((c) => c.couple)
    if (!this.plein && i > 0 && i < cases.length - 1) {
      const moitie = (pose.ouverture - cases[i]!.poids * unite) / 2
      const somme = (cs: CaseDuSoleil[]): number => cs.reduce((s, c) => s + c.poids, 0)
      const [g, d] = [somme(cases.slice(0, i)), somme(cases.slice(i + 1))]
      cases.forEach((_, k) => (unites[k] = k < i ? moitie / g : k > i ? moitie / d : unite))
    }
    let a = pose.depart
    cases.forEach((c, k) => {
      limites.push(a)
      const b = a + pose.sens * c.poids * unites[k]!
      descendre(c, 1, a, b)
      a = b
    })
    limites.push(a)
    return { posees, limites }
  }

  /** La pose par défaut : le premier secteur centré en haut (soleil), ou de gauche à droite par le bas (éventail). */
  poseParDefaut(rotation = 0, versLeHaut = false): PoseDuSoleil {
    if (!this.plein) return { depart: 180, sens: versLeHaut ? -1 : 1, ouverture: 180 }
    const cases = this.cases(this.partout(10))
    const total = cases.reduce((s, c) => s + c.poids, 0) || 1
    const premier = ((cases[0]?.poids ?? 0) / total) * 360
    return { depart: 90 + premier / 2 - rotation, sens: -1, ouverture: 360 }
  }

  /**
   * Ce que réclame une case (mm de rayon) au corps `b` : par l'arc pour les lignes qui le suivent,
   * en rayonnant sinon — la moins gourmande des formes possibles.
   */
  private exigence(p: CasePosee, bornes: number[], k = 1): { rayon: number; forme: 'arc' | 'rayon' | 'coupe' } {
    if (k !== 1) p = { ...p, c: { ...p.c, lignes: p.c.lignes.map((l) => echelle(l, k)), coupees: p.c.coupees.map((l) => echelle(l, k)) } }
    const angle = ((p.a2 - p.a1) * Math.PI) / 180
    const f1 = bornes[p.anneau - 1]!
    const f2 = bornes[p.anneau]!
    const rayonnant = (ls: Ligne[]): number => {
      const h = ls.reduce((s, l) => s + hauteur(l), 0)
      const w = Math.max(...ls.map(largeur))
      const marge = hauteur(ls[0]!) * 0.35
      return Math.max((w + 2 * marge) / (f2 - f1), (h + marge) / (angle * f1))
    }
    const options: { rayon: number; forme: 'arc' | 'rayon' | 'coupe' }[] = [
      { rayon: rayonnant(p.c.lignes), forme: 'rayon' },
      { rayon: rayonnant(p.c.coupees), forme: 'coupe' },
    ]
    const moindre = (os: typeof options): (typeof options)[number] => os.reduce((a, o) => (o.rayon < a.rayon ? o : a))
    if (p.anneau === 1) {
      // Par l'arc : chaque ligne sur le cercle de sa ligne de base, le bloc centré dans l'anneau.
      // La ligne la plus proche du centre est à au moins `fm·r − h/2` : on compte large.
      const ls = p.c.lignes
      const h = ls.reduce((s, l) => s + hauteur(l), 0)
      const fm = (f1 + f2) / 2
      const pire = Math.max(...ls.map((l) => (largeur(l) + hauteur(l)) / angle))
      const arc = { rayon: Math.max((pire + h / 2) / fm, h / (f2 - f1)), forme: 'arc' as const }
      // Le couple suit l'arc s'il le peut sans trop coûter, comme dans la maquette.
      if (p.c.couple && arc.rayon <= moindre(options).rayon * 1.35) return arc
      options.push(arc)
    }
    return moindre(options)
  }

  private partout(b: number): number[] {
    return Array.from({ length: this.anneaux }, () => b)
  }

  /**
   * Le rayon qu'il faut, anneau par anneau à son corps, pour que tous les textes tiennent — une
   * case étroite pouvant rapetisser le sien jusqu'au plancher.
   */
  rayonRequis(corps: number[], pose: PoseDuSoleil, seulement?: number): number {
    const bornes = this.bornes()
    const { posees } = this.poser(this.cases(corps), pose)
    let r = 0
    for (const p of posees) if (seulement === undefined || p.anneau === seulement) r = Math.max(r, this.exigence(p, bornes, PLANCHER_D_UNE_CASE).rayon)
    return r
  }

  /** La part de son corps que prend le texte d'une case dans le rayon `r` : 1, ou moins si elle est étroite. */
  private part(p: CasePosee, bornes: number[], r: number): number {
    if (this.exigence(p, bornes).rayon <= r) return 1
    return plusGrand(PLANCHER_D_UNE_CASE, 1, (k) => this.exigence(p, bornes, k).rayon <= r)
  }

  /**
   * Le corps de chaque anneau : le plus grand de [min, max] où toutes ses cases tiennent dans le
   * rayon `r` (les étroites rapetissant jusqu'au plancher), sans dépasser celui de l'anneau
   * d'avant — la hiérarchie des générations se lit dans la taille. `min` pour un anneau qui n'y
   * tient pas : c'est alors le rayon qu'il faut agrandir (`rayonRequis(corps…)`).
   */
  corpsPour(r: number, pose: PoseDuSoleil, min: number, max: number): number[] {
    const corps = this.partout(min)
    let plafond = max
    for (let k = 1; k <= this.anneaux; k++) {
      const essai = (b: number): number[] => corps.map((c, i) => (i === k - 1 ? b : c))
      const ok = (b: number): boolean => this.rayonRequis(essai(b), pose, k) <= r
      corps[k - 1] = ok(min) ? plusGrand(min, Math.max(min, plafond), ok) : min
      plafond = corps[k - 1]!
    }
    return corps
  }

  /** Dessine le soleil intérieur centré sur (cx, cy), de rayon `r`, chaque anneau à son corps. */
  dessiner(scene: Scene, cx: number, cy: number, r: number, corps: number[], pose: PoseDuSoleil): void {
    const bornes = this.bornes()
    const cases = this.cases(corps)
    const { posees, limites } = this.poser(cases, pose)
    const trait = r * 0.0016
    for (const p of posees) {
      const k = PALIERS[Math.min(p.anneau - 1, PALIERS.length - 1)]!
      scene.chemin(secteur(cx, cy, bornes[p.anneau - 1]! * r, bornes[p.anneau]! * r, p.a1, p.a2), melange(TEINTES_DES_COTES[p.c.cote], k), PAPIER, trait * 1.5)
    }
    // Les familles séparées par un trait plus épais, du cœur au dernier anneau.
    {
      const bords = this.plein ? limites.slice(0, -1) : limites.slice(1, -1)
      for (const a of bords) {
        const [x1, y1] = polaire(cx, cy, bornes[0]! * r, a)
        const [x2, y2] = polaire(cx, cy, bornes[this.anneaux]! * r, a)
        scene.ligneDroite(x1, y1, x2, y2, PAPIER, r * 0.0037)
      }
    }
    for (const p of posees) {
      const k = this.part(p, bornes, r)
      this.texte(scene, cx, cy, r, k === 1 ? p : { ...p, c: { ...p.c, lignes: p.c.lignes.map((l) => echelle(l, k)), coupees: p.c.coupees.map((l) => echelle(l, k)) } }, bornes)
    }
    if (this.coeur) {
      scene.chemin(secteur(cx, cy, 0, bornes[0]! * r, 0, 360), '#F3ECDF', PAPIER, r * 0.003)
      poserLeTitre(scene, this.coeur, cx, cy, bornes[0]! * r * 0.9, true)
    }
  }

  private texte(scene: Scene, cx: number, cy: number, r: number, p: CasePosee, bornes: number[]): void {
    // (La forme se décide comme pour le rayon requis : la moins gourmande.)
    const { forme } = this.exigence(p, bornes)
    const r1 = bornes[p.anneau - 1]! * r
    const r2 = bornes[p.anneau]! * r
    const am = (p.a1 + p.a2) / 2
    const ls = forme === 'coupe' ? p.c.coupees : p.c.lignes
    if (forme === 'arc') {
      const dessous = Math.sin((am * Math.PI) / 180) < 0
      const h = ls.reduce((s, l) => s + hauteur(l), 0)
      const rm = (r1 + r2) / 2
      const angle = ((p.a2 - p.a1) * Math.PI) / 180
      let bord = dessous ? rm - h / 2 : rm + h / 2
      for (const l of ls) {
        const lh = hauteur(l)
        const base = dessous ? bord + lh * 0.8 : bord - lh * 0.8
        bord += dessous ? lh : -lh
        // Ramené à la largeur de l'arc s'il le faut, puis posé morceau par morceau.
        const w = largeur(l)
        const dispo = angle * base - lh
        const ll = w > dispo ? echelle(l, Math.max(0.6, dispo / w)) : l
        const total = largeur(ll)
        let x = -total / 2
        for (const [t, s] of ll) {
          const lw = s.largeur(t)
          const milieu = ((x + lw / 2) / base) * (180 / Math.PI)
          const centre = dessous ? am + milieu : am - milieu
          const demi = (lw / 2 / base) * (180 / Math.PI) * 1.02
          scene.texteSurArc(cx, cy, base, centre - demi, centre + demi, t, s, dessous)
          x += lw
        }
      }
      return
    }
    // En rayonnant : à droite du centre, on lit vers l'extérieur ; à gauche, vers le centre.
    const rm = (r1 + r2) / 2
    const adroite = Math.cos((am * Math.PI) / 180) >= 0
    const [rot, x] = adroite ? [-am, rm] : [180 - am, -rm]
    const tr = `translate(${f3(cx)},${f3(cy)}) rotate(${rot.toFixed(4)})`
    const h = ls.reduce((s, l) => s + hauteur(l), 0)
    let y = -h / 2
    const dispo = r2 - r1 - hauteur(ls[0]!) * 0.7
    for (const l of ls) {
      const lh = hauteur(l)
      poserLigne(scene, l, x, y + lh * 0.78, tr, dispo)
      y += lh
    }
  }
}

/* ------------------------------------------------------------------ */
/* La rosace de médaillons                                              */
/* ------------------------------------------------------------------ */

export class Rosace {
  constructor(
    readonly familles: FamilleDuCentre[],
    readonly coeur: Coeur | null,
    readonly plein: boolean,
  ) {}

  /** Les lignes d'un médaillon au corps `b` (le nom de la tête) ; `null` : l'ornement. */
  private lignes(f: FamilleDuCentre, b: number, largeurMax: number): (Ligne | null)[] {
    const d = f.tete
    // Le couple de l'affiche (éventail des quatre parents) : lui, elle, et leurs seuls enfants
    // communs, comme au soleil intérieur.
    const lignes: (Ligne | null)[] = f.couple
      ? [nomComplet(f.couple[0], b), nomComplet(f.couple[1], b, ENCRE, '∞ ')]
      : [nomComplet(d.p, b), ...conjoints(d).map((p, i) => nomComplet(p, b * 0.79, ENCRE_DOUCE, signe(i), true))]
    const fils = f.couple ? (d.unions.find(([c]) => c?.p.id === f.couple![1].id)?.[2] ?? []) : enfants(d)
    if (fils.length > 0) lignes.push(null)
    for (const e of fils) {
      const moi = nomComplet(e.p, b * 0.76)
      const leurs = conjoints(e)
      const avec = leurs.length > 0 ? [...moi, ...nomComplet(leurs[0]!, b * 0.76, ENCRE_DOUCE, ' ∞ ', true)] : moi
      if (largeur(avec) <= largeurMax) lignes.push(avec)
      else lignes.push(moi, ...leurs.slice(0, 1).map((p) => nomComplet(p, b * 0.72, ENCRE_DOUCE, '∞ ', true)))
      // Les petits-enfants : « Louison et Pablo GABRIEL », par nom de famille.
      const petits = enfants(e)
      if (petits.length > 0) {
        const parNom = new Map<string, string[]>()
        for (const g of petits) parNom.set(g.p.nom, [...(parNom.get(g.p.nom) ?? []), g.p.prenoms.split(' ')[0]!])
        for (const [nom, prenoms] of parNom) {
          const l: Ligne = [[`${enumerer(prenoms)}${nom ? ' ' : ''}`, new Style('SS', b * 0.72, PETITS_ENFANTS, 0, 'Ital')]]
          if (nom) l.push([nom, new Style('SS', b * 0.72 * 0.84, PETITS_ENFANTS, 0.04, 'Semi')])
          lignes.push(l)
        }
      }
    }
    return lignes
  }

  private tient(f: FamilleDuCentre, b: number, m: number): boolean {
    const r = m * 0.86
    const ls = this.lignes(f, b, r * 1.6)
    return tientDansLeCercle(ls, empiler(ls, b * 0.3528 * 0.9).bases, r)
  }

  /** La géométrie du cercle entier : rayon des médaillons, de leur couronne, du cœur — en part du rayon. */
  private geometrie(): { m: number; rho: number; c: number } {
    const n = this.familles.length
    if (n === 0) return { m: 0, rho: 0, c: 0.6 }
    const s = n === 1 ? 1 : 0.94 * Math.sin(Math.PI / n)
    const m = Math.min(0.3, s / (1 + s))
    const rho = 1 - m
    return { m, rho, c: Math.max(0.12, Math.min(0.33, rho - m - 0.03)) }
  }

  /**
   * Les angles des médaillons sur le demi-cercle, depuis son début (degrés), à pas égal ; pour
   * les quatre parents, le couple au milieu, et les fratries de part et d'autre au pas de la plus
   * nombreuse — la plus courte reste serrée contre lui.
   */
  private anglesDuDemi(): number[] {
    const n = Math.max(1, this.familles.length)
    const i = this.familles.findIndex((f) => f.couple)
    if (i <= 0 || i >= this.familles.length - 1) return this.familles.map((_, k) => (180 / n) * (k + 0.5))
    const pas = 90 / (Math.max(i, this.familles.length - 1 - i) + 0.5)
    return this.familles.map((_, k) => 90 + pas * (k - i))
  }

  /** Le demi-cercle : rayon des médaillons et de leur arc pour le corps `b` (mm). */
  private demi(b: number): { m: number; rho: number } {
    const m = Math.max(...this.familles.map((f) => {
      let lo = 5
      while (!this.tient(f, b, lo) && lo < 2000) lo *= 1.15
      return lo
    }), 5)
    // Le pas le plus serré décide du rayon de l'arc : deux voisins ne se touchent pas.
    const angles = this.anglesDuDemi()
    const pas = Math.min(180 / Math.max(1, angles.length), ...angles.slice(1).map((a, k) => a - angles[k]!)) * (Math.PI / 180)
    const rho = Math.max(m * 1.15, (m * 1.06) / Math.sin(pas / 2))
    return { m, rho }
  }

  /** Le plus grand corps de [min, max] qui tient dans le cercle de rayon `r` (cercle entier). */
  corpsPour(r: number, min: number, max: number): number {
    const ok = (b: number): boolean => this.tientTout(b, r)
    return ok(min) ? plusGrand(min, max, ok) : min
  }

  /** Le rayon qu'il faut (cercle entier) au corps `b`. */
  rayonRequis(b: number): number {
    let r = 20
    while (!this.tientTout(b, r) && r < 20000) r *= 1.08
    return r
  }

  private tientTout(b: number, r: number): boolean {
    const { m, c } = this.geometrie()
    if (!this.familles.every((f) => this.tient(f, b, m * r))) return false
    if (!this.coeur) return true
    const rc = c * r * 0.86
    const ok = (u: number): boolean => {
      const ls = lignesDuTitre(this.coeur!, u, false)
      return tientDansLeCercle(ls, empiler(ls, 9 * u * 0.3528).bases, rc)
    }
    // Le cœur au moins au corps des médaillons.
    return ok(b / 14.5)
  }

  /** L'encombrement du demi-cercle au corps `b` : demi-largeur et profondeur sous le centre (mm). */
  encombrementDemi(b: number): { demiLargeur: number; profondeur: number } {
    const { m, rho } = this.demi(b)
    return { demiLargeur: rho + m, profondeur: rho + m }
  }

  /** Dessine la rosace : cercle entier de rayon `r` centré sur (cx, cy), ou demi-cercle sous (cx, cy). */
  dessiner(scene: Scene, cx: number, cy: number, r: number, b: number, rotation = 0, versLeHaut = false): void {
    const n = this.familles.length
    let m: number
    let rho: number
    let angles: number[]
    if (this.plein) {
      const g = this.geometrie()
      ;[m, rho] = [g.m * r, g.rho * r]
      angles = this.familles.map((_, i) => 90 - (i * 360) / n - rotation)
      scene.chemin(secteur(cx, cy, 0, rho, 0, 360), 'none', 'rgba(142,124,108,.7)', r * 0.0022, false, `${f3(r * 0.012)},${f3(r * 0.009)}`)
      if (this.coeur) {
        const c = g.c * r
        scene.chemin(secteur(cx, cy, 0, c, 0, 360), PAPIER, TRAIT, c * 0.0067)
        scene.chemin(secteur(cx, cy, 0, c * 0.98, 0, 360), 'none', '#F1E8D8', c * 0.04)
        poserLeTitre(scene, this.coeur, cx, cy, c * 0.86, false)
      }
    } else {
      ;({ m, rho } = this.demi(b))
      angles = this.anglesDuDemi().map((a) => (versLeHaut ? 180 - a : 180 + a))
    }
    this.familles.forEach((f, i) => {
      const [x, y] = polaire(cx, cy, rho, angles[i]!)
      const { fond, trait } = FONDS_DES_MEDAILLONS[f.cote]
      scene.chemin(secteur(x, y, 0, m, 0, 360), fond, trait, m * 0.0074)
      scene.chemin(secteur(x, y, 0, m * 0.98, 0, 360), 'none', 'rgba(251,248,242,.6)', m * 0.037)
      const ls = this.lignes(f, b, m * 0.86 * 1.6)
      const { bases } = empiler(ls, b * 0.3528 * 0.9)
      ls.forEach((l, j) => {
        if (l) poserLigne(scene, l, x, y + bases[j]!)
        else ornement(scene, x, y + bases[j]!, b * 0.3528 * 4.4)
      })
    })
  }
}
