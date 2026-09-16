/**
 * Le tracé des arêtes — porté de `rendu.py` (`docs/moteur-retrouve/`).
 *
 * **Pourquoi le front trace, alors que l'ancien viewer recevait un SVG tout
 * fait.** `GET /api/arbre/graphe` rend des arêtes logiques (`de`, `vers`,
 * `genre`, `divorce`) et des positions ; il ne rend pas de chemins. Le dessin
 * est donc à refaire ici, et il doit l'être **à l'identique** : le
 * commanditaire regarde ce rendu tous les jours.
 *
 * Trois choses en viennent, et aucune ne se devine :
 *
 * 1. **Les coins sont arrondis à 9 unités**, par une quadratique dont le point
 *    de contrôle est le coin lui-même (`poly()` de `rendu.py`). Le rayon est
 *    rogné à la moitié du segment le plus court, sinon deux coins proches se
 *    chevauchent et le trait se replie sur lui-même.
 * 2. **Un peigne par famille** : une seule barre horizontale partagée, et une
 *    descente par enfant. Sans cela, toutes les arêtes d'une même rangée
 *    passent par la même médiane et se superposent — « le graphe devient
 *    illisible dès 40 familles » (commentaire d'origine). Il y en a 103.
 * 3. **La hauteur de barre est décalée par famille**, les peignes larges au
 *    plus près des enfants et les étroits au plus près des parents : deux
 *    barres qui se croisent en `x` ne sont jamais à la même hauteur, donc elles
 *    restent distinguables.
 *
 * Ce qui a **disparu** du portage : `rendu.py` posait une classe `long` sur
 * toute arête dont les extrémités s'écartaient de plus de 700 unités, que la
 * feuille de style rendait en pointillé translucide. Le commanditaire l'a fait
 * retirer — « ça sert à rien si c'est pour dire c'est loin » (§ 7.3). Il n'y a
 * donc **aucun calcul de distance** dans ce fichier, et il ne doit pas y en
 * avoir : le jour où quelqu'un le rajoute « pour information », la classe
 * revient avec.
 *
 * Tout est pur : ni DOM, ni état. Les chaînes rendues sont comparables
 * caractère par caractère avec celles du viewer d'origine.
 */

export interface PointSvg {
  x: number
  y: number
}

/** Un nœud placé : `x`/`y` au centre, comme le moteur les rend. */
export interface BoiteNoeud {
  x: number
  y: number
  w: number
  h: number
}

/** Rayon des coins, en unités du contenu. */
export const RAYON_COIN = 9

/** Hauteur de la barre d'union au-dessus du losange de famille. */
export const RECUL_UNION = 16

/** Deux points plus proches que cela sont considérés confondus et fusionnés —
 *  un segment nul ferait diverger la normalisation du vecteur. */
const EPSILON = 0.3

/** Une décimale, comme `"%.1f"` en Python : c'est la précision du SVG d'origine
 *  et elle suffit largement à 15 824 unités de large. */
function d1(valeur: number): string {
  return valeur.toFixed(1)
}

/**
 * Trace orthogonal à coins arrondis passant par une suite de points.
 *
 * Port littéral de `poly()`. Rend `''` si les points fusionnent en moins de
 * deux : l'appelant doit alors ne pas créer d'élément, pas créer un élément
 * vide.
 */
export function trace(points: readonly PointSvg[], rayon: number = RAYON_COIN): string {
  const pts: PointSvg[] = points.filter(
    (p, i) =>
      i === 0 ||
      Math.abs(p.x - points[i - 1].x) > EPSILON ||
      Math.abs(p.y - points[i - 1].y) > EPSILON,
  )
  if (pts.length < 2) return ''

  const morceaux: string[] = [`M${d1(pts[0].x)},${d1(pts[0].y)}`]
  for (let i = 1; i < pts.length - 1; i++) {
    const a = pts[i - 1]
    const b = pts[i]
    const c = pts[i + 1]
    const r1 = Math.min(rayon, Math.hypot(b.x - a.x, b.y - a.y) / 2)
    const r2 = Math.min(rayon, Math.hypot(c.x - b.x, c.y - b.y) / 2)
    const ux = a.x - b.x
    const uy = a.y - b.y
    const n = Math.hypot(ux, uy) || 1
    const vx = c.x - b.x
    const vy = c.y - b.y
    const m = Math.hypot(vx, vy) || 1
    morceaux.push(`L${d1(b.x + (ux / n) * r1)},${d1(b.y + (uy / n) * r1)}`)
    morceaux.push(
      `Q${d1(b.x)},${d1(b.y)} ${d1(b.x + (vx / m) * r2)},${d1(b.y + (vy / m) * r2)}`,
    )
  }
  morceaux.push(`L${d1(pts[pts.length - 1].x)},${d1(pts[pts.length - 1].y)}`)
  return morceaux.join(' ')
}

/**
 * Époux → nœud de famille : on descend du bas de la fiche, on rejoint une
 * barre placée juste au-dessus du losange, puis on retombe sur sa pointe haute.
 */
export function pointsUnion(epoux: BoiteNoeud, famille: BoiteNoeud): PointSvg[] {
  const basFiche = epoux.y + epoux.h / 2
  const barre = famille.y - RECUL_UNION
  return [
    { x: epoux.x, y: basFiche },
    { x: epoux.x, y: barre },
    { x: famille.x, y: barre },
    { x: famille.x, y: famille.y - famille.h / 2 },
  ]
}

/**
 * Nœud de famille → enfant : descente jusqu'à la barre **partagée** du peigne,
 * déplacement latéral, puis descente sur le haut de la fiche de l'enfant.
 */
export function pointsFiliation(
  famille: BoiteNoeud,
  enfant: BoiteNoeud,
  hauteurBarre: number,
): PointSvg[] {
  return [
    { x: famille.x, y: famille.y + famille.h / 2 },
    { x: famille.x, y: hauteurBarre },
    { x: enfant.x, y: hauteurBarre },
    { x: enfant.x, y: enfant.y - enfant.h / 2 },
  ]
}

/** Une famille et les enfants qui lui sont rattachés par une arête de filiation. */
export interface PeigneDeFamille {
  id: string
  famille: BoiteNoeud
  enfants: readonly BoiteNoeud[]
}

/**
 * La hauteur de barre de chaque famille, décalée pour éviter les collisions.
 *
 * Port littéral de `barres()`. Les familles d'une même rangée sont triées par
 * **largeur de peigne croissante**, puis étalées entre 30 % et 85 % de la
 * descente : le peigne le plus étroit passe au plus haut, le plus large au plus
 * bas. Le tri de JavaScript est stable, celui de Python aussi — à largeurs
 * égales, l'ordre d'arrivée décide dans les deux cas.
 *
 * Une famille sans enfant placé n'a pas de peigne et n'apparaît pas dans le
 * résultat ; l'appelant retombe alors sur la médiane.
 */
export function hauteursDeBarre(peignes: readonly PeigneDeFamille[]): Map<string, number> {
  const parRang = new Map<number, PeigneDeFamille[]>()
  for (const p of peignes) {
    if (p.enfants.length === 0) continue
    const rang = Math.round(p.famille.y)
    const liste = parRang.get(rang)
    if (liste === undefined) parRang.set(rang, [p])
    else liste.push(p)
  }

  const hauteurs = new Map<string, number>()
  for (const liste of parRang.values()) {
    const largeurs = new Map<string, number>()
    for (const p of liste) {
      const xs = p.enfants.map((c) => c.x)
      largeurs.set(p.id, Math.max(...xs) - Math.min(...xs))
    }
    liste.sort((a, b) => (largeurs.get(a.id) ?? 0) - (largeurs.get(b.id) ?? 0))

    const n = Math.max(liste.length - 1, 1)
    liste.forEach((p, i) => {
      const haut = p.famille.y
      const bas = Math.min(...p.enfants.map((c) => c.y - c.h / 2))
      hauteurs.set(p.id, haut + (bas - haut) * (0.3 + (0.55 * i) / n))
    })
  }
  return hauteurs
}
