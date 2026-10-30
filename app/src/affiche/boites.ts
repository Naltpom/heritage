/**
 * Les boîtes de texte, et l'arbre des descendants — portage de `boites.py`.
 *
 * Deux temps, comme dans l'original : **mesurer** (la largeur de chaque sous-arbre), puis
 * **placer** (la position de chaque boîte, centrée sur ses enfants). Le dessin, lui, ne
 * fait que poser dans la scène ce que le placement a décidé.
 */
import { ligneVie, type StyleDeDate } from './dates'
import type { Personne } from './donnees'
import { couleur, ENCRE, ENCRE_DOUCE, FOND_CONJOINT, FOND_DESC, TRAIT } from './palette'
import { Style } from './polices'
import { murDansUneCase } from './murs'
import { f3, type Scene } from './scene'
import { lienDUnion, type Desc, type InfoUnion, type StatutDUnion } from './structure'

export type Ligne = readonly [string, Style]

/** Coupe des prénoms en deux lignes de largeurs voisines. */
export function coupeEquilibree(texte: string, style: Style): string[] {
  const mots = texte.split(' ')
  if (mots.length < 2) return [texte]
  let meilleur: [number, string[]] | null = null
  for (let i = 1; i < mots.length; i++) {
    const a = mots.slice(0, i).join(' ')
    const b = mots.slice(i).join(' ')
    const w = Math.max(style.largeur(a), style.largeur(b))
    if (meilleur === null || w < meilleur[0]) meilleur = [w, [a, b]]
  }
  return meilleur![1]
}

/**
 * `(style, compression)` pour que `t` tienne dans `dispo` mm : on réduit le corps — jusqu'à
 * 84 % —, puis on serre les lettres.
 */
export function ajuster(t: string, s: Style, dispo: number, plancher = 0.84): [Style, number | null] {
  const w = s.largeur(t)
  if (w <= dispo || dispo <= 0) return [s, null]
  const k = dispo / w
  if (k >= plancher) return [s.echelle(k), null]
  return [s.echelle(plancher), dispo / 1.02]
}

/** Des lignes de texte empilées, chacune avec son style. Les lignes vides tombent. */
export class Bloc {
  readonly lignes: Ligne[]

  constructor(
    lignes: readonly Ligne[],
    readonly mx = 1.2,
    readonly my = 0.9,
  ) {
    this.lignes = lignes.filter(([t]) => Boolean(t))
  }

  get largeurTexte(): number {
    return this.lignes.reduce((m, [t, s]) => Math.max(m, s.largeur(t)), 0)
  }

  get hauteurTexte(): number {
    return this.lignes.reduce((h, [, s]) => h + s.interligne, 0)
  }

  dims(vertical = false): [number, number] {
    const w = this.largeurTexte + 2 * this.mx
    const h = this.hauteurTexte + 2 * this.my
    return vertical ? [h, w] : [w, h]
  }

  /** La variante où la première ligne (les prénoms) passe sur deux lignes ; `null` si un seul mot. */
  coupePrenoms(): Bloc | null {
    const [t, s] = this.lignes[0]!
    const morceaux = coupeEquilibree(t, s)
    if (morceaux.length === 1) return null
    return new Bloc([...morceaux.map((m): Ligne => [m, s]), ...this.lignes.slice(1)], this.mx, this.my)
  }

  /** Les lignes centrées sur (cx, cy) ; rotation en degrés (−90 : se lit de bas en haut). */
  dessiner(scene: Scene, cx: number, cy: number, largeurDispo: number, rotation = 0): void {
    let y = -this.hauteurTexte / 2
    const tr = `translate(${f3(cx)},${f3(cy)})` + (rotation ? ` rotate(${f3(rotation)})` : '')
    for (const [t, s] of this.lignes) {
      const [st, comp] = ajuster(t, s, largeurDispo)
      const base = y + s.interligne * 0.78
      scene.texte(0, base + (s.interligne - st.interligne) * 0.4, t, st, 'middle', tr, comp)
      y += s.interligne
    }
  }
}

/** Prénoms / NOM / dates. `styles` = (prénoms, nom, dates). */
export function blocPersonne(p: Personne, styles: readonly [Style, Style, Style], dates: StyleDeDate = 'long', implexe: number | null = null): Bloc {
  const nom = p.nom + (implexe ? ` ◆${implexe}` : '')
  return new Bloc([
    [p.prenoms, styles[0]],
    [nom, styles[1]],
    [ligneVie(p, dates), styles[2]],
  ])
}

/** Les trois styles d'une boîte à partir d'un corps de base (pt). */
export function stylesNiveau(corps: number, couleurVie = ENCRE_DOUCE): [Style, Style, Style] {
  return [new Style('SS', corps, ENCRE, 0, 'Reg'), new Style('SS', corps * 1.0, ENCRE, 0.04, 'Semi'), new Style('SS', corps * 0.86, couleurVie, 0, 'Ital')]
}

/* ------------------------------------------------------------------ */
/* Les descendants                                                     */
/* ------------------------------------------------------------------ */

export class ReglesDesc {
  constructor(
    readonly corps: readonly number[] = [11, 9.5, 8.5, 8, 8],
    readonly dates: StyleDeDate = 'long',
    readonly ecartX = 3.0,
    readonly ecartY = 9.0,
    readonly ecartConjoint = 5.5,
    readonly largeurMin = 30.0,
  ) {}

  corpsDe(niveau: number): number {
    return this.corps[Math.min(niveau - 1, this.corps.length - 1)]!
  }

  styles(niveau: number): [Style, Style, Style] {
    return stylesNiveau(this.corpsDe(niveau))
  }
}

/**
 * Le lien d'union entre deux boîtes : ∞ (mariage connu) ou & (union sans mariage connu),
 * barré si le couple a divorcé ; au-dessus, l'issue en toutes lettres (div., veuf, veuve) ;
 * à droite, le rang de l'union quand la personne en a plusieurs. Sans rang, trois conjoints
 * alignés à droite d'une personne se lisaient comme une chaîne de mariages entre eux.
 *
 * Pas de « sép. » : un divorce suppose un mariage, et la base ne sait pas représenter la
 * séparation d'un couple non marié (règle du commanditaire, 22/10/2026).
 */
export const ISSUES: Record<Exclude<StatutDUnion, null>, string> = { div: 'div.', veuf: 'veuf', veuve: 'veuve' }

/** (signe, issue, rang). */
export function stylesUnion(d: Desc, regles: ReglesDesc): [Style, Style, Style] {
  const corps = regles.corpsDe(d.niveau)
  return [new Style('SS', corps * 0.95, TRAIT, 0, 'Reg'), new Style('SS', corps * 0.74, ENCRE, 0, 'Ital'), new Style('SS', corps * 0.66, ENCRE, 0, 'Semi')]
}

/** Le blanc entre la boîte précédente et celle du conjoint : assez pour le signe, le rang, l'issue. */
export function ecartUnion(d: Desc, info: InfoUnion, regles: ReglesDesc): number {
  const [s, si, sr] = stylesUnion(d, regles)
  let w = s.largeur('∞')
  if (info.total > 1) w += 2 * (0.4 + sr.largeur(String(info.rang)))
  if (info.statut) w = Math.max(w, si.largeur(ISSUES[info.statut]))
  return Math.max(regles.ecartConjoint, w + 1.6)
}

function blocDesc(d: Desc, regles: ReglesDesc): void {
  const b = blocPersonne(d.p, regles.styles(d.niveau), regles.dates)
  const [w, h] = b.dims()
  d.bloc = b
  d.w = Math.max(w, regles.largeurMin)
  d.h = h
}

/** La largeur de chaque sous-arbre ; rend (largeur totale, hauteur de boîte par niveau). */
export function mesurerDesc(enfants: Desc[], regles: ReglesDesc): [number, Map<number, number>] {
  const hauteurs = new Map<number, number>()

  const mesurer = (d: Desc): number => {
    blocDesc(d, regles)
    hauteurs.set(d.niveau, Math.max(hauteurs.get(d.niveau) ?? 0, d.h))
    let unite = d.w
    let largeurEnfants = 0
    for (const [conj, info, enf] of d.unions) {
      if (conj) {
        blocDesc(conj, regles)
        hauteurs.set(d.niveau, Math.max(hauteurs.get(d.niveau)!, conj.h))
        unite += ecartUnion(d, info, regles) + conj.w
      }
      if (enf.length > 0) largeurEnfants += enf.reduce((s, e) => s + mesurer(e), 0) + regles.ecartX * (enf.length - 1)
    }
    const nb = d.unions.filter(([, , e]) => e.length > 0).length
    largeurEnfants += regles.ecartX * 2 * Math.max(nb - 1, 0)
    d.largeurSousArbre = Math.max(unite, largeurEnfants)
    return d.largeurSousArbre
  }

  const total = enfants.reduce((s, e) => s + mesurer(e), 0) + regles.ecartX * 2 * Math.max(enfants.length - 1, 0)
  return [total, hauteurs]
}

/** Pose les boîtes : `x0` = bord gauche du bloc, `y0` = haut de la rangée des enfants. Rend le bas. */
export function placerDesc(enfants: Desc[], x0: number, y0: number, hauteurs: Map<number, number>, regles: ReglesDesc): number {
  const ys = new Map<number, number>()
  let y = y0
  for (const niv of [...hauteurs.keys()].sort((a, b) => a - b)) {
    ys.set(niv, y)
    y += hauteurs.get(niv)! + regles.ecartY
  }
  const bas = y - regles.ecartY

  const placer = (d: Desc, gauche: number): void => {
    const unite = d.w + d.unions.reduce((s, [c, i]) => (c ? s + ecartUnion(d, i, regles) + c.w : s), 0)
    const avecEnfants = d.unions.filter(([, , e]) => e.length > 0)
    const largeurEnfants =
      avecEnfants.reduce((s, [, , enf]) => s + enf.reduce((t, e) => t + e.largeurSousArbre, 0) + regles.ecartX * (enf.length - 1), 0) +
      regles.ecartX * 2 * Math.max(avecEnfants.length - 1, 0)
    const centre = gauche + d.largeurSousArbre / 2
    let x = centre - unite / 2
    d.x = x
    d.y = ys.get(d.niveau)!
    x += d.w
    for (const [c, i] of d.unions) {
      if (c) {
        x += ecartUnion(d, i, regles)
        c.x = x
        c.y = ys.get(d.niveau)!
        x += c.w
      }
    }
    let g = centre - largeurEnfants / 2
    for (const [, , enf] of d.unions) {
      for (const e of enf) {
        placer(e, g)
        g += e.largeurSousArbre + regles.ecartX
      }
      if (enf.length > 0) g += regles.ecartX
    }
  }

  let g = x0
  for (const e of enfants) {
    placer(e, g)
    g += e.largeurSousArbre + regles.ecartX * 2
  }
  return bas
}

/**
 * Retourne le bloc posé par `placerDesc()` autour de l'axe horizontal `somme / 2` : les enfants
 * passent en bas, contre le couple, et les petits-enfants au-dessus — pour l'éventail qui
 * descend. Chaque boîte garde sa taille ; les rangées s'alignent alors par le bas.
 */
export function inverserDesc(enfants: Desc[], somme: number): void {
  const inverser = (d: Desc): void => {
    d.y = somme - (d.y + d.h)
    for (const [c, , enf] of d.unions) {
      if (c) c.y = somme - (c.y + c.h)
      enf.forEach(inverser)
    }
  }
  enfants.forEach(inverser)
}

/**
 * Le peigne d'une fratrie : de `origine`, un trait jusqu'à la barre, puis une dent jusqu'au
 * haut de chaque boîte (au bas, quand le bloc a été retourné). Les boîtes d'une rangée ont le
 * même haut. Rend l'ordonnée de la barre.
 */
export function peigne(scene: Scene, origine: readonly [number, number], enf: Desc[], regles: ReglesDesc, inverse = false): number | null {
  if (enf.length === 0) return null
  const [ox, oy] = origine
  // Retournées, les boîtes d'une rangée s'alignent par le bas : le trait les prend par là.
  const bord = (e: Desc): number => (inverse ? e.y + e.h : e.y)
  const yBarre = inverse ? bord(enf[0]!) + regles.ecartY * 0.45 : bord(enf[0]!) - regles.ecartY * 0.45
  for (const e of enf) {
    const ex = e.x + e.w / 2
    scene.liaison([
      [ox, oy],
      [ox, yBarre],
      [ex, yBarre],
      [ex, bord(e)],
    ])
  }
  return yBarre
}

/**
 * `depart` = (x, y) du point d'où descend le trait vers les enfants du couple — d'où il monte,
 * quand le bloc a été retourné (`inverse`, voir {@link inverserDesc}) ; `null` : pas de trait,
 * l'appelant tire les siens.
 *
 * `murs` : dit si une personne porte un mur (`aUnMur()`) ; sans lui, aucune icône. Les
 * descendants n'en ont jamais : l'appelant ne le passe que pour le couple des quatre parents.
 */
export function dessinerDesc(scene: Scene, enfants: Desc[], depart: readonly [number, number] | null, regles: ReglesDesc, inverse = false, murs?: (p: Personne) => boolean): void {
  const boite = (d: Desc, fond: string): void => {
    scene.rectangle(d.x, d.y, d.w, d.h, fond, TRAIT, 0.25)
    const b = d.bloc!
    b.dessiner(scene, d.x + d.w / 2, d.y + d.h / 2, d.w - 2 * b.mx)
    if (murs?.(d.p)) murDansUneCase(scene, d.x, d.y, d.w, d.h, 1, [Math.min(b.largeurTexte, d.w - 2 * b.mx), b.hauteurTexte])
  }

  const dessiner = (d: Desc): void => {
    boite(d, couleur(FOND_DESC))
    for (const [c, info, enf] of d.unions) {
      let origine: readonly [number, number]
      if (c) {
        boite(c, couleur(FOND_CONJOINT))
        const gx = c.x - ecartUnion(d, info, regles) / 2
        const ym = d.y + d.h / 2
        const [st, stI, stR] = stylesUnion(d, regles)
        const lien = lienDUnion(info)
        const signe = lien.signe
        scene.texte(gx, ym + st.mm * 0.33, signe, st)
        if (lien.barre) {
          const l = st.largeur(signe) * 0.8
          scene.ligneDroite(gx - l / 2, ym + st.mm * 0.22, gx + l / 2, ym - st.mm * 0.22, TRAIT, 0.45)
        }
        if (lien.rang !== null) scene.texte(gx + st.largeur(signe) / 2 + 0.4, ym + st.mm * 0.02, String(lien.rang), stR, 'start')
        // L'issue se lit du côté opposé au trait des enfants : au-dessus du signe, ou en dessous
        // quand le trait monte.
        if (info.statut) scene.texte(gx, inverse ? ym + st.mm * 0.6 + stI.mm * 0.8 : ym - st.mm * 0.36, ISSUES[info.statut], stI)
        origine = inverse ? [gx, ym - st.mm * 0.6] : [gx, ym + st.mm * 0.6]
      } else {
        origine = [d.x + d.w / 2, inverse ? d.y : d.y + d.h]
      }
      peigne(scene, origine, enf, regles, inverse)
      for (const e of enf) dessiner(e)
    }
  }

  if (depart) peigne(scene, depart, enfants, regles, inverse)
  for (const e of enfants) dessiner(e)
}
