/**
 * Les deux encodages visuels d'une bulle : sa taille et sa couleur.
 *
 * ────────────────────────────────────────────────────────────────────
 * **LA TAILLE ENCODE LE NOMBRE PAR L'AIRE, PAS PAR LE RAYON.**
 *
 * La quantité qu'un disque donne à percevoir est son **aire**, et l'aire varie
 * comme le carré du rayon. Un rayon proportionnel au nombre surestimerait donc
 * au carré : cent événements paraîtraient cent fois un, au lieu de dix fois.
 * C'est la seule échelle honnête pour une carte à symboles proportionnels.
 *
 * **Et la forme qui vient d'abord à l'esprit ne la donne pas.** Écrire
 * `plancher + amplitude × √p` paraît juste et ne l'est pas : le plancher
 * s'ajoutant après la racine, doubler le nombre n'agrandissait l'aire utile que
 * d'un facteur 1,64 au lieu de 2. On interpole donc sur les **carrés** — voir
 * {@link rayon}. Un test mesure ce facteur, précisément parce qu'une carte
 * fausse reste une carte plausible.
 *
 * Le plancher de 7 px n'est pas cosmétique : un lieu à un seul événement doit
 * rester une cible qu'un doigt peut atteindre.
 *
 * ────────────────────────────────────────────────────────────────────
 * **AUCUN HEXADÉCIMAL, ET C'EST CE QUI REND LE DÉGRADÉ POSSIBLE.**
 *
 * La règle de la maison est de ne jamais écrire une couleur en dur dans un
 * composant : le thème sombre ne la suivrait pas. Un dégradé continu semble
 * contredire cette règle — il faut bien interpoler entre deux valeurs. On rend
 * donc une **expression `color-mix()`**, pas une couleur : le calcul est confié
 * au navigateur, qui lit les jetons du thème au moment de peindre. Le procédé
 * est déjà dans `main.css`.
 *
 * **Sur les couleurs de branche, et pas sur l'accent.** La charte déclare les
 * cinq couleurs de branche stables au thème — « ce sont des étiquettes de
 * donnée ; les inverser rendrait deux captures d'écran incomparables ». C'est
 * exactement la propriété qu'un encodage de donnée exige. L'accent, lui, veut
 * dire « ceci est actionnable », et une bulle ne l'est pas de la même façon
 * qu'un bouton.
 *
 * Nuance à dire avant qu'on ne la relève : dans le dégradé « par nombre », seule
 * l'extrémité basse suit le thème, puisqu'elle se dilue dans le fond. C'est
 * voulu — l'extrémité basse veut dire « presque rien », et « presque rien »,
 * c'est la page.
 * ────────────────────────────────────────────────────────────────────
 */

/** En pixels CSS. `circleMarker` prend un rayon en pixels, et non en mètres :
 *  un rayon métrique ferait disparaître une ville au dézoom. */
export const RAYON_MIN = 7
export const RAYON_MAX = 26

export type ModeCouleur = 'date' | 'nombre'

/**
 * L'aire du disque est proportionnelle au nombre. Voir l'en-tête.
 *
 * **On interpole sur l'AIRE, et non sur le rayon.** La forme qui vient d'abord à
 * l'esprit — `RAYON_MIN + (RAYON_MAX − RAYON_MIN) × √p` — a l'air juste et ne
 * l'est pas : le plancher s'ajoute *après* la racine, si bien que l'aire au-delà
 * du plancher vaut `2·r_min·k·√p + k²·p`, qui n'est pas proportionnelle à `p`.
 * Sur cette carte, doubler le nombre n'agrandissait l'aire utile que d'un
 * facteur 1,64 au lieu de 2. Un test le mesure.
 *
 * En interpolant les carrés, l'aire au-delà du plancher vaut exactement
 * `(r_max² − r_min²) × p`. Les deux extrémités sont inchangées, seul le milieu
 * de l'échelle se corrige.
 */
export function rayon(nombre: number, maximum: number): number {
  if (maximum <= 0 || nombre <= 0) return RAYON_MIN
  const part = Math.min(1, nombre / maximum)
  return Math.sqrt(RAYON_MIN * RAYON_MIN + (RAYON_MAX * RAYON_MAX - RAYON_MIN * RAYON_MIN) * part)
}

/**
 * Du plus ancien (chardon, froid) au plus récent (romarin, chaud).
 *
 * Bornes confondues — un seul lieu, ou plusieurs partageant la même médiane —
 * rend l'extrémité haute plutôt que de diviser par zéro. Une teinte unique est
 * la bonne réponse : il n'y a pas d'écart à montrer.
 */
export function teinteParDate(annee: number, bornes: { min: number; max: number }): string {
  const etendue = bornes.max - bornes.min
  const part = etendue <= 0 ? 1 : (annee - bornes.min) / etendue
  return melange('var(--color-branche-pp)', 'var(--color-branche-mp)', part)
}

/**
 * De la rareté (presque le fond) à la densité (bourrache pleine).
 *
 * Le plancher de 18 % n'est pas un réglage esthétique : à 0 %, une bulle isolée
 * serait exactement de la couleur du fond, donc invisible — et c'est le cas le
 * plus fréquent de la carte.
 */
export function teinteParNombre(nombre: number, maximum: number): string {
  const part = maximum <= 0 ? 1 : Math.min(1, nombre / maximum)
  return melange('var(--color-branche-pm)', 'var(--color-fond)', 0.18 + 0.82 * part)
}

export function teinte(
  mode: ModeCouleur,
  bulle: { anneeMediane: number; total: number },
  bornes: { min: number; max: number } | null,
  maximum: number,
): string {
  if (mode === 'nombre' || bornes === null) return teinteParNombre(bulle.total, maximum)
  return teinteParDate(bulle.anneeMediane, bornes)
}

/** Trois cercles étiquetés de leur **vrai** compte : une légende calculée sur
 *  autre chose que les données affichées finit par mentir. */
export function paliersDeTaille(maximum: number): readonly { nombre: number; rayon: number }[] {
  if (maximum <= 0) return []
  const nombres = [...new Set([1, Math.max(1, Math.round(maximum / 4)), maximum])]
  return nombres.map((nombre) => ({ nombre, rayon: rayon(nombre, maximum) }))
}

/** Cinq échantillons du dégradé actif, dans l'ordre de lecture. */
export function paliersDeCouleur(
  mode: ModeCouleur,
  bornes: { min: number; max: number } | null,
  maximum: number,
): readonly { teinte: string; libelle: string }[] {
  const parts = [0, 0.25, 0.5, 0.75, 1]

  if (mode === 'date') {
    if (bornes === null) return []
    return parts.map((p) => {
      const annee = Math.round(bornes.min + (bornes.max - bornes.min) * p)
      return { teinte: teinteParDate(annee, bornes), libelle: String(annee) }
    })
  }

  if (maximum <= 0) return []
  return parts.map((p) => {
    const nombre = Math.max(1, Math.round(maximum * p))
    return { teinte: teinteParNombre(nombre, maximum), libelle: String(nombre) }
  })
}

/** `part` vaut 0 pour tout `fin`, 1 pour tout `debut`. `oklab` donne une rampe
 *  perceptivement régulière, là où `srgb` se tasse dans les tons moyens. */
function melange(debut: string, fin: string, part: number): string {
  const pourcentage = Math.round(Math.min(1, Math.max(0, part)) * 100)
  return `color-mix(in oklab, ${debut} ${pourcentage}%, ${fin})`
}
