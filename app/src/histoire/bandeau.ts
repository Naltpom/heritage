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

/** Largeur estimée d'un nom, en pixels, à la taille du bandeau. */
export function largeurDuNom(nom: string, taille: number): number {
  return nom.length * taille * 0.6
}

export function bandeauDesFamilles(
  branches: readonly BrancheDuBandeau[],
  bordGauche: number,
  bordDroit: number,
  taille: number,
  marge = 8,
): SegmentDuBandeau[] {
  const segments: SegmentDuBandeau[] = []

  for (const b of branches) {
    const gauche = Math.max(b.x0, bordGauche)
    const droite = Math.min(b.x1, bordDroit)
    if (droite - gauche < 4) continue

    const demi = largeurDuNom(b.nom, taille) / 2
    const place = droite - gauche >= 2 * (demi + marge)
    const centre = (b.x0 + b.x1) / 2
    const x = Math.min(droite - demi - marge, Math.max(gauche + demi + marge, centre))

    segments.push({
      cle: b.cle,
      couleur: b.couleur,
      gauche,
      largeur: droite - gauche,
      etiquette: place ? { x, nom: b.nom, sous: droite - gauche >= 2 * (largeurDuNom(b.sous, 11) / 2 + marge) ? b.sous : '' } : null,
    })
  }

  return segments
}
