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

/* ------------------------------------------------------------------ */
/* Partir d'un cadre de visage                                         */
/* ------------------------------------------------------------------ */

/**
 * Un cadre dans le **repère de l'image**, en millièmes (0 à 1000) — la forme
 * des boîtes que rend le modèle de l'assistant (`haut`, `gauche`, `bas`,
 * `droite`, comme `ymin, xmin, ymax, xmax`). Indépendant de la résolution :
 * le même cadre vaut pour la photo d'origine et pour sa version allégée.
 */
export interface CadreVisage {
  haut: number
  gauche: number
  bas: number
  droite: number
}

/** Un millième, borné à l'image. */
function millieme(v: number): number {
  return Math.min(1000, Math.max(0, Number.isFinite(v) ? v : 0))
}

/**
 * Le cadrage de départ **posé sur un visage** : un carré centré sur le cadre,
 * aussi petit que possible **en couvrant tout le cadre**, puis ramené dans ses
 * bornes par `borner()`.
 *
 * - Le carré prend le plus grand côté du cadre (en pixels : un cadre « carré »
 *   en millièmes ne l'est pas sur une photo en paysage), agrandi de `marge`
 *   (0,1 = 10 %) si l'on veut de l'air autour — le modèle inclut déjà les
 *   épaules, d'où 0 par défaut.
 * - Un cadre plus grand que le petit côté de l'image donne le zoom 1 : le carré
 *   ne peut pas sortir de la photo, il couvre alors tout ce qu'il peut.
 * - Un visage minuscule plafonne au `ZOOM_MAX` : le carré est plus grand que le
 *   cadre, qui reste couvert.
 * - Le recentrage de `borner()` ne découvre jamais le cadre : un segment au
 *   moins aussi long que le cadre, centré sur lui puis poussé dans l'image, le
 *   contient toujours.
 *
 * Un cadre vide ou inversé (bas ≤ haut, droite ≤ gauche) ne dit rien : on
 * retombe sur `cadrageInitial()`, l'image centrée.
 */
export function cadrageDepuisCadre(cadre: CadreVisage, image: Dimensions, cote: number, marge = 0): Cadrage {
  const haut = millieme(cadre.haut)
  const bas = millieme(cadre.bas)
  const gauche = millieme(cadre.gauche)
  const droite = millieme(cadre.droite)
  if (bas <= haut || droite <= gauche) return cadrageInitial(image, cote)

  const largeur = ((droite - gauche) / 1000) * image.largeur
  const hauteur = ((bas - haut) / 1000) * image.hauteur
  const cx = ((gauche + droite) / 2000) * image.largeur
  const cy = ((haut + bas) / 2000) * image.hauteur
  const carre = Math.max(largeur, hauteur) * (1 + Math.max(0, marge))

  // Côté du carré découpé au zoom z : min(L, H) / z (voir `zoneSource`).
  const zoom = Math.min(ZOOM_MAX, Math.max(1, Math.min(image.largeur, image.hauteur) / carre))
  const k = echelle(image, cote, zoom)
  return borner({ zoom, x: cote / 2 - cx * k, y: cote / 2 - cy * k }, image, cote)
}

/** Le carré à découper pour un cadre de visage, en pixels de l'image — sans
 *  scène : c'est l'aperçu de la relecture et le fichier envoyé tant que le
 *  membre n'a rien ajusté. */
export function zoneDepuisCadre(cadre: CadreVisage, image: Dimensions): { x: number; y: number; cote: number } {
  const COTE = 1000
  return zoneSource(cadrageDepuisCadre(cadre, image, COTE), image, COTE)
}

/**
 * L'inverse : le cadre (en millièmes) d'un carré découpé. Ce que l'on garde
 * d'un ajustement — rouvrir le recadrage sur ce cadre retrouve le même carré,
 * quelle que soit la taille de la scène.
 */
export function cadreDeZone(zone: { x: number; y: number; cote: number }, image: Dimensions): CadreVisage {
  return {
    haut: (zone.y / image.hauteur) * 1000,
    gauche: (zone.x / image.largeur) * 1000,
    bas: ((zone.y + zone.cote) / image.hauteur) * 1000,
    droite: ((zone.x + zone.cote) / image.largeur) * 1000,
  }
}
