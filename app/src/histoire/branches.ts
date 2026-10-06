/**
 * Les branches d'une fiche, pour la TEINTE et pour le libellé : partagés par
 * l'arbre, son volet, sa recherche et la page des résultats.
 *
 * Pur, sans DOM.
 */
import { LIBELLES_QUART } from './lignees'

const BRANCHES_CONNUES = new Set(['pp', 'pm', 'mp', 'mm', 'tr', 'cpp', 'cpm', 'cmp', 'cmm'])

/** La couleur d'une branche, en variable CSS ; une branche inconnue prend la teinte « hors ». */
export const couleurBranche = (b: string): string => `var(--h-branche-${BRANCHES_CONNUES.has(b) ? b : 'hors'})`

const LIBELLES_BRANCHE: Record<string, string> = { ...LIBELLES_QUART, tr: 'Tronc' }

/** « Côté père du père », « Tronc »… ; « Hors branche » pour le reste. */
export const libelleBranche = (b: string): string => LIBELLES_BRANCHE[b] ?? 'Hors branche'

/**
 * Une couleur rendue **en partie transparente dans la peinture même** — l'équivalent
 * HTML de `fill-opacity` / `stroke-opacity` (ADR 0007, règle 2, note du
 * 2026-10-06) : aucune `opacity`, donc aucun contexte d'empilement par fiche.
 * `alpha` va de 0 à 1 ; à 1, la couleur est rendue telle quelle.
 */
export function attenuer(couleur: string, alpha: number): string {
  if (alpha >= 1) return couleur
  const pourcent = Math.round(Math.max(0, alpha) * 1000) / 10
  return `color-mix(in srgb, ${couleur} ${pourcent}%, transparent)`
}
