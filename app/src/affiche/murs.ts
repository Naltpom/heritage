/**
 * Le **mur** de l'affiche (ADR 0023, § 4 et § 8) : l'icône d'un ancêtre dont un côté est
 * « clos faute d'archives », et la ligne de légende qui l'explique.
 *
 * Pur : ce module dit **qui** porte un mur (`aUnMur()`) et **où** le poser dans une case
 * (`murDansUneCase()`) ou dans un secteur (`murDansUnSecteur()`). Le tracé, la couleur
 * (`BRIQUE`, imprimée telle quelle — l'affiche ne suit pas le thème) et le texte de la
 * légende viennent du module partagé `@/lib/finDeRecherche`.
 *
 * ## Ce que le mur ne fait jamais
 *
 * - **Déplacer quoi que ce soit** : il se pose après le dessin, ne change aucune largeur
 *   mesurée ni aucun angle. Une affiche sans fin de recherche reste identique au millième.
 * - **Chevaucher le nom** : s'il ne tient pas à côté du texte, on le réduit jusqu'à
 *   `TAILLE_MIN` ; s'il ne tient toujours pas, on le pose **contre** la case, côté extérieur.
 * - **Tourner** : même sur un secteur incliné, l'icône reste droite, comme un signe imprimé.
 * - **Se lire sans le trait de couleur** : un cadre et six joints, lisibles en noir et blanc.
 */
import { BRIQUE, cheminDuMur, epaisseurDuMur, estEnFinDeRecherche, etatsDuSommet } from '@/lib/finDeRecherche'
import type { ArbreAffiche, Personne } from './donnees'
import { polaire, type Scene } from './scene'
import type { Anc } from './structure'

/** Le côté du mur dans une case ou un secteur, à `k = 1` (mm). */
export const TAILLE_DU_MUR = 3.2
/** Le plus petit mur qu'on dessine (mm) : en dessous, les joints ne se lisent plus. */
export const TAILLE_MIN = 2.0
/** L'écart du mur au bord de la case (mm). */
export const MARGE_DU_MUR = 0.8
/** L'écart minimal du mur au texte (mm). */
const ECART_AU_TEXTE = 0.4

/**
 * La personne porte-t-elle un mur ? Vrai si un côté est clos **et** qu'aucun parent n'est
 * servi de ce côté : un parent connu — même masqué — l'emporte (`etatDuSommet()`). C'est la
 * donnée, pas le dessin : un ancêtre tronqué (profondeur) ou une seconde occurrence
 * d'implexe a des parents connus, donc pas de mur. Une personne restreinte n'a jamais de
 * fin de recherche (`Personne.depuis()`).
 */
export function aUnMur(A: ArbreAffiche, p: Personne): boolean {
  const [pere, mere] = A.parents(p.id)
  return estEnFinDeRecherche(etatsDuSommet({ pere: pere !== null, mere: mere !== null }, p.finsDeRecherche))
}

/**
 * L'affiche dessine-t-elle au moins un mur ? Sur les ancêtres **effectivement dessinés** :
 * quand le premier anneau est masqué (les quatre parents au centre), le couple ne l'est pas.
 * C'est elle qui décide de la ligne de légende, mesurée comme dessinée.
 */
export function desMurs(A: ArbreAffiche, noeuds: readonly Anc[], premierAnneauMasque = false): boolean {
  return noeuds.some((n) => (n.niveau > 0 || !premierAnneauMasque) && aUnMur(A, n.p))
}

/** Un mur de côté `taille` dont le coin haut gauche est en (x, y). */
function poser(scene: Scene, x: number, y: number, taille: number): void {
  scene.chemin(cheminDuMur(x, y, taille), 'none', BRIQUE, epaisseurDuMur(taille), true)
}

/** Deux rectangles (x, y, l, h) se touchent-ils, à `ecart` près ? */
function seTouchent(a: readonly [number, number, number, number], b: readonly [number, number, number, number], ecart: number): boolean {
  return a[0] < b[0] + b[2] + ecart && b[0] < a[0] + a[2] + ecart && a[1] < b[1] + b[3] + ecart && b[1] < a[1] + a[3] + ecart
}

/**
 * Le mur dans le coin haut droit d'une case (x, y, l, h), de côté `TAILLE_DU_MUR × k`, à
 * `MARGE_DU_MUR` du bord. `texte` : l'encombrement (largeur, hauteur) du nom tel qu'il est
 * dessiné — centré dans la case. Il ne tient pas à côté du nom : on le réduit jusqu'à
 * `TAILLE_MIN`, puis on le pose **au-dessus** de la case (côté des parents), toujours à droite.
 * Rend le côté retenu, pour les tests.
 */
export function murDansUneCase(scene: Scene, x: number, y: number, l: number, h: number, k: number, texte: readonly [number, number] | null = null): number {
  const zone: [number, number, number, number] | null = texte ? [x + (l - texte[0]) / 2, y + (h - texte[1]) / 2, texte[0], texte[1]] : null
  const dedans = (t: number): boolean => {
    if (t > l - 2 * MARGE_DU_MUR || t > h - 2 * MARGE_DU_MUR) return false
    const coin: [number, number, number, number] = [x + l - MARGE_DU_MUR - t, y + MARGE_DU_MUR, t, t]
    return zone === null || !seTouchent(coin, zone, ECART_AU_TEXTE)
  }
  const plein = Math.max(TAILLE_DU_MUR * k, TAILLE_MIN)
  for (let t = plein; t >= TAILLE_MIN - 1e-9; t -= 0.2) {
    if (dedans(t)) {
      poser(scene, x + l - MARGE_DU_MUR - t, y + MARGE_DU_MUR, t)
      return t
    }
  }
  // Contre la case, côté extérieur : au-dessus, dans l'écart entre deux rangées.
  poser(scene, x + l - TAILLE_MIN, y - MARGE_DU_MUR - TAILLE_MIN, TAILLE_MIN)
  return TAILLE_MIN
}

/** Où se trouve le texte d'un secteur : jusqu'à quel rayon il monte, et l'angle libre à l'extérieur. */
export interface ZoneDeSecteur {
  /** Le rayon où s'arrête le texte, le long du rayon médian (mm). Absent : pas de texte à éviter. */
  rTexte?: number
  /** L'ouverture du secteur (radians) : un mur ne dépasse pas ses voisins. Absent : illimitée. */
  ouverture?: number
  /** L'angle (degrés) où poser le mur s'il passe à l'extérieur ; par défaut l'angle médian. */
  aExterieur?: number
}

/**
 * Le mur sur l'arc extérieur d'un secteur, à son angle médian `a` (degrés dessinés), centré à
 * `r2 − taille` du centre (cx, cy), toujours droit. Il ne tient pas entre le texte (`zone.rTexte`)
 * et l'arc, ou dans l'ouverture du secteur : on le réduit jusqu'à `TAILLE_MIN`, puis on le pose
 * **au-delà** de l'arc (`zone.aExterieur`), jamais par-dessus le nom. Rend le côté retenu.
 */
export function murDansUnSecteur(scene: Scene, cx: number, cy: number, r2: number, a: number, k: number, zone: ZoneDeSecteur = {}): number {
  const rad = (a * Math.PI) / 180
  // La demi-étendue d'un carré droit selon la direction du rayon, et selon la tangente (la même).
  const demi = (t: number): number => (t / 2) * (Math.abs(Math.cos(rad)) + Math.abs(Math.sin(rad)))
  const plein = Math.max(TAILLE_DU_MUR * k, TAILLE_MIN)
  for (let t = plein; t >= TAILLE_MIN - 1e-9; t -= 0.2) {
    const rc = r2 - t
    const d = demi(t)
    if (zone.rTexte !== undefined && rc - d < zone.rTexte + ECART_AU_TEXTE) continue
    if (zone.ouverture !== undefined && zone.ouverture * (rc - d) < 2 * d + ECART_AU_TEXTE) continue
    const [mx, my] = polaire(cx, cy, rc, a)
    poser(scene, mx - t / 2, my - t / 2, t)
    return t
  }
  const ae = zone.aExterieur ?? a
  const t = TAILLE_MIN
  const [mx, my] = polaire(cx, cy, r2 + MARGE_DU_MUR + Math.SQRT1_2 * t, ae)
  poser(scene, mx - t / 2, my - t / 2, t)
  return t
}
