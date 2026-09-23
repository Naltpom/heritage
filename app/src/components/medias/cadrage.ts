/**
 * Le cadrage d'un portrait : l'image se déplace et se zoome sous un cercle fixe.
 *
 * La scène est un carré de côté `cote` ; le cercle y est inscrit. L'image la
 * **couvre toujours** : au zoom 1, son petit côté fait exactement la scène, et
 * on ne peut ni dézoomer en deçà ni la tirer au point de laisser un bord vide
 * dans le carré. Ce qui part au serveur est ce carré, à la résolution de
 * l'image : l'arbre l'affiche ensuite rogné en rond (`object-cover`), donc la
 * tête que l'on a mise dans le cercle est celle que l'on verra sur sa carte.
 *
 * Pur, sans DOM : vérifié dans `cadrage.test.ts`.
 */

export const ZOOM_MAX = 4

export interface Cadrage {
  /** Zoom relatif, 1 = l'image couvre juste la scène. */
  zoom: number
  /** Coin haut-gauche de l'image, en pixels de scène. */
  x: number
  y: number
}

export interface Dimensions {
  largeur: number
  hauteur: number
}

/** Échelle image → scène au zoom donné. */
export function echelle(image: Dimensions, cote: number, zoom: number): number {
  return (cote / Math.min(image.largeur, image.hauteur)) * zoom
}

/** Ramène le cadrage dans ses bornes : zoom dans [1, ZOOM_MAX], aucun bord vide. */
export function borner(c: Cadrage, image: Dimensions, cote: number): Cadrage {
  const zoom = Math.min(ZOOM_MAX, Math.max(1, c.zoom))
  const k = echelle(image, cote, zoom)
  const minX = cote - image.largeur * k
  const minY = cote - image.hauteur * k
  return {
    zoom,
    x: Math.min(0, Math.max(minX, c.x)),
    y: Math.min(0, Math.max(minY, c.y)),
  }
}

/** Le cadrage de départ : zoom 1, image centrée. */
export function cadrageInitial(image: Dimensions, cote: number): Cadrage {
  const k = echelle(image, cote, 1)
  return { zoom: 1, x: (cote - image.largeur * k) / 2, y: (cote - image.hauteur * k) / 2 }
}

/** Zoom autour d'un point de la scène : le point de l'image sous lui ne bouge pas. */
export function zoomerAutour(c: Cadrage, image: Dimensions, cote: number, zoom: number, px: number, py: number): Cadrage {
  const k0 = echelle(image, cote, c.zoom)
  const ix = (px - c.x) / k0
  const iy = (py - c.y) / k0
  const z = Math.min(ZOOM_MAX, Math.max(1, zoom))
  const k1 = echelle(image, cote, z)
  return borner({ zoom: z, x: px - ix * k1, y: py - iy * k1 }, image, cote)
}

/** Le carré à découper dans l'image, en pixels de l'image. */
export function zoneSource(c: Cadrage, image: Dimensions, cote: number): { x: number; y: number; cote: number } {
  const k = echelle(image, cote, c.zoom)
  return { x: -c.x / k, y: -c.y / k, cote: cote / k }
}

/**
 * Côté du portrait envoyé : la résolution réelle du carré découpé, plafonnée à
 * 1024 — assez pour une vignette nette et une fiche en grand, sans pousser un
 * fichier de 20 Mo pour un rond de 40 pixels.
 */
export function coteDeSortie(source: number): number {
  return Math.max(1, Math.min(1024, Math.round(source)))
}
