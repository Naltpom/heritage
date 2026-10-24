/**
 * La **scène** d'une affiche : la liste de ce qu'il faut dessiner, en millimètres, sans DOM.
 *
 * L'affiche d'origine écrivait son SVG au fil du placement (`Svg.texte`, `Svg.brut`). On
 * sépare ici les deux temps : le placement (`eventail.ts`, `boites.ts`, `fabriquer.ts`)
 * remplit une scène de primitives — des chemins, des rectangles, des lignes de texte —, et
 * le rendu (`svg.ts`) la sérialise. La scène se teste sans navigateur : on y lit chaque
 * texte, sa position, son corps et sa police, ce qui suffit à vérifier qu'un nom tient
 * dans sa case ou qu'une date de vivant n'est pas écrite.
 *
 * Les méthodes gardent les noms et la sémantique de la classe `Svg` d'origine (ancre
 * `middle` par défaut, `compression` qui force la longueur d'un texte), pour que le
 * portage se relise ligne à ligne.
 */
import { PAPIER, TRAIT } from './palette'
import type { Style } from './polices'

export type Ancre = 'start' | 'middle' | 'end'

export type Primitive =
  | {
      type: 'chemin'
      d: string
      fond: string
      trait?: string
      epaisseur?: number
      /** Un trait de liaison : bouts et angles arrondis. */
      arrondi?: boolean
    }
  | { type: 'rect'; x: number; y: number; l: number; h: number; rayon: number; fond: string; trait?: string; epaisseur?: number }
  | { type: 'ligne'; x1: number; y1: number; x2: number; y2: number; trait: string; epaisseur: number }
  | {
      type: 'texte'
      x: number
      y: number
      texte: string
      style: Style
      ancre: Ancre
      transform?: string
      /** Longueur imposée (mm) : le texte est serré pour y tenir — avec parcimonie. */
      compression?: number
    }
  | { type: 'texteSurArc'; cx: number; cy: number; r: number; a1: number; a2: number; texte: string; style: Style }

export class Scene {
  readonly elements: Primitive[] = []

  constructor(
    readonly largeur: number,
    readonly hauteur: number,
    readonly fond: string = PAPIER,
  ) {}

  /** Une ligne de texte. Vide, elle n'est pas posée. */
  texte(x: number, y: number, t: string, style: Style, ancre: Ancre = 'middle', transform?: string, compression?: number | null): void {
    if (!t) return
    this.elements.push({
      type: 'texte',
      x,
      y,
      texte: t,
      style,
      ancre,
      ...(transform ? { transform } : {}),
      ...(compression ? { compression } : {}),
    })
  }

  /**
   * Un texte centré sur un arc de rayon `r`, en degrés : de `a2` (gauche) à `a1` (droite) au-dessus
   * du centre, de `a1` (gauche) à `a2` (droite) en dessous.
   */
  texteSurArc(cx: number, cy: number, r: number, a1: number, a2: number, t: string, style: Style): void {
    if (!t) return
    this.elements.push({ type: 'texteSurArc', cx, cy, r, a1, a2, texte: t, style })
  }

  chemin(d: string, fond: string, trait?: string, epaisseur?: number, arrondi = false): void {
    this.elements.push({
      type: 'chemin',
      d,
      fond,
      ...(trait !== undefined ? { trait } : {}),
      ...(epaisseur !== undefined ? { epaisseur } : {}),
      ...(arrondi ? { arrondi } : {}),
    })
  }

  /** `rectangle()` de `boites.py` : coins arrondis, trait facultatif. */
  rectangle(x: number, y: number, l: number, h: number, fond: string, trait: string | null = null, epaisseur = 0.25, rayon = 1.2): void {
    this.elements.push({ type: 'rect', x, y, l, h, rayon, fond, ...(trait ? { trait, epaisseur } : {}) })
  }

  ligneDroite(x1: number, y1: number, x2: number, y2: number, trait: string, epaisseur: number): void {
    this.elements.push({ type: 'ligne', x1, y1, x2, y2, trait, epaisseur })
  }

  /** `ligne()` de `boites.py` : un trait orthogonal à coins arrondis. */
  liaison(points: readonly (readonly [number, number])[], couleur = TRAIT, epaisseur = 0.3, rayon = 1.5): void {
    const pts = points.filter(
      (p, i) => i === 0 || Math.abs(p[0] - points[i - 1]![0]) > 1e-3 || Math.abs(p[1] - points[i - 1]![1]) > 1e-3,
    )
    if (pts.length < 2) return
    const d = [`M${f3(pts[0]![0])},${f3(pts[0]![1])}`]
    for (let i = 1; i < pts.length - 1; i++) {
      const [xa, ya] = pts[i - 1]!
      const [xb, yb] = pts[i]!
      const [xc, yc] = pts[i + 1]!
      const la = Math.hypot(xb - xa, yb - ya)
      const lc = Math.hypot(xc - xb, yc - yb)
      const r = Math.min(rayon, la / 2, lc / 2)
      const [ux, uy] = [(xa - xb) / la, (ya - yb) / la]
      const [vx, vy] = [(xc - xb) / lc, (yc - yb) / lc]
      d.push(`L${f3(xb + ux * r)},${f3(yb + uy * r)}`)
      d.push(`Q${f3(xb)},${f3(yb)} ${f3(xb + vx * r)},${f3(yb + vy * r)}`)
    }
    d.push(`L${f3(pts[pts.length - 1]![0])},${f3(pts[pts.length - 1]![1])}`)
    this.chemin(d.join(' '), 'none', couleur, epaisseur, true)
  }

  /** Les lignes de texte posées — pour les tests, et pour la liste des polices à embarquer. */
  get textes(): Extract<Primitive, { type: 'texte' | 'texteSurArc' }>[] {
    return this.elements.filter((e): e is Extract<Primitive, { type: 'texte' | 'texteSurArc' }> => e.type === 'texte' || e.type === 'texteSurArc')
  }

  /** Les polices dont la scène se sert. */
  get polices(): Set<string> {
    return new Set(this.textes.map((t) => t.style.cle))
  }
}

/** `f'{x:.3f}'` — trois décimales, l'arrondi de Python au millième de millimètre. */
export function f3(x: number): string {
  return x.toFixed(3)
}

/** (x, y) d'un point à l'angle `a` (degrés, sens trigonométrique, y vers le bas). */
export function polaire(cx: number, cy: number, r: number, a: number): [number, number] {
  const t = a * (Math.PI / 180) // `math.radians()` : une seule multiplication, comme CPython
  return [cx + r * Math.cos(t), cy - r * Math.sin(t)]
}

/** Le chemin d'un secteur d'anneau entre `r1 < r2` et `a1 < a2` (degrés, sens trigonométrique). */
export function secteur(cx: number, cy: number, r1: number, r2: number, a1: number, a2: number): string {
  // Un tour entier (le disque du soleil) : deux demi-cercles, un arc seul ayant ses deux bouts confondus.
  if (a2 - a1 >= 360 - 1e-9) {
    const cercle = (r: number, sens: 0 | 1): string =>
      `M${f3(cx - r)},${f3(cy)} A${f3(r)},${f3(r)} 0 1 ${sens} ${f3(cx + r)},${f3(cy)} A${f3(r)},${f3(r)} 0 1 ${sens} ${f3(cx - r)},${f3(cy)} Z`
    return r1 <= 0.01 ? cercle(r2, 1) : `${cercle(r2, 1)} ${cercle(r1, 0)}`
  }
  const grand = a2 - a1 > 180 ? 1 : 0
  const p1 = polaire(cx, cy, r2, a2)
  const p2 = polaire(cx, cy, r2, a1)
  const p3 = polaire(cx, cy, r1, a1)
  const p4 = polaire(cx, cy, r1, a2)
  if (r1 <= 0.01) {
    return `M${f3(cx)},${f3(cy)} L${f3(p1[0])},${f3(p1[1])} A${f3(r2)},${f3(r2)} 0 ${grand} 1 ${f3(p2[0])},${f3(p2[1])} Z`
  }
  return (
    `M${f3(p1[0])},${f3(p1[1])} A${f3(r2)},${f3(r2)} 0 ${grand} 1 ${f3(p2[0])},${f3(p2[1])} ` +
    `L${f3(p3[0])},${f3(p3[1])} A${f3(r1)},${f3(r1)} 0 ${grand} 0 ${f3(p4[0])},${f3(p4[1])} Z`
  )
}
