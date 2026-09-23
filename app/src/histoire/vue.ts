/**
 * Le cadrage de `/histoire` : niveaux de détail, échelle anisotrope, zoom
 * autour d'un point et vol animé.
 *
 * **Plus on dézoome, moins on écrit.** Quatre niveaux, choisis sur l'échelle
 * horizontale `s` (1 = taille réelle d'une fiche) :
 *
 * | Niveau    | `s`         | Ce qu'on dessine                          |
 * |-----------|-------------|-------------------------------------------|
 * | Ensemble  | < 0,16      | rectangles de couleur, lignée directe     |
 * | Familles  | 0,16 – 0,42 | fiches, noms de foyers                    |
 * | Prénoms   | 0,42 – 0,75 | prénom seul                               |
 * | Fiches    | ≥ 0,75      | nom dessiné et ligne de dates             |
 *
 * **L'échelle verticale est séparée.** Cinq cents personnes sur sept
 * générations font un arbre trente fois plus large que haut : à l'échelle
 * « tout voir », il tiendrait en 40 px de haut. `sy(s)` garde les rangées
 * lisibles au dézoom, et redevient `s` dès que `s` atteint `fitY`.
 *
 * Pur, sans DOM.
 */

export type Niveau = 0 | 1 | 2 | 3

export const SEUILS = [0.16, 0.42, 0.75] as const

export interface Palier {
  libelle: string
  /** `null` : ajusté à la largeur. */
  echelle: number | null
}

export const PALIERS: readonly Palier[] = [
  { libelle: 'Ensemble', echelle: null },
  { libelle: 'Familles', echelle: 0.26 },
  { libelle: 'Prénoms', echelle: 0.55 },
  { libelle: 'Fiches', echelle: 1 },
]

export function niveau(s: number): Niveau {
  if (s < SEUILS[0]) return 0
  if (s < SEUILS[1]) return 1
  if (s < SEUILS[2]) return 2
  return 3
}

/** `sy(s) = max(s, min(fitY, 30·s))`, avec `fitY = min(1, (hauteurCanevas − 90) / hauteurMonde)`. */
export function echelleVerticale(s: number, hauteurCanevas: number, hauteurMonde: number): number {
  const fitY = Math.min(1, Math.max(0, hauteurCanevas - 90) / Math.max(1, hauteurMonde))
  return Math.max(s, Math.min(fitY, 30 * s))
}

export const ECHELLE_MAX = 1.8

export function echelleMin(largeurCanevas: number, largeurMonde: number): number {
  return Math.min(0.9, (0.8 * Math.max(1, largeurCanevas)) / Math.max(1, largeurMonde))
}

export interface Cadrage {
  /** Échelle horizontale. */
  s: number
  /** Translation, en pixels écran. */
  tx: number
  ty: number
}

export interface Canevas {
  largeur: number
  hauteur: number
  largeurMonde: number
  hauteurMonde: number
}

export function borner(s: number, c: Canevas): number {
  return Math.min(ECHELLE_MAX, Math.max(echelleMin(c.largeur, c.largeurMonde), s))
}

export const syDe = (s: number, c: Canevas): number => echelleVerticale(s, c.hauteur, c.hauteurMonde)

/** Écran → monde. */
export function versMonde(v: Cadrage, c: Canevas, px: number, py: number): { x: number; y: number } {
  return { x: (px - v.tx) / v.s, y: (py - v.ty) / syDe(v.s, c) }
}

/**
 * Zoom autour d'un point écran : le point du monde sous le curseur reste sous
 * le curseur, sur les deux axes.
 */
export function zoomAutour(v: Cadrage, c: Canevas, px: number, py: number, facteur: number): Cadrage {
  const { x, y } = versMonde(v, c, px, py)
  const s = borner(v.s * facteur, c)
  return { s, tx: px - x * s, ty: py - y * syDe(s, c) }
}

/** Le cadrage qui met le point monde `(x, y)` au point écran `(px, py)` à l'échelle `s`. */
export function centrerSur(s: number, c: Canevas, x: number, y: number, px: number, py: number): Cadrage {
  return { s, tx: px - x * s, ty: py - y * syDe(s, c) }
}

/** Premier affichage : ajusté à la largeur, centré verticalement. */
export function ajusterLargeur(c: Canevas, gouttiere: number): Cadrage {
  const s = borner((c.largeur - gouttiere - 20) / Math.max(1, c.largeurMonde), c)
  const sy = syDe(s, c)
  return { s, tx: gouttiere - 20, ty: (c.hauteur - c.hauteurMonde * sy) / 2 }
}

export const easeInOutQuad = (t: number): number => (t < 0.5 ? 2 * t * t : 1 - Math.pow(-2 * t + 2, 2) / 2)

/** Durée du vol, en millisecondes. */
export const DUREE_VOL = 520

/**
 * Le vol vers `(x, y)` à l'échelle `sCible` : on interpole `log(s)` et le
 * centre monde. Rend la fonction `t ∈ [0, 1] → cadrage`.
 */
export function trajectoire(
  depart: Cadrage,
  c: Canevas,
  sCible: number,
  x: number,
  y: number,
): (t: number) => Cadrage {
  const cible = borner(sCible, c)
  const px = c.largeur / 2
  const py = c.hauteur / 2
  const centre = versMonde(depart, c, px, py)
  const l0 = Math.log(depart.s)
  const l1 = Math.log(cible)
  return (t) => {
    const e = easeInOutQuad(Math.min(1, Math.max(0, t)))
    const s = Math.exp(l0 + (l1 - l0) * e)
    return centrerSur(s, c, centre.x + (x - centre.x) * e, centre.y + (y - centre.y) * e, px, py)
  }
}
