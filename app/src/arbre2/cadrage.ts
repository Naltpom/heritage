/**
 * Le cadrage de `/arbre` écrit dans le DOM — en fonctions pures (ADR 0007).
 *
 * Pendant un geste, le cadrage change à chaque image et **aucun composant ne se
 * rend** : la vue écrit ces chaînes directement dans le DOM (`useCadrage.ts`).
 *  - les traits et les rectangles des familles, un `<svg>` : son **`viewBox`**
 *    (règle 1), `preserveAspectRatio="none"` pour l'échelle verticale séparée ;
 *  - les boîtes des fiches, du HTML sans `viewBox` : **une** transformation, sur
 *    leur seul conteneur (révision du 2026-10-14 de l'ADR) ;
 *  - les textes, photos et anneaux, en coordonnées écran : rendus par Vue d'après
 *    un instantané du cadrage, et tenus à leur place entre deux rendus par
 *    **une** transformation relative, sur leur conteneur.
 *
 * Si ces trois écritures ne montrent pas exactement le même cadrage, les traits
 * glissent sous les fiches : `cadrage.test.ts` le vérifie.
 *
 * Pur, sans DOM.
 */
import { syDe, type Cadrage, type Canevas } from '@/histoire/vue'

/**
 * Le `viewBox` du `<svg>` des traits : la portion du monde que le cadrage montre,
 * échelle verticale séparée comprise — d'où `preserveAspectRatio="none"`. Le
 * découpage a lieu avant la rastérisation : seul le visible est peint.
 */
export function viewBoxDe(v: Cadrage, c: Canevas): string {
  const sy = syDe(v.s, c)
  const l = Math.max(1, c.largeur)
  const h = Math.max(1, c.hauteur)
  return `${-v.tx / v.s} ${-v.ty / sy} ${l / v.s} ${h / sy}`
}

/** La transformation du conteneur des boîtes, posées en coordonnées monde. */
export function transformationMonde(v: Cadrage, c: Canevas): string {
  return `translate(${v.tx}px, ${v.ty}px) scale(${v.s}, ${syDe(v.s, c)})`
}

/**
 * Ce qui amène une couche dessinée en coordonnées ÉCRAN pour le cadrage `depuis`
 * à sa place sous le cadrage `vers` — `none` s'ils sont égaux. Un point écran `X`
 * de `depuis` vient en `kx·X + ex`, avec `kx = s'/s`.
 */
export function transformationRelative(depuis: Cadrage, vers: Cadrage, c: Canevas): string {
  if (depuis === vers) return 'none'
  const kx = vers.s / depuis.s
  const ky = syDe(vers.s, c) / syDe(depuis.s, c)
  const ex = vers.tx - kx * depuis.tx
  const ey = vers.ty - ky * depuis.ty
  if (Math.abs(kx - 1) < 1e-9 && Math.abs(ky - 1) < 1e-9 && Math.abs(ex) < 1e-6 && Math.abs(ey) < 1e-6) return 'none'
  return `matrix(${kx}, 0, 0, ${ky}, ${ex}, ${ey})`
}

/**
 * Le rectangle monde `(x, y, w, h)` est-il à l'écran ? `marge` élargit la fenêtre
 * d'une fraction de sa taille de chaque côté : les textes sont posés un peu
 * au-delà du bord, pour qu'un glisser ne découvre pas des fiches muettes avant le
 * rendu suivant.
 */
export function dansLaFenetre(v: Cadrage, c: Canevas, x: number, y: number, w: number, h: number, marge = 0): boolean {
  const sy = syDe(v.s, c)
  const X = v.tx + x * v.s
  const Y = v.ty + y * sy
  const mx = marge * c.largeur
  const my = marge * c.hauteur
  return X + w * v.s > -mx && X < c.largeur + mx && Y + h * sy > -my && Y < c.hauteur + my
}
