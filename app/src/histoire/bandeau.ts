/**
 * Le bandeau des familles, en haut de l'arbre — le pendant horizontal de la
 * gouttière des générations.
 *
 * Chaque branche (les quarts d'ascendance, et la zone des personnes sans lien)
 * occupe une étendue horizontale du monde. Le bandeau en dessine la part
 * **visible à l'écran**, d'un trait de sa couleur, et y pose son nom. Le nom est
 * **collant** : tant que la branche déborde de l'écran, il reste contre le bord,
 * si bien qu'on sait dans quelle famille on se trouve à tout zoom et en tout
 * endroit de l'arbre — même au milieu d'une branche dont on ne voit ni le début
 * ni la fin.
 *
 * Pur, sans DOM : les coordonnées d'entrée sont déjà projetées à l'écran.
 */
import { largeurDuTexte, poserEtiquettes, type DemandeDEtiquette } from './etiquettes'

export interface BrancheDuBandeau {
  cle: string
  couleur: string
  nom: string
  /** Seconde ligne facultative : « côté père du père · 134 ». */
  sous: string
  /** Étendue à l'écran, en pixels. */
  x0: number
  x1: number
}

export interface SegmentDuBandeau {
  cle: string
  couleur: string
  /** Part visible de la branche. */
  gauche: number
  largeur: number
  /** `null` quand le nom n'a pas la place : le trait de couleur reste. */
  etiquette: { x: number; nom: string; sous: string } | null
}

/** Largeur estimée d'un nom, en pixels, à la taille du bandeau (gras, capitales comprises). */
export function largeurDuNom(nom: string, taille: number): number {
  return largeurDuTexte(nom, taille)
}

/**
 * Deux noms ne se recouvrent JAMAIS. Chaque branche calcule d'abord sa part visible et
 * la place voulue de son nom ; puis les noms se posent ensemble (`poserEtiquettes`), la
 * branche la plus visible d'abord : une autre qui la chevaucherait se décale dans sa
 * propre part visible, perd sa seconde ligne, se tronque, ou se tait — son trait de
 * couleur, lui, reste. Deux bandes qui se recouvrent calaient leurs deux noms
 * « collants » sur le même bord d'écran, au même pixel (« Voulgre@1365 Figarol@1365 »).
 */
export function bandeauDesFamilles(
  branches: readonly BrancheDuBandeau[],
  bordGauche: number,
  bordDroit: number,
  taille: number,
  marge = 8,
): SegmentDuBandeau[] {
  const segments: SegmentDuBandeau[] = []
  const demandes: DemandeDEtiquette[] = []

  for (const b of branches) {
    const gauche = Math.max(b.x0, bordGauche)
    const droite = Math.min(b.x1, bordDroit)
    if (droite - gauche < 4) continue

    segments.push({ cle: b.cle, couleur: b.couleur, gauche, largeur: droite - gauche, etiquette: null })
    demandes.push({
      cle: b.cle,
      // Le milieu de la branche entière : collé au bord tant qu'elle déborde de l'écran.
      x: (b.x0 + b.x1) / 2,
      y: 0,
      nom: b.nom,
      taille,
      sous: b.sous,
      tailleSous: 11,
      min: gauche + marge,
      max: droite - marge,
      priorite: droite - gauche,
    })
  }

  for (const e of poserEtiquettes(demandes, { marge })) {
    const s = segments.find((x) => x.cle === e.cle)
    if (s) s.etiquette = { x: e.x, nom: e.nom, sous: e.sous }
  }
  return segments
}
