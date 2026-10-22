/**
 * Les couleurs de l'affiche — celles de l'affiche d'origine (`boites.py`), à l'identique.
 *
 * Elles ne suivent **pas** le thème de l'application : une affiche s'imprime, et le papier
 * ne passe pas en mode sombre. Les noms sont ceux du script d'origine, pour qu'on retrouve
 * une teinte d'un fichier à l'autre sans table de correspondance.
 */

export const ENCRE = '#2A2421'
export const ENCRE_DOUCE = '#5B4F46'
export const TRAIT = '#8E7C6C'
export const PAPIER = '#FBF8F2'

export type Rgb = readonly [number, number, number]

/** Les quatre quartiers de l'ascendance du couple : père et mère de lui (H), d'elle (F). */
export type Quartier = 'HP' | 'HM' | 'FP' | 'FM'

/** (trait, fond le plus soutenu) de chaque quartier ; `H` et `F` pour le couple lui-même. */
export const TEINTES: Record<Quartier | 'H' | 'F', readonly [string, Rgb]> = {
  HP: ['#46698C', [205, 219, 233]], // bleu ardoise
  HM: ['#557A50', [212, 228, 205]], // vert sauge
  FP: ['#A2553B', [240, 214, 202]], // terre cuite
  FM: ['#9C7A2C', [238, 225, 192]], // ocre
  H: ['#6B5A49', [229, 218, 196]],
  F: ['#6B5A49', [229, 218, 196]],
}

export const FOND_DESC: Rgb = [236, 228, 215]
export const FOND_CONJOINT: Rgb = [246, 241, 233]

const P: Rgb = [251, 248, 242]

/**
 * Arrondi « au pair le plus proche », celui de `round()` en Python 3 : `melange()` en dépend,
 * et un `Math.round` (qui arrondit ½ vers le haut) décalerait d'une unité les teintes qui
 * tombent pile entre deux entiers.
 */
export function arrondiPair(x: number): number {
  const bas = Math.floor(x)
  // Égalité exacte, comme Python : 12.4999999 s'arrondit à 12, seul 12.5 va au pair.
  if (x - bas === 0.5) return bas % 2 === 0 ? bas : bas + 1
  return Math.round(x)
}

const hex = (v: number): string => v.toString(16).toUpperCase().padStart(2, '0')

/** Le fond `rgb` éclairci vers le papier : `k = 1` garde la teinte, `k = 0` donne le papier. */
export function melange(rgb: Rgb, k: number): string {
  const [r, g, b] = [0, 1, 2].map((i) => arrondiPair(P[i]! + (rgb[i]! - P[i]!) * k))
  return `#${hex(r!)}${hex(g!)}${hex(b!)}`
}

/** `'#' + ''.join(f'{v:02X}' …)` : une teinte telle quelle. */
export function couleur(rgb: Rgb): string {
  return `#${hex(rgb[0])}${hex(rgb[1])}${hex(rgb[2])}`
}
