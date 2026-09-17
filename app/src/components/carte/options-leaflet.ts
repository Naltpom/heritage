/**
 * Les options que l'on passe à Leaflet, calculées hors de Leaflet.
 *
 * ────────────────────────────────────────────────────────────────────
 * **L'import de `leaflet` est `import type`, et il doit le rester.**
 * `leaflet/src/core/Browser.js` lit `document.documentElement.style` **à
 * l'évaluation du module**. Sous l'environnement `node` de vitest, il n'y a pas
 * de `document` : un import de valeur, ici, ferait tomber ce fichier et tout ce
 * qui l'importe. `verbatimModuleSyntax` efface un `import type` à la
 * compilation, si bien qu'il ne reste rien à l'exécution — c'est ce qui permet à
 * ce module d'avoir des tests.
 *
 * ────────────────────────────────────────────────────────────────────
 * **`opacity` et `fillOpacity` de Leaflet NE SONT PAS la propriété CSS
 * `opacity`.** L'ADR 0007 interdit `opacity` sur un élément SVG — une couche de
 * composition par élément — et prescrit `fill-opacity` / `stroke-opacity`.
 * Vérifié dans `leaflet/src/layer/vector/SVG.js`, `_updateStyle()` écrit
 * littéralement :
 *
 *     path.setAttribute('stroke-opacity', options.opacity)
 *     path.setAttribute('fill-opacity',  options.fillOpacity)
 *
 * — c'est-à-dire exactement les deux propriétés que l'ADR demande. Leur emploi
 * est donc **conforme**. Ce qu'il faut refuser en revue : `path.style.opacity`,
 * une classe `opacity-*` de Tailwind sur un enfant SVG, et tout `setStyle` dont
 * on croirait qu'il parle de la propriété CSS.
 *
 * (Leaflet emploie bien `opacity` en CSS sur le calque de tuiles, pour son
 * fondu. C'est du HTML raster, hors du champ de l'ADR, dont le coût est celui
 * des 377 arêtes du canevas de l'arbre. Ne « corrigez » pas cela.)
 *
 * ────────────────────────────────────────────────────────────────────
 * **`fillColor` porte le dégradé, donc `carte.css` ne déclare pas `fill` sur
 * `.bulle`.** Leaflet écrit `fill` en **attribut** ; une règle CSS de même nom
 * le battrait et effacerait le dégradé — sans erreur, sans rien dans la console,
 * et toutes les bulles deviendraient de la même couleur.
 */
import type { CircleMarkerOptions, PathOptions } from 'leaflet'
import { rayon } from './echelles'

/** Une bulle : un disque dont l'aire dit le nombre et la couleur dit le mode. */
export function optionsBulle(
  nombre: number,
  maximum: number,
  teinte: string,
  selectionnee: boolean,
): CircleMarkerOptions {
  return {
    radius: rayon(nombre, maximum),
    // Le halo : décoration ET cible tactile, gratuitement — le test de collision
    // d'un tracé SVG inclut la largeur du trait. Sur un téléphone, c'est ce qui
    // rend atteignable un lieu à un seul événement.
    weight: selectionnee ? 10 : 8,
    className: selectionnee ? 'bulle bulle-selection' : 'bulle',
    color: teinte,
    fillColor: teinte,
    // Attributs `stroke-opacity` et `fill-opacity` — voir l'en-tête.
    opacity: selectionnee ? 0.55 : 0.28,
    fillOpacity: 0.85,
    interactive: true,
    bubblingMouseEvents: false,
  }
}

/**
 * Le fond vectoriel.
 *
 * `interactive: false` n'est pas un détail : sans lui, les polygones des pays
 * avalent les clics destinés aux bulles et prennent le curseur de pointeur. Les
 * couleurs, elles, ne sont pas ici — `carte.css` les peint à partir des jetons
 * du thème, ce qui fait suivre le thème sombre tout seul.
 */
export function optionsPays(): PathOptions {
  return { className: 'pays', interactive: false, weight: 0.6 }
}

/** L'unique URL externe du produit, et elle ne part qu'au second appui sur la
 *  bascule de fond. Elle est nommée ici pour qu'un seul endroit la porte — le
 *  même que celui que la CSP de `app/nginx.conf` autorise. */
export const HOTE_TUILES = 'https://tile.openstreetmap.org'
export const GABARIT_TUILES = `${HOTE_TUILES}/{z}/{x}/{y}.png`
export const MENTION_TUILES = '© les contributeurs d’OpenStreetMap'
