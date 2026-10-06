/**
 * Le cadrage de `/histoire` : niveaux de détail, échelle anisotrope, zoom
 * autour d'un point et vol animé.
 *
 * **Plus on dézoome, moins on écrit.** Trois niveaux, choisis sur l'échelle
 * horizontale `s` (1 = taille réelle d'une fiche) :
 *
 * | Niveau    | `s`         | Ce qu'on dessine                          |
 * |-----------|-------------|-------------------------------------------|
 * | Ensemble  | < 0,16      | rectangles de couleur, lignée directe     |
 * | Familles  | 0,16 – 0,45 | fiches avec leur photo, noms de foyers     |
 * | Fiches    | ≥ 0,45      | tout le texte, réduit sous 0,8 (carte)    |
 *
 * Le seuil des Fiches (0,45) est celui par défaut : le lecteur le règle
 * (`SEUIL_DES_NOMS`).
 *
 * **Il y avait un quatrième niveau, « Prénoms », entre 0,42 et 0,75, et il ne
 * tenait pas sa promesse.** Une fiche fait ~132 px de monde, dont 56 pour la
 * photo : à 0,42 il restait ~32 px d'écran pour écrire, soit trois lettres ; à
 * 0,75, ~57 px, où un nom complet ne tenait pas davantage. Les seuils étaient
 * posés sur l'échelle, alors que ce qui décide est **la place du texte**. Le
 * niveau Fiches choisit donc lui-même, fiche par fiche, ce qu'il écrit.
 *
 * **L'échelle verticale est séparée.** Cinq cents personnes sur sept
 * générations font un arbre trente fois plus large que haut : à l'échelle
 * « tout voir », il tiendrait en 40 px de haut. `sy(s)` garde les rangées
 * lisibles au dézoom, et redevient `s` dès que `s` atteint `fitY`.
 *
 * Pur, sans DOM.
 */

export type Niveau = 0 | 1 | 2

export const SEUILS = [0.16, 0.45] as const

/**
 * Le zoom auquel le texte d'une fiche est écrit à sa taille nominale. En
 * dessous (niveau Fiches dès 45 %), la fiche se dessine **comme à ce zoom, en
 * plus petit** : même texte, police réduite avec le zoom, comme une carte.
 * Au-dessus, la police ne grossit plus et c'est la place qui s'élargit.
 */
export const ZOOM_DU_TEXTE = 0.8

/** Le facteur de réduction du texte à l'échelle `s` (1 au-delà de `ZOOM_DU_TEXTE`). */
export const reductionDuTexte = (s: number): number => Math.min(1, s / ZOOM_DU_TEXTE)

export interface Palier {
  libelle: string
  /** `null` : ajusté à la largeur. */
  echelle: number | null
}

export const PALIERS: readonly Palier[] = [
  { libelle: 'Ensemble', echelle: null },
  { libelle: 'Familles', echelle: 0.3 },
  { libelle: 'Fiches', echelle: 1 },
]

/**
 * Le seuil du niveau Fiches — le zoom à partir duquel les noms, prénoms et dates
 * s'écrivent — est **réglable** par le lecteur (05/10/2026), dans les filtres de
 * l'arbre. 45 % convient à un téléphone ou un portable ; sur un écran 2K, 4K ou
 * une télévision, les fiches restent grandes bien plus loin, et l'on veut lire
 * les noms plus tôt. À l'inverse, on peut le relever pour un arbre plus calme.
 */
export const SEUIL_DES_NOMS = { min: 0.1, max: 0.9, defaut: SEUILS[1] } as const

/** Le seuil ramené dans ses bornes ; une valeur illisible rend le défaut. */
export function bornerSeuilDesNoms(seuil: number): number {
  if (!Number.isFinite(seuil)) return SEUIL_DES_NOMS.defaut
  return Math.min(SEUIL_DES_NOMS.max, Math.max(SEUIL_DES_NOMS.min, seuil))
}

/**
 * Le niveau de détail à l'échelle `s`. Sous un seuil des noms plus bas que celui
 * des familles, le niveau Familles disparaît : on passe de l'Ensemble aux Fiches.
 */
export function niveau(s: number, seuilDesNoms: number = SEUILS[1]): Niveau {
  if (s >= seuilDesNoms) return 2
  if (s < SEUILS[0]) return 0
  return 1
}

/**
 * Ce qu'une fiche écrit, selon la place qu'elle a **à l'écran**.
 *
 * Par ordre de préférence : le nom complet et la ligne de dates ; le nom
 * complet seul ; le prénom. La police ne suit pas le zoom — une lettre de 7 px
 * n'est lisible par personne —, c'est le texte qui s'adapte.
 *
 * La largeur d'un texte est estimée, pas mesurée : mesurer demanderait un
 * canevas par fiche et par image. L'estimation est volontairement généreuse,
 * pour qu'un nom annoncé « complet » ne soit jamais tronqué.
 */
export function texteDeFiche(
  entree: { nom: string; prenom: string; dates: string },
  largeur: number,
  hauteur: number,
): { t1: string; t2: string; taille: number } {
  const tient = (texte: string, taille: number, chasse = 0.58): boolean => texte.length * taille * chasse <= largeur

  if (tient(entree.nom, 12)) {
    const dates = entree.dates !== '' && hauteur >= 30 && tient(entree.dates, 10.5, 0.62) ? entree.dates : ''
    return { t1: entree.nom, t2: dates, taille: 12 }
  }

  const prenom = entree.prenom !== '' ? entree.prenom : entree.nom
  return { t1: prenom, t2: '', taille: tient(prenom, 11) ? 11 : 10.5 }
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

/**
 * La jauge de zoom réservée à l'administration : le zoom en pour cent (100 % =
 * le palier Fiches, une unité du monde par pixel) et sa place entre le zoom
 * minimal et `ECHELLE_MAX`, sur une échelle logarithmique — chaque cran de
 * molette avance d'autant. Elle sert à dire « à 30 % les noms se chevauchent »
 * sur une capture, sans deviner.
 */
export interface JaugeDuZoom {
  pourcent: number
  /** 0 au zoom minimal, 1 à `ECHELLE_MAX`. */
  position: number
  /** Les seuils des niveaux Familles et Fiches, à la même échelle. */
  seuils: readonly [number, number]
}

export function jaugeDuZoom(s: number, c: Canevas, seuilDesNoms: number = SEUILS[1]): JaugeDuZoom {
  const min = echelleMin(c.largeur, c.largeurMonde)
  const etendue = Math.log(ECHELLE_MAX) - Math.log(min)
  const place = (e: number): number =>
    etendue <= 0 ? 1 : Math.min(1, Math.max(0, (Math.log(e) - Math.log(min)) / etendue))
  return { pourcent: Math.round(s * 100), position: place(s), seuils: [place(Math.min(SEUILS[0], seuilDesNoms)), place(seuilDesNoms)] }
}

/* ------------------------------------------------------------------ */
/* Écrire le cadrage dans le DOM (ADR 0007)                            */
/* ------------------------------------------------------------------ */

/**
 * Le `viewBox` du `<svg>` des traits (ADR 0007, règle 1) : il montre, dans un
 * `<svg preserveAspectRatio="none">` de la taille du canevas, exactement ce que
 * le cadrage montre — l'échelle verticale séparée comprise, d'où le `none`. Le
 * découpage a lieu avant la rastérisation : seul le visible est peint.
 */
export function viewBoxDe(v: Cadrage, c: Canevas): string {
  const sy = syDe(v.s, c)
  const l = Math.max(1, c.largeur)
  const h = Math.max(1, c.hauteur)
  return `${-v.tx / v.s} ${-v.ty / sy} ${l / v.s} ${h / sy}`
}

/**
 * La transformation de la couche HTML des boîtes, posées en coordonnées monde.
 * Une seule écriture par image, sur un seul élément (note du 2026-10-06 à
 * l'ADR 0007 : les boîtes sont du HTML, pas du SVG).
 */
export function transformationMonde(v: Cadrage, c: Canevas): string {
  return `translate(${v.tx}px, ${v.ty}px) scale(${v.s}, ${syDe(v.s, c)})`
}

/**
 * Ce qui amène une couche dessinée en coordonnées ÉCRAN pour le cadrage `depuis`
 * à sa place sous le cadrage `vers` — `none` s'ils sont égaux.
 *
 * Les textes, photos et anneaux ne sont recalculés (rendu Vue) qu'à la fin d'un
 * geste, à un changement de niveau, ou au plus quelques fois par seconde ; entre
 * deux, cette transformation, posée sur leur conteneur, les garde collés à leur
 * fiche. Un point écran `X` de `depuis` vient en `kx·X + ex`, avec `kx = s'/s`.
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
 * au-delà du bord, pour qu'un glisser ne découvre pas des fiches muettes avant
 * le recalcul suivant.
 */
export function dansLaFenetre(v: Cadrage, c: Canevas, x: number, y: number, w: number, h: number, marge = 0): boolean {
  const sy = syDe(v.s, c)
  const X = v.tx + x * v.s
  const Y = v.ty + y * sy
  const mx = marge * c.largeur
  const my = marge * c.hauteur
  return X + w * v.s > -mx && X < c.largeur + mx && Y + h * sy > -my && Y < c.hauteur + my
}
