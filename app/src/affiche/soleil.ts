/**
 * L'affiche « soleil » : l'ascendance sur le cercle entier, la descendance dans le disque central.
 *
 * Le cercle est l'éventail à 360° (`Reglages.angle`), tourné au choix (`Reglages.rotation`).
 * Deux centres possibles (`OptionsDuSoleil.centre`) :
 *
 * - **le couple** de la fiche : il occupe le premier anneau, et ses descendants le disque ;
 * - **ses quatre parents** : ils occupent le premier anneau (celui du couple est masqué), et le
 *   disque reçoit leurs descendants — le couple, ses frères et sœurs, leurs enfants. La famille
 *   du mari à gauche, celle de la femme à droite ; le couple et ses enfants une seule fois, sous
 *   le mari (la femme figure aussi, seule, parmi ses frères et sœurs).
 *
 * Le centre porte le titre, puis les sous-arbres des enfants **rangés en bandes qui suivent le
 * cercle** : chaque bande est aussi large que le disque le permet à sa hauteur, le long d'un tronc
 * qui descend du titre — au milieu, enfants de part et d'autre, pour le couple ; un tronc par
 * famille pour les quatre parents. Une rangée unique, trop large, laissait le disque vide au-dessus
 * et en dessous (relevé du 24/10/2026 : trente et un descendants à mi-taille dans un disque grossi
 * de moitié).
 *
 * Le disque garde sa taille tant que les descendants y tiennent à 55 % de leur corps ; ils
 * grossissent s'il y a de la place (jusqu'à 140 %). Au-delà, c'est le disque qui grandit.
 */
import { dessinerDesc, mesurerDesc, placerDesc, ReglesDesc } from './boites'
import type { ArbreAffiche } from './donnees'
import { etendues, Eventail, pointLibre, Reglages } from './eventail'
import { legende, lignesDuCouple, MENTION_PAR_DEFAUT, pied, type AfficheProduite, type ChiffresDuPied, type OptionsDAffiche } from './fabriquer'
import type { Style } from './polices'
import { Scene } from './scene'
import { ascendance, descendance, descendanceDeFamille, type Desc } from './structure'

export type CentreDuSoleil = 'couple' | 'parents'

export interface OptionsDuSoleil extends OptionsDAffiche {
  /** Ce que porte le premier anneau, et dont le disque montre la descendance. */
  centre?: CentreDuSoleil
}

/** Les bornes du corps des descendants, en part de leur corps de base (13 pt pour les enfants). */
const ECHELLE_MIN = 0.55
const ECHELLE_MAX = 1.4
/** La part du rayon où le texte peut aller : un peu de marge avant les anneaux. */
const DANS_LE_DISQUE = 0.93

const reglesPour = (e: number): ReglesDesc => new ReglesDesc([13, 11, 9.5, 9].map((c) => c * e), 'long', 3 * e, 11 * e, 5.5 * e, 34 * e)

/** Les lignes du titre posées de haut en bas depuis 0 (le haut des noms) : lignes de base et hauteur du bloc. */
function titreEmpile(ls: [string, Style, number][]): { lignes: [string, Style][]; bases: number[]; hauteur: number } {
  const vivantes = ls.filter(([t]) => Boolean(t))
  // Les écarts de `centreCouple()`, du bas vers le haut.
  const ys = [0]
  for (let i = 0; i + 1 < vivantes.length; i++) {
    const [, st, apres] = vivantes[i]!
    ys.push(ys[i]! - (st.mm * 0.74 + st.mm * 0.32 * apres + vivantes[i + 1]![1].mm * 0.26))
  }
  const dernier = vivantes.length - 1
  const haut = ys[dernier]! - vivantes[dernier]![1].mm * 0.8
  return { lignes: vivantes.map(([t, st]) => [t, st]), bases: ys.map((y) => y - haut), hauteur: vivantes[0]![1].mm * 0.25 - haut }
}

/** Le nombre de rangées d'un sous-arbre (1 : l'enfant seul). */
function profondeur(d: Desc): number {
  let p = 1
  for (const [, , enf] of d.unions) for (const e of enf) p = Math.max(p, 1 + profondeur(e))
  return p
}

/**
 * Une bande du centre. `cote` dit où sont ses enfants par rapport à son tronc : des deux côtés
 * (`0`, avec un couloir où le tronc passe, ou centrés sous lui s'il s'arrête là), à gauche (`-1`)
 * ou à droite (`1`).
 */
interface Bande {
  enfants: Desc[]
  /** Pour `cote` 0 : combien des enfants vont à gauche du couloir ; `-1` : centrés, sans couloir. */
  coupure: number
  cote: -1 | 0 | 1
  y: number
  hauteurs: Map<number, number>
}

interface Agencement {
  e: number
  regles: ReglesDesc
  titre: ReturnType<typeof titreEmpile>
  /** Le haut du bloc, par rapport au centre du disque. */
  haut: number
  /** Les bandes de chaque tronc, avec l'abscisse du tronc par rapport au centre. */
  troncs: { x: number; bandes: Bande[] }[]
}

/** Les familles du centre : une seule (le couple), ou deux, de part et d'autre (les quatre parents). */
type Familles = { seule: Desc[] } | { gauche: Desc[]; droite: Desc[] }

/**
 * Le centre à l'échelle `e` dans un disque de rayon `r` (coordonnées relatives à son centre), ou
 * `null` s'il n'y tient pas. On essaie plusieurs hauteurs pour le haut du bloc et l'on garde
 * celle qui le centre le mieux.
 */
function agencer(familles: Familles, lignes: (u: number) => [string, Style, number][], e: number, r: number): Agencement | null {
  const regles = reglesPour(e)
  const listes = 'seule' in familles ? [familles.seule] : [familles.gauche, familles.droite]
  const hauteursGlobales = new Map<number, number>()
  for (const l of listes) for (const [n, h] of mesurerDesc(l, regles)[1]) hauteursGlobales.set(n, Math.max(hauteursGlobales.get(n) ?? 0, h))
  const titre = titreEmpile(lignes(Math.max(e, 0.75)))
  const rr = r * DANS_LE_DISQUE
  const demiCorde = (y1: number, y2: number): number => {
    const loin = Math.max(Math.abs(y1), Math.abs(y2))
    return loin >= rr ? -1 : Math.sqrt(rr * rr - loin * loin)
  }
  const couloir = 8 * e
  const ecartBandes = regles.ecartY * 1.6
  const aucun = listes.every((l) => l.length === 0)
  const hauteurDe = (p: number): number => {
    let h = 0
    for (let n = 1; n <= p; n++) h += hauteursGlobales.get(n) ?? 0
    return h + regles.ecartY * (p - 1)
  }
  const largeurDe = (ds: Desc[]): number => ds.reduce((s, d) => s + d.largeurSousArbre, 0) + regles.ecartX * 2 * Math.max(ds.length - 1, 0)
  /** La coupure gauche/droite la plus équilibrée, dans l'ordre. */
  const couper = (ds: Desc[]): number => {
    let meilleur: [number, number] = [Infinity, 0]
    for (let k = 0; k <= ds.length; k++) {
      const pire = Math.max(largeurDe(ds.slice(0, k)), largeurDe(ds.slice(k)))
      if (pire < meilleur[0]) meilleur = [pire, k]
    }
    return meilleur[1]
  }
  const hauteursDe = (ds: Desc[]): Map<number, number> => {
    const p = Math.max(...ds.map(profondeur))
    return new Map([...hauteursGlobales].filter(([n]) => n <= p))
  }

  /**
   * Les bandes d'un tronc, du haut `y0` vers le bas. Sur le tronc du milieu (`cote` 0), la
   * dernière bande se centre sous lui ; un enfant seul aussi, mais il arrête le tronc : il ne peut
   * donc être que dans la dernière.
   */
  const bandesDuTronc = (liste: Desc[], cote: -1 | 0 | 1, y0: number): { bandes: Bande[]; bas: number } | null => {
    const bandes: Bande[] = []
    let y = y0
    let i = 0
    while (i < liste.length) {
      let retenue: Bande | null = null
      for (let j = i; j < liste.length; j++) {
        const ds = liste.slice(i, j + 1)
        const h = hauteurDe(Math.max(...ds.map(profondeur)))
        const derniere = j === liste.length - 1
        let coupure = -1
        let besoin: number
        if (cote !== 0) {
          besoin = couloir / 2 + regles.ecartX + largeurDe(ds)
        } else if (derniere || ds.length === 1) {
          besoin = largeurDe(ds) / 2
        } else {
          coupure = couper(ds)
          besoin = Math.max(largeurDe(ds.slice(0, coupure)), largeurDe(ds.slice(coupure))) + couloir / 2
        }
        if (besoin > demiCorde(y, y + h)) break
        retenue = { enfants: ds, coupure, cote, y, hauteurs: hauteursDe(ds) }
      }
      if (!retenue) return null
      if (cote === 0 && retenue.coupure === -1 && i + retenue.enfants.length < liste.length) return null
      bandes.push(retenue)
      i += retenue.enfants.length
      y += hauteurDe(Math.max(...retenue.enfants.map(profondeur))) + ecartBandes
    }
    return { bandes, bas: bandes.length > 0 ? y - ecartBandes : y0 }
  }

  const essayer = (haut: number): { troncs: Agencement['troncs']; bas: number } | null => {
    for (const [i, [t, st]] of titre.lignes.entries()) {
      const base = haut + titre.bases[i]!
      if (st.largeur(t) / 2 > demiCorde(base - st.mm * 0.8, base + st.mm * 0.25)) return null
    }
    const y0 = haut + titre.hauteur + (aucun ? 0 : 18 * e)
    const plan: [Desc[], -1 | 0 | 1, number][] = 'seule' in familles ? [[familles.seule, 0, 0]] : [[familles.gauche, -1, -couloir / 2], [familles.droite, 1, couloir / 2]]
    const troncs: Agencement['troncs'] = []
    let bas = haut + titre.hauteur
    for (const [liste, cote, x] of plan) {
      const b = bandesDuTronc(liste, cote, y0)
      if (!b) return null
      troncs.push({ x, bandes: b.bandes })
      bas = Math.max(bas, b.bas)
    }
    return { troncs, bas }
  }

  let meilleur: Agencement | null = null
  let ecart = Infinity
  for (let k = 0; k <= 16; k++) {
    const haut = -rr + (k / 16) * rr
    const essai = essayer(haut)
    if (!essai) continue
    const decentrage = Math.abs(haut + essai.bas)
    if (decentrage < ecart) [meilleur, ecart] = [{ e, regles, titre, haut, troncs: essai.troncs }, decentrage]
  }
  return meilleur
}

/** Pose le centre agencé sur (cx, cy). */
function dessinerLeCentre(scene: Scene, a: Agencement, cx: number, cy: number): void {
  const haut = cy + a.haut
  a.titre.lignes.forEach(([t, st], i) => scene.texte(cx, haut + a.titre.bases[i]!, t, st))
  const couloir = 8 * a.e
  const largeurDe = (ds: Desc[]): number => ds.reduce((s, d) => s + d.largeurSousArbre, 0) + a.regles.ecartX * 2 * Math.max(ds.length - 1, 0)
  for (const tronc of a.troncs) {
    const xt = cx + tronc.x
    let depart: [number, number] = [xt, haut + a.titre.hauteur + 3 * a.e]
    for (const b of tronc.bandes) {
      const y = cy + b.y
      if (b.cote === -1) {
        placerDesc(b.enfants, xt - a.regles.ecartX - largeurDe(b.enfants), y, b.hauteurs, a.regles)
      } else if (b.cote === 1) {
        placerDesc(b.enfants, xt + a.regles.ecartX, y, b.hauteurs, a.regles)
      } else if (b.coupure === -1) {
        placerDesc(b.enfants, xt - largeurDe(b.enfants) / 2, y, b.hauteurs, a.regles)
      } else {
        const gauche = b.enfants.slice(0, b.coupure)
        placerDesc(gauche, xt - couloir / 2 - largeurDe(gauche), y, b.hauteurs, a.regles)
        placerDesc(b.enfants.slice(b.coupure), xt + couloir / 2, y, b.hauteurs, a.regles)
      }
      dessinerDesc(scene, b.enfants, depart, a.regles)
      // Le tronc repart de la barre de cette bande.
      depart = [xt, b.enfants[0]!.y - a.regles.ecartY * 0.45]
    }
  }
}

/** Le plus grand `x` de [lo, hi] pour lequel `ok(x)`, `ok` étant supposé vrai en `lo`. */
function plusGrand(lo: number, hi: number, ok: (x: number) => boolean): number {
  if (ok(hi)) return hi
  for (let i = 0; i < 18; i++) {
    const mid = (lo + hi) / 2
    if (ok(mid)) lo = mid
    else hi = mid
  }
  return lo
}

/** L'affiche « soleil » du couple (`hId`, `fId`). */
export function afficheSoleil(A: ArbreAffiche, hId: number, fId: number, options: OptionsDuSoleil = {}): AfficheProduite {
  const H = A.P.get(hId)
  const F = A.P.get(fId)
  if (!H || !F) throw new Error('Les deux époux doivent être dans l’arbre de l’affiche.')
  const mention = options.mention ?? MENTION_PAR_DEFAUT
  const date = options.date ?? new Date()
  const niveaux = options.niveauxDescendance ?? 6

  // Les quatre parents au centre : il faut connaître la famille d'origine de chacun des époux.
  const familleH = A.familleDOrigine(H.id)
  const familleF = A.familleDOrigine(F.id)
  const parents = options.centre === 'parents' && familleH !== null && familleF !== null

  const { racineH, racineF, noeuds } = ascendance(A, H, F, options.profondeur ?? Infinity)
  const reglages = new Reglages({ ...(options.reglages ?? new Reglages()), angle: 360, sens: 'haut', premierAnneauMasque: parents })
  let E = new Eventail(racineH, racineF, noeuds, reglages).calculer()

  let familles: Familles
  let lignes: (u: number) => [string, Style, number][]
  if (parents) {
    const gauche = descendanceDeFamille(A, familleH, niveaux)
    const droite = descendanceDeFamille(A, familleF, niveaux)
    // Le couple et ses enfants une seule fois : sous le mari.
    for (const d of droite) if (d.p.id === F.id) d.unions = d.unions.filter(([c]) => c?.p.id !== H.id)
    familles = { gauche, droite }
    const titre = lignesDuCouple(A, null, [...gauche, ...droite], H, F)
    lignes = (u) => titre(u).filter(([t]) => t !== '∞')
  } else {
    const { fam, enfants } = descendance(A, H, F, niveaux)
    familles = { seule: enfants }
    lignes = lignesDuCouple(A, fam, enfants, H, F)
  }

  // D'abord rapetisser les descendants pour qu'ils tiennent dans le disque ; ensuite seulement,
  // agrandir le disque.
  const r0 = E.rC
  let centre = agencer(familles, lignes, ECHELLE_MIN, r0)
  if (centre) {
    const e = plusGrand(ECHELLE_MIN, ECHELLE_MAX, (x) => agencer(familles, lignes, x, r0) !== null)
    centre = agencer(familles, lignes, e, r0)!
  } else {
    let rayon = r0
    while (!agencer(familles, lignes, ECHELLE_MIN, rayon)) rayon *= 1.03
    // Le plus petit rayon qui suffit, entre le dernier essai raté et le premier réussi.
    let lo = rayon / 1.03
    for (let i = 0; i < 12; i++) {
      const mid = (lo + rayon) / 2
      if (agencer(familles, lignes, ECHELLE_MIN, mid)) rayon = mid
      else lo = mid
    }
    centre = agencer(familles, lignes, ECHELLE_MIN, rayon)!
    E = new Eventail(racineH, racineF, noeuds, new Reglages({ ...reglages, rayonCentralMin: rayon })).calculer()
  }

  const marge = 35.0
  const ext = etendues(E)
  const W = ext.gauche + ext.droite + 2 * marge
  const cx = marge + ext.gauche
  const cy = marge + ext.haut
  const chiffres: ChiffresDuPied = {
    ancetres: new Set(noeuds.map((n) => n.p.id)).size - 2,
    generations: E.nbNiveaux + 1,
    implexes: new Set(noeuds.map((n) => n.implexe).filter((i) => i)).size,
  }

  // La légende dans le coin haut gauche, le pied dans le coin haut droit, hors du cercle ;
  // sinon, sous le soleil.
  const encombrement = (k: number): [[number, number], [number, number]] => [
    legende(new Scene(1, 1), 0, 0, H, F, A, k),
    pied(new Scene(1, 1), 0, 0, chiffres, mention, date, 'end', k),
  ]
  const tient = (k: number): boolean => {
    const [[hl, wl], [hp, wp]] = encombrement(k)
    for (const [x0, w, h] of [
      [marge, wl, hl],
      [W - marge - wp, wp, hp],
    ] as const) {
      const bas = marge + h + 9 * k * 0.3528
      if ((x0 < cx && cx < x0 + w) || bas >= cy) return false
      const pas = 4.0
      const pts: [number, number][] = []
      for (let i = 0; i < Math.floor(w / pas) + 1; i++) pts.push([x0 + i * pas, bas])
      for (const j of [0, w]) for (let i = 0; i < Math.floor((bas - marge) / pas) + 1; i++) pts.push([x0 + j, marge + i * pas])
      if (!pts.every(([x, y]) => pointLibre(E, cx, cy, x, y, 8.0))) return false
    }
    return true
  }
  let kl: number | null = tient(1.0) ? Math.min(plusGrand(1.0, 3.0, tient), 1.15) : null
  const basSoleil = cy + ext.bas
  const hauteur = kl !== null ? basSoleil + marge : basSoleil + 22 + 52 * Math.max(1.0, W / 1100) + marge

  const scene = new Scene(W, hauteur)
  E.dessiner(scene, cx, cy)
  dessinerLeCentre(scene, centre, cx, cy)
  if (kl !== null) {
    const y0 = marge + 9 * kl * 0.3528
    legende(scene, marge, y0, H, F, A, kl)
    pied(scene, W - marge, y0, chiffres, mention, date, 'end', kl)
  } else {
    kl = Math.max(1.0, W / 1100)
    legende(scene, marge, basSoleil + 22, H, F, A, kl)
    pied(scene, W - marge, basSoleil + 22 + 30 * kl, chiffres, mention, date, 'end', kl)
  }
  return { scene, eventail: E, echelleDescendants: centre.e, echelleLegende: kl, chiffres, centre: [cx, cy] }
}
