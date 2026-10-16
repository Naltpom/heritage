/**
 * La couleur d'une feuille de l'arbre en 3D — pure, testée (`apparence.test.ts`).
 *
 * Les quatre quarts gardent **les teintes de la charte** (Romarin, Bourrache,
 * Chardon, Tilleul — `styles/main.css`), leurs homologues du conjoint les mêmes
 * assourdies, comme sur `/arbre`. Deux écarts, voulus :
 *
 * - **le tronc** est gris sur `/arbre` (`--h-branche-tr`) ; ici il porte la
 *   sauge des feuilles — c'est l'arbre lui-même, et il compte la plupart des
 *   fiches : 431 feuilles grises feraient un arbre mort ;
 * - **un défunt** est une feuille d'automne : sa teinte tirée vers l'ocre. Un
 *   vivant garde sa teinte franche.
 *
 * Une fiche restreinte (vivant hors cercle) est un parchemin sans nom.
 */

import type { Feuille, Teinte } from './disposition'

export const TEINTES: Record<Teinte, number> = {
  pp: 0xd9a066,
  pm: 0x4f9d8c,
  mp: 0x8a7bc8,
  mm: 0x7fa855,
  tr: 0x8fae7e,
  cpp: 0xa28a6f,
  cpm: 0x5e8379,
  cmp: 0x7c7393,
  cmm: 0x768a63,
  hors: 0x76879c,
}

const AUTOMNE = 0xc9955a
const PARCHEMIN = 0xe6d3b0

/** Mélange linéaire de deux couleurs `0xRRGGBB`. */
export function melanger(a: number, b: number, t: number): number {
  const ca = [(a >> 16) & 255, (a >> 8) & 255, a & 255]
  const cb = [(b >> 16) & 255, (b >> 8) & 255, b & 255]
  const c = ca.map((v, i) => Math.round(v + (cb[i]! - v) * t))
  return (c[0]! << 16) | (c[1]! << 8) | c[2]!
}

export function couleurDeFeuille(f: Pick<Feuille, 'teinte' | 'vivant' | 'restreinte'>): number {
  if (f.restreinte) return PARCHEMIN
  const base = TEINTES[f.teinte]
  return f.vivant ? base : melanger(base, AUTOMNE, 0.38)
}

/** La teinte en CSS, pour la pastille du panneau. */
export function cssDeTeinte(t: Teinte): string {
  return `#${TEINTES[t].toString(16).padStart(6, '0')}`
}

export const LIBELLES_TEINTE: Record<Teinte, string> = {
  pp: 'Père du père',
  pm: 'Mère du père',
  mp: 'Père de la mère',
  mm: 'Mère de la mère',
  tr: 'Tronc',
  cpp: 'Conjoint · père du père',
  cpm: 'Conjoint · mère du père',
  cmp: 'Conjoint · père de la mère',
  cmm: 'Conjoint · mère de la mère',
  hors: 'Par alliance ou sans lien',
}
