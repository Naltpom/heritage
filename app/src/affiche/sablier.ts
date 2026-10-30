/**
 * Le sablier : l'ascendance du couple au-dessus, sa descendance en dessous — portage de
 * `sablier.py`.
 *
 * Chaque génération est une rangée. Les premières rangées portent des cases couchées ; au-delà,
 * les cases sont debout (texte tourné d'un quart de tour), sinon la rangée la plus peuplée
 * ferait plusieurs mètres. Les sous-arbres sont serrés l'un contre l'autre au plus près de
 * leurs contours (Reingold-Tilford) : une lignée courte laisse sa place à la voisine plus haut.
 *
 * Deux modes : `normal` (trois lignes par case debout : prénoms, NOM, dates) et `dense`
 * (« Prénoms NOM » puis les dates, corps plus petits, écarts resserrés).
 */
import { Bloc, type Ligne } from './boites'
import { ligneVie, type StyleDeDate } from './dates'
import type { Personne } from './donnees'
import { murDansUneCase } from './murs'
import { ENCRE, ENCRE_DOUCE, melange, TEINTES, TRAIT } from './palette'
import { Style } from './polices'
import type { Scene } from './scene'
import type { Anc } from './structure'

export type ModeDeSablier = 'normal' | 'dense'

export class ReglagesSablier {
  /** Les niveaux couchés (0 à `couches` − 1) ; au-delà, debout. */
  couches: number
  corps: number[]
  dates: StyleDeDate[]
  /** 2 : « Prénoms NOM » / dates ; 3 : prénoms / NOM / dates. */
  lignesDebout: 2 | 3
  ecartCouple: number[]
  ecartFamille: number[]
  ecartRangs: number
  margeCase: readonly [number, number]
  largeurMin: number
  percentile = 0.96

  constructor(mode: ModeDeSablier = 'normal') {
    if (mode === 'dense') {
      this.couches = 3
      this.corps = [13, 10, 8.5, 7.2, 6.8, 6.6, 6.4]
      this.dates = ['long', 'abr', 'court', 'court']
      this.lignesDebout = 2
      this.ecartCouple = [6, 2.5, 1.6, 0.9]
      this.ecartFamille = [10, 4.0, 2.4, 1.6]
      this.ecartRangs = 6.0
      this.margeCase = [1.2, 0.8]
      this.largeurMin = 22
    } else {
      this.couches = 4
      this.corps = [16, 12, 10, 9, 8, 7.6, 7.4, 7.2]
      this.dates = ['long', 'long', 'abr', 'abr', 'abr']
      this.lignesDebout = 3
      this.ecartCouple = [8, 4, 2.5, 2.0, 1.2]
      this.ecartFamille = [14, 6, 4, 3.0, 2.2]
      this.ecartRangs = 8.0
      this.margeCase = [1.6, 1.1]
      this.largeurMin = 28
    }
  }

  corpsDe(g: number): number {
    return this.corps[Math.min(g, this.corps.length - 1)]!
  }

  datesDe(g: number): StyleDeDate {
    return this.dates[Math.min(g, this.dates.length - 1)]!
  }

  ecart(g: number, couple: boolean): number {
    const t = couple ? this.ecartCouple : this.ecartFamille
    return t[Math.min(g, t.length - 1)]!
  }
}

function styles(corps: number): [Style, Style, Style] {
  return [new Style('SS', corps, ENCRE, 0, 'Reg'), new Style('SS', corps, ENCRE, 0.045, 'Semi'), new Style('SS', corps * 0.86, ENCRE_DOUCE, 0, 'Ital')]
}

/** Le contour d'un sous-arbre : `niveau → [gauche, droite]`, l'enfant étant en x = 0. */
type Contour = Map<number, [number, number]>

export class Sablier {
  readonly nbNiveaux: number
  readonly hauteurRang = new Map<number, number>()
  readonly basRang = new Map<number, number>()
  gauche = 0
  droite = 0
  haut = 0
  /** Le facteur appliqué aux corps des rangées couchées par `calculer()`. */
  grossissement = 1

  constructor(
    readonly h: Anc,
    readonly f: Anc,
    readonly noeuds: Anc[],
    readonly R: ReglagesSablier = new ReglagesSablier(),
  ) {
    this.nbNiveaux = Math.max(...noeuds.map((n) => n.niveau)) + 1
  }

  debout(g: number): boolean {
    return g >= this.R.couches
  }

  /* ---------------------------------------------------------------- cases */

  private cases(): void {
    const R = this.R
    const [mx, my] = R.margeCase
    this.hauteurRang.clear()
    const parNiveau = new Map<number, Anc[]>()
    for (const n of this.noeuds) parNiveau.set(n.niveau, [...(parNiveau.get(n.niveau) ?? []), n])
    for (const [g, ns] of parNiveau) {
      const st = styles(R.corpsDe(g))
      const longueurs: number[] = []
      for (const n of ns) {
        const p = n.p
        const nom = p.nom + (n.implexe ? ` ◆${n.implexe}` : '') + (p.probable ? ' ?' : '')
        const vie = ligneVie(p, R.datesDe(g))
        const lignes: Ligne[] =
          this.debout(g) && R.lignesDebout === 2
            ? [
                [`${p.prenoms} ${nom}`.trim(), new Style('SS', st[0].taille, ENCRE, 0, 'Semi')],
                [vie, st[2]],
              ]
            : [
                [p.prenoms, st[0]],
                [nom, st[1]],
                [vie, st[2]],
              ]
        n.bloc = new Bloc(lignes, mx, my)
        longueurs.push(n.bloc.largeurTexte + 2 * mx)
      }
      if (this.debout(g)) {
        longueurs.sort((a, b) => a - b)
        const long = longueurs[Math.min(longueurs.length - 1, Math.floor(R.percentile * longueurs.length))]!
        for (const n of ns) {
          n.w = n.bloc!.hauteurTexte + 2 * my
          n.h = long
        }
        this.hauteurRang.set(g, long)
      } else {
        const h = Math.max(...ns.map((n) => n.bloc!.hauteurTexte)) + 2 * my
        for (const n of ns) {
          n.w = Math.max(n.bloc!.largeurTexte + 2 * mx, (R.largeurMin * R.corpsDe(g)) / R.corpsDe(1))
          n.h = h
        }
        this.hauteurRang.set(g, h)
      }
    }
  }

  /* ---------------------------------------------------------------- disposition */

  /** Le contour du sous-arbre de `n` ; pose au passage le décalage de chaque parent. */
  private contour(n: Anc): Contour {
    const cont: Contour = new Map([[n.niveau, [-n.w / 2, n.w / 2]]])
    const ps = n.parents
    if (ps.length === 0) return cont
    const cs = ps.map((p) => this.contour(p))
    const offs = ps.length === 2 ? this.separer(cs[0]!, cs[1]!, n.niveau + 1) : [0.0]
    ps.forEach((p, i) => {
      const o = offs[i]!
      p.x = o // décalage par rapport à l'enfant, rendu absolu plus tard
      for (const [L, [l, r]] of cs[i]!) {
        const actuel = cont.get(L)
        cont.set(L, actuel ? [Math.min(actuel[0], l + o), Math.max(actuel[1], r + o)] : [l + o, r + o])
      }
    })
    return cont
  }

  private separer(cg: Contour, cd: Contour, niveauParents: number): [number, number] {
    let s = 0.0
    for (const [L, [, droiteG]] of cg) {
      const d = cd.get(L)
      if (d) s = Math.max(s, droiteG - d[0] + this.R.ecart(L, L === niveauParents))
    }
    return [-s / 2, s / 2]
  }

  get largeur(): number {
    return this.droite - this.gauche
  }

  /**
   * Dispose l'arbre. `optimiser` : couche autant de rangées et grossit autant les corps des
   * rangées couchées que possible sans élargir l'affiche de plus de 3 %.
   */
  calculer(optimiser = true): this {
    if (!optimiser) return this.disposer()
    const R = this.R
    const corps0 = [...R.corps]
    R.couches = 1
    let largeurRef = this.disposer().largeur
    let meilleur = 1
    for (let c = 2; c < this.nbNiveaux; c++) {
      R.couches = c
      if (this.disposer().largeur <= largeurRef * 1.03) meilleur = c
    }
    R.couches = meilleur
    largeurRef = Math.max(largeurRef, this.disposer().largeur)
    let lo = 1.0
    let hi = 2.2
    for (let i = 0; i < 14; i++) {
      const mid = (lo + hi) / 2
      R.corps = corps0.map((c, g) => c * (g < meilleur ? mid : 1.0))
      if (this.disposer().largeur <= largeurRef * 1.03) lo = mid
      else hi = mid
    }
    R.corps = corps0.map((c, g) => c * (g < meilleur ? lo : 1.0))
    this.grossissement = lo
    return this.disposer()
  }

  private disposer(): this {
    this.cases()
    const ch = this.contour(this.h)
    const cf = this.contour(this.f)
    const [og, od] = this.separer(ch, cf, 0)
    this.h.x = og
    this.f.x = od
    const absolu = (n: Anc, x: number): void => {
      n.x = x
      for (const p of n.parents) absolu(p, x + p.x)
    }
    absolu(this.h, this.h.x)
    absolu(this.f, this.f.x)
    // Le couple se rapproche au centre ; les traits vers ses parents font le chemin.
    const e = this.R.ecart(0, true)
    const milieu = (this.h.x + this.f.x) / 2
    this.h.x = milieu - e / 2 - this.h.w / 2
    this.f.x = milieu + e / 2 + this.f.w / 2
    // Rangées : y = bas de la rangée, en remontant depuis le couple (y négatifs).
    this.basRang.clear()
    let y = 0.0
    for (let g = 0; g < this.nbNiveaux; g++) {
      this.basRang.set(g, y)
      y -= this.hauteurRang.get(g)! + this.R.ecartRangs * (g < this.R.couches ? 1.6 : 1.0)
    }
    for (const n of this.noeuds) n.y = this.basRang.get(n.niveau)! - this.hauteurRang.get(n.niveau)!
    const xs = [...this.noeuds.map((n) => n.x - n.w / 2), ...this.noeuds.map((n) => n.x + n.w / 2)]
    this.gauche = Math.min(...xs)
    this.droite = Math.max(...xs)
    this.haut = Math.min(...this.noeuds.map((n) => n.y))
    return this
  }

  /* ---------------------------------------------------------------- dessin */

  fond(n: Anc): [string, string] {
    const [trait, rgb] = TEINTES[n.quartier ?? (n.sosa === 2 ? 'H' : 'F')]
    if (n.niveau === 0) return [melange(rgb, 1.0), trait]
    let k = 0.95 - (0.5 * (n.niveau - 1)) / Math.max(this.nbNiveaux - 2, 1)
    if (n.p.probable) k *= 0.4
    return [melange(rgb, k), trait]
  }

  /**
   * `ox`, `oy` : le milieu du couple (bas de ses cases). `murs` : dit si une personne porte un
   * mur (`aUnMur()`) ; sans lui, aucune icône. Le mur se pose dans le coin haut droit de la case,
   * côté des parents, après son texte, sans rien déplacer.
   */
  dessiner(scene: Scene, ox: number, oy: number, murs?: (p: Personne) => boolean): void {
    const R = this.R
    // Les traits d'abord, les cases par-dessus.
    for (const n of this.noeuds) {
      const ps = n.parents
      if (ps.length === 0) continue
      const hautEnfant = oy + n.y
      const yBarre = hautEnfant - R.ecartRangs * (n.niveau < R.couches ? 0.8 : 0.5)
      const xe = ox + n.x
      const ptsParents = ps.map((p): [number, number] => [ox + p.x, oy + p.y + p.h])
      const ep = n.niveau < R.couches ? 0.35 : 0.25
      if (ps.length === 2) {
        const [[xp, yp], [xm, ym]] = ptsParents as [[number, number], [number, number]]
        scene.liaison([[xp, yp], [xp, yBarre], [xm, yBarre], [xm, ym]], TRAIT, ep)
        scene.liaison(
          xp <= xe && xe <= xm
            ? [
                [xe, yBarre],
                [xe, hautEnfant],
              ]
            : [
                [(xp + xm) / 2, yBarre],
                [(xp + xm) / 2, (yBarre + hautEnfant) / 2],
                [xe, (yBarre + hautEnfant) / 2],
                [xe, hautEnfant],
              ],
          TRAIT,
          ep,
        )
      } else {
        const [xp, yp] = ptsParents[0]!
        scene.liaison([[xp, yp], [xp, yBarre], [xe, yBarre], [xe, hautEnfant]], TRAIT, ep)
      }
    }
    for (const n of this.noeuds) {
      const [fond] = this.fond(n)
      const x0 = ox + n.x - n.w / 2
      const y0 = oy + n.y
      scene.rectangle(x0, y0, n.w, n.h, fond, null, 0, this.debout(n.niveau) ? 0.9 : 1.4)
      if (this.debout(n.niveau)) n.bloc!.dessiner(scene, x0 + n.w / 2, y0 + n.h / 2, n.h - 2 * n.bloc!.mx, -90)
      else n.bloc!.dessiner(scene, x0 + n.w / 2, y0 + n.h / 2, n.w - 2 * n.bloc!.mx)
      if (murs?.(n.p)) {
        // Le nom tel qu'il est dessiné : tourné d'un quart de tour dans une case debout.
        const b = n.bloc!
        const texte: [number, number] = this.debout(n.niveau) ? [b.hauteurTexte, Math.min(b.largeurTexte, n.h - 2 * b.mx)] : [Math.min(b.largeurTexte, n.w - 2 * b.mx), b.hauteurTexte]
        murDansUneCase(scene, x0, y0, n.w, n.h, 1, texte)
      }
    }
  }

  /** L'année de naissance médiane de chaque rangée — sur les dates affichées. */
  anneesParRang(): Map<number, number | null> {
    const sortie = new Map<number, number | null>()
    for (let g = 0; g < this.nbNiveaux; g++) {
      const ans = this.noeuds
        .filter((n) => n.niveau === g)
        .map((n) => n.p.annee)
        .filter((a): a is number => Boolean(a))
        .sort((a, b) => a - b)
      sortie.set(g, ans.length > 0 ? ans[Math.floor(ans.length / 2)]! : null)
    }
    return sortie
  }
}
