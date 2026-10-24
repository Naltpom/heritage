/**
 * L'arbre de boîtes des descendants **rangé en bandes qui suivent un cercle** — le disque central
 * du soleil (`soleil.ts`), et le demi-disque sous l'éventail des quatre parents (`fabriquer.ts`).
 *
 * Chaque bande est aussi large que le cercle le permet à sa hauteur, le long d'un tronc qui
 * descend du titre (ou de la base de l'éventail) — au milieu, enfants de part et d'autre, pour
 * le couple ; un tronc par famille pour les quatre parents. Une rangée unique, trop large,
 * laissait le disque vide au-dessus et en dessous (relevé du 24/10/2026 : trente et un
 * descendants à mi-taille dans un disque grossi de moitié).
 */
import { dessinerDesc, inverserDesc, mesurerDesc, peigne, placerDesc, ReglesDesc } from './boites'
import type { Style } from './polices'
import type { Scene } from './scene'
import type { Desc, QuatreParents } from './structure'

/** La part du rayon où le texte peut aller : un peu de marge avant les anneaux. */
const DANS_LE_DISQUE = 0.93
/** Sans titre (sous la base de l'éventail), le haut du bloc : la place des repères d'années. */
const SOUS_LA_BASE = 8

/** Les règles des boîtes à l'échelle `e` (1 : 13 pt pour les enfants). */
export const reglesPour = (e: number): ReglesDesc => new ReglesDesc([13, 11, 9.5, 9].map((c) => c * e), 'long', 3 * e, 11 * e, 5.5 * e, 34 * e)

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
 * Une bande. `cote` dit où sont ses enfants par rapport à son tronc : des deux côtés (`0`, avec
 * un couloir où le tronc passe, ou centrés sous lui s'il s'arrête là), à gauche (`-1`) ou à
 * droite (`1`).
 */
interface Bande {
  enfants: Desc[]
  /** Pour `cote` 0 : combien des enfants vont à gauche du couloir ; `-1` : centrés, sans couloir. */
  coupure: number
  cote: -1 | 0 | 1
  y: number
  hauteurs: Map<number, number>
}

export interface Agencement {
  e: number
  regles: ReglesDesc
  titre: ReturnType<typeof titreEmpile>
  /** Le haut et le bas du bloc, par rapport au centre du cercle. */
  haut: number
  bas: number
  /** D'où partent les troncs, par rapport au centre : sous le titre, ou de la base de l'éventail. */
  depart: number
  /** Le couple au milieu (quatre parents), avec ses descendants. */
  couple: { desc: Desc; y: number; largeur: number; hauteurs: Map<number, number> } | null
  /**
   * Les bandes de chaque tronc, avec l'abscisse du tronc par rapport au centre — et, pour les
   * quatre parents, l'époux que ce tronc prend aussi, dans le couple du milieu.
   */
  troncs: { x: number; bandes: Bande[]; avec: Desc | null }[]
}

/** Les familles : une seule (les enfants du couple), ou celles des quatre parents. */
export type Familles = { seule: Desc[] } | QuatreParents

/**
 * Les familles à l'échelle `e` dans un cercle de rayon `r` (coordonnées relatives à son centre),
 * ou `null` si elles n'y tiennent pas. Avec un titre, on essaie plusieurs hauteurs pour le haut
 * du bloc et l'on garde celle qui le centre le mieux. Sans titre (`lignes` nul), le bloc part du
 * centre et descend : c'est le demi-disque sous la base de l'éventail.
 */
export function agencer(familles: Familles, lignes: ((u: number) => [string, Style, number][]) | null, e: number, r: number): Agencement | null {
  const regles = reglesPour(e)
  const listes = 'seule' in familles ? [familles.seule] : [familles.gauche, familles.droite, [familles.couple]]
  const hauteursGlobales = new Map<number, number>()
  for (const l of listes) for (const [n, h] of mesurerDesc(l, regles)[1]) hauteursGlobales.set(n, Math.max(hauteursGlobales.get(n) ?? 0, h))
  const titre = lignes ? titreEmpile(lignes(Math.max(e, 0.75))) : { lignes: [], bases: [], hauteur: 0 }
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
  const bandesDuTronc = (liste: Desc[], cote: -1 | 0 | 1, y0: number, decalage = couloir / 2): { bandes: Bande[]; bas: number } | null => {
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
          besoin = decalage + regles.ecartX + largeurDe(ds)
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

  const essayer = (haut: number): Omit<Agencement, 'e' | 'regles' | 'titre' | 'haut' | 'depart'> | null => {
    for (const [i, [t, st]] of titre.lignes.entries()) {
      const base = haut + titre.bases[i]!
      if (st.largeur(t) / 2 > demiCorde(base - st.mm * 0.8, base + st.mm * 0.25)) return null
    }
    const y0 = haut + titre.hauteur + (aucun ? 0 : 18 * e)
    if ('seule' in familles) {
      const b = bandesDuTronc(familles.seule, 0, y0)
      return b ? { couple: null, troncs: [{ x: 0, bandes: b.bandes, avec: null }], bas: Math.max(haut + titre.hauteur, b.bas) } : null
    }
    // Le couple au milieu, ses descendants en dessous ; les fratries de part et d'autre, à côté
    // de lui ou, si elles n'y tiennent pas, sous sa rangée — toujours en dehors de ses colonnes.
    const { couple, epouse } = familles
    const largeur = couple.largeurSousArbre
    const pCouple = profondeur(couple)
    const hCouple = hauteurDe(pCouple)
    if (largeur / 2 > demiCorde(y0, y0 + hCouple)) return null
    const decalage = largeur / 2 + regles.ecartX
    const sousLeCouple = y0 + hauteurDe(1) + ecartBandes
    const g = bandesDuTronc(familles.gauche, -1, y0, decalage) ?? bandesDuTronc(familles.gauche, -1, sousLeCouple, decalage)
    const d = bandesDuTronc(familles.droite, 1, y0, decalage) ?? bandesDuTronc(familles.droite, 1, sousLeCouple, decalage)
    if (!g || !d) return null
    return {
      couple: { desc: couple, y: y0, largeur, hauteurs: new Map([...hauteursGlobales].filter(([n]) => n <= pCouple)) },
      troncs: [
        { x: -decalage, bandes: g.bandes, avec: couple },
        { x: decalage, bandes: d.bandes, avec: epouse },
      ],
      bas: Math.max(y0 + hCouple, g.bas, d.bas),
    }
  }

  const hauts = lignes ? Array.from({ length: 17 }, (_, k) => -rr + (k / 16) * rr) : [SOUS_LA_BASE]
  let meilleur: Agencement | null = null
  let ecart = Infinity
  for (const haut of hauts) {
    const essai = essayer(haut)
    if (!essai) continue
    const decentrage = Math.abs(haut + essai.bas)
    const depart = lignes ? haut + titre.hauteur + 3 * e : 0
    if (decentrage < ecart) [meilleur, ecart] = [{ e, regles, titre, haut, depart, ...essai }, decentrage]
  }
  return meilleur
}

/**
 * Pose le bloc agencé sur le centre (cx, cy). `inverse` : retourné autour de la base de
 * l'éventail qui descend — les bandes au-dessus, les troncs qui montent (sans titre).
 */
export function dessinerLesBandes(scene: Scene, a: Agencement, cx: number, cy: number, inverse = false): void {
  const haut = cy + a.haut
  a.titre.lignes.forEach(([t, st], i) => scene.texte(cx, haut + a.titre.bases[i]!, t, st))
  const couloir = 8 * a.e
  const largeurDe = (ds: Desc[]): number => ds.reduce((s, d) => s + d.largeurSousArbre, 0) + a.regles.ecartX * 2 * Math.max(ds.length - 1, 0)
  // D'abord tout poser : les traits des troncs vont chercher les époux dans le couple du milieu.
  if (a.couple) placerDesc([a.couple.desc], cx - a.couple.largeur / 2, cy + a.couple.y, a.couple.hauteurs, a.regles)
  for (const tronc of a.troncs) {
    const xt = cx + tronc.x
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
    }
  }
  if (inverse) inverserDesc([...(a.couple ? [a.couple.desc] : []), ...a.troncs.flatMap((t) => t.bandes.flatMap((b) => b.enfants))], 2 * cy)
  if (a.couple) dessinerDesc(scene, [a.couple.desc], null, a.regles, inverse)
  for (const tronc of a.troncs) {
    const xt = cx + tronc.x
    let depart: [number, number] = [xt, inverse ? cy - a.depart : cy + a.depart]
    // L'époux du couple d'abord : sa barre est celle de la première bande, quand elle est à côté.
    const barre = tronc.avec ? peigne(scene, depart, [tronc.avec], a.regles, inverse) : null
    if (barre !== null) depart = [xt, barre]
    for (const b of tronc.bandes) {
      const y = peigne(scene, depart, b.enfants, a.regles, inverse)
      dessinerDesc(scene, b.enfants, null, a.regles, inverse)
      // Le tronc repart de la barre de cette bande.
      if (y !== null) depart = [xt, y]
    }
  }
}

/** Le plus grand `x` de [lo, hi] pour lequel `ok(x)`, `ok` étant supposé vrai en `lo`. */
export function plusGrand(lo: number, hi: number, ok: (x: number) => boolean): number {
  if (ok(hi)) return hi
  for (let i = 0; i < 18; i++) {
    const mid = (lo + hi) / 2
    if (ok(mid)) lo = mid
    else hi = mid
  }
  return lo
}

/**
 * Le plus petit rayon, à partir de `r0`, où les familles tiennent à l'échelle `e` — le cercle
 * grandit quand les descendants, au plus petit, n'y tiennent pas.
 */
export function rayonPour(familles: Familles, lignes: ((u: number) => [string, Style, number][]) | null, e: number, r0: number): number {
  let rayon = r0
  while (!agencer(familles, lignes, e, rayon)) rayon *= 1.03
  if (rayon === r0) return r0
  // Le plus petit rayon qui suffit, entre le dernier essai raté et le premier réussi.
  let lo = rayon / 1.03
  for (let i = 0; i < 12; i++) {
    const mid = (lo + rayon) / 2
    if (agencer(familles, lignes, e, mid)) rayon = mid
    else lo = mid
  }
  return rayon
}
