/**
 * Les formats d'impression et les résolutions du PNG — ce que la modale propose.
 *
 * Pur. Un format est posé **dans le sens du dessin** : l'éventail est plus large que haut,
 * il prend donc le A0 à l'italienne. L'affiche d'origine recevait le sens en argument
 * (`--format=220x127`) ; ici, personne n'a à y penser.
 */
import type { Format } from './svg'

export type CleDeFormat = 'naturelle' | 'mesure' | 'A3' | 'A2' | 'A1' | 'A0' | '220x127'

export interface FormatPropose {
  cle: CleDeFormat
  libelle: string
  /** Côtés en mm, sans orientation (le petit d'abord) ; `null` : la taille naturelle, ou sur mesure. */
  cotes: readonly [number, number] | null
}

export const FORMATS: readonly FormatPropose[] = [
  { cle: 'naturelle', libelle: 'Taille naturelle', cotes: null },
  { cle: 'mesure', libelle: 'Sur mesure (cm)', cotes: null },
  { cle: 'A3', libelle: 'A3 (29,7 × 42 cm)', cotes: [297, 420] },
  { cle: 'A2', libelle: 'A2 (42 × 59,4 cm)', cotes: [420, 594] },
  { cle: 'A1', libelle: 'A1 (59,4 × 84,1 cm)', cotes: [594, 841] },
  { cle: 'A0', libelle: 'A0 (84,1 × 118,9 cm)', cotes: [841, 1189] },
  { cle: '220x127', libelle: '220 × 127 cm', cotes: [1270, 2200] },
]

/** Le format orienté comme le dessin ; `null` pour la taille naturelle. */
export function formatOriente(cle: CleDeFormat, largeur: number, hauteur: number): Format | null {
  const f = FORMATS.find((x) => x.cle === cle)
  if (!f?.cotes) return null
  const [petit, grand] = f.cotes
  return largeur >= hauteur ? { largeur: grand, hauteur: petit } : { largeur: petit, hauteur: grand }
}

/**
 * Le format sur mesure, en centimètres : la page imprimée, et la boîte du dessin centrée dessus.
 * Un dessin de 120 cm sur une page de 100 perd 10 cm de chaque côté ; un dessin de 100 sur une
 * page de 120 en gagne 10 de papier. C'est le fichier « à l'échelle 1:1 » que demandent les
 * imprimeurs d'affiches sur mesure.
 */
export function formatSurMesure(impression: readonly [number, number], dessin: readonly [number, number]): Format {
  return { largeur: impression[0] * 10, hauteur: impression[1] * 10, dessin: { largeur: dessin[0] * 10, hauteur: dessin[1] * 10 } }
}

/** La page qui sortira : le format choisi, ou le dessin lui-même. */
export function page(cle: CleDeFormat, largeur: number, hauteur: number): Format {
  return formatOriente(cle, largeur, hauteur) ?? { largeur, hauteur }
}

/** Les résolutions proposées pour le PNG, en points par pouce. */
export const RESOLUTIONS = [72, 100, 150, 200, 300] as const

/**
 * Le plus grand canevas qu'on demande au navigateur. Chrome et Firefox acceptent 32 767 px
 * de côté et environ 268 millions de pixels ; Safari beaucoup moins. 120 millions de pixels,
 * c'est 480 Mo en mémoire avant compression : au-delà, on propose plutôt le PDF ou le SVG,
 * qui sont vectoriels et s'agrandissent sans perte.
 */
export const COTE_MAX_PX = 32767
export const PIXELS_MAX = 120_000_000

export function pixels(p: Format, dpi: number): [number, number] {
  return [Math.round((p.largeur / 25.4) * dpi), Math.round((p.hauteur / 25.4) * dpi)]
}

export function pngPossible(p: Format, dpi: number): boolean {
  const [l, h] = pixels(p, dpi)
  return l <= COTE_MAX_PX && h <= COTE_MAX_PX && l * h <= PIXELS_MAX
}

/** « 84,1 × 118,9 cm » */
export function cotesLisibles(p: Format): string {
  const cm = (mm: number): string => (Math.round(mm) / 10).toLocaleString('fr-FR', { maximumFractionDigits: 1 })
  return `${cm(p.largeur)} × ${cm(p.hauteur)} cm`
}
