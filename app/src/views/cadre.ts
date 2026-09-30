/**
 * La hauteur à poser sur le cadre d'un écran à canevas.
 *
 * ────────────────────────────────────────────────────────────────────
 * **Pourquoi ce fichier existe séparément.**
 *
 * Cette fonction vivait dans le bloc `<script>` d'`ArbreView.vue`, et l'arbre
 * était seul à l'employer. La chronologie, elle, avait sa propre copie — et
 * cette copie portait encore les trois défauts corrigés ci-dessous : elle lisait
 * `window.innerHeight`, arrondissait avec `Math.round`, et plaçait un plancher
 * de 320 px. Les trois. Corriger deux fois la même règle à deux endroits est
 * précisément ce qui n'arrive jamais, et la carte comme les anniversaires
 * allaient en faire une troisième copie.
 *
 * Elle est donc ici, appelée par tout écran qui mesure un canevas, et sa suite
 * est `cadre.test.ts`.
 * ────────────────────────────────────────────────────────────────────
 */

/**
 * La hauteur à poser sur le cadre, sachant à quelle hauteur son haut se trouve
 * — c'est-à-dire ce que l'en-tête applicatif lui a déjà pris.
 *
 * ────────────────────────────────────────────────────────────────────
 * **Trois décisions, et chacune a coûté un pixel de défilement.**
 *
 * 1. **`document.documentElement.clientHeight`, pas `window.innerHeight`.**
 *    `innerHeight` compte la bande d'une barre de défilement horizontale ;
 *    `clientHeight` est la hauteur de **mise en page**, qui l'exclut. Douze
 *    pixels d'écart, mesurés. Prendre `innerHeight` posait le cadre 12 px trop
 *    bas et faisait naître une barre verticale sur une page qui n'avait rien de
 *    plus à montrer — une barre causée par la mesure qui la subissait.
 *
 * 2. **Arrondi vers le bas.** Le haut du cadre est fractionnaire — 54,40 px
 *    pour un en-tête à 1,25 dpr. `Math.round(617,6)` rend 618 : le bas du cadre
 *    dépasse de 0,40 px, et 0,40 px de trop font un pixel de défilement.
 *
 * 3. **Aucun plancher.** Un `Math.max(320, …)` garantissait mathématiquement le
 *    débordement dès que la place restante passait sous 320 px — 164 px de trop
 *    sur un écran de 400 px, où l'en-tête s'enroule sur quatre rangs. Un
 *    plancher ne peut pas coexister avec « la page ne défile pas ». Et la
 *    garantie qu'il prétendait donner — « un canevas qui s'effondre à zéro ne
 *    se voit qu'à l'exécution » — est déjà portée par la chaîne flex
 *    (`min-h-0` / `flex-1`) du gabarit : le nombre magique n'y ajoutait qu'un
 *    débordement.
 *
 * Reste `Math.max(0, …)`, qui n'est pas un plancher de confort : une hauteur
 * négative n'est pas une longueur CSS, et le cadre garderait alors la dernière
 * valeur valide.
 * ────────────────────────────────────────────────────────────────────
 */
export function hauteurDuCadre(hautDuCadre: number): string {
  const disponible = document.documentElement.clientHeight - hautDuCadre - hauteurDeLaBarreDuBas()
  return `${Math.max(0, Math.floor(disponible))}px`
}

/**
 * La hauteur de la barre de navigation du bas, sur téléphone (refonte du
 * 30/09/2026) — ce que le bas de l'écran prend au cadre, comme l'en-tête en
 * prend le haut.
 *
 * `BarreNavigation` la **publie** sur `<html>` dans `--hauteur-barre-bas`, en
 * style en ligne, mesurée par un `ResizeObserver` : 0 sur bureau (la barre y
 * est `display: none`), l'encoche comprise sur un iPhone. On la lit dans le
 * style en ligne et non par `getComputedStyle` : c'est là qu'elle est écrite,
 * et c'est lisible sans moteur de mise en page.
 *
 * Absente, illisible ou négative, elle vaut 0 : un écran sans barre (connexion,
 * visiteur anonyme) ne doit rien perdre.
 */
export function hauteurDeLaBarreDuBas(): number {
  const brut = document.documentElement.style?.getPropertyValue?.('--hauteur-barre-bas') ?? ''
  const valeur = Number.parseFloat(brut)
  return Number.isFinite(valeur) && valeur > 0 ? valeur : 0
}
