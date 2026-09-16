/**
 * La géométrie du canevas : tout ce qui calcule un `viewBox`.
 *
 * ────────────────────────────────────────────────────────────────────
 * **RÈGLE N° 1, DÉJÀ PAYÉE : le zoom passe par le `viewBox`, jamais par
 * `transform: scale()`.** Avec `scale()`, le navigateur rastérise les
 * 15 824 unités entières à l'échelle demandée ; avec le `viewBox`, il ne
 * dessine que le visible. Aucune fonction de ce fichier ne produit un
 * `transform`, et il ne faut pas en ajouter une.
 * ────────────────────────────────────────────────────────────────────
 *
 * Tout est **pur** : entrées scalaires, sortie scalaire, aucun accès au DOM.
 * C'est ce qui rend le zoom et le recadrage vérifiables sans navigateur — les
 * deux endroits où une erreur de signe ne se voit qu'à l'usage, et se corrige
 * alors à tâtons.
 *
 * Le portage suit `arbre.html` au coefficient près : les constantes ci-dessous
 * sont celles du viewer que le commanditaire regarde tous les jours. Les
 * changer change une sensation qu'il connaît.
 */

/** Une boîte du repère du contenu : `x`/`y` au coin haut-gauche. */
export interface Boite {
  x: number
  y: number
  w: number
  h: number
}

/** Un nœud tel que le moteur le place : `x`/`y` au **centre**, `w`/`h` mesurés. */
export interface BoiteCentree {
  x: number
  y: number
  w: number
  h: number
}

/** Le cadre à l'écran, en pixels CSS. `gauche`/`haut` viennent d'un
 *  `getBoundingClientRect()` : ils ne servent qu'à convertir un point de la
 *  souris, pas à dimensionner. */
export interface CadreEcran {
  largeur: number
  hauteur: number
  gauche: number
  haut: number
}

/** Facteur de zoom par cran de molette (`exp(deltaY × 0.0016)`). */
export const SENSIBILITE_MOLETTE = 0.0016

/** On ne dézoome pas au-delà de 2,5 fois la boîte d'origine… */
export const DEZOOM_MAX = 2.5
/** …et on ne zoome pas au-delà du 1/500ᵉ de sa largeur. */
export const ZOOM_MAX = 500

/** Largeur visée quand on centre sur une fiche, en unités du contenu. */
export const LARGEUR_CENTRAGE = 1700
/** Largeur du panneau latéral : au-delà de ce seuil d'écran, le centrage la
 *  compense pour que la fiche sélectionnée ne finisse pas dessous. */
export const LARGEUR_PANNEAU = 340
/** En deçà, le panneau prend toute la largeur : plus de biais à appliquer. */
export const SEUIL_PANNEAU = 760

/** Marge autour d'un ensemble de fiches qu'on cadre. */
export const MARGE_CADRAGE = 240
/** Largeur minimale d'un cadrage : cadrer une fiche seule sans plancher
 *  donnerait un zoom absurde. */
export const LARGEUR_CADRAGE_MIN = 900

/** Le `viewBox` sous sa forme d'attribut. Rien d'autre n'écrit cet attribut. */
export function viewBoxEnTexte(vb: Boite): string {
  return `${vb.x} ${vb.y} ${vb.w} ${vb.h}`
}

/**
 * Le rapport unités-du-contenu / pixels, tel que `preserveAspectRatio
 * ="xMidYMid meet"` le résout : la plus petite des deux échelles.
 */
export function echelle(vb: Boite, cadre: CadreEcran): number {
  return Math.min(cadre.largeur / vb.w, cadre.hauteur / vb.h)
}

/**
 * Convertit un point de l'écran (coordonnées client) en point du contenu.
 *
 * `meet` centre le dessin dans le cadre : il reste des bandes (`ox`, `oy`) de
 * part et d'autre, qu'il faut retirer avant de diviser par l'échelle. Les
 * oublier décale le zoom d'autant, et le décalage grandit avec le zoom — c'est
 * le bogue « ça zoome à côté du curseur ».
 */
export function versContenu(
  vb: Boite,
  cadre: CadreEcran,
  clientX: number,
  clientY: number,
): { x: number; y: number } {
  const k = echelle(vb, cadre)
  const ox = (cadre.largeur - vb.w * k) / 2
  const oy = (cadre.hauteur - vb.h * k) / 2
  return {
    x: vb.x + (clientX - cadre.gauche - ox) / k,
    y: vb.y + (clientY - cadre.haut - oy) / k,
  }
}

/**
 * Un cran de molette : on zoome **autour du curseur**, pas autour du centre.
 *
 * `initiale` est la boîte du graphe telle que l'API l'a rendue (`boite`), qui
 * fixe les deux butées. Elle n'est **jamais recalculée côté client**.
 */
export function zoomer(
  vb: Boite,
  initiale: Boite,
  cadre: CadreEcran,
  clientX: number,
  clientY: number,
  deltaY: number,
): Boite {
  const p = versContenu(vb, cadre, clientX, clientY)
  const f = Math.exp(deltaY * SENSIBILITE_MOLETTE)
  const nw = Math.min(initiale.w * DEZOOM_MAX, Math.max(initiale.w / ZOOM_MAX, vb.w * f))
  const k = nw / vb.w
  return {
    x: p.x - (p.x - vb.x) * k,
    y: p.y - (p.y - vb.y) * k,
    w: nw,
    h: vb.h * k,
  }
}

/** Un déplacement à la souris : `dx`/`dy` sont des pixels d'écran. */
export function deplacer(vb: Boite, cadre: CadreEcran, dxEcran: number, dyEcran: number): Boite {
  const k = echelle(vb, cadre)
  return { x: vb.x - dxEcran / k, y: vb.y - dyEcran / k, w: vb.w, h: vb.h }
}

/**
 * Centre la vue sur un nœud.
 *
 * Le `biais` compense le panneau latéral : sans lui, la fiche sur laquelle on
 * vient de cliquer se retrouve derrière le panneau qui l'affiche. Sur un écran
 * étroit le panneau couvre tout, il n'y a rien à compenser.
 */
export function centrerSur(
  noeud: BoiteCentree,
  initiale: Boite,
  cadre: CadreEcran,
  largeurVisee: number = LARGEUR_CENTRAGE,
): Boite {
  const w = Math.min(largeurVisee, initiale.w)
  const h = w * (cadre.hauteur / Math.max(cadre.largeur, 1))
  const biais =
    cadre.largeur > SEUIL_PANNEAU ? ((LARGEUR_PANNEAU / cadre.largeur) * w) / 2 : 0
  return { x: noeud.x - w / 2 + biais, y: noeud.y - h / 2, w, h }
}

/**
 * Cadre un ensemble de nœuds — ce que fait `Entrée` sur un résultat de
 * recherche.
 *
 * La largeur mène, la hauteur suit le rapport du cadre ; si la hauteur ne
 * suffit pas, on inverse. Sans ce second passage, une fratrie étalée sur douze
 * générations sort du cadre par le haut sans qu'on le voie.
 *
 * Rend `null` sur un ensemble vide : il n'y a rien à cadrer, et un `viewBox`
 * fabriqué sur des bornes infinies ferait disparaître le graphe.
 */
export function cadrer(
  noeuds: readonly BoiteCentree[],
  cadre: CadreEcran,
  marge: number = MARGE_CADRAGE,
  largeurMin: number = LARGEUR_CADRAGE_MIN,
): Boite | null {
  if (noeuds.length === 0) return null

  let x1 = Infinity
  let y1 = Infinity
  let x2 = -Infinity
  let y2 = -Infinity
  for (const n of noeuds) {
    x1 = Math.min(x1, n.x - n.w / 2)
    y1 = Math.min(y1, n.y - n.h / 2)
    x2 = Math.max(x2, n.x + n.w / 2)
    y2 = Math.max(y2, n.y + n.h / 2)
  }

  let w = Math.max(x2 - x1 + 2 * marge, largeurMin)
  let h = w * (cadre.hauteur / Math.max(cadre.largeur, 1))
  if (h < y2 - y1 + 2 * marge) {
    h = y2 - y1 + 2 * marge
    w = h * (cadre.largeur / Math.max(cadre.hauteur, 1))
  }
  return { x: (x1 + x2) / 2 - w / 2, y: (y1 + y2) / 2 - h / 2, w, h }
}
